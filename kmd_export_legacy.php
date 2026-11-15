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
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function cents(string $amount): int
{
    $negative = isset($amount[0]) && $amount[0] === '-';
    $amount = ltrim($amount, '-');
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
    $value = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    return $negative ? -$value : $value;
}

function euros(int $amount): string
{
    return number_format($amount / 100, 2, ',', ' ') . ' €';
}

$companyId = (int) $_SESSION['company_id'];
$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT);
$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT);
$currentYear = (int) date('Y');
$currentMonth = (int) date('n');
$year = $year && $year >= 2000 && $year <= 2100 ? $year : $currentYear;
$month = $month && $month >= 1 && $month <= 12 ? $month : $currentMonth;
$period = sprintf('%04d-%02d', $year, $month);
$startDate = $period . '-01';
$endDate = date('Y-m-t', strtotime($startDate));
$download = ($_GET['download'] ?? '') === 'xml';

$companyStmt = $pdo->prepare('SELECT name, registry_code FROM companies WHERE id = ?');
$companyStmt->execute([$companyId]);
$company = $companyStmt->fetch();

$errors = [];
$warnings = [];
$rates = ['24.00', '22.00', '20.00', '13.00', '9.00', '5.00'];
$salesByRate = array_fill_keys($rates, 0);
$inputVat = 0;
$zeroSales=0; $exemptSales=0;
$salesCount = 0;
$purchaseCount = 0;
$salesPartners = [];
$purchasePartners = [];

$salesStmt = $pdo->prepare(
    "SELECT si.id, si.invoice_number, si.contact_id, c.name AS contact_name,
            sil.vat_rate, sil.net_amount, sil.vat_amount, sil.gross_amount, tc.code AS tax_code,tc.tax_category,tc.kmd_box
     FROM sales_invoices si
     JOIN contacts c ON c.id = si.contact_id AND c.company_id = si.company_id
     JOIN sales_invoice_lines sil ON sil.invoice_id = si.id
     LEFT JOIN tax_codes tc ON tc.id = sil.tax_code_id AND tc.company_id = si.company_id
     WHERE si.company_id = ? AND si.status = 'posted'
       AND si.journal_entry_id IS NOT NULL
       AND si.invoice_date BETWEEN ? AND ?
     ORDER BY si.invoice_date, si.id, sil.id"
);
$salesStmt->execute([$companyId, $startDate, $endDate]);
$salesRows = $salesStmt->fetchAll();

$purchaseStmt = $pdo->prepare(
    "SELECT pi.id, pi.supplier_invoice_number AS invoice_number,
            pi.contact_id, c.name AS contact_name,
            pil.vat_rate, pil.net_amount, pil.vat_amount, pil.gross_amount, tc.code AS tax_code,tc.tax_category,tc.kmd_box
     FROM purchase_invoices pi
     JOIN contacts c ON c.id = pi.contact_id AND c.company_id = pi.company_id
     JOIN purchase_invoice_lines pil ON pil.invoice_id = pi.id
     LEFT JOIN tax_codes tc ON tc.id = pil.tax_code_id AND tc.company_id = pi.company_id
     WHERE pi.company_id = ? AND pi.status = 'posted'
       AND pi.journal_entry_id IS NOT NULL
       AND pi.invoice_date BETWEEN ? AND ?
     ORDER BY pi.invoice_date, pi.id, pil.id"
);
$purchaseStmt->execute([$companyId, $startDate, $endDate]);
$purchaseRows = $purchaseStmt->fetchAll();

ensureCreditNoteSchema($pdo);
$saleCreditLinks=loadCreditLinks($pdo,$companyId,'sales');
$purchaseCreditLinks=loadCreditLinks($pdo,$companyId,'purchase');
$supportedRates = ['24.00', '22.00', '20.00', '13.00', '9.00', '5.00'];
$salesInvoices = [];
$purchaseInvoices = [];
$cashSales = loadCashSales($pdo,$companyId,$startDate,$endDate);
$errors = array_merge($errors,$cashSales['errors']);
foreach ($cashSales['invoices'] as $invoice) {
    foreach($invoice['taxable_by_rate'] as $rate=>$base) $salesByRate[$rate]+=$base;
    ++$salesCount;
}

