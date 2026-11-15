<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/tax_code_lib.php';
ensureTaxCodeSchema($pdo);
require_once __DIR__ . '/kmd_inf_confirmation.php';
require_once __DIR__ . '/cash_sales_vat.php';
require_once __DIR__ . '/kmd_manual_adjustments.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function amountCents(string $amount): int
{
    $amount = trim($amount);
    if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $parts)) {
        throw new RuntimeException('Arvel on vigane rahasumma: ' . $amount);
    }
    $cents = ((int) $parts[2] * 100) + (int) str_pad($parts[3] ?? '', 2, '0');
    return ($parts[1] ?? '') === '-' ? -$cents : $cents;
}

function formatAmount(int $cents): string
{
    $sign = $cents < 0 ? '-' : '';
    $absolute = abs($cents);
    return $sign . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
}

function euros(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

function addXmlElement(
    DOMDocument $xml,
    DOMElement $parent,
    string $qualifiedName,
    string $namespace,
    string $value,
    array $attributes = []
): DOMElement {
    $element = $xml->createElementNS($namespace, $qualifiedName);
    $parent->appendChild($element);
    foreach ($attributes as $name => $attributeValue) {
        $element->setAttribute($name, (string) $attributeValue);
    }
    $element->appendChild($xml->createTextNode($value));
    return $element;
}

function failExport(string $message): void
{
    http_response_code(422);
    echo '<!doctype html><html lang="et"><meta charset="utf-8"><title>KMD faili ei koostatud</title>'
        . '<style>body{font:16px Arial,sans-serif;background:#f4f7fb;color:#263746;padding:30px}'
        . 'main{max-width:760px;margin:30px auto;background:#fff;padding:24px;border:1px solid #dce3ec;border-radius:12px}'
        . 'p{background:#fff0f0;color:#922;padding:14px;border-radius:8px}a{display:inline-block;padding:10px 14px;background:#1769aa;color:#fff;text-decoration:none;border-radius:7px}.confirmation-link{display:inline-block;margin-left:8px;color:inherit;font-weight:600}
</style>'
        . '<main><h1>KMD faili ei koostatud</h1><p>' . h($message) . '</p><a href="kmd_export.php">Tagasi KMD ettevalmistusse</a></main></html>';
    exit;
}

function resolveParty(array $row, int $periodNetCents, bool $purchase = false): array
{
    $categoryValue = $row['kmd_party_category'] ?? null;
    $categoryValue = $categoryValue === null ? null : (int) $categoryValue;
    $idType = strtoupper(trim((string) ($row['kmd_identifier_type'] ?? '')));
    $registryCode = preg_replace('/\s+/', '', (string) ($row['registry_code'] ?? ''));
    $vatNumber = preg_replace('/\s+/', '', (string) ($row['vat_number'] ?? ''));
    $country = strtoupper(trim((string) ($row['country_code'] ?? 'EE')));
    $name = trim((string) ($row['contact_name'] ?? ''));

    if ($purchase && $country !== 'EE' && ($estoniaVat = foreignPurchaseEstonianVat($row)) !== null) {
        if ($idType === 'MRR_KOOD' && preg_match('/^[A-Za-z0-9-]{5,30}$/', $registryCode)) {
            return ['category'=>'100', 'code'=>$registryCode, 'description'=>'MRR_KOOD'];
        }
        return ['category'=>'100', 'code'=>$estoniaVat, 'description'=>'KMKR_NUMBER'];
    }
    if ($country !== '' && $country !== 'EE') {
        throw new RuntimeException('„' . $name . '” on välisriigi kontakt. Uus eksport vajab selle tehingu jaoks eraldi KMD liigitust; seda ei liigitata automaatselt Eesti siseriiklikuks tehinguks.');
    }
    if (preg_match('/^[A-Z]{2}/', $vatNumber) && substr(strtoupper($vatNumber), 0, 2) !== 'EE') {
        throw new RuntimeException('„' . $name . '” käibemaksunumber viitab välisriigile. Lisa tehingule õige EL-i või ekspordi KMD liik enne eksporti.');
    }

    if ($categoryValue === 1 || $categoryValue === 100) {
        $category = '100';
    } elseif ($categoryValue === 2 || $categoryValue === 200) {
        $category = '200';
    } elseif ($categoryValue === 3 || $categoryValue === 103) {
        $category = '103';
    } elseif (preg_match('/^\d{8}$/', $registryCode)) {
        $category = '100';
    } elseif (preg_match('/^\d{11}$/', $registryCode) || in_array($idType, ['ISIKUKOOD', 'MRR_KOOD'], true)
        || preg_match('/^(kassamüük|eraklient|eraisik)(\b|[-\s])/iu', $name)) {
        $category = '200';
    } elseif ($periodNetCents < 100000) {
        $category = '103';
    } else {
        throw new RuntimeException('„' . $name . '” kontaktile on perioodis vähemalt 1 000 € tehinguid, kuid kontaktikaardil puudub KMD osapoole liigitus või 8-kohaline registrikood. Täienda kontakti enne eksporti.');
    }

    if ($category === '103' && $periodNetCents >= 100000) {
        throw new RuntimeException('„' . $name . '” on märgitud alla 1 000 € tehingupartneriks, kuid perioodi maksustatav kogusumma on vähemalt 1 000 €. Kontrolli kontakti KMD liigitust.');
    }

    if ($category !== '100') {
        return ['category' => $category, 'code' => null, 'description' => null];
    }

    if ($idType === 'MRR_KOOD') {
        $code = $registryCode;
        $description = 'MRR_KOOD';
    } elseif ($idType === 'ISIKUKOOD') {
        $code = $registryCode;
        $description = 'ISIKUKOOD';
    } elseif (preg_match('/^\d{8}$/', $registryCode)) {
        $code = $registryCode;
        $description = 'ARIREGISTRIKOOD';
    } elseif ($idType === 'KMKR_NUMBER') {
        $code = $vatNumber;
        $description = 'KMKR_NUMBER';
    } else {
        $code = $registryCode;
        $description = 'ARIREGISTRIKOOD';
    }

    if ($code === '' || !preg_match('/^[A-Za-z0-9-]{5,30}$/', $code)) {
        throw new RuntimeException('„' . $name . '” on märgitud registrikoodiga osapooleks, kuid sobiv tunnus puudub. Kontrolli kontakti registrikoodi ja KMD tunnuse tüüpi.');
    }

    return ['category' => $category, 'code' => $code, 'description' => $description];
}

$companyId = (int) $_SESSION['company_id'];
$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT);
$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT);
$currentYear = (int) date('Y');
$year = $year && $year >= 2000 && $year <= 2100 ? $year : $currentYear;
$month = $month && $month >= 1 && $month <= 12 ? $month : (int) date('n');
$period = sprintf('%04d-%02d', $year, $month);
$startDate = $period . '-01';
$endDate = date('Y-m-t', strtotime($startDate));
$download = ($_GET['download'] ?? '') === 'xml';

$companyStmt = $pdo->prepare('SELECT name, registry_code FROM companies WHERE id = ?');
$companyStmt->execute([$companyId]);
$company = $companyStmt->fetch(PDO::FETCH_ASSOC);

