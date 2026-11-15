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

function validReportDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function amountInCents(string $amount): int
{
    $negative = isset($amount[0]) && $amount[0] === '-';
    $amount = ltrim($amount, '-');
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
    $cents = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    return $negative ? -$cents : $cents;
}

function euros(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

$companyId = (int) $_SESSION['company_id'];
$today = date('Y-m-d');
$startDate = (string) ($_GET['start_date'] ?? date('Y-01-01'));
$endDate = (string) ($_GET['end_date'] ?? $today);
$errors = [];
$warnings = [];
$salesRates = [];
$purchaseRates = [];
$salesInvoices = [];
$purchaseInvoices = [];
$salesVatTotal = 0;
$purchaseVatTotal = 0;
$salesNetTotal = 0;
$purchaseNetTotal = 0;
$salesGrossTotal = 0;
$purchaseGrossTotal = 0;

if (!validReportDate($startDate) || !validReportDate($endDate)) {
    $errors[] = 'Vali kehtiv algus- ja lõpukuupäev.';
} elseif ($startDate > $endDate) {
    $errors[] = 'Alguskuupäev peab olema lõpukuupäevast varasem või sellega sama.';
}

if (!$errors) {
    $cashSales = loadCashSales($pdo,$companyId,$startDate,$endDate);
    $errors = array_merge($errors,$cashSales['errors']);
    $salesStmt = $pdo->prepare(
        "SELECT si.id, si.invoice_date, si.invoice_number, c.name AS contact_name,
                sil.vat_rate, tc.code AS tax_code, tc.label AS tax_code_label,
                tc.tax_category, tc.kmd_box, sil.net_amount, sil.vat_amount, sil.gross_amount
         FROM sales_invoices si
         JOIN contacts c ON c.id = si.contact_id AND c.company_id = si.company_id
         JOIN sales_invoice_lines sil ON sil.invoice_id = si.id
         LEFT JOIN tax_codes tc ON tc.id = sil.tax_code_id AND tc.company_id = si.company_id
         WHERE si.company_id = :company_id
           AND si.status = 'posted'
           AND si.journal_entry_id IS NOT NULL
           AND si.invoice_date BETWEEN :start_date AND :end_date
         ORDER BY si.invoice_date, si.id, sil.id"
    );
    $salesStmt->execute([
        'company_id' => $companyId,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);

    foreach ($salesStmt->fetchAll() as $row) {
        if (isset($cashSales['ids'][$row['id']])) continue;
        if(in_array($row['tax_category']??'',['zero_rated','exempt'],true)) continue;
        $rate = number_format((float) $row['vat_rate'], 2, '.', '');
        $net = amountInCents((string) $row['net_amount']);
        $vat = amountInCents((string) $row['vat_amount']);
        $gross = amountInCents((string) $row['gross_amount']);
        if ($net + $vat !== $gross) {
            $warnings[] = 'Müügiarve ' . $row['invoice_number'] . ' ridade summad ei klapi.';
        }
        $rateKey = $row['tax_code'] ? 'code:' . $row['tax_code'] : 'legacy:' . $rate;
        if (!isset($salesRates[$rateKey])) {
            $salesRates[$rateKey] = [
                'code' => (string) ($row['tax_code'] ?? ''),
                'label' => (string) ($row['tax_code_label'] ?? ''),
                'category' => (string) ($row['tax_category'] ?? ''),
                'kmd_box' => (string) ($row['kmd_box'] ?? ''),
                'rate' => $rate,
                'net' => 0, 'vat' => 0, 'gross' => 0,
            ];
        }
        $salesRates[$rateKey]['net'] += $net;
        $salesRates[$rateKey]['vat'] += $vat;
        $salesRates[$rateKey]['gross'] += $gross;
        $salesVatTotal += $vat;
        $salesNetTotal += $net;
        $salesGrossTotal += $gross;

        $id = (int) $row['id'];
        if (!isset($salesInvoices[$id])) {
            $salesInvoices[$id] = [
                'id' => $id,
                'date' => (string) $row['invoice_date'],
                'number' => (string) $row['invoice_number'],
                'contact' => (string) $row['contact_name'],
                'net' => 0,
                'vat' => 0,
                'gross' => 0,
            ];
        }
        $salesInvoices[$id]['net'] += $net;
        $salesInvoices[$id]['vat'] += $vat;
        $salesInvoices[$id]['gross'] += $gross;
    }

    foreach ($cashSales['invoices'] as $invoice) {
        foreach($invoice['taxable_by_rate'] as $rate=>$net) {
            $vat=$invoice['cash_vat_by_rate'][$rate]??$invoice['cash_vat'];
            $gross=$invoice['cash_gross_by_rate'][$rate]??$invoice['cash_gross'];
            $code='M_CASH'.(int)$rate;$key='code:'.$code;
            $salesRates[$key]??=['code'=>$code,'label'=>'Kassapõhine arvestus — '.(int)$rate.'%','category'=>'standard',
                'kmd_box'=>$rate==='13.00'?'2²':($rate==='9.00'?'2':'1'),'rate'=>$rate,'net'=>0,'vat'=>0,'gross'=>0];
            $salesRates[$key]['net']+=$net;$salesRates[$key]['vat']+=$vat;$salesRates[$key]['gross']+=$gross;
            $salesNetTotal+=$net;$salesVatTotal+=$vat;$salesGrossTotal+=$gross;
        }
        $net=array_sum($invoice['taxable_by_rate']);$vat=$invoice['cash_vat'];$gross=$invoice['cash_gross'];
        $salesInvoices[$invoice['id']] = ['id'=>$invoice['id'],'date'=>$invoice['document_date'],
            'number'=>$invoice['document_number'],'contact'=>$invoice['contact_name'],'net'=>$net,'vat'=>$vat,'gross'=>$gross];
    }

    $purchaseStmt = $pdo->prepare(
        "SELECT pi.id, pi.invoice_date, pi.supplier_invoice_number AS invoice_number,
                c.name AS contact_name, pil.vat_rate, tc.code AS tax_code,
                tc.label AS tax_code_label, tc.tax_category, tc.kmd_box, pil.net_amount,
                pil.vat_amount, pil.gross_amount
         FROM purchase_invoices pi
         JOIN contacts c ON c.id = pi.contact_id AND c.company_id = pi.company_id
         JOIN purchase_invoice_lines pil ON pil.invoice_id = pi.id
         LEFT JOIN tax_codes tc ON tc.id = pil.tax_code_id AND tc.company_id = pi.company_id
         WHERE pi.company_id = :company_id
           AND pi.status = 'posted'
           AND pi.journal_entry_id IS NOT NULL
           AND pi.invoice_date BETWEEN :start_date AND :end_date
         ORDER BY pi.invoice_date, pi.id, pil.id"
    );
    $purchaseStmt->execute([
        'company_id' => $companyId,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);

    foreach ($purchaseStmt->fetchAll() as $row) {
        $rate = number_format((float) $row['vat_rate'], 2, '.', '');
        $net = amountInCents((string) $row['net_amount']);
        $vat = amountInCents((string) $row['vat_amount']);
        $gross = amountInCents((string) $row['gross_amount']);
        if ($net + $vat !== $gross) {
            $warnings[] = 'Ostuarve ' . $row['invoice_number'] . ' ridade summad ei klapi.';
        }
        $rateKey = $row['tax_code'] ? 'code:' . $row['tax_code'] : 'legacy:' . $rate;
        if (!isset($purchaseRates[$rateKey])) {
            $purchaseRates[$rateKey] = [
                'code' => (string) ($row['tax_code'] ?? ''),
                'label' => (string) ($row['tax_code_label'] ?? ''),
                'category' => (string) ($row['tax_category'] ?? ''),
                'kmd_box' => (string) ($row['kmd_box'] ?? ''),
                'rate' => $rate,
                'net' => 0, 'vat' => 0, 'gross' => 0,
            ];
        }
        $purchaseRates[$rateKey]['net'] += $net;
        $purchaseRates[$rateKey]['vat'] += $vat;
        $purchaseRates[$rateKey]['gross'] += $gross;
        $purchaseVatTotal += $vat;
        $purchaseNetTotal += $net;
        $purchaseGrossTotal += $gross;

        $id = (int) $row['id'];
        if (!isset($purchaseInvoices[$id])) {
            $purchaseInvoices[$id] = [
                'id' => $id,
                'date' => (string) $row['invoice_date'],
                'number' => (string) $row['invoice_number'],
                'contact' => (string) $row['contact_name'],
                'net' => 0,
                'vat' => 0,
                'gross' => 0,
            ];
        }
        $purchaseInvoices[$id]['net'] += $net;
        $purchaseInvoices[$id]['vat'] += $vat;
        $purchaseInvoices[$id]['gross'] += $gross;
    }

    $warnings[] = 'Aruanne sisaldab kinnitatud müügi- ja ostuarveid ning impordi KM registri tollidokumente. Muud käsitsi pearaamatukanded ja pangakanded tuleb eraldi üle kontrollida.';
}

$deductions = ['total' => 0, 'invoices' => [], 'pending' => [], 'errors' => [], 'reverse_vat' => 0, 'eu_goods_net' => 0, 'eu_services_net' => 0, 'domestic_purchase_net' => 0, 'non_eu_services_net' => 0, 'fixed_assets_vat'=>0, 'cars_vat'=>0, 'cars_partial_vat'=>0, 'number_of_cars'=>0, 'number_of_cars_partial'=>0];
if (!$errors) {
    $deductions = loadPurchaseDeductions($pdo, $companyId, $startDate, $endDate);
    $errors = array_merge($errors, $deductions['errors']);
    if ($deductions['pending']) {
        $warnings[] = 'Mahaarvatav summa on kinnitamata ostuarvetel: ' . implode(', ', $deductions['pending'])
            . '. Kokkuvõtte mahaarvatav summa sisaldab ainult kontrollitud arveid.';
    }
}
$inputVatTotal = $deductions['total'];
ensureKmdManualAdjustments($pdo);
$manualAdjustments=loadKmdManualAdjustments($pdo,$companyId,$startDate,$endDate);
$errors=array_merge($errors,$manualAdjustments['errors']);
$zeroSalesData=['rows'=>[],'errors'=>[],'boxes'=>['3'=>0,'3.1'=>0,'3.1.1'=>0,'3.2'=>0,'8'=>0]];
if(!$errors) {
    $zeroSalesData=loadZeroSales($pdo,$companyId,$startDate,$endDate);
    $errors=array_merge($errors,$zeroSalesData['errors']);
    foreach($zeroSalesData['rows'] as $row) {
        $key='code:'.$row['code'];
        $salesRates[$key]??=['code'=>$row['code'],'label'=>$row['label'],'category'=>zeroSalesSpecs()[$row['code']]['category'],'kmd_box'=>$row['box'],'rate'=>'0.00','net'=>0,'vat'=>0,'gross'=>0];
        $salesRates[$key]['net']+=$row['net']; $salesRates[$key]['gross']+=$row['net'];
        $salesNetTotal+=$row['net']; $salesGrossTotal+=$row['net'];
        $id=$row['invoice_id']; $salesInvoices[$id]??=['id'=>$id,'date'=>$row['tax_date'],'number'=>$row['invoice_number'],'contact'=>$row['party']['name'],'net'=>0,'vat'=>0,'gross'=>0];
        $salesInvoices[$id]['net']+=$row['net']; $salesInvoices[$id]['gross']+=$row['net'];
    }
}
$vatBalance = $salesVatTotal + $deductions['reverse_vat'] + ($deductions['import_payable']??0) - $inputVatTotal + $manualAdjustments['net'];
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Käibemaksu aruanne</title>
<style>
*{box-sizing:border-box}body{max-width:1200px;margin:30px auto;padding:0 20px;background:#f4f7fb;color:#263746;font-family:Arial,sans-serif}h1,h2{color:#17324d}.card{margin:18px 0;padding:20px;background:#fff;border:1px solid #dce3ec;border-radius:12px}.filters{display:flex;flex-wrap:wrap;align-items:end;gap:12px}label{display:grid;gap:6px;font-weight:600}input{min-height:42px;padding:8px 10px;border:1px solid #b8c4d1;border-radius:7px;font:inherit}.button{display:inline-block;min-height:42px;padding:11px 15px;border:0;border-radius:7px;background:#1769aa;color:#fff;text-decoration:none;font:inherit;font-weight:600;cursor:pointer}.secondary{background:#657789}.summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.metric{padding:16px;background:#f7f9fc;border:1px solid #e1e7ef;border-radius:9px}.metric span{display:block;color:#637589;font-size:14px}.metric strong{display:block;margin-top:7px;color:#17324d;font-size:22px}.muted{color:#667788;line-height:1.5}.warning,.error{padding:11px 14px;border-radius:7px}.warning{background:#fff8e6;color:#71520a;border:1px solid #ead9a3}.error{background:#fff0f0;color:#a32121;border:1px solid #e5a3a3}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{padding:10px;border-bottom:1px solid #e5eaf0;text-align:left}th{background:#f3f6fa;color:#526579}.num{text-align:right;white-space:nowrap}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.negative{color:#a32121}.positive{color:#205c31}@media(max-width:700px){body{padding:0 12px}.summary{grid-template-columns:1fr}.table-wrap{overflow-x:auto}}
@media print{body{max-width:none;margin:0;padding:0;background:#fff}.card{break-inside:avoid;border:1px solid #bbb}.filters,.actions,.no-print{display:none!important}}
</style>
</head>
<body>
<h1>Käibemaksu aruanne</h1>
<p class="muted">Kinnitatud müügi- ja ostuarvete käibemaksu kokkuvõte valitud perioodil.</p>
<section class="card no-print">
    <form class="filters" method="get">
        <label>Alates<input type="date" name="start_date" required value="<?= h($startDate) ?>"></label>
        <label>Kuni<input type="date" name="end_date" required value="<?= h($endDate) ?>"></label>
        <button class="button" type="submit">Näita</button>
    </form>
</section>
<?php foreach ($errors as $error): ?><p class="error"><?= h($error) ?></p><?php endforeach; ?>
<?php foreach (array_unique($warnings) as $warning): ?><p class="warning"><?= h($warning) ?></p><?php endforeach; ?>
<?php if (!$errors): ?>
<section class="card">
    <h2>Perioodi kokkuvõte: <?= h($startDate) ?> kuni <?= h($endDate) ?></h2>
    <div class="summary">
        <div class="metric"><span>0% käive (3)</span><strong><?= h(euros($zeroSalesData['boxes']['3'])) ?></strong></div>
        <div class="metric"><span>EL kaup ja teenus (3.1); sellest kaup (3.1.1)</span><strong><?= h(euros($zeroSalesData['boxes']['3.1'])) ?> / <?= h(euros($zeroSalesData['boxes']['3.1.1'])) ?></strong></div>
        <div class="metric"><span>Eksport (3.2); maksuvaba käive (8)</span><strong><?= h(euros($zeroSalesData['boxes']['3.2'])) ?> / <?= h(euros($zeroSalesData['boxes']['8'])) ?></strong></div>
        <div class="metric"><span>Arvestatud KM koos pöördmaksu ja KMD-l tasutava impordiga</span><strong><?= h(euros($salesVatTotal + $deductions['reverse_vat'] + ($deductions['import_payable']??0))) ?></strong></div>
        <div class="metric"><span>Kinnitatud mahaarvatav KM (lahter 5)</span><strong><?= h(euros($inputVatTotal)) ?></strong></div>
        <div class="metric"><span>Sisendkäibemaksu parandused (+ vähendab, − suurendab)</span><strong><?= h(euros($manualAdjustments['net'])) ?></strong></div>
        <div class="metric"><span>Arvestatud KM − mahaarvatav KM koos parandustega</span><strong class="<?= $vatBalance < 0 ? 'negative' : 'positive' ?>"><?= h(euros($vatBalance)) ?></strong></div>
    </div>
    <p><a href="kmd_adjustment_register.php?year=<?= (int)substr($startDate,0,4) ?>&amp;month=<?= (int)substr($startDate,5,2) ?>">Sisendkäibemaksu paranduste register</a></p>
    <p>Mahaarvatav impordi KM (lahter 5.1): <strong><?= h(euros($deductions['import_vat']??0)) ?></strong> — sisaldub lahtris 5.
    KMD-l tasutav impordi KM (MTA täidetav 4.1): <strong><?= h(euros($deductions['import_payable']??0)) ?></strong>.
    <a href="import_vat_documents.php">Tollidokumendid ja impordi KM</a></p>
    <?php if ($deductions['reverse_vat'] > 0): ?>
    <p>pöördmaksu käibemaks: <strong><?= h(euros($deductions['reverse_vat'])) ?></strong>.
    EL ostud (lahter 6): <?= h(euros($deductions['eu_goods_net'] + $deductions['eu_services_net'])) ?>;
    sellest kaup (lahter 6.1): <?= h(euros($deductions['eu_goods_net'])) ?>.
    Muud pöördmaksu soetused (lahter 7): <?= h(euros($deductions['domestic_purchase_net'] + $deductions['non_eu_services_net'])) ?>;
    siseriiklik pöördmaksu ost (lahter 7.1): <?= h(euros($deductions['domestic_purchase_net'])) ?>.
    Tarnijale tasutav ostusumma ei suurene.</p>
    <?php endif; ?>
    <p>Põhivara mahaarvatav KM (5.2): <strong><?= h(euros($deductions['fixed_assets_vat'])) ?></strong>.
    Sõiduautod ainult ettevõtluses (5.3): <?= $deductions['number_of_cars'] ?> autot, KM <?= h(euros($deductions['cars_vat'])) ?>.
    Segakasutuses sõiduautod (5.4): <?= $deductions['number_of_cars_partial'] ?> autot, KM <?= h(euros($deductions['cars_partial_vat'])) ?>.</p>
    <p class="muted">Alamlahtrite summad sisalduvad lahtris 5, neid ei liideta sellele juurde. Autode arvud on aruandesse jäävate kuude arvude summa.
    Jaotust saad muuta KMD INF eelvaates.</p>
    <p class="muted">See vahe on arvete põhjal koostatud ülevaade, mitte automaatselt esitatav maksudeklaratsioon. Kontrolli sisendkäibemaksu mahaarvamise õigust ja muid deklaratsioonis kajastatavaid tehinguid.</p>
</section>
<section class="card">
    <h2>Müügiarved maksukoodi järgi</h2>
    <?php if (!$salesRates): ?><p class="muted">Valitud perioodis kinnitatud müügiarveid ei ole.</p><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Maksukood</th><th>KMD lahter</th><th class="num">Maksustatav summa</th><th class="num">Käibemaks</th><th class="num">Kokku</th></tr></thead><tbody>
    <?php foreach ($salesRates as $totals): ?><tr><td><?= h($totals['code'] !== '' ? $totals['code'] . ' — ' . $totals['label'] : rtrim(rtrim($totals['rate'], '0'), '.') . '% (vana arverida)') ?></td><td><?= h($totals['kmd_box'] !== '' ? $totals['kmd_box'] : 'Vajab täpsustamist') ?></td><td class="num"><?= h(euros($totals['net'])) ?></td><td class="num"><?= h(euros($totals['vat'])) ?></td><td class="num"><?= h(euros($totals['gross'])) ?></td></tr><?php endforeach; ?>
    <tr><th>Kokku</th><th></th><th class="num"><?= h(euros($salesNetTotal)) ?></th><th class="num"><?= h(euros($salesVatTotal)) ?></th><th class="num"><?= h(euros($salesGrossTotal)) ?></th></tr>
    </tbody></table></div><?php endif; ?>
</section>
<section class="card">
    <h2>Ostuarved maksukoodi järgi</h2>
    <?php if (!$purchaseRates): ?><p class="muted">Valitud perioodis kinnitatud ostuarveid ei ole.</p><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Maksukood</th><th class="num">Maksustatav summa</th><th class="num">Sisendkäibemaks</th><th class="num">Kokku</th></tr></thead><tbody>
    <?php foreach ($purchaseRates as $totals): ?><tr><td><?= h($totals['code'] !== '' ? $totals['code'] . ' — ' . $totals['label'] : rtrim(rtrim($totals['rate'], '0'), '.') . '% (vana arverida)') ?></td><td class="num"><?= h(euros($totals['net'])) ?></td><td class="num"><?= h(euros($totals['vat'])) ?></td><td class="num"><?= h(euros($totals['gross'])) ?></td></tr><?php endforeach; ?>
    <tr><th>Kokku</th><th class="num"><?= h(euros($purchaseNetTotal)) ?></th><th class="num"><?= h(euros($purchaseVatTotal)) ?></th><th class="num"><?= h(euros($purchaseGrossTotal)) ?></th></tr>
    </tbody></table></div><?php endif; ?>
</section>
<section class="card">
    <h2>Dokumendid</h2>
    <h3>Müügiarved (<?= count($salesInvoices) ?>)</h3>
    <?php if ($salesInvoices): ?><div class="table-wrap"><table><thead><tr><th>Kuupäev</th><th>Arve nr</th><th>Klient</th><th class="num">Netosumma</th><th class="num">KM</th><th class="num">Kokku</th></tr></thead><tbody>
    <?php foreach ($salesInvoices as $invoice): ?><tr><td><?= h($invoice['date']) ?></td><td><a href="invoices.php?id=<?= (int) $invoice['id'] ?>"><?= h($invoice['number']) ?></a></td><td><?= h($invoice['contact']) ?></td><td class="num"><?= h(euros($invoice['net'])) ?></td><td class="num"><?= h(euros($invoice['vat'])) ?></td><td class="num"><?= h(euros($invoice['gross'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php else: ?><p class="muted">Müügiarveid pole.</p><?php endif; ?>
    <p><a class="button secondary" href="kmd_inf_preview.php">Kontrolli ostuarvete mahaarvatavaid summasid</a></p>
    <?php if (!empty($deductions['scheduled'])): ?>
    <h3>Mahaarvamise jaotus kuude vahel</h3>
    <div class="table-wrap"><table>
        <thead><tr><th>Mahaarvamise kuu</th><th>Ostuarve</th><th>Arve kuupäev</th><th class="num">Kuu mahaarvatav KM</th></tr></thead>
        <tbody>
        <?php foreach ($deductions['scheduled'] as $row): ?>
            <tr><td><?= h(substr($row['report_date'], 0, 7)) ?></td>
            <td><a href="purchase_vat_period_edit.php?id=<?= (int)$row['id'] ?>"><?= h($row['document_number']) ?></a></td>
            <td><?= h($row['document_date']) ?></td><td class="num"><?= h(euros($row['deductible_cents'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
    <h3>Ostuarved (<?= count($purchaseInvoices) ?>)</h3>
    <?php if ($purchaseInvoices): ?><div class="table-wrap"><table><thead><tr><th>Kuupäev</th><th>Arve nr</th><th>Tarnija</th><th class="num">Netosumma</th><th class="num">Arve KM</th><th class="num">Mahaarvatav KM</th><th class="num">Kokku</th></tr></thead><tbody>
    <?php foreach ($purchaseInvoices as $invoice): ?><tr><td><?= h($invoice['date']) ?></td><td><a href="purchase_invoices.php?id=<?= (int) $invoice['id'] ?>"><?= h($invoice['number']) ?></a></td><td><?= h($invoice['contact']) ?></td><td class="num"><?= h(euros($invoice['net'])) ?></td><td class="num"><?= h(euros($invoice['vat'])) ?></td><td class="num"><?= isset($deductions['invoices'][$invoice['id']]['deductible_cents']) ? h(euros($deductions['invoices'][$invoice['id']]['deductible_cents'])) : (isset($deductions['planned_invoice_ids'][$invoice['id']]) ? 'Jaotatud teise kuusse' : 'Kinnitamata') ?></td><td class="num"><?= h(euros($invoice['gross'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php else: ?><p class="muted">Ostuarveid pole.</p><?php endif; ?>
</section>
<?php endif; ?>
<div class="actions no-print"><button class="button secondary" type="button" onclick="window.print()">Prindi / salvesta PDF</button><a class="button secondary" href="kmd_export.php">KMD ettevalmistus</a><a class="button secondary" href="index.php">Avalehele</a></div>
</body>
</html>