foreach ($salesRows as $row) {
    if (isset($cashSales['ids'][$row['id']])) continue;
    if (reverseVatSpec((string) ($row['tax_code'] ?? ''))) {
        $id=(int)$row['id'];
        if (!isset($salesInvoices[$id])) { $salesInvoices[$id]=['contact_id'=>(int)$row['contact_id'],'gross'=>0]; ++$salesCount; }
        $salesInvoices[$id]['gross']+=cents((string)$row['gross_amount']);
        $contactId=(int)$row['contact_id'];
        $salesPartners[$contactId] ??= ['name'=>(string)$row['contact_name'],'net'=>0];
        $salesPartners[$contactId]['net']+=cents((string)$row['net_amount']);
        continue;
    }
    $rate = number_format((float) $row['vat_rate'], 2, '.', '');
    $net = cents((string) $row['net_amount']);
    $vat = cents((string) $row['vat_amount']);
    $gross = cents((string) $row['gross_amount']);
    if($rate==='0.00' && $vat===0 && in_array($row['tax_category']??'',['zero_rated','exempt'],true)) {
        if($gross!==$net) { $errors[]='Müügiarve '.$row['invoice_number'].' nullmäära summad ei klapi.'; continue; }
        $id=(int)$row['id'];
        if(!isset($salesInvoices[$id])) { $salesInvoices[$id]=['contact_id'=>(int)$row['contact_id'],'gross'=>0]; ++$salesCount; }
        $salesInvoices[$id]['gross']+=$gross;
        // The shared classification below uses the confirmed tax date and historical party.
        continue;
    }
    if ($net + $vat !== $gross) {
        $errors[] = 'Müügiarve ' . $row['invoice_number'] . ' summad ei klapi.';
        continue;
    }
    if (!in_array($rate, $supportedRates, true)) {
        $errors[] = 'Müügiarvel ' . $row['invoice_number'] . ' on määr ' . $rate
            . '%, mille KMD rida vajab eraldi liigitamist.';
        continue;
    }
    if (!isset($saleCreditLinks[$row['id']]) && abs((int) round($net * (float) $rate / 100) - $vat) > 1) {
        $errors[] = 'Müügiarve ' . $row['invoice_number']
            . ' käibemaks ei vasta salvestatud määrale.';
        continue;
    }
    $salesByRate[$rate] += $net;
    $id = (int) $row['id'];
    if (!isset($salesInvoices[$id])) {
        $salesInvoices[$id] = ['contact_id' => (int) $row['contact_id'], 'gross' => 0];
        $salesCount++;
    }
    $salesInvoices[$id]['gross'] += $gross;
    $contactId = (int) $row['contact_id'];
    $salesPartners[$contactId] ??= ['name' => (string) $row['contact_name'], 'net' => 0];
    $salesPartners[$contactId]['net'] += max(0,$net);
    $salesPartners[$contactId]['credit_net']=($salesPartners[$contactId]['credit_net']??0)+max(0,-$net);
}