$errors = [];
$comparison = $period < '2027-04';
$warnings = [
    'Uus andmepõhine KMD fail on mõeldud MTA uuele esitamisele alates 2027. aasta aprillist. Varasema perioodi tegelikuks esitamiseks kasuta vana KMD6 vormingut.',
    'Ostuarvetelt eksporditakse perioodi kinnitatud mahaarvatav summa. Eesti sõiduautode ostud ja jooksvad kulud liigitatakse kinnitatud kasutusviisi järgi.',
];

if ($comparison) {
    $warnings[] = 'Valitud perioodi XBRL fail on võrdlusfail. See säilitab tegeliku perioodi ja kuupäevad ning sobib struktuuri ja summade võrdlemiseks. Selle perioodi päris deklaratsiooni esitamiseks kasuta vana KMD6 vormingut.';
}

if (!$company || !preg_match('/^\d{8}$/', trim((string) ($company['registry_code'] ?? '')))) {
    $errors[] = 'Ettevõtte seadetes peab olema 8-kohaline registrikood.';
}

$contactColumns = $pdo->query('SHOW COLUMNS FROM contacts')->fetchAll(PDO::FETCH_COLUMN);
$optionalContactFields = [
    'kmd_identifier_type' => in_array('kmd_identifier_type', $contactColumns, true) ? 'c.kmd_identifier_type' : 'NULL',
    'kmd_party_category' => in_array('kmd_party_category', $contactColumns, true) ? 'c.kmd_party_category' : 'NULL',
    'country_code' => in_array('country_code', $contactColumns, true) ? 'c.country_code' : "'EE'",
    'estonia_vat_number' => in_array('estonia_vat_number', $contactColumns, true) ? 'c.estonia_vat_number' : 'NULL',
];
$accountColumns = $pdo->query('SHOW COLUMNS FROM accounts')->fetchAll(PDO::FETCH_COLUMN);
$accountKmdTypeExpression = in_array('kmd_type_code', $accountColumns, true) ? 'a.kmd_type_code' : 'NULL';

$salesStmt = $pdo->prepare(
    'SELECT si.id, si.invoice_number AS document_number, si.invoice_date AS document_date,
            si.contact_id, c.name AS contact_name, c.registry_code, c.vat_number,
            ' . $optionalContactFields['kmd_identifier_type'] . ' AS kmd_identifier_type,
            ' . $optionalContactFields['kmd_party_category'] . ' AS kmd_party_category,
            ' . $optionalContactFields['country_code'] . ' AS country_code,
            sil.id AS line_id, sil.tax_code_id, sil.vat_rate, sil.net_amount, sil.vat_amount, sil.gross_amount,
            tc.code AS tax_code, tc.rate AS tax_code_rate, tc.tax_category,
            tc.valid_from AS tax_valid_from, tc.valid_to AS tax_valid_to
     FROM sales_invoices si
     JOIN contacts c ON c.id = si.contact_id AND c.company_id = si.company_id
     JOIN sales_invoice_lines sil ON sil.invoice_id = si.id
     LEFT JOIN tax_codes tc ON tc.id = sil.tax_code_id AND tc.company_id = si.company_id
     WHERE si.company_id = :company_id AND si.status = \'posted\'
       AND si.journal_entry_id IS NOT NULL
       AND si.invoice_date BETWEEN :start_date AND :end_date
     ORDER BY si.invoice_date, si.id, sil.id'
);
$salesStmt->execute(['company_id' => $companyId, 'start_date' => $startDate, 'end_date' => $endDate]);
$salesRows = $salesStmt->fetchAll(PDO::FETCH_ASSOC);
$cashSales = loadCashSales($pdo,$companyId,$startDate,$endDate);
$errors = array_merge($errors, $cashSales['errors']);

$deductions = loadPurchaseDeductions($pdo, $companyId, $startDate, $endDate);
$scheduledInvoiceIds = array_map('intval', array_keys($deductions['planned_invoice_ids']));
$scheduledIdSql = implode(',', $scheduledInvoiceIds ?: [0]);
$purchaseStmt = $pdo->prepare(
    'SELECT pi.id, pi.supplier_invoice_number AS document_number, pi.invoice_date AS document_date,
            pi.contact_id, c.name AS contact_name, c.registry_code, c.vat_number,
            ' . $optionalContactFields['estonia_vat_number'] . ' AS estonia_vat_number,
            ' . $optionalContactFields['kmd_identifier_type'] . ' AS kmd_identifier_type,
            ' . $optionalContactFields['kmd_party_category'] . ' AS kmd_party_category,
            ' . $optionalContactFields['country_code'] . ' AS country_code,
            pil.id AS line_id, pil.tax_code_id, pil.vat_rate, pil.net_amount, pil.vat_amount, pil.gross_amount,
            tc.code AS tax_code, tc.rate AS tax_code_rate, tc.tax_category,
            tc.valid_from AS tax_valid_from, tc.valid_to AS tax_valid_to,
            a.code AS posting_account_code, a.account_type AS posting_account_type,
            ' . $accountKmdTypeExpression . ' AS kmd_type_code
     FROM purchase_invoices pi
     JOIN contacts c ON c.id = pi.contact_id AND c.company_id = pi.company_id
     JOIN purchase_invoice_lines pil ON pil.invoice_id = pi.id
     LEFT JOIN tax_codes tc ON tc.id = pil.tax_code_id AND tc.company_id = pi.company_id
     JOIN accounts a ON a.id = pil.account_id AND a.company_id = pi.company_id
     WHERE pi.company_id = :company_id AND pi.status = \'posted\'
       AND pi.journal_entry_id IS NOT NULL
       AND (pi.invoice_date BETWEEN :start_date AND :end_date OR pi.id IN (' . $scheduledIdSql . '))
     ORDER BY pi.invoice_date, pi.id, pil.id'
);
$purchaseStmt->execute(['company_id' => $companyId, 'start_date' => $startDate, 'end_date' => $endDate]);
$purchaseRows = $purchaseStmt->fetchAll(PDO::FETCH_ASSOC);

$salesInvoices = [];
$salesNetByInvoice = [];
$salesWithZeroLines = [];
$purchaseInvoices = [];
$partnerSalesTotals = [];
$partnerPurchaseTotals = [];
$salesNetTotal = 0;
$purchaseVatTotal = 0;
$salesCredits = loadCreditLinks($pdo, $companyId, 'sales');
$purchaseCredits = loadCreditLinks($pdo, $companyId, 'purchase');

