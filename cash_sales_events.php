<?php
require_once __DIR__.'/cash_vat_events.php';
require_once __DIR__.'/cash_vat_lines.php';

function loadCashSales(PDO $pdo, int $company, string $start, string $end): array
{
    $result=['ids'=>[],'invoices'=>[],'errors'=>[]];
    $policy=companyCashVatPeriod($pdo,$company);
    $q=$pdo->prepare("SELECT DISTINCT i.id,i.invoice_date,i.supply_date,t.code FROM sales_invoices i
        JOIN sales_invoice_lines l ON l.invoice_id=i.id JOIN tax_codes t ON t.id=l.tax_code_id AND t.company_id=i.company_id
        WHERE i.company_id=? AND i.status='posted' AND i.journal_entry_id IS NOT NULL AND t.code IN ('M_CASH24','M_CASH13','M_CASH9','M_EE24','M_EE13','M_EE9')
        AND (i.invoice_date BETWEEN ? AND ? OR i.supply_date BETWEEN ? AND ? OR EXISTS (
            SELECT 1 FROM invoice_payments p WHERE p.invoice_id=i.id AND p.payment_date BETWEEN ? AND ?)
            OR EXISTS (SELECT 1 FROM credit_note_refunds r WHERE r.company_id=i.company_id AND r.invoice_kind='sales'
                AND r.original_invoice_id=i.id AND r.status='posted' AND r.refund_date BETWEEN ? AND ?))");
    $q->execute([$company,$start,$end,$start,$end,$start,$end,$start,$end]); $ids=[];$standard=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
        $cash=str_starts_with($candidate['code'],'M_CASH');
        if($cash || ($candidate['supply_date'] && companyUsesCashVat($policy,$candidate['supply_date'])!==companyUsesCashVat($policy,$candidate['invoice_date']))) {
            $ids[$candidate['id']]=$candidate['id'];if(!$cash)$standard[$candidate['id']]=true;
        }
    }
    if(!$ids) return $result;
    $links=loadCreditLinks($pdo,$company,'sales'); $originals=[];
    foreach($ids as $id) $originals[(int)($links[$id]['original_invoice_id']??$id)]=true;
    $allIds=$originals;
    foreach($links as $id=>$link) if(isset($originals[$link['original_invoice_id']]) && $link['credit_status']==='posted') $allIds[$id]=true;
    $data=loadInfRows($pdo,$company,$start,$end,'sales',[],array_keys($allIds));
    $result['errors']=$data['errors']; $rows=[];
    foreach($data['invoices'] as $row) {
        if($row['document_date']>$end && !isset($originals[$row['id']])) continue;
        try { $row['cash_lines']=cashSalesLines($pdo,$company,(int)$row['id'],true); }
        catch(InvalidArgumentException $error) { $result['errors'][]=$error->getMessage();continue; }
        $rows[$row['id']]=$row; $result['ids'][$row['id']]=true;
    }
    $payments=$pdo->prepare("SELECT p.payment_date,p.amount FROM invoice_payments p
        JOIN journal_entries j ON j.id=p.journal_entry_id AND j.company_id=? AND j.status='posted'
        WHERE p.invoice_id=? AND p.payment_date<=? ORDER BY p.payment_date,p.id");
    foreach($originals as $id=>$_) {
        try {
            if(!isset($rows[$id])) throw new RuntimeException('Kassapõhise müügi algne arve puudub.');
            $original=$rows[$id];
            $q=$pdo->prepare('SELECT supply_date FROM sales_invoices WHERE id=? AND company_id=?');$q->execute([$id,$company]);$supply=$q->fetchColumn();
            if(isset($standard[$id])) {
                $q=$pdo->prepare('SELECT journal_entry_id FROM sales_invoices WHERE id=? AND company_id=?');$q->execute([$id,$company]);importEntry($pdo,$company,(int)$q->fetchColumn(),false);
                if($original['country_code']!=='EE')throw new InvalidArgumentException('Ülemineku kodumaine arve peab olema Eesti partneriga.');
            } else assertCashSaleCreditBasis($pdo,$company,$id,max($end,$original['document_date']));
            $credits=[];
            foreach($rows as $creditId=>$row) {
                $link=$row['credit_link'];
                if(!$link || (int)$link['original_invoice_id']!==$id) continue;
                if($row['net']>=0 || $row['vat']>=0 || $row['country_code']!=='EE') {
                    throw new RuntimeException('Kassapõhise kreeditarve summad, partner või maksukood vajavad kontrolli.');
                }
                $credits[]=['id'=>$creditId,'date'=>$row['document_date'],'lines'=>cashCreditLineMap($pdo,$company,'sales',$creditId,$row),
                    'defer_until_refund'=>$supply && $supply>$row['document_date']];
            }
            $payments->execute([$company,$id,$end]);
            $paymentRows=$payments->fetchAll(PDO::FETCH_ASSOC);
            foreach($paymentRows as $payment) foreach($original['cash_lines'] as $line) {
                if(($line['valid_from']!==null && $payment['payment_date']<$line['valid_from']) || ($line['valid_to']!==null && $payment['payment_date']>$line['valid_to'])) {
                    throw new InvalidArgumentException('Ettemakse kuupäeval peab kehtima arvereal kasutatud maksumäär. Koosta õige määraga ettemaksuarve.');
                }
            }
            $q=$pdo->prepare('SELECT supply_date FROM sales_invoices WHERE id=? AND company_id=?');$q->execute([$id,$company]);$supply=$q->fetchColumn();
            $policy=companyCashVatPeriod($pdo,$company);$accrual=null;
            if($supply && (isset($standard[$id]) || ((!empty($policy['cash_vat_from']) || !empty($policy['cash_vat_history'])) && !companyUsesCashVat($policy,$supply)))) $accrual=$supply;
            $events=cashVatLineEvents($id,$original['cash_lines'],'0001-01-01',
                $paymentRows,$credits,cashVatRefunds($pdo,$company,'sales',$id,$end),$accrual);
            $period=[];
            foreach($events as $event) {
                if($event['date']<$start || $event['date']>$end) continue;
                $key=$event['invoice_id'];
                $period[$key]??=['amount'=>0,'gross'=>0,'credit'=>false,'rates'=>[],'tax'=>[],'gross_rates'=>[],'accrual'=>false];
                $period[$key]['accrual']=$period[$key]['accrual'] || $event['accrual'];
                $rate=$rows[$key]['cash_lines'][$event['line_id']]['rate'];
                $period[$key]['amount']+=$event['net']; $period[$key]['gross']+=$event['gross'];
                $period[$key]['rates'][$rate]=($period[$key]['rates'][$rate]??0)+$event['net'];
                $period[$key]['tax'][$rate]=($period[$key]['tax'][$rate]??0)+$event['tax'];
                $period[$key]['gross_rates'][$rate]=($period[$key]['gross_rates'][$rate]??0)+$event['gross'];
                $period[$key]['credit']=$period[$key]['credit']||$event['credit'];
            }
            foreach($period as $key=>$amount) {
                if(!$amount['gross'] && !$amount['credit']) continue;
                $row=$rows[$key]; $row['cash_sale']=true;
                $row['cash_accrual']=$amount['accrual'];
                $row['taxable_by_rate']=$amount['rates']; $row['cash_vat_by_rate']=$amount['tax'];$row['cash_gross_by_rate']=$amount['gross_rates'];
                $row['cash_vat']=$amount['gross']-$amount['amount']; $row['cash_gross']=$amount['gross'];
                $result['invoices'][]=$row;
            }
        } catch(Throwable $error) {
            $result['errors'][]='Müügiarve '.($rows[$id]['document_number']??$id).': '.$error->getMessage();
        }
    }
    return $result;
}