foreach ($purchaseRows as $row) {
    if((float)$row['vat_rate']===0.0 && cents((string)$row['vat_amount'])===0 && in_array($row['tax_category']??'',['zero_rated','exempt'],true)) {
        if(cents((string)$row['net_amount'])!==cents((string)$row['gross_amount'])) $errors[]='Ostuarve '.$row['invoice_number'].' nullmäära summad ei klapi.';
        $id=(int)$row['id'];
        if(!isset($purchaseInvoices[$id])) { $purchaseInvoices[$id]=['contact_id'=>(int)$row['contact_id'],'gross'=>0]; ++$purchaseCount; }
        $purchaseInvoices[$id]['gross']+=cents((string)$row['gross_amount']);
        continue;
    }
    if (reverseVatSpec((string) ($row['tax_code'] ?? ''))) {
        if (!isset($purchaseInvoices[(int) $row['id']])) {
            $purchaseInvoices[(int) $row['id']] = ['contact_id' => (int) $row['contact_id'], 'gross' => 0];
            ++$purchaseCount;
        }
        $purchaseInvoices[(int) $row['id']]['gross'] += cents((string) $row['gross_amount']);
        continue; // Included through the shared EU reverse-charge calculation below.
    }
    $rate = number_format((float) $row['vat_rate'], 2, '.', '');
    $net = cents((string) $row['net_amount']);
    $vat = cents((string) $row['vat_amount']);
    $gross = cents((string) $row['gross_amount']);
    if ($net + $vat !== $gross) {
        $errors[] = 'Ostuarve ' . $row['invoice_number'] . ' summad ei klapi.';
        continue;
    }
    if (!in_array($rate, $supportedRates, true)) {
        $errors[] = 'Ostuarvel ' . $row['invoice_number'] . ' on määr ' . $rate
            . '%, mille KMD rida vajab eraldi liigitamist.';
        continue;
    }
    if (!isset($purchaseCreditLinks[$row['id']]) && abs((int) round($net * (float) $rate / 100) - $vat) > 1) {
        $errors[] = 'Ostuarve ' . $row['invoice_number']
            . ' käibemaks ei vasta salvestatud määrale.';
        continue;
    }
    $inputVat += $vat;
    $id = (int) $row['id'];
    if (!isset($purchaseInvoices[$id])) {
        $purchaseInvoices[$id] = ['contact_id' => (int) $row['contact_id'], 'gross' => 0];
        $purchaseCount++;
    }
    $purchaseInvoices[$id]['gross'] += $gross;
    $contactId = (int) $row['contact_id'];
    $purchasePartners[$contactId] ??= ['name' => (string) $row['contact_name'], 'net' => 0];
    $purchasePartners[$contactId]['net'] += max(0,$net);
    $purchasePartners[$contactId]['credit_net']=($purchasePartners[$contactId]['credit_net']??0)+max(0,-$net);
}

$deductions = loadPurchaseDeductions($pdo, $companyId, $startDate, $endDate);
$zeroSalesData=loadZeroSales($pdo,$companyId,$startDate,$endDate);
$zeroSales=$zeroSalesData['boxes']['3']; $exemptSales=$zeroSalesData['boxes']['8'];
$errors=array_merge($errors,$zeroSalesData['errors']);
$inputVat = $deductions['total'];
ensureKmdManualAdjustments($pdo);
$manualAdjustments = loadKmdManualAdjustments($pdo,$companyId,$startDate,$endDate);
$errors = array_merge($errors,$manualAdjustments['errors']);
$salesDetails = loadInfRows($pdo, $companyId, $startDate, $endDate, 'sales');
$domesticSalesNet = array_sum(array_column($salesDetails['invoices'], 'domestic_sales_net'));
$errors = array_merge($errors, $salesDetails['errors']);
$otherAcquisitionsTotal = $deductions['domestic_purchase_net'] + $deductions['non_eu_services_net'];
foreach ($deductions['reverse_base_by_rate'] as $rate => $net) $salesByRate[$rate] += $net;
$euAcquisitionsTotal = $deductions['eu_goods_net'] + $deductions['eu_services_net'];
$errors = array_merge($errors, $deductions['errors']);
$pendingDeductionError = null;
if ($deductions['pending']) {
    $pendingDeductionError = 'Kinnita ostuarvete mahaarvatavad summad KMD INF eelvaates: ' . implode(', ', $deductions['pending']);
    $errors[] = $pendingDeductionError;
}

foreach ($salesPartners as $partner) {
    if ($partner['net'] >= 100000 || ($partner['credit_net']??0)>=100000) {
        $warnings[] = 'Müügipartneri „' . $partner['name']
            . '” netosumma jõuab KMD INF A-osa 1 000 € piirmäärani. Kontrolli selle perioodi arveid KMD INF eelvaates ja laadi A-osa CSV eraldi alla.';
    }
}
foreach ($purchasePartners as $partner) {
    if ($partner['net'] >= 100000 || ($partner['credit_net']??0)>=100000) {
        $warnings[] = 'Tarnija „' . $partner['name']
            . '” netosumma jõuab KMD INF B-osa 1 000 € piirmäärani. Kontrolli arveid KMD INF eelvaates ja laadi B-osa CSV alla pärast mahaarvatavate summade kontrollimist.';
    }
}