foreach ([['rows' => $salesRows, 'target' => &$salesInvoices, 'totals' => &$partnerSalesTotals, 'type' => 'müügiarve'],
          ['rows' => $purchaseRows, 'target' => &$purchaseInvoices, 'totals' => &$partnerPurchaseTotals, 'type' => 'ostuarve']] as &$collection) {
    foreach ($collection['rows'] as $row) {
        $invoiceId = (int) $row['id'];
        $isPurchase = $collection['type'] === 'ostuarve';
        // Cash sales are added from posted receipts, including invoices from earlier months.
        if (!$isPurchase && isset($cashSales['ids'][$invoiceId])) continue;
        $scheduled = $isPurchase && isset($deductions['planned_invoice_ids'][$invoiceId]);
        $deduction = $scheduled ? ($deductions['invoices'][$invoiceId] ?? null) : null;
        if ($scheduled && !$deduction) continue;
        $contactId = (int) $row['contact_id'];
        try {
            $net = amountCents((string) $row['net_amount']);
            $vat = amountCents((string) $row['vat_amount']);
            $gross = amountCents((string) $row['gross_amount']);
            $rate = number_format((float) $row['vat_rate'], 2, '.', '');
        } catch (Throwable $exception) {
            $errors[] = ucfirst($collection['type']) . ' ' . $row['document_number'] . ': ' . $exception->getMessage();
            continue;
        }

        if ($net + $vat !== $gross) {
            $errors[] = ucfirst($collection['type']) . ' ' . $row['document_number'] . ': neto, käibemaks ja kogusumma ei klapi.';
            continue;
        }
        if($isPurchase && !empty($deductions['invoices'][$invoiceId]['cash_supplier']) && $vat===0 && (float)$rate===0.0
            && ($row['tax_category']??'')!=='reverse_charge') continue;
        $credit = ($isPurchase ? $purchaseCredits : $salesCredits)[$invoiceId] ?? null;
        $negative = $net < 0 || $vat < 0 || $gross < 0;
        if (($negative || $credit) && (!$credit || $credit['original_status'] !== 'posted'
            || (int) $credit['original_contact_id'] !== $contactId || $credit['original_date'] > $row['document_date']
            || (int) $credit['percentage_bp'] < 1 || (int) $credit['percentage_bp'] > 10000
            || $net > 0 || $vat > 0 || $gross > 0)) {
            $errors[] = 'Arve ' . $row['document_number'] . ': kreeditarve vajab kehtivat algse arve seost ja negatiivseid summasid.';
            continue;
        }
        if (!$isPurchase) {
            $salesNetByInvoice[$invoiceId] = ($salesNetByInvoice[$invoiceId] ?? 0) + $net;
            if (in_array($row['tax_category'], ['zero_rated', 'exempt'], true)) {
                $salesWithZeroLines[$invoiceId] = true;
                continue;
            }
        }
        if ($row['tax_code_id'] !== null) {
            if ($row['tax_code'] === null) {
                $errors[] = ucfirst($collection['type']) . ' ' . $row['document_number'] . ': arvereal valitud maksukood puudub või ei kuulu ettevõttele.';
                continue;
            }
            if (number_format((float) $row['tax_code_rate'], 2, '.', '') !== $rate) {
                $errors[] = ucfirst($collection['type']) . ' ' . $row['document_number'] . ': maksukoodi määr ja arvereale salvestatud määr ei klapi.';
                continue;
            }
            $taxDate = $credit['original_date'] ?? $row['document_date'];
            if (($row['tax_valid_from'] !== null && $taxDate < $row['tax_valid_from'])
                || ($row['tax_valid_to'] !== null && $taxDate > $row['tax_valid_to'])) {
                $errors[] = ucfirst($collection['type']) . ' ' . $row['document_number'] . ': maksukood ei kehti arve kuupäeval.';
                continue;
            }
            if ($isPurchase && $row['tax_code'] === 'O_IMPORT0' && $row['tax_category'] === 'zero_rated'
                && $vat === 0 && (float) $rate === 0.0 && !$credit) continue;
            if ($isPurchase && $row['tax_code'] === 'O_EXEMPT' && $row['tax_category'] === 'exempt'
                && $vat === 0 && (float) $rate === 0.0) continue;
            if ($isPurchase && $row['tax_code'] === 'O_OUTSIDE_EE' && $row['tax_category'] === 'zero_rated'
                && $vat === 0 && (float) $rate === 0.0) continue;
            $reverse = $row['tax_category'] === 'reverse_charge' ? reverseVatSpec($row['tax_code']) : null;
            if ($isPurchase && $reverse && $reverse['side'] === 'purchase') {
                if (!isset($deductions['invoices'][$invoiceId])) {
                    $errors[] = 'Ostuarve ' . $row['document_number'] . ': pöördmaksu aruandeandmed puuduvad.';
                }
                continue;
            }
            $entryType = 'M_101';
            if (!$isPurchase && $reverse && $reverse['side'] === 'sales') {
                $partyError = reversePartyError($reverse, $row['country_code'] ?? '', $row['vat_number'] ?? '')
                    ?? reverseCompanyError($pdo, $companyId, $reverse);
                if ($partyError !== null || $vat !== 0 || (float) $rate !== 0.0) {
                    $errors[] = 'Müügiarve ' . $row['document_number'] . ': ' . ($partyError ?? 'pöördmaksu arverea maks peab olema 0.');
                    continue;
                }
                $entryType = 'M_105';
                $rate = number_format($reverse['rate'], 2, '.', '');
            } elseif (!in_array($row['tax_category'], ['standard', 'reduced'], true)) {
                $errors[] = ucfirst($collection['type']) . ' ' . $row['document_number'] . ': maksukoodi KMD tehinguliik pole uues ekspordis veel toetatud.';
                continue;
            }
        } else {
            $errors[] = ucfirst($collection['type']) . ' ' . $row['document_number'] . ': arvereal puudub maksukood. Uue KMD tehinguliiki ei saa ainult käibemaksumäära järgi ohutult määrata.';
            continue;
        }
        if (!$reverse && abs((int) round($net * (float) $rate / 100) - $vat) > 1) {
            $errors[] = ucfirst($collection['type']) . ' ' . $row['document_number'] . ': käibemaks ei vasta salvestatud määrale.';
            continue;
        }
        $allowedRates = $credit ? [24.0, 22.0, 20.0, 13.0, 9.0, 5.0] : [24.0, 13.0, 9.0];
        if (!in_array((float) $rate, $allowedRates, true)) {
            $errors[] = ucfirst($collection['type']) . ' ' . $row['document_number'] . ' kasutab määra ' . rtrim(rtrim($rate, '0'), '.') . '%. Uus eksport toetab praegu ainult Eesti 24%, 13% ja 9% standard-/vähendatud määra.';
            continue;
        }
        if ($collection['type'] === 'ostuarve') {
            $confirmed = $deductions['invoices'][$invoiceId] ?? [];
            $lineAllocation = $confirmed['vat_line_allocations'][$row['line_id']] ?? null;
            $purpose = $lineAllocation['vat_purpose'] ?? $confirmed['vat_purpose'] ?? 'ordinary';
            $carCode = match ($purpose) {
                'fixed_car_full' => 'O_301',
                'fixed_car_partial' => 'O_304',
                'car_full' => 'O_307',
                'car_partial' => 'O_309',
                default => null,
            };
            $fixedAsset = in_array($purpose, ['fixed_asset', 'fixed_car_full', 'fixed_car_partial'], true);
            $baseCode = $fixedAsset ? 'O_201' : 'O_101';
            $periodDeduction = $lineAllocation['deductible_cents'] ?? $confirmed['deductible_cents'] ?? 0;
            $totalDeduction = $lineAllocation ? ($lineAllocation['period_plan_total'] ?? $periodDeduction) : ($confirmed['period_plan_total'] ?? $periodDeduction);
            $partial = abs($totalDeduction) < abs($lineAllocation ? $vat : ($confirmed['deduction_vat'] ?? $vat));
            if ($carCode !== null) {
                $purchaseCode = $carCode;
            } elseif ($fixedAsset) {
                $purchaseCode = $partial ? 'O_202' : 'O_201';
            } elseif (!empty($confirmed['cash_company_purchase'])) {
                $purchaseCode = $partial ? 'O_105' : 'O_104';
            } else {
                $purchaseCode = $partial ? 'O_103' : (abs($periodDeduction) < abs($totalDeduction) ? 'O_102' : 'O_101');
            }
            $postingCode = trim((string) ($row['posting_account_code'] ?? ''));
            $mappedKmdType = trim((string) ($row['kmd_type_code'] ?? ''));
            if (($carCode === null && !in_array($purpose, ['ordinary', 'fixed_asset'], true))
                || ($fixedAsset && $row['posting_account_type'] !== 'asset')
                || (!$fixedAsset && preg_match('/^15/', $postingCode))
                || ($mappedKmdType !== '' && !in_array($mappedKmdType, [$baseCode, $purchaseCode], true))) {
                $errors[] = 'Ostuarve ' . $row['document_number'] . ': kinnitatud ostuliik ja arverea konto KMD liigitus vajavad ühtlustamist või selle ostuliigi XBRL-tugi puudub.';
                continue;
            }
        }

        $invoice = &$collection['target'][$invoiceId];
        if ($invoice === null) {
            $invoice = [
                'document_number' => (string) $row['document_number'],
                'document_date' => (string) $row['document_date'],
                'contact' => $row,
                'net' => 0,
                'vat' => 0,
                'gross' => 0,
                'groups' => [],
                'original_date' => $credit['original_date'] ?? null,
            ];
        }
        $entryType = $isPurchase ? $purchaseCode : $entryType;
        $key = $entryType . ':' . $rate;
        $invoice['groups'][$key] ??= ['rate' => $rate, 'entry_type' => $entryType, 'net' => 0, 'vat' => 0];
        $invoice['groups'][$key]['net'] += $net;
        $invoice['groups'][$key]['vat'] += $isPurchase ? ($lineAllocation['deductible_cents'] ?? $vat) : $vat;
        $invoice['net'] += $net;
        $invoice['vat'] += $vat;
        $invoice['gross'] += $gross;
        unset($invoice);
        $collection['totals'][$contactId] = ($collection['totals'][$contactId] ?? 0) + $net;
    }
}
unset($collection);

