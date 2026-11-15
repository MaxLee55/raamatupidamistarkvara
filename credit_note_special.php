<?php
/** Tax treatment is copied from the original invoice, never chosen on a credit. */
function creditValidateTaxLine(array $line): void
{
    $category=$line['tax_category']??'';
    $rate=(float)$line['vat_rate'];
    $spec=reverseVatSpec((string)($line['tax_code']??''));
    if($spec) {
        if($category!=='reverse_charge' || $rate!==0.0 || creditCents((string)$line['vat_amount'])!==0) throw new RuntimeException('Algse pöördmaksurea maksukood ja summad ei klapi.');
        return;
    }
    if(in_array($category,['standard','reduced'],true) && in_array($rate,[24.0,22.0,20.0,13.0,9.0,5.0],true)) return;
    if(in_array($category,['zero_rated','exempt'],true) && $rate===0.0 && creditCents((string)$line['vat_amount'])===0) return;
    throw new RuntimeException('Algse arverea maksuliik ei ole toetatud või on puudulik. Täpsusta algse tehingu maksuliik.');
}

function creditReverseAmounts(PDO $pdo,int $companyId,string $kind): array
{
    $stmt=$pdo->prepare('SELECT credit_line_id,reverse_cents FROM credit_note_line_links WHERE company_id=? AND invoice_kind=?');
    $stmt->execute([$companyId,$kind]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

function creditDisplayReverseVat(int $net,int $rate): int
{
    return ($net<0?-1:1)*euReverseVat(abs($net),$rate);
}