if (!$company || !preg_match('/^\d{8}$/', trim((string) ($company['registry_code'] ?? '')))) {
    $errors[] = 'Ettevõtte seadetes peab olema 8-kohaline registrikood.';
}
if ($year > 2027 || ($year === 2027 && $month >= 4)) {
    $errors[] = 'Valitud perioodile tuleb kasutada MTA uut andmepõhist KMD vormingut. KMD6 XML kehtib kuni 2027. aasta märtsini.';
}

$warnings[] = 'Käsitsi tehtud pearaamatukanded jõuavad lahtritesse 10 ja 11 pärast KMD täpsustusena sidumist.';
$warnings[] = 'Sisendkäibemaksu summa kasutab arvetel kinnitatud mahaarvatavaid summasid. Muud deklareeritavad tehingud tuleb eraldi kontrollida.';
$warnings[] = 'KMD6 XML ei sisalda KMD INF A- ega B-osa. Kontrolli vajadusel KMD INF eelvaadet ja esita A- või B-osa CSV eraldi.';

if ($download && !$errors) {
    $xml = new DOMDocument('1.0', 'UTF-8');
    $xml->formatOutput = true;
    $root = $xml->createElement('vatDeclaration');
    $xml->appendChild($root);
    foreach ([
        'taxPayerRegCode' => trim((string) $company['registry_code']),
        'year' => (string) $year,
        'month' => sprintf('%02d', $month),
        'declarationType' => '1',
        'version' => 'KMD6',
    ] as $name => $value) {
        $root->appendChild($xml->createElement($name, $value));
    }
    $body = $xml->createElement('declarationBody');
    $root->appendChild($body);
    foreach ([
        'noSales' => 'true',
        'noPurchases' => 'true',
        'sumPerPartnerSales' => 'false',
        'sumPerPartnerPurchases' => 'false',
    ] as $name => $value) {
        $body->appendChild($xml->createElement($name, $value));
    }
    foreach ([
        '24.00' => 'transactions24',
        '22.00' => 'transactions22',
        '20.00' => 'transactions20',
        '9.00' => 'transactions9',
        '5.00' => 'transactions5',
        '13.00' => 'transactions13',
    ] as $rate => $element) {
        if ($salesByRate[$rate] !== 0) {
            $body->appendChild($xml->createElement(
                $element,
                number_format($salesByRate[$rate] / 100, 2, '.', '')
            ));
        }
    }
    if($zeroSales!==0) $body->appendChild($xml->createElement('transactionsZeroVat',number_format($zeroSales/100,2,'.','')));
    foreach(['3.1'=>'euSupplyInclGoodsAndServicesZeroVat','3.1.1'=>'euSupplyGoodsZeroVat','3.2'=>'exportZeroVat'] as $box=>$tag) if($zeroSalesData['boxes'][$box]!==0) $body->appendChild($xml->createElement($tag,number_format($zeroSalesData['boxes'][$box]/100,2,'.','')));
    if ($inputVat !== 0) {
        $body->appendChild($xml->createElement(
            'inputVatTotal',
            number_format($inputVat / 100, 2, '.', '')
        ));
    }
    foreach ([
        'importVat' => $deductions['import_vat'],
        'fixedAssetsVat' => $deductions['fixed_assets_vat'],
        'carsVat' => $deductions['cars_vat'],
        'numberOfCars' => $deductions['number_of_cars'],
        'carsPartialVat' => $deductions['cars_partial_vat'],
        'numberOfCarsPartial' => $deductions['number_of_cars_partial'],
    ] as $tag => $amount) {
        if ($amount!==0) $body->appendChild($xml->createElement($tag,
            in_array($tag,['numberOfCars','numberOfCarsPartial'],true) ? (string)$amount : number_format($amount/100,2,'.','')));
    }
    if ($euAcquisitionsTotal !== 0) {
        $body->appendChild($xml->createElement('euAcquisitionsGoodsAndServicesTotal', number_format($euAcquisitionsTotal / 100, 2, '.', '')));
    }
    if ($deductions['eu_goods_net'] !== 0) {
        $body->appendChild($xml->createElement('euAcquisitionsGoods', number_format($deductions['eu_goods_net'] / 100, 2, '.', '')));
    }
    foreach ([
        'acquisitionOtherGoodsAndServicesTotal' => $otherAcquisitionsTotal,
        'acquisitionImmovablesAndScrapMetalAndGold' => $deductions['domestic_purchase_net'],
        'supplyExemptFromTax' => $exemptSales,
        'supplySpecialArrangements' => $domesticSalesNet,
    ] as $tag => $amount) {
        if ($amount !== 0) $body->appendChild($xml->createElement($tag, number_format($amount / 100, 2, '.', '')));
    }
    if ($manualAdjustments['net'] !== 0) {
        $tag = $manualAdjustments['net'] > 0 ? 'adjustmentsPlus' : 'adjustmentsMinus';
        $body->appendChild($xml->createElement($tag,number_format(abs($manualAdjustments['net']) / 100,2,'.','')));
    }
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="KMD_' . $year . '_' . sprintf('%02d', $month) . '.xml"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $xml->saveXML();
    exit;
}