foreach ($cashSales['invoices'] as $invoice) {
    if ($invoice['review']) {
        $errors[] = 'Müügiarve ' . $invoice['document_number'] . ': ' . implode(' ', $invoice['review']);
        continue;
    }
    $invoiceId = (int) $invoice['id'];
    $base = array_sum($invoice['taxable_by_rate']);
    if (!array_filter($invoice['taxable_by_rate'])) continue;
    $cashGroups=[];
    $cashType=!empty($invoice['cash_accrual'])?'M_101':'M_102';
    foreach($invoice['taxable_by_rate'] as $rate=>$rateBase) if($rateBase!==0) $cashGroups[$cashType.':'.$rate]=[
        'rate'=>$rate,'entry_type'=>$cashType,'net'=>$rateBase,'vat'=>$invoice['cash_vat_by_rate'][$rate]??$invoice['cash_vat']];
    $salesInvoices[$invoiceId] = [
        'document_number' => $invoice['document_number'], 'document_date' => $invoice['document_date'],
        'contact' => $invoice, 'net' => $base, 'vat' => $invoice['cash_vat'],
        'gross' => $invoice['cash_gross'], 'original_date' => $invoice['credit_link']['original_date'] ?? null,
        'groups' => $cashGroups,
    ];
    $salesNetByInvoice[$invoiceId] = $invoice['net'];
    $contactId = (int) $invoice['contact_id'];
    $partnerSalesTotals[$contactId] = ($partnerSalesTotals[$contactId] ?? 0) + $invoice['net'];
}

$zeroSalesData=loadZeroSales($pdo,$companyId,$startDate,$endDate);
$errors=array_merge($errors,$zeroSalesData['errors']);
$errors = array_merge($errors, $deductions['errors']);
if ($deductions['number_of_cars'] > 0 || $deductions['number_of_cars_partial'] > 0) {
    $warnings[] = 'Sõiduautode kuuarvu näed KMD aruandes. XBRL GL fail sisaldab sõiduautodega seotud tehinguid ja mahaarvatavaid summasid.';
}
$pendingDeductionError = null;
if ($deductions['pending']) {
    $pendingDeductionError = 'Ostuarvete mahaarvatavad summad on kinnitamata: ' . implode(', ', $deductions['pending']);
    $errors[] = $pendingDeductionError;
}
foreach ($purchaseInvoices as $invoiceId => &$purchaseInvoice) {
    if(!empty($deductions['invoices'][$invoiceId]['cash_supplier'])) {
        $fullNet=$deductions['invoices'][$invoiceId]['net'];
        $partnerPurchaseTotals[(int)$purchaseInvoice['contact']['contact_id']]+=$fullNet-$purchaseInvoice['net'];
        $purchaseInvoice['net']=$fullNet;
    }
    $savedDeduction = $deductions['invoices'][$invoiceId]['deductible_cents'] ?? 0;
    if (!empty($deductions['invoices'][$invoiceId]['mixed_reverse'])) {
        $confirmed = $deductions['invoices'][$invoiceId];
        $savedDeduction = 0;
        foreach ($confirmed['vat_line_allocations'] ?? [] as $lineId=>$line) {
            if (empty($confirmed['vat_input_lines'][$lineId]['reverse'])) $savedDeduction += $line['deductible_cents'];
        }
    }
    if ($savedDeduction === 0) {
        $partnerPurchaseTotals[(int)$purchaseInvoice['contact']['contact_id']] -= $purchaseInvoice['net'];
        unset($purchaseInvoices[$invoiceId]);
        continue;
    }
    $confirmed = $deductions['invoices'][$invoiceId];
    if ($confirmed['country_code'] !== 'EE' && $confirmed['net'] !== $purchaseInvoice['net']) {
        $partnerPurchaseTotals[(int)$purchaseInvoice['contact']['contact_id']] += $confirmed['net'] - $purchaseInvoice['net'];
        $purchaseInvoice['net'] = $confirmed['net'];
    }
    if (!empty($deductions['invoices'][$invoiceId]['vat_line_allocations'])) {
        $groupTotal = array_sum(array_column($purchaseInvoice['groups'],'vat'));
        if ($groupTotal !== $savedDeduction) {
            $errors[] = 'Ostuarve ' . $purchaseInvoice['document_number'] . ': reajaotuse summa ei klapi kinnitatud mahaarvamisega.';
        }
        $purchaseInvoice['groups'] = array_filter($purchaseInvoice['groups'],static fn(array $group): bool => $group['vat'] !== 0);
    } elseif (count($purchaseInvoice['groups']) === 1) {
        $key = array_key_first($purchaseInvoice['groups']);
        $purchaseInvoice['groups'][$key]['vat'] = $savedDeduction;
    } elseif ($savedDeduction !== $purchaseInvoice['vat']) {
        $errors[] = 'Ostuarve ' . $purchaseInvoice['document_number'] . ': osaline mahaarvamine vajab summade jaotust käibemaksumäärade kaupa.';
    }
    $purchaseInvoice['vat'] = $savedDeduction;
}
unset($purchaseInvoice);
$details = [];
foreach ($deductions['import_documents'] as $document) {
    $amount = (int) $document['deductible_cents'];
    if ($amount === 0) continue;
    $rates = array_values(array_filter([24, 13, 9], static fn(int $rate): bool =>
        (int) round((int) $document['taxable_cents'] * $rate / 100) === (int) $document['vat_cents']));
    if (count($rates) !== 1) {
        $errors[] = 'Tollidokument ' . $document['mrn'] . ': tolli maksubaas ja käibemaks ei anna üheselt üht maksumäära.';
        continue;
    }
    $details[] = [
        'entry_type' => $document['vat_purpose'] === 'fixed_asset' ? 'O_203' : 'O_106',
        'amount_cents' => $amount, 'vat_rate' => $rates[0] / 100, 'party' => null,
    ];
    $purchaseVatTotal += $amount;
}

