<?php
require_once __DIR__.'/cash_vat_lines.php';

function cashPurchasePeriodRows(PDO $pdo,int $company,array $source,array $plan,array $sources,array $parents,string $start,string $end): array
{
    $id=(int)$source['id']; $cashSources=[$id=>$source]; $credits=[];
    $payments=purchaseCashSupplierPayments($pdo,$company,$id,$end);
    foreach($payments as &$payment) {
        $deadline=(new DateTimeImmutable(substr($payment['payment_date'],0,7).'-01'))->modify('first day of next month')->modify('+19 days');
        $easter=function_exists('easter_date')?gmdate('Y-m-d',easter_date((int)$deadline->format('Y'))-2*86400):'';
        while((int)$deadline->format('N')>5 || $deadline->format('Y-m-d')===$easter) $deadline=$deadline->modify('+1 day');
        if(!empty($plan['filed_date']) && $plan['received_date']<=$plan['filed_date']
            && $plan['received_date']<=$deadline->format('Y-m-d') && $plan['filed_date']>=$payment['payment_date']) $payment['tax_date']=$payment['payment_date'];
    } unset($payment);
    purchaseCashSupplierAllocations($source,$payments,$plan['received_date']);
    foreach($parents as $creditId=>$parent) {
        if($parent!==$id || !isset($sources[$creditId]) || $sources[$creditId]['document_date']>$end) continue;
        $credit=applyInfConfirmations($pdo,$company,substr($sources[$creditId]['document_date'],0,7),[$sources[$creditId]])[0];
        if(!$credit['deduction_confirmed']) throw new InvalidArgumentException('Kassapõhise ostukreediti kinnitus puudub.');
        $cashSources[$creditId]=$credit;
        $credits[]=['id'=>$creditId,'date'=>$credit['document_date'],'lines'=>cashCreditLineMap($pdo,$company,'purchase',$creditId,$credit)];
    }
    $own=$plan['allocation_json']===CASH_COMPANY_PLAN; $accrual=null;
    $policy=companyCashVatPeriod($pdo,$company); $eventDate=$plan['tax_event_date']??$source['document_date'];
    if($own && (!empty($policy['cash_vat_from']) || !empty($policy['cash_vat_history'])) && !companyUsesCashVat($policy,$eventDate)) $accrual=$eventDate;
    $events=cashVatLineEvents($id,cashPurchaseLines($source),$plan['received_date'],$payments,$credits,cashVatRefunds($pdo,$company,'purchase',$id,$end),$accrual);
    $period=[]; $settled=null;
    foreach($events as $event) {
        if($event['settled']) $settled??=$event['date'];
        if($event['date']<$start || $event['date']>$end) continue;
        $key=$event['invoice_id'].':'.substr($event['date'],0,7); $lineId=$event['line_id'];
        $period[$key]??=['id'=>$event['invoice_id'],'date'=>$event['date'],'lines'=>[],'accrual'=>false];
        $period[$key]['accrual']=$period[$key]['accrual'] || $event['accrual'];
        $period[$key]['lines'][$lineId]??=['net'=>0,'tax'=>0,'capacity'=>0];
        foreach(['net','tax','capacity'] as $field) $period[$key]['lines'][$lineId][$field]+=$event[$field];
    }
    if($own) {
        $first=max($start,$plan['received_date']); $last=min($end,$settled??$end);
        for($date=new DateTimeImmutable($first);$date->format('Y-m-d')<=$last;$date=$date->modify('first day of next month')) {
            $period[$id.':'.$date->format('Y-m')]??=['id'=>$id,'date'=>$date->format('Y-m-d'),'lines'=>[],'accrual'=>false];
        }
    }
    $out=[];
    foreach($period as $record) {
        $row=$cashSources[$record['id']]; $originalLines=cashPurchaseLines($row);
        $row['period_plan_total']=$row['deductible_cents']; $row['deductible_cents']=0;
        $row['cash_supplier']=true; $row['cash_company_purchase']=$own && !$record['accrual']; $row['report_date']=$record['date'];
        $row['reverse_vat']=0;$row['reverse_base_by_rate']=[];$row['domestic_purchase_net']=0;
        $explicit=$row['vat_line_allocations']??[]; $row['vat_line_allocations']=[];
        foreach($originalLines as $lineId=>$line) {
            $amounts=$record['lines'][$lineId]??['net'=>0,'tax'=>0,'capacity'=>0];
            $meta=$explicit[$lineId]??['vat_purpose'=>$row['vat_purpose'],'car_ids'=>$row['car_ids']];
            $row['vat_line_allocations'][$lineId]=array_replace($meta,['vat'=>$line['tax'],'deductible_cents'=>$amounts['capacity'],
                'period_plan_total'=>$line['capacity'],'car_ids'=>$amounts['capacity']?($meta['car_ids']??[]):[]]);
            $row['deductible_cents']+=$amounts['capacity'];
            if($line['reverse']) {
                $rate=number_format((float)$row['reverse_spec']['rate'],2,'.','');
                $row['reverse_base_by_rate'][$rate]=($row['reverse_base_by_rate'][$rate]??0)+$amounts['net'];
                $row['reverse_vat']+=$amounts['tax']; $row['domestic_purchase_net']+=$amounts['net'];
            }
        }
        $out[]=$row;
    }
    return $out;
}