$exportUrl = 'kmd_export_legacy.php?' . http_build_query([
    'year' => $year,
    'month' => $month,
    'download' => 'xml',
]);
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Käibedeklaratsiooni ettevalmistus</title>
<style>
body{max-width:1100px;margin:32px auto;padding:0 20px;background:#f4f7fb;color:#263746;font-family:Arial,sans-serif}h1,h2{color:#17324d}.card{margin:18px 0;padding:20px;background:#fff;border:1px solid #dce3ec;border-radius:12px}.period{display:flex;flex-wrap:wrap;align-items:end;gap:12px}label{display:grid;gap:6px;font-weight:600}select{min-height:42px;padding:8px 10px;border:1px solid #b8c4d1;border-radius:7px;background:#fff;font:inherit}.button{display:inline-block;padding:10px 15px;border:0;border-radius:7px;background:#1769aa;color:#fff;text-decoration:none;font:inherit;font-weight:600;cursor:pointer}.secondary{background:#657789}.disabled{background:#9aa7b3;pointer-events:none}.error,.warning{padding:10px 13px;border-radius:7px}.error{background:#fff0f0;color:#a32121;border:1px solid #e5a3a3}.warning{background:#fff8e6;color:#71520a;border:1px solid #ead9a3}.muted{color:#667788}.note{line-height:1.5}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{padding:10px;border-bottom:1px solid #e5eaf0;text-align:left}th{background:#f3f6fa}.num{text-align:right}.actions{display:flex;gap:10px;flex-wrap:wrap}
.confirmation-link{display:inline-block;margin-left:8px;color:inherit;font-weight:600}
</style>
</head>
<body>
<h1>Käibedeklaratsiooni ettevalmistus</h1>
<section class="card">
<p class="note">Vali kuu, et vaadata kinnitatud müügi- ja ostuarvete põhjal koostatud kokkuvõtet. KMD6 XML-faili saab kasutada kuni 2027. aasta märtsini; see ei sisalda KMD INF lisasid. Vajadusel laadi A- ja B-osa eraldi alla KMD INF eelvaatest. Failide MTA-le esitamine jääb eraldi toiminguks.</p>
<form class="period" method="get">
<label>Aasta<select name="year"><?php for ($y = $currentYear - 3; $y <= $currentYear + 1; $y++): ?><option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></label>
<label>Kuu<select name="month"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= sprintf('%02d', $m) ?></option><?php endfor; ?></select></label>
<button class="button" type="submit">Näita</button>
</form>
</section>
<?php foreach (array_unique($errors) as $error): ?>
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
<p>Müügiarveid: <strong><?= $salesCount ?></strong> · Ostuarveid: <strong><?= $purchaseCount ?></strong></p>
<table><thead><tr><th>Käibemaksumäär</th><th class="num">Maksustatav summa koos pöördmaksu ostudega</th></tr></thead><tbody>
<?php foreach ($rates as $rate): ?><tr><td><?= h(rtrim(rtrim($rate, '0'), '.')) ?>%</td><td class="num"><?= h(euros($salesByRate[$rate])) ?></td></tr><?php endforeach; ?>
<tr><th>0% käive (lahter 3)</th><th class="num"><?= h(euros($zeroSales)) ?></th></tr>
<tr><th>Sellest EL kaup ja VD teenus (3.1)</th><th class="num"><?= h(euros($zeroSalesData['boxes']['3.1'])) ?></th></tr>
<tr><th>Sellest EL kaup (3.1.1)</th><th class="num"><?= h(euros($zeroSalesData['boxes']['3.1.1'])) ?></th></tr>
<tr><th>Sellest kauba eksport (3.2)</th><th class="num"><?= h(euros($zeroSalesData['boxes']['3.2'])) ?></th></tr>
<tr><th>Maksuvaba käive (lahter 8)</th><th class="num"><?= h(euros($exemptSales)) ?></th></tr>
<tr><th>EL kauba ja teenuse ostud (lahter 6)</th><th class="num"><?= h(euros($euAcquisitionsTotal)) ?></th></tr>
<tr><th>EL kaubaostud (lahter 6.1)</th><th class="num"><?= h(euros($deductions['eu_goods_net'])) ?></th></tr>
<tr><th>Muud pöördmaksu soetused (lahter 7)</th><th class="num"><?= h(euros($otherAcquisitionsTotal)) ?></th></tr>
<tr><th>Siseriiklik pöördmaksu ost (lahter 7.1)</th><th class="num"><?= h(euros($deductions['domestic_purchase_net'])) ?></th></tr>
<tr><th>Siseriiklik pöördmaksu müük (lahter 9)</th><th class="num"><?= h(euros($domesticSalesNet)) ?></th></tr>
<tr><th>Pöördmaksu arvestatud käibemaks</th><th class="num"><?= h(euros($deductions['reverse_vat'])) ?></th></tr>
<tr><th>Põhivara mahaarvatav KM (5.2)</th><th class="num"><?= h(euros($deductions['fixed_assets_vat'])) ?></th></tr>
<tr><th>Sõiduautod ainult ettevõtluses (5.3): <?= $deductions['number_of_cars'] ?> autot</th><th class="num"><?= h(euros($deductions['cars_vat'])) ?></th></tr>
<tr><th>Sõiduautod segakasutuses (5.4): <?= $deductions['number_of_cars_partial'] ?> autot</th><th class="num"><?= h(euros($deductions['cars_partial_vat'])) ?></th></tr>
<tr><th>Kinnitatud mahaarvatav sisendkäibemaks (lahter 5)</th><th class="num"><?= h(euros($inputVat)) ?></th></tr>
<tr><th>KMD täpsustus (lahter 10 või 11)</th><th class="num"><?= h(euros($manualAdjustments['net'])) ?></th></tr>
<tr><th>Sellest impordi mahaarvatav KM (5.1)</th><th class="num"><?= h(euros($deductions['import_vat'])) ?></th></tr>
<tr><th>Loaga KMD-l tasutav impordi KM (4.1, MTA täidab)</th><th class="num"><?= h(euros($deductions['import_payable'])) ?></th></tr>
</tbody></table>
<p>Impordi lahter 5.1 sisaldub juba lahtris 5. Lahtri 4.1 täidab MTA tolliandmetest; XML seda lahtrit ei saada. Võrdle registri summat e-MTA summaga. <a href="import_vat_documents.php">Tollidokumendid ja impordi KM</a></p>
<p class="actions"><a class="button <?= $errors ? 'disabled' : '' ?>" href="<?= $errors ? '#' : h($exportUrl) ?>">Laadi alla vana KMD6 XML</a> <a class="button secondary" href="kmd_inf_preview.php?year=<?= (int) $year ?>&amp;month=<?= (int) $month ?>">KMD INF eelvaade</a> <a class="button secondary" href="kmd_export.php?year=<?= (int) $year ?>&amp;month=<?= (int) $month ?>">Uus XBRL GL vorming</a> <a class="button secondary" href="vat_report.php">Tagasi aruande lehele</a></p>
</section>
<p><a href="kmd_adjustment_register.php?year=<?= (int)$year ?>&amp;month=<?= (int)$month ?>">Lisa või vaata KMD täpsustusi</a></p>
</body>
</html>