foreach ($deductions['invoices'] as $invoice) {
    if (empty($invoice['reverse_line_count'])) continue;
    $spec = $invoice['reverse_spec'];
    $domestic = $spec['scope'] === 'domestic';
    if (!$domestic) {
        $creditDate = $invoice['credit_link']['original_date'] ?? null;
        $fields = ['party'=>null, 'original_date'=>$creditDate];
        if ($creditDate !== null) {
            $fields += ['document_number'=>$invoice['document_number'], 'document_date'=>$invoice['document_date'],
                'invoice_gross_cents'=>$invoice['net']];
        }
        foreach ($invoice['foreign_reverse_bases'] ?? [] as $code => $base) {
            if ($base === 0) continue;
            $lineSpec = reverseVatSpec($code);
            $baseCode = match (true) {
                !empty($lineSpec['exempt']) => 'S_106',
                $lineSpec['scope'] === 'non_eu' => 'S_104',
                $lineSpec['kind'] === 'goods' => 'S_101',
                default => 'S_102',
            };
            $details[] = $fields + ['entry_type'=>$baseCode, 'amount_cents'=>$base,
                'vat_rate'=>!empty($lineSpec['exempt']) ? null : $lineSpec['rate']/100];
        }
        $groups = [];
        $explicit = $invoice['vat_line_allocations'] ?? [];
        $inputs = $explicit ? $invoice['vat_input_lines'] : [['reverse_spec'=>$spec]];
        foreach ($inputs as $id => $line) {
            $lineSpec = $line['reverse_spec'] ?? null;
            if ($lineSpec === null) continue;
            $amount = $explicit ? $explicit[$id]['deductible_cents'] : ($invoice['deductible_cents'] ?? 0);
            if ($amount === 0) continue;
            $purpose = $explicit ? $explicit[$id]['vat_purpose'] : ($invoice['vat_purpose'] ?? 'ordinary');
            $type = foreignReverseDeductionCode($lineSpec, $purpose);
            if ($type === null) {
                $errors[] = 'Ostuarve '.$invoice['document_number'].': pöördmaksu mahaarvamine vajab sobivat ostuliiki.';
                continue;
            }
            $rate = $lineSpec['rate'];
            $groups[$type][$rate] = ($groups[$type][$rate] ?? 0) + $amount;
        }
        foreach ($groups as $type => $rates) {
            foreach ($rates as $rate => $amount) {
                $details[] = $fields + ['entry_type'=>$type, 'amount_cents'=>$amount, 'vat_rate'=>$rate/100];
                $purchaseVatTotal += $amount;
            }
        }
        continue;
    }
    [$baseCode, $inputCode] = ['S_105', 'O_405'];
    $amount = $invoice['deductible_cents'] ?? 0;
    $reverseTotalDeduction = null;
    $inputCapacity = $invoice['deduction_vat'];
    if (!empty($invoice['mixed_reverse'])) {
        $amount = 0;
        $inputCapacity = 0;
        $reverseTotalDeduction = 0;
        foreach ($invoice['vat_line_allocations'] ?? [] as $lineId=>$line) {
            if (!empty($invoice['vat_input_lines'][$lineId]['reverse'])) {
                $amount += $line['deductible_cents'];
                $reverseTotalDeduction += $line['period_plan_total'] ?? $line['deductible_cents'];
                $inputCapacity += $invoice['vat_input_lines'][$lineId]['vat'];
            }
        }
    }
    $totalDeduction = $reverseTotalDeduction ?? $invoice['period_plan_total'] ?? $amount;
    $purpose = $invoice['vat_purpose'] ?? 'ordinary';
    if ($purpose !== 'ordinary') {
        $inputCode = match ($purpose) {
            'fixed_asset' => abs($totalDeduction)<abs($inputCapacity) ? 'O_202' : 'O_201',
            'fixed_car_full' => 'O_301',
            'fixed_car_partial' => 'O_304',
            'car_full' => 'O_307',
            'car_partial' => 'O_309',
            default => null,
        };
    } elseif (abs($totalDeduction) < abs($inputCapacity)) {
        $inputCode = 'O_406';
    }
    $rates = !empty($invoice['mixed_reverse']) ? array_keys($invoice['reverse_rates']) : $invoice['rates'];
    if ($amount !== 0 && ($inputCode === null || count($rates) !== 1)) {
        $errors[] = 'Ostuarve ' . $invoice['document_number'] . ': pöördmaksu mahaarvamine vajab täpsemat ostuliigi või maksumäärade jaotust.';
        continue;
    }
    $creditDate = $invoice['credit_link']['original_date'] ?? null;
    $fields = ['party' => null, 'original_date' => $creditDate];
    try {
        $fields['party'] = resolveParty($invoice, $invoice['net']);
    } catch (RuntimeException $exception) {
        $errors[] = $exception->getMessage();
        continue;
    }
    $fields += [
        'document_number' => $invoice['document_number'],
        'document_date' => $invoice['document_date'],
        'invoice_gross_cents' => $invoice['net'],
    ];
    foreach ($invoice['reverse_base_by_rate'] as $rate => $base) {
        if ($base !== 0) $details[] = $fields + [
            'entry_type' => $baseCode, 'amount_cents' => $base, 'vat_rate' => (float) $rate / 100,
        ];
    }
    if ($amount !== 0) {
        $details[] = $fields + ['entry_type' => $inputCode, 'amount_cents' => $amount, 'vat_rate' => (float) reset($rates) / 100];
        $purchaseVatTotal += $amount;
    }
}
$zeroSalesDetails = [];
$exportedZeroSales = [];
foreach ($zeroSalesData['rows'] as $row) {
    $entryType = match ($row['kind']) {
        'eu_goods' => 'M_201',
        'eu_services' => 'M_202',
        'export' => 'M_208',
        'other_zero_goods' => 'M_210',
        'transport', 'non_eu_services', 'other_zero_services' => 'M_211',
        'exempt_rent', 'exempt_property', 'other_exempt' => 'M_301',
        default => null,
    };
    $number = $row['invoice_number'];
    if ($entryType === null) {
        $errors[] = 'Müügiarve ' . $number . ': 0% eriliik vajab täpsemat XBRL GL liigitust.';
        continue;
    }
    $euSale = in_array($entryType, ['M_201', 'M_202'], true);
    $buyer = $row['party'];
    if ($euSale && substr($buyer['vat_number'], 0, 2) !== ($buyer['country_code'] === 'GR' ? 'EL' : $buyer['country_code'])) {
        $errors[] = 'Müügiarve ' . $number . ': ostja riik ja KMKR numbri riigitunnus ei klapi.';
        continue;
    }
    $key = $row['invoice_id'] . ':' . $entryType;
    $zeroSalesDetails[$key] ??= [
        'entry_type' => $entryType,
        'amount_cents' => 0,
        'vat_rate' => $entryType === 'M_301' ? null : 0,
        'party' => $euSale ? ['category' => '100', 'code' => $buyer['vat_number'], 'description' => 'KMKR_NUMBER'] : null,
        'buyer_country' => $euSale ? $buyer['country_code'] : null,
    ];
    $zeroSalesDetails[$key]['amount_cents'] += $row['net'];
    if (isset($salesCredits[$row['invoice_id']])) {
        $credit = $salesCredits[$row['invoice_id']];
        $zeroSalesDetails[$key] += [
            'original_date' => $credit['original_date'],
            'document_number' => $number,
            'document_date' => $row['invoice_date'],
            'invoice_gross_cents' => $salesNetByInvoice[$row['invoice_id']] ?? null,
        ];
    }
    $exportedZeroSales[$row['invoice_id']] = true;
    if ($entryType !== 'M_301') {
        $salesNetTotal += $row['net'];
    }
}
$details = array_merge($details, array_values($zeroSalesDetails));
$salesInvoiceCount = count($salesInvoices + $exportedZeroSales);
$purchaseInvoiceCount = count($purchaseInvoices) + count(array_filter($deductions['invoices'], static fn(array $invoice): bool => !empty($invoice['reverse_line_count'])));

