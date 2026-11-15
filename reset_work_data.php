<?php
/** Full work-data reset; settings and contacts are deliberately retained. */
function supplementaryResetTables(): array
{
    return ['security_fx_trades','kmd_manual_adjustments','purchase_vat_periods','zero_sales_history','zero_sales_context','import_vat_events','import_vat_payments','import_vat_documents','purchase_vat_adjustment_history','purchase_vat_adjustments','credit_note_refunds','credit_note_asset_links','credit_note_stock_lots','credit_note_line_links','credit_note_links','kmd_inf_purchase_confirmations','kmd_vat_period_cars'];
}
function clearSupplementaryResetData(PDO $pdo,int $companyId): void
{
    if(!$pdo->inTransaction() || $companyId<1) throw new LogicException('Lähtestamine vajab aktiivset andmebaasitehingut ja ettevõtet.');
    $exists=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    foreach(supplementaryResetTables() as $table) {
        $exists->execute([$table]); if(!(int)$exists->fetchColumn()) continue;
        $stmt=$pdo->prepare('DELETE FROM '.$table.' WHERE company_id=?'); $stmt->execute([$companyId]);
    }
}
function lockWorkDataCompany(PDO $pdo, int $companyId): void
{
    $query = $pdo->prepare('SELECT id FROM companies WHERE id=? FOR UPDATE');
    $query->execute([$companyId]);
}

