<?php

function calculateKmdAdjustment(string $kind, string $period, int $inputVat, int $referenceDeduction, string $eligiblePercent, ?int $remainingYears = null): int
{
    $divisors = ['annual'=>1,'first_use'=>1,'asset_year'=>5,'property_year'=>10,
        'asset_sale'=>5,'property_sale_taxed'=>10,'property_sale_exempt'=>10];
    if (!isset($divisors[$kind])) throw new InvalidArgumentException('Vali ümberarvestuse liik.');
    $sale = in_array($kind,['asset_sale','property_sale_taxed','property_sale_exempt'],true);
    if (!$sale && $kind !== 'first_use' && substr($period,5,2) !== '12') {
        throw new InvalidArgumentException('Aastane ümberarvestus arvutatakse detsembri perioodil.');
    }
    if ($kind === 'first_use' && $period < '2025-01') {
        throw new InvalidArgumentException('Esmasel kasutuselevõtul täies ulatuses arvutamine kehtib alates 2025. aastast.');
    }
    if ($sale) {
        if ($remainingYears === null || $remainingYears < 0 || $remainingYears > $divisors[$kind]) {
            throw new InvalidArgumentException('Sisesta järelejäänud aastate arv koos müügiaastaga.');
        }
        $eligiblePercent = $kind === 'property_sale_exempt' ? '0' : '100';
    }
    $percent = str_replace(',','.',trim($eligiblePercent));
    if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D',$percent) || (float)$percent > 100
        || $inputVat <= 0 || $referenceDeduction < 0 || $referenceDeduction > $inputVat) {
        throw new InvalidArgumentException('Kontrolli sisendkäibemaksu, võrdlussummat ja osatähtsust vahemikus 0–100%.');
    }
    $percentUnits = (int)round((float)$percent * 100);
    $numerator = $inputVat * $percentUnits - $referenceDeduction * 10000;
    if ($sale) $numerator *= $remainingYears;
    $denominator = 10000 * $divisors[$kind];
    $difference = intdiv(abs($numerator) + intdiv($denominator,2),$denominator);
    return $numerator > 0 ? -$difference : $difference;
}

function calculateKmdCarAcquisitionReduction(string $acquired, string $privateUse, int $inputVat, string $eligiblePercent): array
{
    $start = DateTimeImmutable::createFromFormat('!Y-m-d',$acquired);
    $change = DateTimeImmutable::createFromFormat('!Y-m-d',$privateUse);
    if (!$start || !$change || $start->format('Y-m-d') !== $acquired || $change->format('Y-m-d') !== $privateUse
        || $acquired < '2018-01-01' || $change < $start || $change >= $start->modify('+2 years')) {
        throw new InvalidArgumentException('See arvutus sobib alates 2018. aastast soetatud autole, mille erakasutus algas enne kahe aasta täitumist.');
    }
    $percent = str_replace(',','.',trim($eligiblePercent));
    if ($inputVat <= 0 || !preg_match('/^\d{1,2}(?:\.\d{1,2})?$/D',$percent) || (float)$percent > 50) {
        throw new InvalidArgumentException('Sisesta positiivne soetamise sisendkäibemaks ja lubatud osatähtsus kuni 50%.');
    }
    $retained = intdiv($inputVat * (int)round((float)$percent * 100) + 5000,10000);
    return ['period'=>substr($acquired,0,7),'reduction'=>$inputVat-$retained,'retained'=>$retained];
}

/** Only suggest the full VAT when the card, the invoice and its single asset line match uniquely. */
function loadKmdAssetCalculationSource(PDO $pdo, int $company, int $assetId): ?array
{
    $query = $pdo->prepare('SELECT * FROM fixed_assets WHERE company_id=? AND id=?');
    $query->execute([$company,$assetId]);
    $asset = $query->fetch(PDO::FETCH_ASSOC);
    if (!$asset) return null;

    $result = ['asset'=>$asset,'invoice'=>null,'input_vat'=>null,'confirmed_vat'=>null];
    if (empty($asset['source_document']) || !empty($asset['legacy_opening_balance'])) return $result;
    $query = $pdo->prepare("SELECT COUNT(*) FROM fixed_assets WHERE company_id=? AND source_document=? AND asset_account_id=?");
    $query->execute([$company,$asset['source_document'],$asset['asset_account_id']]);
    if ((int)$query->fetchColumn() !== 1) return $result;

    $query = $pdo->prepare("SELECT id,supplier_invoice_number,invoice_date FROM purchase_invoices
        WHERE company_id=? AND supplier_invoice_number=? AND status='posted' AND journal_entry_id IS NOT NULL LIMIT 2");
    $query->execute([$company,$asset['source_document']]);
    $invoices = $query->fetchAll(PDO::FETCH_ASSOC);
    if (count($invoices) !== 1) return $result;
    $invoice = $invoices[0];
    $result['invoice'] = $invoice;
    $query = $pdo->prepare('SELECT account_id,net_amount,vat_amount FROM purchase_invoice_lines WHERE invoice_id=? LIMIT 2');
    $query->execute([$invoice['id']]);
    $lines = $query->fetchAll(PDO::FETCH_ASSOC);
    if (count($lines) !== 1 || (int)$lines[0]['account_id'] !== (int)$asset['asset_account_id']) return $result;

    $date = $invoice['invoice_date'];
    $loaded = loadInfRows($pdo,$company,$date,$date,'purchase',[(int)$invoice['id']]);
    if ($loaded['errors'] || count($loaded['invoices']) !== 1) return $result;
    $source = applyInfConfirmations($pdo,$company,substr($date,0,7),$loaded['invoices'])[0];
    if (!$source['deduction_confirmed'] || !in_array($source['vat_purpose'],['fixed_asset','fixed_car_full','fixed_car_partial'],true)
        || $source['reverse_line_count'] || $source['credit_link'] || $source['vat'] <= 0 || $source['net'] <= 0) return $result;
    foreach (loadCreditLinks($pdo,$company,'purchase') as $link) {
        if ((int)$link['original_invoice_id'] === (int)$invoice['id'] && $link['credit_status'] === 'posted') return $result;
    }
    $cost = creditCents((string)$asset['acquisition_cost']);
    if (!in_array($cost,[$source['net'],$source['gross']-$source['deductible_cents']],true)) return $result;
    $result['input_vat'] = $source['vat'];
    $result['confirmed_vat'] = $source['deductible_cents'];
    return $result;
}