foreach ([['invoices' => $salesInvoices, 'totals' => $partnerSalesTotals, 'amount' => 'net', 'label' => 'müügiarve'],
          ['invoices' => $purchaseInvoices, 'totals' => $partnerPurchaseTotals, 'amount' => 'vat', 'label' => 'ostuarve']] as $source) {
    foreach ($source['invoices'] as $invoiceId => $invoice) {
        $documentNumber = $invoice['document_number'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoice['document_date'])) {
            $errors[] = ucfirst($source['label']) . ' ' . $documentNumber . ' kuupäev on vigane.';
            continue;
        }
        $contact = $invoice['contact'];
        $isSale = $source['amount'] === 'net';
        try {
            $party = resolveParty($contact, $source['totals'][(int) $contact['contact_id']] ?? 0, !$isSale);
        } catch (RuntimeException $exception) {
            $errors[] = $exception->getMessage();
            continue;
        }
        if (count($invoice['groups']) > 1 || ($isSale && isset($salesWithZeroLines[$invoiceId]))) {
            $party['category'] = '104';
        }
        foreach ($invoice['groups'] as $group) {
            $details[] = [
                'entry_type' => $group['entry_type'],
                'document_number' => $documentNumber,
                'document_date' => $invoice['document_date'],
                'original_date' => $invoice['original_date'],
                'amount_cents' => $group[$source['amount']],
                'invoice_gross_cents' => $isSale ? $salesNetByInvoice[$invoiceId] : $invoice['net'],
                'vat_rate' => (float) $group['rate'] / 100,
                'party' => $party,
            ];
        }
        if ($isSale) {
            $salesNetTotal += (int) $invoice['net'];
        } else {
            $purchaseVatTotal += (int) $invoice['vat'];
        }
    }
}

if (!$errors && $purchaseVatTotal !== $deductions['total']) {
    $errors[] = 'XBRL GL sisendkäibemaksu summa ei klapi perioodi kinnitatud mahaarvamistega.';
}
$errors = array_values(array_unique($errors));
ensureKmdManualAdjustments($pdo);
$manualAdjustments=loadKmdManualAdjustments($pdo,$companyId,$startDate,$endDate);
if($comparison && array_filter($manualAdjustments['rows'],static fn(array $row):bool=>$row['voided_at']===null)) {
    $errors[]='Võrdlusfaili jaoks puudub vana KMD käsitsi paranduste XBRL-maksumäärade jaotus. Nende summasid ei saa ohutult automaatselt teisendada.';
}
$errors=array_values(array_unique(array_merge($errors,$manualAdjustments['errors'])));
// O_601 has its own sign convention: positive reduces input VAT, negative increases it.
// Keep it separate from ordinary purchase deductions and their reconciliation above.
$details=array_merge($details,$manualAdjustments['details']);