function resetCompanyWorkData(PDO $pdo,int $companyId): string
{
    if(!$pdo->inTransaction() || $companyId<1) throw new LogicException('Lähtestamine vajab aktiivset andmebaasitehingut ja ettevõtet.');
    lockWorkDataCompany($pdo,$companyId);
    // Kõik kustutused on aktiivse ettevõtte kohta ja samas tehingus.
    clearSupplementaryResetData($pdo,$companyId);
    $statement=$pdo->prepare('DELETE l FROM inventory_movement_lines l JOIN inventory_movements m ON m.id=l.movement_id WHERE m.company_id=?');
    $statement->execute([$companyId]);
    foreach (['bank_statement_lines', 'portfelli_algsaldo'] as $table) {
        $statement = $pdo->prepare("DELETE FROM $table WHERE company_id = ?");
        $statement->execute([$companyId]);
    }

    $statement = $pdo->prepare('DELETE FROM inventory_movements WHERE company_id = ?');
    $statement->execute([$companyId]);

    // E-arvete ja e-kirjade logid on ajalugu, mitte seadistused.
    foreach (['billberry_sent_invoices', 'billberry_inbox', 'email_send_log'] as $table) {
        $statement = $pdo->prepare("DELETE FROM $table WHERE company_id = ?");
        $statement->execute([$companyId]);
    }

    // Põhivara tööandmed kustutatakse koos muu arvestusega; kontoseaded jäävad alles.
    $fixedAssetTables = [
        'fixed_asset_depreciation_runs',
        'fixed_asset_depreciation_corrections',
        'fixed_asset_estimate_changes',
        'fixed_asset_disposal_reversals',
        'fixed_asset_disposals',
        'fixed_assets',
    ];
    $fixedAssetTableExists = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    foreach ($fixedAssetTables as $table) {
        $fixedAssetTableExists->execute([$table]);
        if ((int) $fixedAssetTableExists->fetchColumn() > 0) {
            $statement = $pdo->prepare("DELETE FROM `$table` WHERE company_id = ?");
            $statement->execute([$companyId]);
        }
    }

    $statement = $pdo->prepare(
        'DELETE ip
         FROM invoice_payments ip
         JOIN sales_invoices si ON si.id = ip.invoice_id
         WHERE si.company_id = ?'
    );
    $statement->execute([$companyId]);

    $statement = $pdo->prepare(
        'DELETE pip
         FROM purchase_invoice_payments pip
         JOIN purchase_invoices pi ON pi.id = pip.invoice_id
         WHERE pi.company_id = ?'
    );
    $statement->execute([$companyId]);

    $cancellationTableExists = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    foreach (['purchase_invoice_cancellations', 'sales_invoice_cancellations'] as $cancellationTable) {
        $cancellationTableExists->execute([$cancellationTable]);
        if ((int) $cancellationTableExists->fetchColumn() > 0) {
            $statement = $pdo->prepare("DELETE FROM `$cancellationTable` WHERE company_id = ?");
            $statement->execute([$companyId]);
        }
    }

    $statement = $pdo->prepare(
        'DELETE sil
         FROM sales_invoice_lines sil
         JOIN sales_invoices si ON si.id = sil.invoice_id
         WHERE si.company_id = ?'
    );
    $statement->execute([$companyId]);

    $statement = $pdo->prepare(
        'DELETE pil
         FROM purchase_invoice_lines pil
         JOIN purchase_invoices pi ON pi.id = pil.invoice_id
         WHERE pi.company_id = ?'
    );
    $statement->execute([$companyId]);

    $statement = $pdo->prepare(
        'UPDATE sales_invoices SET journal_entry_id = NULL WHERE company_id = ?'
    );
    $statement->execute([$companyId]);

    $statement = $pdo->prepare(
        'UPDATE purchase_invoices SET journal_entry_id = NULL WHERE company_id = ?'
    );
    $statement->execute([$companyId]);

    $statement = $pdo->prepare('DELETE FROM sales_invoices WHERE company_id = ?');
    $statement->execute([$companyId]);

    $statement = $pdo->prepare('DELETE FROM purchase_invoices WHERE company_id = ?');
    $statement->execute([$companyId]);

    // Palgamooduli palgajooksud ja nende arvutused/maksed.
    foreach ([
        'payroll_run_item_payments',
        'payroll_run_item_calculations',
        'payroll_run_item_components',
        'payroll_run_confirmations',
        'payroll_run_items',
        'payroll_runs',
        'payroll_employment_closures',
        'payroll_employment_events',
        'payroll_employment_suspensions',
        'payroll_employment_terms',
        'payroll_employee_tax_settings',
        'payroll_employments',
        'payroll_employees',
    ] as $table) {
        $statement = $pdo->prepare("DELETE FROM $table WHERE company_id = ?");
        $statement->execute([$companyId]);
    }

    $statement = $pdo->prepare(
        'DELETE jl
         FROM journal_lines jl
         JOIN journal_entries je ON je.id = jl.entry_id
         WHERE je.company_id = ?'
    );
    $statement->execute([$companyId]);

    $statement = $pdo->prepare('DELETE FROM journal_entries WHERE company_id = ?');
    $statement->execute([$companyId]);

    // Talleta pearaamatukontode seosed enne tootekaartide kustutamist.
    preserveInventoryAccountDefaultsBeforeClear($pdo, $companyId);
    foreach (['inventory_products'=>'product_id','inventory_warehouses'=>'warehouse_id'] as $table=>$column) {
        $keep = resetTableExists($pdo,'purchase_import_defaults')
            ? " AND NOT EXISTS (SELECT 1 FROM purchase_import_defaults d WHERE d.$column=$table.id)" : '';
        $pdo->prepare("DELETE FROM $table WHERE company_id=?".$keep)->execute([$companyId]);
    }

    // Sulgemiste ajalugu kustutatakse. Kontaktid säilivad.
    foreach ([
        'accounting_period_events',
        'accounting_year_events',
    ] as $table) {
        $statement = $pdo->prepare("DELETE FROM $table WHERE company_id = ?");
        $statement->execute([$companyId]);
    }

    $noticeText = 'Ettevõtte kanded, müügi- ja ostuarved, laekumised, palgaandmed, '
        . 'laoliikumised, pangaväljavõtte read, portfelli algsaldo, perioodide sulgemise ajalugu, '
        . 'põhivara kaardid ja nende amortisatsiooni-, muudatuste ning võõrandamiste ajalugu, '
        . 'e-arvete ajalugu, e-kirjade saatmislogi, käibemaksu kinnitused, sõiduautode kuunimekirjad ning kreeditarvete viited kustutati. Põhivara kontoseaded, kontoplaan, ettevõte, kasutajakontod, '
        . 'palga kontoseaded, majandusaasta aruande seosed ja seaded, tarnija vaikimisi seosed ning lao kontode vaikeseosed jäid alles. '
        . 'Kontaktid säilisid. Lao kaardid ja laod säilisid, kui neile viitab ostuimpordi vaikeseade. Kustutati ka väärtpaberitehingud, valuutaarved ja nende maksed.';

    foreach(supplementaryResetTables() as $table) {
        $exists=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $exists->execute([$table]); if(!(int)$exists->fetchColumn()) continue;
        $check=$pdo->prepare('SELECT COUNT(*) FROM '.$table.' WHERE company_id=?'); $check->execute([$companyId]);
        if((int)$check->fetchColumn()!==0) throw new RuntimeException('Lähtestamine jäi poolikuks: '.$table);
    }
    return $noticeText;
}

function resetTableExists(PDO $pdo, string $table): bool
{
    $query = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $query->execute([$table]);
    return (bool)$query->fetchColumn();
}
