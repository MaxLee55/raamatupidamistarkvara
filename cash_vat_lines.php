<?php
require_once __DIR__.'/purchase_vat_adjustment.php';
require_once __DIR__.'/cash_vat_events.php';

function cashUnrefundedAdvanceParts(array $held): array
{
    $out=[];
    foreach($held as $h) {
        $weights=[];foreach($h['lines'] as $id=>$l)$weights[$id]=$l['gross'];$due=array_sum($weights);
        $released=$due?purchaseVatAllocate(min($h['refunded'],$due),$weights):[];
        foreach($h['lines'] as $id=>$l) {
            $gross=$l['gross']-($released[$id]??0);
            [$net,$rem]=$l['gross']?purchaseVatMulDiv($l['net'],$gross,$l['gross']):[0,0];
            $net+=($l['gross'] && $rem>=intdiv($l['gross']+1,2)?1:0);
            foreach(['gross'=>$gross,'net'=>$net,'tax'=>$gross-$net,'capacity'=>$net] as $field=>$value)$out[$id][$field]=($out[$id][$field]??0)+$value;
        }
    }
    return $out;
}

/** Cash is allocated proportionally to the remaining invoice lines, with exact cent totals. */
function cashVatLineEvents(int $invoiceId,array $lines,string $received,array $payments,array $credits,array $refunds=[],?string $accrualDate=null): array
{
    $originalGross=array_sum(array_column($lines,'gross')); $events=[];
    if($originalGross<=0) throw new InvalidArgumentException('Kassapõhise arve summa peab olema positiivne.');
    foreach($lines as $id=>$line) {
        if($line['gross']<0 || min($line['net'],$line['tax'],$line['capacity'])<0 || $line['capacity']>$line['gross']
            || ($line['gross']===0 && $line['net']+$line['tax']+$line['capacity']!==0)) throw new InvalidArgumentException('Kassapõhise arverea alus on vigane.');
    }
    foreach($payments as $i=>$p) $events[]=['date'=>$p['tax_date']??max($received,$p['payment_date']),'kind'=>0,'order'=>$i,'gross'=>creditCents((string)$p['amount'])];
    foreach($credits as $c) $events[]=['date'=>$c['date'],'kind'=>1,'order'=>$c['id'],'credit'=>$c];
    foreach($refunds as $r) $events[]=['date'=>$r['refund_date'],'kind'=>2,'order'=>$r['id'],'gross'=>(int)$r['amount_cents'],'credit_id'=>(int)($r['credit_invoice_id']??0)];
    if($accrualDate!==null) $events[]=['date'=>max($received,$accrualDate),'kind'=>3,'order'=>0];
    usort($events,static fn($a,$b)=>[$a['date'],$a['kind'],$a['order']]<=>[$b['date'],$b['kind'],$b['order']]);
    $paid=$returned=0; $previous=[]; $out=[]; $accrued=false;$held=[];
    foreach($events as $e) {
        if($e['kind']===0) {
            if($e['gross']<=0 || $e['gross']>$originalGross-$paid) throw new InvalidArgumentException('Kassapõhise arve makse ületab arve summat.');
            $paid+=$e['gross'];
        } elseif($e['kind']===1) {
            if($e['date']<$received) throw new InvalidArgumentException('Kreeditarve ei tohi eelneda arve saamisele.');
            foreach($e['credit']['lines'] as $id=>$credit) {
                if(!isset($lines[$id])) throw new InvalidArgumentException('Kassapõhise kreedi algne arverida puudub.');
                foreach(['gross','net','tax','capacity'] as $key) {
                    if($credit[$key]<0 || $credit[$key]>$lines[$id][$key]) throw new InvalidArgumentException('Kassapõhise kreedi reajaotus ületab algse rea jääki.');
                    $lines[$id][$key]-=$credit[$key];
                }
                if($lines[$id]['gross']===0 && array_sum([$lines[$id]['net'],$lines[$id]['tax'],$lines[$id]['capacity']])!==0) throw new InvalidArgumentException('Kreedirea ümardusjääk ei klapi.');
            }
            if(!empty($e['credit']['defer_until_refund'])) $held[$e['credit']['id']]=['credit'=>$e['credit'],'lines'=>[],'refunded'=>0];
        } elseif($e['kind']===2) {
            $remaining=array_sum(array_column($lines,'gross'));
            if($e['gross']<=0 || $e['gross']>max(0,$paid-$returned-$remaining)) throw new InvalidArgumentException('Tagasimakse ületab krediteeritud enammakset.');
            $returned+=$e['gross'];
            if(isset($held[$e['credit_id']])) {
                $h=&$held[$e['credit_id']];$h['refunded']+=$e['gross'];unset($h);
            }
        } else $accrued=true;
        $remaining=array_sum(array_column($lines,'gross'));
        $alloc=$remaining?purchaseVatAllocate($accrued?$remaining:min($paid-$returned,$remaining),array_column($lines,'gross','id')):array_fill_keys(array_keys($lines),0);
        $targets=[];
        foreach($lines as $id=>$line) {
            $target=['gross'=>$alloc[$id]??0];
            foreach(['net','tax','capacity'] as $key) {
                [$value,$remainder]=$line['gross']?purchaseVatMulDiv($line[$key],$target['gross'],$line['gross']):[0,0];
                $target[$key]=$value+($line['gross'] && $remainder>=intdiv($line['gross']+1,2)?1:0);
            }
            if(empty($line['reverse'])) $target['tax']=$target['gross']-$target['net'];
            $targets[$id]=$target;
        }
        if($e['kind']===1 && !empty($e['credit']['defer_until_refund'])) {
            $priorHolds=cashUnrefundedAdvanceParts($held);$weights=[];
            foreach($targets as $id=>$target)$weights[$id]=max(0,($previous[$id]['gross']??0)-$target['gross']-($priorHolds[$id]['gross']??0));
            $budget=max(0,array_sum(array_column($previous,'gross'))-array_sum(array_column($targets,'gross'))-array_sum(array_column($priorHolds,'gross')));
            $parts=purchaseVatAllocate(min($budget,array_sum($weights)),$weights);
            foreach($parts as $id=>$gross) {
                $netDue=max(0,($previous[$id]['net']??0)-$targets[$id]['net']-($priorHolds[$id]['net']??0));
                [$net,$rem]=$weights[$id]?purchaseVatMulDiv($netDue,$gross,$weights[$id]):[0,0];
                $net=min($gross,$net+($weights[$id] && $rem>=intdiv($weights[$id]+1,2)?1:0));
                $held[$e['credit']['id']]['lines'][$id]=['gross'=>$gross,'net'=>$net,'tax'=>$gross-$net,'capacity'=>$net];
            }
        }
        $retained=cashUnrefundedAdvanceParts($held);
        foreach($targets as $id=>$target) {
            foreach($retained[$id]??[] as $field=>$value)$target[$field]+=$value;
            $delta=[]; foreach($target as $key=>$value) $delta[$key]=$value-($previous[$id][$key]??0);
            $previous[$id]=$target;
            $eventCredit=$e['kind']===1?$e['credit']:($e['kind']===2?($held[$e['credit_id']]['credit']??null):null);
            $credit=$eventCredit['lines'][$id]??null;
            $negative=$delta['net']<0 || $delta['capacity']<0;
            if(array_sum(array_map('abs',$delta))===0 && !$credit) continue;
            $out[]=$delta+['date'=>$e['date'],'invoice_id'=>$credit && $negative?$eventCredit['id']:$invoiceId,
                'line_id'=>$credit && $negative?$credit['line_id']:$id,'original_line_id'=>$id,
                'credit'=>(bool)$credit,'settled'=>$accrued || $paid-$returned>=$remaining,'accrual'=>$accrued && !($e['kind']===2 && $eventCredit)];
        }
    }
    return $out;
}