if ($download && !$errors) {
    $registryCode = trim((string) $company['registry_code']);
    $today = date('Y-m-d');
    $createdAt = new DateTimeImmutable('now');
    $namespaces = [
        'xbrli' => 'http://www.xbrl.org/2003/instance',
        'xlink' => 'http://www.w3.org/1999/xlink',
        'xbrll' => 'http://www.xbrl.org/2003/linkbase',
        'xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
        'gl-cor' => 'http://www.xbrl.org/int/gl/cor/2015-03-25',
        'gl-ext' => 'https://xbrl.eesti.ee/gl/ext/2026-03-31',
        'gl-bus' => 'http://www.xbrl.org/int/gl/bus/2015-03-25',
        'iso639' => 'http://www.w3.org/2005/iso639',
        'iso4217' => 'http://www.xbrl.org/2003/iso4217',
    ];
    $xml = new DOMDocument('1.0', 'UTF-8');
    $xml->formatOutput = true;
    $root = $xml->createElementNS($namespaces['xbrli'], 'xbrli:xbrl');
    $xml->appendChild($root);
    foreach ($namespaces as $prefix => $uri) {
        if ($prefix !== 'xbrli') {
            $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:' . $prefix, $uri);
        }
    }
    $schemaUrl = 'https://xbrl.eesti.ee/gl/ext/2026-03-31/gl/plt/case-c-b-e/gl-plt-2026-03-31.xsd';
    $root->setAttributeNS($namespaces['xsi'], 'xsi:schemaLocation', 'https://xbrl.eesti.ee/gl/plt/2026-03-31 ' . $schemaUrl);

    $schemaRef = $xml->createElementNS($namespaces['xbrll'], 'xbrll:schemaRef');
    $schemaRef->setAttributeNS($namespaces['xlink'], 'xlink:href', $schemaUrl);
    $schemaRef->setAttributeNS($namespaces['xlink'], 'xlink:arcrole', 'http://www.w3.org/1999/xlink/properties/linkbase');
    $schemaRef->setAttributeNS($namespaces['xlink'], 'xlink:type', 'simple');
    $root->appendChild($schemaRef);

    $context = $xml->createElementNS($namespaces['xbrli'], 'xbrli:context');
    $context->setAttribute('id', 'now');
    $root->appendChild($context);
    $entity = $xml->createElementNS($namespaces['xbrli'], 'xbrli:entity');
    $context->appendChild($entity);
    addXmlElement($xml, $entity, 'xbrli:identifier', $namespaces['xbrli'], $registryCode, ['scheme' => 'ARIREGISTRIKOOD']);
    $periodNode = $xml->createElementNS($namespaces['xbrli'], 'xbrli:period');
    $context->appendChild($periodNode);
    addXmlElement($xml, $periodNode, 'xbrli:instant', $namespaces['xbrli'], $today);

    foreach ([['EUR', 'iso4217:EUR'], ['pureRef', 'pure'], ['NotUsed', 'pure']] as [$unitId, $measure]) {
        $unit = $xml->createElementNS($namespaces['xbrli'], 'xbrli:unit');
        $unit->setAttribute('id', $unitId);
        $root->appendChild($unit);
        addXmlElement($xml, $unit, 'xbrli:measure', $namespaces['xbrli'], $measure);
    }

    $entries = $xml->createElementNS($namespaces['gl-cor'], 'gl-cor:accountingEntries');
    $root->appendChild($entries);
    $documentInfo = $xml->createElementNS($namespaces['gl-cor'], 'gl-cor:documentInfo');
    $entries->appendChild($documentInfo);
    addXmlElement($xml, $documentInfo, 'gl-cor:entriesType', $namespaces['gl-cor'], 'other', ['contextRef' => 'now']);
    addXmlElement($xml, $documentInfo, 'gl-cor:uniqueID', $namespaces['gl-cor'], $registryCode . '-' . $createdAt->format('Y-m-d\TH:i:s.vP'), ['contextRef' => 'now']);
    addXmlElement($xml, $documentInfo, 'gl-cor:language', $namespaces['gl-cor'], 'iso639:et', ['contextRef' => 'now']);
    addXmlElement($xml, $documentInfo, 'gl-cor:creationDate', $namespaces['gl-cor'], $today, ['contextRef' => 'now']);
    addXmlElement($xml, $documentInfo, 'gl-bus:creator', $namespaces['gl-bus'], trim((string) $company['name']), ['contextRef' => 'now']);
    addXmlElement($xml, $documentInfo, 'gl-cor:entriesComment', $namespaces['gl-cor'], 'Käibemaksuga seotud tehingute ja toimingute andmed Maksu- ja Tolliametile', ['contextRef' => 'now']);
    addXmlElement($xml, $documentInfo, 'gl-cor:periodCoveredStart', $namespaces['gl-cor'], $startDate, ['contextRef' => 'now']);
    addXmlElement($xml, $documentInfo, 'gl-cor:periodCoveredEnd', $namespaces['gl-cor'], $endDate, ['contextRef' => 'now']);
    addXmlElement($xml, $documentInfo, 'gl-bus:sourceApplication', $namespaces['gl-bus'], $comparison ? 'Raamatupidamine (võrdlusfail)' : 'Raamatupidamine', ['contextRef' => 'now']);

    $entityInfo = $xml->createElementNS($namespaces['gl-cor'], 'gl-cor:entityInformation');
    $entries->appendChild($entityInfo);
    $organizationIds = $xml->createElementNS($namespaces['gl-bus'], 'gl-bus:organizationIdentifiers');
    $entityInfo->appendChild($organizationIds);
    addXmlElement($xml, $organizationIds, 'gl-bus:organizationIdentifier', $namespaces['gl-bus'], $registryCode, ['contextRef' => 'now']);
    addXmlElement($xml, $organizationIds, 'gl-bus:organizationDescription', $namespaces['gl-bus'], 'ARIREGISTRIKOOD', ['contextRef' => 'now']);

    $header = $xml->createElementNS($namespaces['gl-cor'], 'gl-cor:entryHeader');
    $entries->appendChild($header);
    addXmlElement($xml, $header, 'gl-cor:entryNumber', $namespaces['gl-cor'], 'EE0203001', ['contextRef' => 'now']);
    addXmlElement($xml, $header, 'gl-ext:entryVersion', $namespaces['gl-ext'], '1.0', ['contextRef' => 'now']);

    foreach ($details as $index => $detail) {
        $entryDetail = $xml->createElementNS($namespaces['gl-cor'], 'gl-cor:entryDetail');
        $header->appendChild($entryDetail);
        addXmlElement($xml, $entryDetail, 'gl-cor:lineNumberCounter', $namespaces['gl-cor'], (string) ($index + 1), ['contextRef' => 'now', 'unitRef' => 'NotUsed']);
        $account = $xml->createElementNS($namespaces['gl-cor'], 'gl-cor:account');
        $entryDetail->appendChild($account);
        $accountSub = $xml->createElementNS($namespaces['gl-cor'], 'gl-cor:accountSub');
        $account->appendChild($accountSub);
        addXmlElement($xml, $accountSub, 'gl-cor:accountSubID', $namespaces['gl-cor'], $detail['entry_type'], ['contextRef' => 'now']);
        addXmlElement($xml, $accountSub, 'gl-cor:accountSubType', $namespaces['gl-cor'], 'KMDTYYP2026ap', ['contextRef' => 'now']);
        if (!empty($detail['buyer_country'])) {
            $countrySub = $account->appendChild($xml->createElementNS($namespaces['gl-cor'], 'gl-cor:accountSub'));
            addXmlElement($xml, $countrySub, 'gl-cor:accountSubID', $namespaces['gl-cor'], $detail['buyer_country'], ['contextRef' => 'now']);
            addXmlElement($xml, $countrySub, 'gl-cor:accountSubType', $namespaces['gl-cor'], 'RTK2T2013ap', ['contextRef' => 'now']);
            $role = $countrySub->appendChild($xml->createElementNS($namespaces['gl-cor'], 'gl-cor:segmentParentTuple'));
            addXmlElement($xml, $role, 'gl-cor:parentSubaccountCode', $namespaces['gl-cor'], 'RR_ostja', ['contextRef' => 'now']);
            addXmlElement($xml, $role, 'gl-cor:parentSubaccountType', $namespaces['gl-cor'], 'RIIGIROLL2022ap', ['contextRef' => 'now']);
        }
        addXmlElement($xml, $entryDetail, 'gl-cor:amount', $namespaces['gl-cor'], formatAmount($detail['amount_cents']), ['contextRef' => 'now', 'decimals' => '2', 'unitRef' => 'EUR']);

        if ($detail['party'] !== null) {
            $identifier = $xml->createElementNS($namespaces['gl-cor'], 'gl-cor:identifierReference');
            $entryDetail->appendChild($identifier);
            if ($detail['party']['code'] !== null) {
                addXmlElement($xml, $identifier, 'gl-cor:identifierCode', $namespaces['gl-cor'], $detail['party']['code'], ['contextRef' => 'now']);
                addXmlElement($xml, $identifier, 'gl-cor:identifierDescription', $namespaces['gl-cor'], $detail['party']['description'], ['contextRef' => 'now']);
            }
            addXmlElement($xml, $identifier, 'gl-cor:identifierCategory', $namespaces['gl-cor'], $detail['party']['category'], ['contextRef' => 'now']);
        }
        if (isset($detail['document_number'])) {
            addXmlElement($xml, $entryDetail, 'gl-cor:documentNumber', $namespaces['gl-cor'], $detail['document_number'], ['contextRef' => 'now']);
            addXmlElement($xml, $entryDetail, 'gl-cor:documentDate', $namespaces['gl-cor'], $detail['document_date'], ['contextRef' => 'now']);
        }
        if (isset($detail['invoice_gross_cents'])) {
            $measurable = $xml->createElementNS($namespaces['gl-bus'], 'gl-bus:measurable');
            $entryDetail->appendChild($measurable);
            addXmlElement($xml, $measurable, 'gl-bus:measurableID', $namespaces['gl-bus'], 'ARVE_KOGUSUMMA', ['contextRef' => 'now']);
            addXmlElement($xml, $measurable, 'gl-bus:measurableIDSchema', $namespaces['gl-bus'], 'MEASURABLEQUANTITY_enum', ['contextRef' => 'now']);
            addXmlElement($xml, $measurable, 'gl-bus:measurableQuantity', $namespaces['gl-bus'], formatAmount($detail['invoice_gross_cents']), ['contextRef' => 'now', 'decimals' => '2', 'unitRef' => 'EUR']);
        }
        if (isset($detail['original_date'])) {
            $measurable = $entryDetail->appendChild($xml->createElementNS($namespaces['gl-bus'], 'gl-bus:measurable'));
            addXmlElement($xml, $measurable, 'gl-bus:measurableID', $namespaces['gl-bus'], 'ALGSE_ARVE_KP', ['contextRef' => 'now']);
            addXmlElement($xml, $measurable, 'gl-bus:measurableIDSchema', $namespaces['gl-bus'], 'SYNDMUS2017ap', ['contextRef' => 'now']);
            addXmlElement($xml, $measurable, 'gl-bus:measurableStartDateTime', $namespaces['gl-bus'], $detail['original_date'], ['contextRef' => 'now']);
        }

        if ($detail['vat_rate'] !== null) {
            $taxes = $xml->createElementNS($namespaces['gl-cor'], 'gl-cor:taxes');
            $entryDetail->appendChild($taxes);
            addXmlElement($xml, $taxes, 'gl-cor:taxPercentageRate', $namespaces['gl-cor'], number_format($detail['vat_rate'], 3, '.', ''), ['contextRef' => 'now', 'decimals' => '3', 'unitRef' => 'pureRef']);
        }
    }

    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="KMD_XBRL_GL_' . ($comparison ? 'VORDLUS_' : '') . $year . '_' . sprintf('%02d', $month) . '.xml"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $xml->saveXML();
    exit;
}

