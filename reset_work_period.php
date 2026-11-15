<?php

/** The cutoff can split a trade from settlement. Remove the affected FIFO suffix as one group. */
function resetSecurityFxPeriod(PDO $pdo, int $company, string $from, array &$entries): int
{
    if (!resetTableExists($pdo,'security_fx_trades')) return 0;
    $query = $pdo->prepare('SELECT *, HEX(WEIGHT_STRING(portfolio)) AS portfolio_key,
        HEX(WEIGHT_STRING(instrument)) AS instrument_key FROM security_fx_trades WHERE company_id=? ORDER BY trade_date,id FOR UPDATE');
    $query->execute([$company]);
    $remove = [];
    $suffix = [];
    $references = ['trade_entry_id','settlement_entry_id','void_trade_entry_id','void_settlement_entry_id'];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = $row['security_account_id'].'|'.$row['portfolio_key'].'|'.$row['instrument_key'];
        $active = !$row['voided_at'];
        $linked = array_intersect_key($entries,array_fill_keys(array_map('intval',array_intersect_key($row,array_flip($references))),true));
        if ($row['trade_date'] >= $from || $row['settlement_date'] >= $from || $linked || ($active && isset($suffix[$key]))) {
            $remove[(int)$row['id']] = $row;
            if ($active) $suffix[$key] = true;
            foreach ($references as $field) if ($row[$field]) $entries[(int)$row[$field]] = true;
        }
    }
    $restore = $pdo->prepare("UPDATE security_fx_trades SET remaining_quantity=remaining_quantity+?,
        remaining_cost_cents=remaining_cost_cents+? WHERE company_id=? AND id=? AND side='buy' AND voided_at IS NULL
        AND remaining_quantity+?<=quantity AND remaining_cost_cents+?<=cost_cents");
    foreach ($remove as $row) {
        if ($row['side'] !== 'sell' || $row['voided_at']) continue;
        foreach (json_decode($row['consumed_lots'],true,512,JSON_THROW_ON_ERROR) as $lot) {
            if (isset($remove[(int)$lot['id']])) continue;
            $restore->execute([$lot['quantity'],$lot['cost'],$company,$lot['id'],$lot['quantity'],$lot['cost']]);
            if ($restore->rowCount() !== 1) throw new RuntimeException('Varasema väärtpaberi ostupartii jääki ei saanud taastada.');
        }
    }
    resetDeleteIds($pdo,$company,'security_fx_trades','id',array_keys($remove));
    return count($remove);
}

function resetDeleteIds(PDO $pdo, int $company, string $table, string $column, array $ids, array $scope = []): void
{
    if (!$ids || !resetTableExists($pdo,$table)) return;
    $extra = '';
    foreach (array_keys($scope) as $name) $extra .= " AND $name=?";
    foreach (array_chunk($ids,1000) as $batch) {
        $placeholders = implode(',',array_fill(0,count($batch),'?'));
        $pdo->prepare("DELETE FROM $table WHERE company_id=?$extra AND $column IN ($placeholders)")
            ->execute([$company,...array_values($scope),...$batch]);
    }
}

/**
 * Follow document links before the old date-reset path deletes invoice rows or journal entries.
 * An older document is included only when a linked accounting event would otherwise be split.
 */
function resetPeriodSupplementary(PDO $pdo, int $company, string $from, array &$entryIds, array &$salesIds,
    array &$purchaseIds, array &$movementIds, array &$salesCancellationIds, array &$purchaseCancellationIds,
    array $payrollRunIds, array $assetIds): int
{
    if (!$pdo->inTransaction()) throw new LogicException('Kuupäevapõhine kustutamine vajab aktiivset tehingut.');
    $load = static function(string $table, string $fields = '*') use($pdo,$company): array {
        if (!resetTableExists($pdo,$table)) return [];
        $query = $pdo->prepare("SELECT $fields FROM $table WHERE company_id=?");
        $query->execute([$company]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    };
    $mark = static function(array &$set, mixed ...$ids): void {
        foreach ($ids as $id) if ((int)$id > 0) $set[(int)$id] = true;
    };
    $entries = $sales = $purchases = $movements = $salesCancels = $purchaseCancels = [];
    $mark($entries,...$entryIds);
    $mark($sales,...$salesIds);
    $mark($purchases,...$purchaseIds);
    $mark($movements,...$movementIds);
    $mark($salesCancels,...$salesCancellationIds);
    $mark($purchaseCancels,...$purchaseCancellationIds);
    foreach ($load('payroll_run_item_payments','run_id,journal_entry_id') as $row) {
        if (in_array((int)$row['run_id'],$payrollRunIds,true)) $mark($entries,$row['journal_entry_id']);
    }
    $hasEntry = static function(mixed ...$ids) use (&$entries): bool {
        foreach ($ids as $id) if (isset($entries[(int)$id])) return true;
        return false;
    };
    $security = resetSecurityFxPeriod($pdo,$company,$from,$entries);
    $invoices = [
        'sales'=>$load('sales_invoices','id, invoice_date, journal_entry_id, status'),
        'purchase'=>$load('purchase_invoices','id, invoice_date, journal_entry_id, status'),
    ];
    $invoiceSets = ['sales'=>&$sales,'purchase'=>&$purchases];
    $cancelSets = ['sales'=>&$salesCancels,'purchase'=>&$purchaseCancels];
    $cancellations = ['sales'=>$load('sales_invoice_cancellations'),'purchase'=>$load('purchase_invoice_cancellations')];
    $credits = $load('credit_note_links');
    $creditRefunds = $load('credit_note_refunds');
    $imports = $load('import_vat_documents');
    $importPayments = $load('import_vat_payments');
    $adjustmentHistory = $load('purchase_vat_adjustment_history','invoice_id,journal_entry_id,entry_date');
    $adjustments = $load('purchase_vat_adjustments','invoice_id,last_entry_id');
    $zeroContexts = $load('zero_sales_context','invoice_id,tax_date');
    $plans = $load('purchase_vat_periods','invoice_id,tax_event_date');
    $inventory = $load('inventory_movements','id,movement_date,journal_entry_id,source_sales_invoice_id,source_purchase_invoice_id');
    $stockLinks = [];
    if (resetTableExists($pdo,'credit_note_stock_lots')) {
        $query = $pdo->prepare('SELECT c.invoice_kind,c.credit_invoice_id,l.movement_id
            FROM credit_note_stock_lots c JOIN inventory_movement_lines l ON l.id=c.original_lot_id
            JOIN inventory_movements m ON m.id=l.movement_id AND m.company_id=c.company_id WHERE c.company_id=?');
        $query->execute([$company]);
        $stockLinks = $query->fetchAll(PDO::FETCH_ASSOC);
    }
    $corrections = $load('journal_entries','id,corrects_entry_id');
    $payments = [];
    foreach (['sales'=>'invoice_payments','purchase'=>'purchase_invoice_payments'] as $kind=>$table) {
        $query=$pdo->prepare("SELECT p.invoice_id,p.journal_entry_id FROM $table p JOIN ".$kind."_invoices i ON i.id=p.invoice_id WHERE i.company_id=?");
        $query->execute([$company]);
        $payments[$kind]=$query->fetchAll(PDO::FETCH_ASSOC);
    }
    $documents = $refunds = $importPaymentIds = [];
    do {
        $before = count($entries)+count($sales)+count($purchases)+count($movements)+count($documents);
        foreach ($invoices as $kind=>$rows) {
            $selected = &$invoiceSets[$kind];
            foreach ($rows as $row) {
                if ($row['invoice_date'] >= $from || $hasEntry($row['journal_entry_id']) || isset($selected[(int)$row['id']])) {
                    $mark($selected,$row['id']);
                    $mark($entries,$row['journal_entry_id']);
                }
            }
            foreach ($payments[$kind] as $row) if (isset($selected[(int)$row['invoice_id']])) $mark($entries,$row['journal_entry_id']);
            foreach ($cancellations[$kind] as $row) {
                $id = (int)$row[$kind.'_invoice_id'];
                if (isset($selected[$id]) || isset($movements[(int)$row['inventory_movement_id']])
                    || $hasEntry($row['original_journal_entry_id'],$row['reversal_journal_entry_id'])) {
                    $mark($selected,$id);
                    $mark($cancelSets[$kind],$row['id']);
                    $mark($movements,$row['inventory_movement_id']);
                    $mark($entries,$row['original_journal_entry_id'],$row['reversal_journal_entry_id']);
                }
            }
            unset($selected);
        }
        foreach ($credits as $row) {
            $selected = &$invoiceSets[$row['invoice_kind']];
            if (isset($selected[(int)$row['original_invoice_id']]) || isset($selected[(int)$row['credit_invoice_id']]) || $hasEntry($row['void_entry_id'])) {
                $mark($selected,$row['credit_invoice_id']);
                $mark($entries,$row['void_entry_id']);
            }
            unset($selected);
        }
        foreach ($creditRefunds as $row) {
            $selected = $invoiceSets[$row['invoice_kind']];
            if ($row['refund_date'] >= $from || isset($selected[(int)$row['original_invoice_id']]) || isset($selected[(int)$row['credit_invoice_id']])
                || $hasEntry($row['journal_entry_id'],$row['void_entry_id'])) {
                $mark($refunds,$row['id']);
                $mark($entries,$row['journal_entry_id'],$row['void_entry_id']);
            }
        }
        foreach ($zeroContexts as $row) if ($row['tax_date'] >= $from) $mark($sales,$row['invoice_id']);
        foreach ($plans as $row) if ($row['tax_event_date'] && $row['tax_event_date'] >= $from) $mark($purchases,$row['invoice_id']);
        foreach ($imports as $row) {
            if ($row['release_date'] >= $from || isset($purchases[(int)$row['invoice_id']]) || isset($purchases[(int)$row['cost_invoice_id']])
                || $hasEntry($row['journal_entry_id'],$row['void_entry_id'])) {
                $mark($documents,$row['id']);
                $mark($entries,$row['journal_entry_id'],$row['void_entry_id']);
            }
        }
        foreach ($importPayments as $row) {
            if ($row['payment_date'] >= $from || $hasEntry($row['journal_entry_id'])) {
                $mark($entries,$row['journal_entry_id']);
                $mark($importPaymentIds,$row['id']);
            } elseif (isset($documents[(int)$row['document_id']])) {
                $mark($entries,$row['journal_entry_id']);
                $mark($importPaymentIds,$row['id']);
            }
        }
        foreach ($adjustmentHistory as $row) {
            if ($row['entry_date'] >= $from || $hasEntry($row['journal_entry_id'])) $mark($purchases,$row['invoice_id']);
            if (isset($purchases[(int)$row['invoice_id']])) $mark($entries,$row['journal_entry_id']);
        }
        foreach ($adjustments as $row) {
            if ($hasEntry($row['last_entry_id'])) $mark($purchases,$row['invoice_id']);
            if (isset($purchases[(int)$row['invoice_id']])) $mark($entries,$row['last_entry_id']);
        }
        foreach ($inventory as $row) {
            if ($row['movement_date'] >= $from || isset($movements[(int)$row['id']]) || $hasEntry($row['journal_entry_id'])
                || isset($sales[(int)$row['source_sales_invoice_id']]) || isset($purchases[(int)$row['source_purchase_invoice_id']])) {
                $mark($movements,$row['id']);
                $mark($entries,$row['journal_entry_id']);
                $mark($sales,$row['source_sales_invoice_id']);
                $mark($purchases,$row['source_purchase_invoice_id']);
            }
        }
        foreach ($stockLinks as $row) {
            if (isset($movements[(int)$row['movement_id']])) $mark($invoiceSets[$row['invoice_kind']],$row['credit_invoice_id']);
        }
        foreach ($corrections as $row) if ($hasEntry($row['corrects_entry_id'])) $mark($entries,$row['id']);
    } while ($before !== count($entries)+count($sales)+count($purchases)+count($movements)+count($documents));

    $deletedAssets = array_fill_keys($assetIds,true);
    foreach ($load('credit_note_asset_links','credit_invoice_id,asset_id') as $link) {
        if (!isset($purchases[(int)$link['credit_invoice_id']]) || isset($deletedAssets[(int)$link['asset_id']])) continue;
        foreach ($invoices['purchase'] as $invoice) {
            if ((int)$invoice['id']===(int)$link['credit_invoice_id'] && $invoice['status']==='posted') {
                throw new RuntimeException('Valitud perioodis on aktiivne põhivara kreeditarve, mis muutis varasemat varakaarti. Tühista see esmalt kreeditarve vaates.');
            }
        }
    }
    resetDeleteIds($pdo,$company,'credit_note_refunds','id',array_keys($refunds));
    foreach ($invoiceSets as $kind=>$selected) {
        foreach (['credit_note_stock_lots','credit_note_line_links','credit_note_links'] as $table) {
            resetDeleteIds($pdo,$company,$table,'credit_invoice_id',array_keys($selected),['invoice_kind'=>$kind]);
        }
    }
    resetDeleteIds($pdo,$company,'credit_note_asset_links','credit_invoice_id',array_keys($purchases));
    foreach (['purchase_vat_adjustment_history','purchase_vat_adjustments','purchase_vat_periods','kmd_inf_purchase_confirmations'] as $table) {
        resetDeleteIds($pdo,$company,$table,'invoice_id',array_keys($purchases));
    }
    foreach (['zero_sales_history','zero_sales_context'] as $table) {
        resetDeleteIds($pdo,$company,$table,'invoice_id',array_keys($sales));
    }
    resetDeleteIds($pdo,$company,'import_vat_payments','id',array_keys($importPaymentIds));
    resetDeleteIds($pdo,$company,'import_vat_events','document_id',array_keys($documents));
    resetDeleteIds($pdo,$company,'import_vat_documents','id',array_keys($documents));
    if (resetTableExists($pdo,'kmd_manual_adjustments')) {
        $pdo->prepare('DELETE FROM kmd_manual_adjustments WHERE company_id=? AND entry_date>=?')->execute([$company,$from]);
        foreach (['journal_entry_id','active_entry_id'] as $field) resetDeleteIds($pdo,$company,'kmd_manual_adjustments',$field,array_keys($entries));
    }
    if (resetTableExists($pdo,'kmd_vat_period_cars')) {
        $pdo->prepare("DELETE FROM kmd_vat_period_cars WHERE company_id=? AND CONCAT(period,'-01')>=?")->execute([$company,$from]);
    }
    resetDeleteIds($pdo,$company,'billberry_sent_invoices','sales_invoice_id',array_keys($sales));
    resetDeleteIds($pdo,$company,'billberry_inbox','purchase_invoice_id',array_keys($purchases));
    resetDeleteIds($pdo,$company,'email_send_log','document_id',array_keys($sales),['document_type'=>'sales_invoice']);
    resetDeleteIds($pdo,$company,'email_send_log','document_id',$payrollRunIds,['document_type'=>'payslip']);
    foreach (['billberry_sent_invoices'=>'sent_at','billberry_inbox'=>'COALESCE(invoice_date,received_at,created_at)',
        'email_send_log'=>'attempted_at'] as $table=>$field) {
        if (resetTableExists($pdo,$table)) $pdo->prepare("DELETE FROM $table WHERE company_id=? AND $field>=?")->execute([$company,$from]);
    }
    [$entryIds,$salesIds,$purchaseIds,$movementIds,$salesCancellationIds,$purchaseCancellationIds] =
        array_map('array_keys',[$entries,$sales,$purchases,$movements,$salesCancels,$purchaseCancels]);
    return $security;
}
