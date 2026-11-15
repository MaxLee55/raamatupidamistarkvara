<?php

/** Reconcile cash receipts/payments with the invoice amount remaining after credits.
 * Credits first remove the unrecognised balance; only excess reduces recognised tax.
 * Refunds of that excess do not create a second tax correction.
 */
function cashVatEvents(int $invoiceId, int $gross, int $capacity, string $received, array $payments, array $credits, array $refunds=[]): array
{
    if($gross<=0 || $capacity<=0 || $capacity>$gross) throw new InvalidArgumentException('Kassapõhise arve alus on vigane.');
    $events=[];
    foreach($payments as $i=>$p) $events[]=['date'=>$p['payment_date'],'kind'=>0,'order'=>$i,'id'=>$invoiceId,'gross'=>creditCents((string)$p['amount'])];
    foreach($credits as $c) $events[]=['date'=>$c['date'],'kind'=>1,'order'=>$c['id'],'id'=>$c['id'],'gross'=>$c['gross'],'capacity'=>$c['capacity']];
    foreach($refunds as $r) $events[]=['date'=>$r['refund_date'],'kind'=>2,'order'=>$r['id'],'id'=>$invoiceId,'gross'=>(int)$r['amount_cents']];
    usort($events,static fn($a,$b)=>[$a['date'],$a['kind'],$a['order']]<=>[$b['date'],$b['kind'],$b['order']]);
    $paid=$returned=$recognised=$recognisedGross=0; $remaining=$gross; $limit=$capacity; $result=[];
    foreach($events as $event) {
        $amount=$event['gross'];
        if($event['date']<$received || $amount<=0) throw new InvalidArgumentException('Kassapõhise arve ettemakse või sündmuse summa vajab eraldi kontrolli.');
        if($event['kind']===0) {
            if($amount>$gross-$paid) throw new InvalidArgumentException('Arve maksed ületavad algse arve summat.');
            $paid+=$amount;
        } elseif($event['kind']===1) {
            if($amount>$remaining || $event['capacity']<0 || $event['capacity']>$limit) throw new InvalidArgumentException('Kassapõhise kreediti summa ületab arve jääki.');
            $remaining-=$amount; $limit-=$event['capacity'];
            if($limit>$remaining) throw new InvalidArgumentException('Kassapõhise kreediti ümardus ja allesjäänud mahaarvamine ei klapi arve jäägiga.');
        } else {
            if($amount>max(0,$paid-$returned-$remaining)) throw new InvalidArgumentException('Kassapõhise arve tagasimakse ületab krediteeritud enammakset.');
            $returned+=$amount;
        }
        $netPaid=$paid-$returned;
        $target=$netPaid>=$remaining?$limit:min($limit,(int)round($netPaid/$gross*$capacity));
        $targetGross=min($netPaid,$remaining);
        // Settling a reduced invoice can release a final input-VAT rounding cent.
        // Attribute a positive remainder to the source invoice, never to a negative credit.
        $eventInvoice=$event['kind']===1 && $target>$recognised ? $invoiceId : $event['id'];
        $result[]=['invoice_id'=>$eventInvoice,'date'=>$event['date'],'amount'=>$target-$recognised,
            'gross'=>$targetGross-$recognisedGross,'credit'=>$event['kind']===1,'settled'=>$netPaid>=$remaining];
        $recognised=$target; $recognisedGross=$targetGross;
    }
    return $result;
}

function cashVatRefunds(PDO $pdo,int $company,string $kind,int $original,string $end): array
{
    $q=$pdo->prepare("SELECT r.id,r.credit_invoice_id,r.refund_date,r.amount_cents FROM credit_note_refunds r
        JOIN journal_entries j ON j.id=r.journal_entry_id AND j.company_id=r.company_id AND j.status='posted'
        WHERE r.company_id=? AND r.invoice_kind=? AND r.original_invoice_id=? AND r.status='posted' AND r.refund_date<=?
        ORDER BY r.refund_date,r.id");
    $q->execute([$company,$kind,$original,$end]); return $q->fetchAll(PDO::FETCH_ASSOC);
}