$downloadUrl = 'kmd_export.php?' . http_build_query(['year' => $year, 'month' => $month, 'download' => 'xml']);
$legacyUrl = 'kmd_export_legacy.php?' . http_build_query(['year' => $year, 'month' => $month]);
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Käibedeklaratsiooni andmepõhine eksport</title>
<style>
body{max-width:1100px;margin:32px auto;padding:0 20px;background:#f4f7fb;color:#263746;font-family:Arial,sans-serif}h1,h2{color:#17324d}.card{margin:18px 0;padding:20px;background:#fff;border:1px solid #dce3ec;border-radius:12px}.period{display:flex;flex-wrap:wrap;align-items:end;gap:12px}label{display:grid;gap:6px;font-weight:600}select{min-height:42px;padding:8px 10px;border:1px solid #b8c4d1;border-radius:7px;background:#fff;font:inherit}.button{display:inline-block;padding:10px 15px;border:0;border-radius:7px;background:#1769aa;color:#fff;text-decoration:none;font:inherit;font-weight:600;cursor:pointer}.secondary{background:#657789}.disabled{background:#9aa7b3;pointer-events:none}.error,.warning,.success{padding:10px 13px;border-radius:7px}.error{background:#fff0f0;color:#a32121;border:1px solid #e5a3a3}.warning{background:#fff8e6;color:#71520a;border:1px solid #ead9a3}.success{background:#eaf7ef;color:#176236;border:1px solid #b9dfc7}.note{line-height:1.5}.actions{display:flex;gap:10px;flex-wrap:wrap}.muted{color:#667788}
.confirmation-link{display:inline-block;margin-left:8px;color:inherit;font-weight:600}
</style>
</head>
<body>
<h1>Käibedeklaratsiooni ettevalmistus</h1>
<section class="card">
<p class="note">XBRL GL eksport hõlmab tavatehinguid, kinnitatud 0% ja maksuvaba müüki, seotud kreeditarveid, pöördmaksu ning tollidokumentide sisendkäibemaksu. Kassapõhine Eesti 24%, 13% ja 9% müük, ettemaksed ning kinnitatud põhivara-, auto- ja segaarvete ostud kasutavad maksete ja arve saamise kuupäevi. Arvestuskorra üleminekul säilib varasemate tehingute käsitlus. Mitme määraga arve summad esitatakse määrade kaupa. Liigitamata tehing või puuduv mahaarvamise reajaotus peatab faili koostamise.</p>
<form class="period" method="get">
<label>Aasta<select name="year"><?php for ($y = $currentYear - 3; $y <= $currentYear + 2; $y++): ?><option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></label>
<label>Kuu<select name="month"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= sprintf('%02d', $m) ?></option><?php endfor; ?></select></label>
<button class="button" type="submit">Näita</button>
</form>
</section>
<?php foreach ($errors as $error): ?>
<p class="error">
    <?= h($error) ?>
    <?php if ($error === $pendingDeductionError): ?>
        <a class="confirmation-link" href="kmd_inf_preview.php?year=<?= (int) $year ?>&amp;month=<?= (int) $month ?>#purchase-invoices">Mine kinnitama</a>
    <?php endif; ?>
</p>
<?php endforeach; ?>
<?php foreach ($warnings as $warning): ?><p class="warning"><?= h($warning) ?></p><?php endforeach; ?>
<section class="card">
<h2>Periood <?= h($period) ?></h2>
<p>Müügiarveid: <strong><?= $salesInvoiceCount ?></strong> · Ostuarveid: <strong><?= $purchaseInvoiceCount ?></strong> · XML-i ridu: <strong><?= count($details) ?></strong></p>
<p>Müügi maksustatav väärtus: <strong><?= h(euros($salesNetTotal)) ?></strong> · Ostude sisendkäibemaks: <strong><?= h(euros($purchaseVatTotal)) ?></strong></p>
<p>Sisendkäibemaksu parandused (O_601): <strong><?= h(euros($manualAdjustments['net'])) ?></strong> · Sisendkäibemaks pärast parandusi: <strong><?= h(euros($purchaseVatTotal-$manualAdjustments['net'])) ?></strong>.</p>
<p class="muted">Paranduse plussmärk vähendab sisendkäibemaksu, miinusmärk suurendab seda. Iga seotud kanne esitatakse kontrollitud maksumäärade kaupa.</p>
<p><a class="button secondary" href="kmd_adjustment_register.php?year=<?= (int)$year ?>&amp;month=<?= (int)$month ?>">Sisendkäibemaksu parandused</a></p>
<?php if (!$errors && !$details): ?><p class="success">Perioodi kohta puuduvad kinnitatud eksporditavad arved. Koostatakse tühja andmesektsiooniga XML.</p><?php endif; ?>
<p class="actions"><a class="button <?= $errors ? 'disabled' : '' ?>" href="<?= $errors ? '#' : h($downloadUrl) ?>"><?= $comparison ? 'Laadi alla XBRL KMD võrdlusfail' : 'Laadi alla uus XBRL GL XML' ?></a><a class="button secondary" href="kmd_inf_preview.php?year=<?= (int) $year ?>&amp;month=<?= (int) $month ?>">KMD INF eelvaade</a><a class="button secondary" href="<?= h($legacyUrl) ?>">Vana KMD6 vorming</a><a class="button secondary" href="vat_report.php">Tagasi käibemaksu aruandele</a><a class="button secondary" href="index.php">Avalehele</a></p>
</section>
</body>
</html>