function cashPurchaseLines(array $source): array
{
    $inputs=$source['vat_input_lines']??[]; $explicit=$source['vat_line_allocations']??[];
    if(count($inputs)>1 && !$explicit) throw new InvalidArgumentException('Kassapõhise segaarve jaoks kinnita mahaarvamine arveridade kaupa.');
    $out=[];
    foreach($inputs as $id=>$line) {
        $reverse=!empty($line['reverse']);
        $out[$id]=['id'=>$id,'net'=>$line['net'],'tax'=>$line['vat'],'gross'=>$line['net']+($reverse?0:$line['vat']),
            'capacity'=>$explicit[$id]['deductible_cents']??$source['deductible_cents'],'reverse'=>$reverse];
    }
    return $out;
}

function cashCreditLineMap(PDO $pdo,int $company,string $kind,int $creditId,array $source): array
{
    $q=$pdo->prepare('SELECT original_line_id,credit_line_id FROM credit_note_line_links WHERE company_id=? AND invoice_kind=? AND credit_invoice_id=?');
    $q->execute([$company,$kind,$creditId]); $map=[];
    $lines=$kind==='purchase'?cashPurchaseLines($source):$source['cash_lines'];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id=(int)$row['credit_line_id']; if(!isset($lines[$id])) throw new InvalidArgumentException('Kreedirea seos puudub.');
        $l=$lines[$id]; $map[(int)$row['original_line_id']]=['line_id'=>$id,'gross'=>-$l['gross'],'net'=>-$l['net'],'tax'=>-$l['tax'],'capacity'=>-$l['capacity']];
    }
    if(!$map) throw new InvalidArgumentException('Kassapõhise kreedi reaseosed puuduvad.');
    return $map;
}

function cashSalesLines(PDO $pdo,int $company,int $invoice,bool $allowStandard=false): array
{
    $q=$pdo->prepare('SELECT l.*,t.code,t.rate,t.valid_from,t.valid_to FROM sales_invoice_lines l
        LEFT JOIN tax_codes t ON t.id=l.tax_code_id AND t.company_id=? WHERE l.invoice_id=? ORDER BY l.id');
    $q->execute([$company,$invoice]);$out=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $net=creditCents($l['net_amount']);$tax=creditCents($l['vat_amount']);$gross=creditCents($l['gross_amount']);
        $allowed=$allowStandard?['M_CASH24','M_CASH13','M_CASH9','M_EE24','M_EE13','M_EE9']:['M_CASH24','M_CASH13','M_CASH9'];
        if(!in_array($l['code'],$allowed,true) || (float)$l['rate']!==(float)$l['vat_rate']
            || $net+$tax!==$gross || abs((int)round($net*(float)$l['rate']/100)-$tax)>1) throw new InvalidArgumentException('Kassapõhise müügirea summa, määr või maksukood on vigane.');
        $id=(int)$l['id']; $out[$id]=['id'=>$id,'net'=>$net,'tax'=>$tax,'gross'=>$gross,'capacity'=>$net,
            'rate'=>number_format((float)$l['rate'],2,'.',''),'valid_from'=>$l['valid_from'],'valid_to'=>$l['valid_to']];
    }
    return $out;
}
