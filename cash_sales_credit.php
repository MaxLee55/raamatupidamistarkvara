<?php

/** Validate the cash sale and its posted receipts before a credit is accepted. */
function assertCashSaleCreditBasis(PDO $pdo, int $company, int $invoiceId, string $creditDate): void
{
    $q=$pdo->prepare("SELECT i.invoice_date FROM sales_invoices i
        JOIN journal_entries j ON j.id=i.journal_entry_id AND j.company_id=i.company_id AND j.status='posted'
        JOIN contacts c ON c.id=i.contact_id AND c.company_id=i.company_id
        WHERE i.id=? AND i.company_id=? AND i.status='posted' AND c.country_code='EE'");
    $q->execute([$invoiceId,$company]); $date=$q->fetchColumn();
    if (!$date || $creditDate<$date) throw new RuntimeException('Kassapõhise kreediti algne Eesti müügiarve peab olema kinnitatud.');
    $q=$pdo->prepare('SELECT l.*,t.code,t.rate,t.tax_category,t.valid_from,t.valid_to FROM sales_invoice_lines l
        LEFT JOIN tax_codes t ON t.id=l.tax_code_id AND t.company_id=? WHERE l.invoice_id=?');
    $q->execute([$company,$invoiceId]); $lines=$q->fetchAll(PDO::FETCH_ASSOC); $gross=0;
    foreach($lines as $line) {
        $net=creditCents((string)$line['net_amount']); $vat=creditCents((string)$line['vat_amount']);
        $g=creditCents((string)$line['gross_amount']);
        $rate=(float)$line['vat_rate'];
        if (!in_array($line['code'],['M_CASH24','M_CASH13','M_CASH9'],true) || !in_array($line['tax_category'],['standard','reduced'],true)
            || (float)$line['rate']!==$rate || !in_array($rate,[24.0,13.0,9.0],true)
            || $net<0 || $net+$vat!==$g || abs((int)round($net*$rate/100)-$vat)>1
            || ($line['valid_from']!==null && $date<$line['valid_from']) || ($line['valid_to']!==null && $date>$line['valid_to'])) {
            throw new RuntimeException('Kassapõhise kreediti algse Eesti arve summad, määr või maksukood vajavad kontrolli.');
        }
        $gross+=$g;
    }
    $q=$pdo->prepare("SELECT p.payment_date,p.amount FROM invoice_payments p
        JOIN journal_entries j ON j.id=p.journal_entry_id AND j.company_id=? AND j.status='posted'
        WHERE p.invoice_id=? AND p.payment_date<=? ORDER BY p.payment_date,p.id");
    $q->execute([$company,$invoiceId,$creditDate]); $paid=0;
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $payment) {
        $amount=creditCents((string)$payment['amount']);
        if($amount<=0 || $amount>$gross-$paid) {
            throw new RuntimeException('Kassapõhise kreediti algse arve laekumised vajavad eraldi korrigeerimist.');
        }
        $paid+=$amount;
    }
    if(!$lines || $gross<=0) {
        throw new RuntimeException('Kassapõhise müügi algse arve summa on vigane.');
    }
}
