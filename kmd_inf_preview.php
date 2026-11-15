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
ensureInfConfirmationSchema($pdo);
require_once __DIR__ . '/period_lock.php';
if (empty($_SESSION['kmd_inf_csrf'])) {
    $_SESSION['kmd_inf_csrf'] = bin2hex(random_bytes(32));
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

require_once __DIR__ . '/kmd_inf_data.php';
require_once __DIR__ . '/cash_sales_vat.php';

function applyInfThreshold(array $invoices): array
{
    $partnerTotals = [];
    $partnerCreditTotals = [];
    $partnerAllTotals = [];
    foreach ($invoices as $invoice) $partnerAllTotals[$invoice['partner_key']] = ($partnerAllTotals[$invoice['partner_key']] ?? 0) + $invoice['net'];
    foreach ($invoices as $invoice) {
        if ((!empty($invoice['deduction_confirmed']) && infPurchaseDeduction($invoice) === 0 && empty($invoice['cash_company_purchase'])) || !$invoice['qualifying_rates'] || $invoice['net'] === 0 || $invoice['party']['kind'] === 'person') {
            continue;
        }
        $key = $invoice['partner_key'];
        if($invoice['net']>0) $partnerTotals[$key] = ($partnerTotals[$key] ?? 0) + $invoice['net'];
        else $partnerCreditTotals[$key]=($partnerCreditTotals[$key]??0)+abs($invoice['net']);
    }
    foreach ($invoices as &$invoice) {
        $invoice['partner_total'] = $partnerTotals[$invoice['partner_key']] ?? 0;
        $invoice['partner_period_net'] = $partnerAllTotals[$invoice['partner_key']] ?? 0;
        $invoice['partner_credit_total']=$partnerCreditTotals[$invoice['partner_key']]??0;
        $invoice['threshold_met'] = $invoice['partner_total'] >= 100000 || $invoice['partner_credit_total']>=100000;
        $invoice['reportable_candidate'] = (!empty($invoice['cash_company_purchase']) || empty($invoice['deduction_confirmed']) || infPurchaseDeduction($invoice) !== 0) && $invoice['qualifying_rates'] !== []
            && $invoice['threshold_met']
            && $invoice['party']['kind'] !== 'person';
        if (!empty($invoice['zero_sales'])) {
            $invoice['status_label']='0% / maksuvaba müük — INF A-ossa ei kanta';
        } elseif (!empty($invoice['import_goods'])) {
            $invoice['status_label']='Kauba import — KM tollidokumendilt, INF B-ossa ei kanta';
        } elseif (!empty($invoice['reverse_line_count']) && ($invoice['reverse_scope'] ?? '') !== 'domestic' && !$invoice['qualifying_rates']) {
            $invoice['status_label'] = !empty($invoice['deduction_confirmed']) ? 'Kontrollitud — välisostu pöördmaks, INF B-ossa ei kanta' : 'Välisostu pöördmaks — kinnita mahaarvatav summa';
        } elseif (!empty($invoice['deduction_confirmed']) && $invoice['deductible_cents'] === 0) {
            $invoice['status_label'] = 'Kontrollitud — mahaarvamist ei ole';
        } elseif ($invoice['party']['kind'] === 'person') {
            $invoice['status_label'] = 'Eraisik — KMD INF-i ei kanta';
        } elseif (!$invoice['qualifying_rates']) {
            $invoice['status_label'] = 'KMD INF määra ei leitud';
        } elseif ($invoice['party']['kind'] === 'unknown') {
            $invoice['status_label'] = 'Partneri liik vajab kontrolli';
        } elseif (!$invoice['threshold_met']) {
            $invoice['status_label'] = !empty($invoice['deduction_confirmed']) ? 'Kontrollitud — INF piirmäärast allpool' : 'Partneri netosumma jääb alla 1 000 €';
        } elseif ($invoice['review']) {
            $invoice['status_label'] = 'Kandidaat — kontrolli märkusi';
        } else {
            $invoice['status_label'] = !empty($invoice['deduction_confirmed']) ? 'Kontrollitud — mahaarvatav summa kinnitatud' : 'KMD INF-i kandidaat';
        }
    }
    unset($invoice);
    return $invoices;
}

function infCsvCell(string $value): string
{
    // MTA's CSV format is semicolon-separated and follows the normal CSV
    // quoting rules for partner names and invoice numbers containing quotes.
    return '"' . str_replace('"', '""', $value) . '"';
}

function infCsvMoney(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

$companyId = (int) $_SESSION['company_id'];
$year = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT);
$month = filter_input(INPUT_GET, 'month', FILTER_VALIDATE_INT);
$currentYear = (int) date('Y');
$year = $year && $year >= 2000 && $year <= 2100 ? $year : $currentYear;
$month = $month && $month >= 1 && $month <= 12 ? $month : (int) date('n');
$period = sprintf('%04d-%02d', $year, $month);
$usesDataBasedKmd = $period >= '2027-04';
$startDate = $period . '-01';
$endDate = date('Y-m-t', strtotime($startDate));

$salesResult = loadInfRows($pdo, $companyId, $startDate, $endDate, 'sales');
$cashSales = loadCashSales($pdo,$companyId,$startDate,$endDate);
$salesResult['invoices'] = array_merge(
    array_filter($salesResult['invoices'], static fn(array $invoice): bool => !isset($cashSales['ids'][$invoice['id']])),
    $cashSales['invoices']
);
$purchaseResult = loadPurchasePeriodRows($pdo, $companyId, $startDate, $endDate);
$sales = applyInfThreshold($salesResult['invoices']);
$purchases = applyInfThreshold(applyInfConfirmations($pdo, $companyId, $period, $purchaseResult['invoices']));
$vatLedgerQuery=$pdo->prepare('SELECT invoice_id,amount_cents,deductible_cents,last_entry_id FROM purchase_vat_adjustments WHERE company_id=?');
$vatLedgerQuery->execute([$companyId]); $vatLedgerStates=$vatLedgerQuery->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
$errors = array_merge($salesResult['errors'], $purchaseResult['errors'], $cashSales['errors']);
$salesCandidates = count(array_filter($sales, static fn(array $invoice): bool => $invoice['reportable_candidate']));
$purchaseCandidates = count(array_filter($purchases, static fn(array $invoice): bool => $invoice['reportable_candidate']));
$salesReviewCount = count(array_filter($sales, static fn(array $invoice): bool => $invoice['reportable_candidate'] && $invoice['review']));
$reviewCount = count(array_filter(array_merge($sales, $purchases), static fn(array $invoice): bool => $invoice['reportable_candidate'] && $invoice['review']));

$companyStmt = $pdo->prepare('SELECT registry_code FROM companies WHERE id = ?');
$companyStmt->execute([$companyId]);
$companyRegistryCode = trim((string) $companyStmt->fetchColumn());
$cleanSalesCandidates = array_values(array_filter($sales, static fn(array $invoice): bool => $invoice['reportable_candidate'] && !$invoice['review']));
$salesExportReady = !$usesDataBasedKmd && $cleanSalesCandidates !== [] && $salesReviewCount === 0 && preg_match('/^\\d{8}$/', $companyRegistryCode) === 1;
$purchaseExportIssues = [];
$salesExportReady = $salesExportReady && !$cashSales['errors'];
$purchaseExportCandidates = [];
foreach ($purchases as $invoice) {
    if (!$invoice['reportable_candidate']) {
        continue;
    }
    $candidateIssues = array_values(array_filter(
        $invoice['review'],
        static fn(string $note): bool => $note !== 'Kontrolli, kas arve sisendkäibemaks kuulub KMD lahtris 5 mahaarvamisele.'
    ));
    $registeredAbroad = foreignPurchaseEstonianVat($invoice) !== null;
    if ($invoice['party']['kind'] !== 'business' || ($invoice['country_code'] !== 'EE' && !$registeredAbroad)) {
        $candidateIssues[] = 'B-osa jaoks on vaja tarnija Eesti käibemaksukohustuslase andmeid.';
    }
    if (preg_replace('/\\s+/', '', (string) $invoice['registry_code']) === '') {
        $candidateIssues[] = 'Tarnija registrikood puudub.';
    }
    if ($invoice['country_code'] !== 'EE' && ($invoice['kmd_identifier_type'] ?? '') !== 'MRR_KOOD') {
        $candidateIssues[] = 'Lisa tarnija Eesti mitteresidendi kood ja vali KMD tunnuse liigiks mitteresidendi kood.';
    }
    if (!$registeredAbroad && preg_replace('/\\s+/', '', (string) $invoice['vat_number']) === '') {
        $candidateIssues[] = 'Tarnija Eesti käibemaksukohustuslase number puudub.';
    }
    if ($candidateIssues) {
        $purchaseExportIssues[$invoice['id']] = array_values(array_unique($candidateIssues));
    } else {
        $purchaseExportCandidates[] = $invoice;
    }
}
$purchaseExportReady = !$purchaseResult['errors'] && !$usesDataBasedKmd && $purchaseExportCandidates !== [] && !$purchaseExportIssues
    && preg_match('/^\\d{8}$/', $companyRegistryCode) === 1;

if ($usesDataBasedKmd && in_array((string) ($_GET['download'] ?? ''), ['sales', 'purchases'], true)) {
    http_response_code(422);
    exit('Alates 2027. aasta aprilli perioodist vana KMD INF A/B eksport enam ei kehti. Kasuta andmepõhise KMD ettevalmistust.');
}

if (($_GET['download'] ?? '') === 'sales') {
    if (!$salesExportReady) {
        http_response_code(422);
        exit('Müügi KMD INF CSV-d ei saa koostada: kontrolli märkustega kandidaadid ja ettevõtte registrikood üle.');
    }

    $cashVatPeriod = companyCashVatPeriod($pdo,$companyId);
    $companyCash = companyUsesCashVat($cashVatPeriod,$startDate);
    $csvRows = [];
    foreach ($cleanSalesCandidates as $invoice) {
        $identifierType = (string) ($invoice['kmd_identifier_type'] ?? '');
        if ($identifierType === 'KMKR_NUMBER') {
            $partnerIdentifier = preg_replace('/\\s+/', '', (string) ($invoice['vat_number'] ?? ''));
        } elseif (in_array($identifierType, ['ARIREGISTRIKOOD', 'ISIKUKOOD', 'MRR_KOOD'], true)) {
            $partnerIdentifier = preg_replace('/\\s+/', '', (string) ($invoice['registry_code'] ?? ''));
        } else {
            // Legacy contacts have no identifier-type setting; only use a
            // plainly valid Estonian business registry code as a safe default.
            $partnerIdentifier = preg_replace('/\\s+/', '', (string) ($invoice['registry_code'] ?? ''));
        }
        if ($partnerIdentifier === '' || strlen($partnerIdentifier) > 20) {
            http_response_code(422);
            exit('Müügi KMD INF CSV-d ei saa koostada: vähemalt ühe partneri tunnus puudub või on liiga pikk.');
        }
        $taxRates = array_values(array_intersect($invoice['qualifying_rates'], ['24.00', '13.00', '9.00', '5.00', '22.00', '20.00']));
        foreach ($taxRates as $rate) {
            $rateLabel = rtrim(rtrim($rate, '0'), '.');
            $base = (int) ($invoice['taxable_by_rate'][$rate] ?? 0);
            $values = [
                'A', $partnerIdentifier, (string) $invoice['contact_name'],
                (string) $invoice['document_number'],
                date('d.m.Y', strtotime((string) $invoice['document_date'])),
                infCsvMoney((int) $invoice['net']), $rateLabel,
                !empty($invoice['cash_sale']) ? infCsvMoney($invoice['net']) : ($companyCash ? infCsvMoney($base) : ''),
                ($invoice['reverse_scope'] ?? '') === 'domestic' ? '' : infCsvMoney($base), ($invoice['reverse_scope'] ?? '') === 'domestic' ? '02' : '',
            ];
            $csvRows[] = implode(';', array_map(static fn(string $value): string => infCsvCell($value), $values));
        }
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="kmd-inf-A-' . $period . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF" . implode("\r\n", $csvRows) . "\r\n";
    exit;
}

$deductionCandidates = $purchaseResult['errors'] ? [] : array_values(array_filter($purchases,
    static fn(array $invoice): bool => !isset($invoice['period_plan_total']) && ($invoice['deduction_vat'] ?? $invoice['vat']) > 0 && $invoice['net'] >= 0
        && ($invoice['country_code'] === 'EE' || !empty($invoice['reverse_line_count']) || foreignPurchaseEstonianVat($invoice) !== null)
        && $invoice['party']['kind'] === 'business'
        && !array_filter($invoice['review'], static fn(string $note): bool =>
            $note !== 'Kontrolli, kas arve sisendkäibemaks kuulub KMD lahtris 5 mahaarvamisele.')));
$deductionCandidateIds = array_column($deductionCandidates, 'id');
$batchCandidates = array_values(array_filter($deductionCandidates, static fn(array $invoice): bool =>
    empty($invoice['deduction_confirmed']) && empty($invoice['requires_line_vat'])));
$manualCars=loadVatCars($pdo,$companyId,$period);
$previousCarPeriod = (new DateTimeImmutable($startDate))->modify('first day of previous month')->format('Y-m');
$previousCars = loadVatCars($pdo, $companyId, $previousCarPeriod);
$carTotals=vatCarTotals($purchases,$manualCars);
$errors=array_merge($errors,$carTotals['errors']);
$purchaseExportReady=$purchaseExportReady && !$carTotals['errors'];
$confirmationSnapshot = hash('sha256', json_encode(array_map(
    static fn(array $invoice): array => [$invoice['id'], $invoice['confirmation_hash']],
    $deductionCandidates
), JSON_THROW_ON_ERROR));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['kmd_inf_csrf'],(string)($_POST['csrf']??''))) { http_response_code(422); exit('Vorm aegus. Värskenda lehte.'); }
    $periodMessage=closedAccountingPeriodMessage($pdo,$companyId,$startDate);
    if ($periodMessage!==null) { http_response_code(422); exit(h($periodMessage)); }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'copy_previous_cars') {
        try {
            $copied = copyPreviousVatCars($pdo, $companyId, $period, $purchases);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            exit(h($exception->getMessage()));
        }
        $_SESSION['vat_car_copy_result'] = ['company_id'=>$companyId, 'period'=>$period, 'count'=>$copied];
        header('Location: kmd_inf_preview.php?year='.$year.'&month='.$month.'#period-cars');
        exit;
    }
    if (in_array($action,['save_car','remove_car'],true)) {
        try {
            $ids=vatCarIds((string)($_POST['car_identifier']??''));
            if(count($ids)!==1) throw new InvalidArgumentException('Sisesta üks sõiduauto registreerimismärk või VIN.');
            if($action==='save_car') {
                $usage=(string)($_POST['usage_kind']??'');
                if(!in_array($usage,['full','partial'],true)) throw new InvalidArgumentException('Vali sõiduauto kasutusliik.');
                $proposed=array_values(array_filter($manualCars,static fn(array $car):bool=>$car['car_identifier']!==$ids[0]));
                $proposed[]=['car_identifier'=>$ids[0],'usage_kind'=>$usage];
                $check=vatCarTotals($purchases,$proposed);
                if($check['errors']) throw new InvalidArgumentException(implode(' ',$check['errors']));
                saveVatCar($pdo,$companyId,$period,$ids[0],$usage);
            } else {
                $stmt=$pdo->prepare('DELETE FROM kmd_vat_period_cars WHERE company_id = ? AND period = ? AND car_identifier = ?');
                $stmt->execute([$companyId,$period,$ids[0]]);
            }
        } catch(InvalidArgumentException $exception) { http_response_code(422); exit(h($exception->getMessage())); }
        header('Location: kmd_inf_preview.php?year='.$year.'&month='.$month.'#period-cars'); exit;
    }
    $toConfirm = [];
    if ($action === 'confirm_purchase') {
        foreach ($deductionCandidates as $candidate) {
            if ((string) $candidate['id'] === (string) ($_POST['invoice_id'] ?? '')
                && hash_equals($candidate['confirmation_hash'], (string) ($_POST['snapshot'] ?? ''))) {
                try {
                    unset($candidate['vat_line_allocations']);
                    if (!empty($_POST['use_line_vat']) || !empty($candidate['requires_line_vat'])) {
                        $posted = is_array($_POST['vat_line'] ?? null) ? $_POST['vat_line'] : [];
                        $allocations = [];
                        foreach ($candidate['vat_input_lines'] ?? [] as $id=>$line) {
                            $data = $posted[$id] ?? [];
                            $allocations[$id] = [
                                'deductible_cents'=>parseDeductibleCents((string)($data['amount'] ?? ''),$line['vat']),
                                'vat_purpose'=>(string)($data['purpose'] ?? ''),
                                'car_ids'=>vatCarIds((string)($data['cars'] ?? '')),
                            ];
                        }
                        $candidate['vat_line_allocations'] = validatePurchaseVatLines($candidate,$allocations);
                        $candidate['deductible_cents'] = array_sum(array_column($allocations,'deductible_cents'));
                        $candidate = validateVatPurpose($candidate,'ordinary','');
                    } else {
                        $candidate['deductible_cents'] = parseDeductibleCents((string) ($_POST['deductible_amount'] ?? ''), (int) ($candidate['deduction_vat'] ?? $candidate['vat']));
                        $candidate=validateVatPurpose($candidate,(string)($_POST['vat_purpose']??'ordinary'),(string)($_POST['car_ids']??''));
                    }
                    $proposed=$purchases;
                    foreach($proposed as &$proposedInvoice) if($proposedInvoice['id']===$candidate['id']) {
                        $proposedInvoice=$candidate; $proposedInvoice['deduction_confirmed']=true;
                    }
                    unset($proposedInvoice);
                    $check=vatCarTotals($proposed,$manualCars);
                    if($check['errors']) throw new InvalidArgumentException(implode(' ',$check['errors']));
                    $toConfirm = [$candidate];
                } catch (InvalidArgumentException $exception) {
                    http_response_code(422);
                    exit(h($exception->getMessage()));
                }
                break;
            }
        }
    } elseif ($action === 'confirm_purchases' && $deductionCandidates !== []
        && hash_equals($confirmationSnapshot, (string) ($_POST['snapshot'] ?? ''))) {
        $toConfirm = $batchCandidates;
    }
    if (!hash_equals($_SESSION['kmd_inf_csrf'], (string) ($_POST['csrf'] ?? '')) || !$toConfirm) {
        http_response_code(422);
        exit('Kinnitust ei salvestatud. Ava eelvaade uuesti ja kontrolli ostuarvete märkusi.');
    }
    foreach($toConfirm as &$item) {
        try { $item=validateVatPurpose($item,$item['vat_purpose']??'ordinary',implode(',',$item['car_ids']??[])); }
        catch(InvalidArgumentException $exception) { http_response_code(422); exit(h($exception->getMessage())); }
    }
    unset($item);
    $proposed=$purchases;
    foreach($toConfirm as $item) foreach($proposed as &$proposedInvoice) {
        if($proposedInvoice['id']===$item['id']) { $proposedInvoice=$item; $proposedInvoice['deduction_confirmed']=true; }
    }
    unset($proposedInvoice);
    $check=vatCarTotals($proposed,$manualCars);
    if($check['errors']) { http_response_code(422); exit(h(implode(' ',$check['errors']))); }
    try { saveInfConfirmations($pdo, $companyId, $period, (int) $_SESSION['user_id'], $toConfirm); }
    catch(Throwable $exception) { http_response_code(422); if($exception instanceof PDOException) { error_log($exception->getMessage()); exit('Käibemaksu kinnitust ja pearaamatu parandust ei salvestatud. Kontrolli arve andmeid ja proovi uuesti.'); } exit(h($exception->getMessage())); }
    header('Location: kmd_inf_preview.php?year=' . $year . '&month=' . $month . '#purchase-invoices');
    exit;
}
$allPurchasesConfirmed = $purchaseExportCandidates !== [];
foreach ($purchaseExportCandidates as $invoice) {
    $allPurchasesConfirmed = $allPurchasesConfirmed && !empty($invoice['deduction_confirmed']);
}

if (($_GET['download'] ?? '') === 'purchases') {
    if ($carTotals['errors'] || !$allPurchasesConfirmed || !$purchaseExportReady) {
        http_response_code(422);
        exit('Ostude KMD INF CSV vajab kinnitust ja sobivaid kandidaate. Kontrolli, et eksporditavate arvete mahaarvatavad summad on selle perioodi jaoks kinnitatud.');
    }

    $csvRows = [];
    foreach ($purchaseExportCandidates as $invoice) {
        $supplierIdentifier = preg_replace('/\\s+/', '', (string) $invoice['registry_code']);
        if ($supplierIdentifier === '' || strlen($supplierIdentifier) > 20) {
            http_response_code(422);
            exit('Ostude KMD INF CSV-d ei saa koostada: vähemalt ühe tarnija registrikood puudub või on liiga pikk.');
        }
        $invoiceVat = (int) ($invoice['reverse_scope'] === 'domestic' ? $invoice['deduction_vat'] : $invoice['vat']);
        $values = [
            'B', $supplierIdentifier, (string) $invoice['contact_name'],
            (string) $invoice['document_number'],
            date('d.m.Y', strtotime((string) $invoice['document_date'])),
            infCsvMoney((int) $invoice['gross'] + (($invoice['reverse_scope'] ?? '') === 'domestic' ? $invoice['reverse_info_vat'] : 0)),
            infCsvMoney($invoiceVat),
            infCsvMoney(infPurchaseDeduction($invoice)),
            implode(',', array_filter([
                abs(infPurchaseDeduction($invoice, true)) < abs($invoiceVat) ? '11' : '',
                ($invoice['reverse_scope'] ?? '') === 'domestic' ? '12' : '',
            ])),
        ];
        $csvRows[] = implode(';', array_map(static fn(string $value): string => infCsvCell($value), $values));
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="kmd-inf-B-' . $period . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF" . implode("\r\n", $csvRows) . "\r\n";
    exit;
}
?>
<!doctype html>
<html lang="et">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>KMD INF eelvaade</title>
<style>
*{box-sizing:border-box}body{max-width:1250px;margin:32px auto;padding:0 20px;background:#f4f7fb;color:#263746;font-family:Arial,sans-serif}h1,h2{color:#17324d}.card{margin:18px 0;padding:20px;background:#fff;border:1px solid #dce3ec;border-radius:12px}.muted{color:#667788;line-height:1.5}.filters,.actions{display:flex;align-items:end;gap:12px;flex-wrap:wrap}label{display:grid;gap:6px;font-weight:600}input,select{min-height:42px;padding:8px 10px;border:1px solid #b8c4d1;border-radius:7px;font:inherit}.button{display:inline-block;min-height:40px;padding:10px 14px;border:0;border-radius:7px;background:#1769aa;color:#fff;text-decoration:none;font:inherit;font-weight:600;cursor:pointer}.secondary{background:#657789}.button:disabled{background:#657789;opacity:.65;cursor:not-allowed}.row-confirm{margin-top:10px}.row-confirm .button{font-size:13px;min-height:34px;padding:8px 10px}.notice{padding:12px 14px;background:#fff8e6;border:1px solid #ead9a3;border-radius:8px;color:#71520a;line-height:1.5}.summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.metric{padding:14px;background:#f7f9fc;border:1px solid #e1e7ef;border-radius:9px}.metric span{display:block;color:#637589;font-size:14px}.metric strong{display:block;margin-top:5px;color:#17324d;font-size:22px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{padding:9px;border-bottom:1px solid #e5eaf0;text-align:left;vertical-align:top}th{background:#f3f6fa;color:#526579}.num{text-align:right;white-space:nowrap}.status{font-weight:600}.good{color:#176236}.warn{color:#8a5a00}.bad{color:#a32121}.small{font-size:13px;color:#667788;line-height:1.4}@media(max-width:700px){body{padding:0 12px}.summary{grid-template-columns:1fr}}
</style>
</head>
<body>
<h1>KMD INF eelvaade</h1>
<p class="muted">Kinnitatud müügi- ja ostuarvete esialgne kontroll valitud maksustamisperioodil.</p>
<section class="card">
    <form class="filters" method="get">
        <label>Aasta<select name="year"><?php for ($y = $currentYear - 5; $y <= $currentYear + 1; $y++): ?><option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></label>
        <label>Kuu<select name="month"><?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= sprintf('%02d', $m) ?></option><?php endfor; ?></select></label>
        <button class="button" type="submit">Näita</button>
    </form>
    <?php if ($usesDataBasedKmd): ?>
        <p class="notice">Alates 2027. aasta aprilli perioodist asendub vana KMD INF A/B andmepõhise KMD-ga. Selle perioodi andmed koostatakse <a href="kmd_export.php?year=<?= (int) $year ?>&amp;month=<?= (int) $month ?>">andmepõhise KMD ettevalmistuse lehel</a>; siin vana vormi CSV-faile ei pakuta.</p>
    <?php else: ?>
        <p class="notice">Eelvaade kasutab kinnitatud arveid ning salvestatud mahaarvamisperioode. Kassapõhise arvestuse summad sõltuvad registreeritud maksetest. Arvete ja kreeditarvete piirmäära arvestatakse eraldi. Ostude B-osa CSV jaoks peab selle perioodi mahaarvatav käibemaksusumma olema kinnitatud. Kontrolli märkusi enne faili laadimist.</p>
    <?php endif; ?>
</section>
<?php foreach ($errors as $error): ?><p class="notice"><?= h($error) ?></p><?php endforeach; ?>
<section class="card">
    <h2>Periood <?= h($period) ?></h2>
    <div class="summary">
        <div class="metric"><span>Müügiarvete KMD INF kandidaadid</span><strong><?= $salesCandidates ?></strong></div>
        <div class="metric"><span>Ostuarvete KMD INF kandidaadid</span><strong><?= $purchaseCandidates ?></strong></div>
        <div class="metric"><span>Kandidaadid märkustega</span><strong><?= $reviewCount ?></strong></div>
    </div>
    <p class="actions">
        <?php if ($salesExportReady): ?><a class="button" href="?year=<?= (int) $year ?>&amp;month=<?= (int) $month ?>&amp;download=sales">Laadi müügi A-osa CSV</a>
        <?php else: ?><span class="small">Müügi A-osa faili saab laadida siis, kui kandidaatidel pole kontrollmärkusi ja ettevõtte registrikood on sisestatud.</span><?php endif; ?>
        <?php if ($purchaseExportReady && $allPurchasesConfirmed): ?><a class="button" href="?year=<?= (int) $year ?>&amp;month=<?= (int) $month ?>&amp;download=purchases">Laadi ostude B-osa CSV</a>
        <?php else: ?><button class="button" type="button" disabled aria-describedby="purchase-csv-reason">Laadi ostude B-osa CSV</button>
        <?php if ($purchaseExportReady): ?><span class="small" id="purchase-csv-reason">Ostude CSV laadimiseks kinnita allpool sisendkäibemaksu kontroll.</span>
        <?php elseif ($purchaseExportCandidates): ?><span class="small" id="purchase-csv-reason">Ostude B-osa CSV on peatatud, kuni tarnijate tunnused ja kontrollmärkused on korras.</span>
        <?php else: ?><span class="small" id="purchase-csv-reason">Ostude B-osa kandidaate pole või nende partneriandmed vajavad täiendamist.</span><?php endif; ?>
        <?php endif; ?>
    </p>
    <?php if ($batchCandidates): ?>
    <form method="post" action="?year=<?= (int) $year ?>&amp;month=<?= (int) $month ?>" onsubmit="return confirm('Kas kinnitad, et kõigi veel kontrollimata sobivate ostuarvete käibemaks on valitud perioodis täielikult mahaarvatav? Varem kinnitatud summad jäävad alles.')">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['kmd_inf_csrf']) ?>">
        <input type="hidden" name="action" value="confirm_purchases">
        <input type="hidden" name="snapshot" value="<?= h($confirmationSnapshot) ?>">
        <button class="button" type="submit">Kinnita ülejäänud ostuarved täies ulatuses</button>
        <p class="small">Kinnitus salvestatakse arvete kaupa, ka INF piirmäärast allpool. Osalise mahaarvamise summa määra arve real. See valik ei muuda pearaamatu kandeid. Varem kinnitatud summad jäävad alles. Arve või partneri andmete muutmisel tuleb kontroll uuesti kinnitada.</p>
    </form>
    <?php endif; ?>
</section>
<section class="card" id="period-cars">
    <h2>Sõiduautod ja põhivara — <?= h($period) ?></h2>
    <?php $copyResult = $_SESSION['vat_car_copy_result'] ?? null; ?>
    <?php if ($copyResult && $copyResult['company_id'] === $companyId && $copyResult['period'] === $period): ?>
        <p class="notice" role="status"><?= $copyResult['count'] > 0
            ? 'Lisatud autosid: '.(int)$copyResult['count'].'. Vaata selle kuu nimekiri üle.'
            : 'Eelmisest kuust polnud ühtegi uut autot lisada.' ?></p>
        <?php unset($_SESSION['vat_car_copy_result']); ?>
    <?php endif; ?>
    <p>Põhivara mahaarvatav KM (5.2): <strong><?= h(infEuros($carTotals['fixed_assets_vat'])) ?></strong>.
    Ainult ettevõtluses kasutatavad sõiduautod (5.3): <strong><?= $carTotals['number_of_cars'] ?></strong>, KM <?= h(infEuros($carTotals['cars_vat'])) ?>.
    Segakasutuses sõiduautod (5.4): <strong><?= $carTotals['number_of_cars_partial'] ?></strong>, KM <?= h(infEuros($carTotals['cars_partial_vat'])) ?>.</p>
    <p class="small">Arve real vali põhivara või sõiduauto jaotus ja kinnita mahaarvatav summa. Sobival mitmerealisel Eesti ostuarvel saab mahaarvamise ja käsitluse määrata arveridade kaupa.
    Allpool lisa ka selle kuu autod, mille kohta ostuarvet ei ole. Sama reg-märk või VIN loetakse kuus üks kord.
    Kasuta igal pool sama tunnust. Nimekiri on kuupõhine, järgmise kuu omad tuleb eraldi kinnitada.</p>
    <?php if ($previousCars): ?>
        <details>
            <summary>Vaata eelmise kuu (<?= h($previousCarPeriod) ?>) käsitsi lisatud autosid</summary>
            <ul><?php foreach ($previousCars as $car): ?>
                <li><?= h($car['car_identifier']) ?> — <?= $car['usage_kind'] === 'full' ? 'ainult ettevõtluses' : 'segakasutus' ?></li>
            <?php endforeach; ?></ul>
            <p class="small">Lisatakse autod, mille tunnust pole selle kuu nimekirjas ega kinnitatud arvetel.</p>
            <form method="post" action="?year=<?= $year ?>&amp;month=<?= $month ?>">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['kmd_inf_csrf']) ?>">
                <button class="button secondary" type="submit" name="action" value="copy_previous_cars">Lisa eelmise kuu autod</button>
            </form>
        </details>
    <?php endif; ?>
    <form class="filters" method="post" action="?year=<?= $year ?>&amp;month=<?= $month ?>">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['kmd_inf_csrf']) ?>">
        <input type="hidden" name="action" value="save_car">
        <label>Reg-märk või VIN<input name="car_identifier" maxlength="20" required></label>
        <label>Kasutus<select name="usage_kind"><option value="full">Ainult ettevõtluses — 5.3</option><option value="partial">Segakasutus — 5.4</option></select></label>
        <button class="button" type="submit">Salvesta sõiduauto kuusse</button>
    </form>
    <?php if($manualCars): ?><ul><?php foreach($manualCars as $car): ?>
        <li><?= h($car['car_identifier']) ?> — <?= $car['usage_kind']==='full'?'ainult ettevõtluses':'segakasutus' ?>
        <form method="post" action="?year=<?= $year ?>&amp;month=<?= $month ?>" style="display:inline">
            <input type="hidden" name="csrf" value="<?= h($_SESSION['kmd_inf_csrf']) ?>">
            <input type="hidden" name="action" value="remove_car">
            <input type="hidden" name="car_identifier" value="<?= h($car['car_identifier']) ?>">
            <button class="button secondary" type="submit">Eemalda nimekirjast</button>
        </form></li>
    <?php endforeach; ?></ul><?php endif; ?>
    <p class="small">Nimekirjast eemaldamine ei eemalda autot arve jaotusest. Need valikud muudavad KMD aruandlust, mitte põhivara kaarti ega pearaamatu kandeid.
    Segakasutuses auto puhul saab maha arvata kuni 50% käibemaksust; vajadusel määra väiksem summa.</p>
</section>
<?php foreach ([['Müügiarved — A-osa kandidaadid', $sales, 'sales'], ['Ostuarved — mahaarvamise kontroll ja B-osa kandidaadid', $purchases, 'purchase']] as [$heading, $rows, $invoiceType]): ?>
<section class="card" <?= $invoiceType === 'purchase' ? 'id="purchase-invoices"' : '' ?>>
    <h2><?= h($heading) ?></h2>
    <?php if (!$rows): ?><p class="muted">Valitud perioodis kinnitatud arveid ei ole.</p><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Kuupäev / arve nr</th><th>Partner ja tunnus</th><th>Määrad</th><th class="num">Arve neto</th><th class="num">Partneri perioodi neto</th><th>Staatus / kontroll</th></tr></thead><tbody>
    <?php foreach ($rows as $invoice): ?>
        <?php $statusClass = $invoice['party']['kind'] === 'person' ? 'bad' : (((!empty($invoice['deduction_confirmed']) || $invoice['reportable_candidate']) && !$invoice['review']) ? 'good' : 'warn'); ?>
        <tr>
            <td><?= h($invoice['document_date']) ?><br><strong><a href="<?= $invoiceType === 'sales' ? 'invoices.php' : 'purchase_invoices.php' ?>?id=<?= (int) $invoice['id'] ?>"><?= h($invoice['document_number']) ?></a></strong></td>
            <td><?= h($invoice['contact_name']) ?><br><span class="small"><?= h($invoice['registry_code'] ?: $invoice['vat_number'] ?: 'Tunnus puudub') ?> · <?= h($invoice['party']['label']) ?></span></td>
            <td><?= h(implode(', ', array_map(static fn(string $rate): string => rtrim(rtrim($rate, '0'), '.') . '%', $invoice['rates']))) ?><br><span class="small"><?= h(implode(', ', $invoice['codes']) ?: 'Vana arverida, maksukood puudub') ?></span></td>
            <td class="num"><?= h(infEuros($invoice['net'])) ?></td>
            <td class="num"><?= h(infEuros($invoice['partner_period_net'] ?? $invoice['partner_total'])) ?></td>
            <td><span class="status <?= $statusClass ?>"><?= h($invoice['status_label']) ?></span><?php if(!empty($invoice['credit_link'])): ?><p class="small">Kreeditarve algsele arvele <?= h($invoice['credit_link']['original_number']) ?></p><?php endif; ?><?php if ($invoice['review']): ?><ul class="small"><?php foreach ($invoice['review'] as $note): ?><li><?= h($note) ?></li><?php endforeach; ?></ul><?php endif; ?>
                <?php if($invoiceType==='purchase' && isset($invoice['period_plan_total'])): ?>
                <p class="small"><?= !empty($invoice['cash_supplier']) ? 'Kassapõhine ost. Selle kuu mahaarvamine' : 'Selle kuu mahaarvamine' ?>: <?= h(infEuros($invoice['deductible_cents'])) ?>.
                    <a href="purchase_vat_period_edit.php?id=<?= (int)$invoice['id'] ?>">Vaata kuude jaotust</a></p>
                <?php elseif($invoiceType==='purchase' && !empty($invoice['deduction_confirmed'])): ?>
                <?php $nonDeductible=(int)($invoice['deduction_vat']??$invoice['vat'])-(int)$invoice['deductible_cents']; $ledger=$vatLedgerStates[$invoice['id']]??null; ?>
                <?php if($nonDeductible!==0 || $ledger): ?><p class="small">Mahaarvamata KM: <?= h(infEuros($nonDeductible)) ?>. <?= $ledger && (int)$ledger['amount_cents']===$nonDeductible?'Pearaamatu parandus ajakohane.':'Pearaamatu parandus vajab salvestamist.' ?> <a href="purchase_vat_adjustment_history.php?invoice_id=<?= (int)$invoice['id'] ?>">Paranduste ajalugu</a></p><?php endif; ?>
                <?php endif; ?>
                <?php if ($invoiceType === 'purchase' && in_array($invoice['id'], $deductionCandidateIds, true)): ?>
                <form class="row-confirm" method="post" action="?year=<?= (int) $year ?>&amp;month=<?= (int) $month ?>" onsubmit="return confirm('Kas kinnitad, et selle arve sisestatud summa kuulub valitud perioodi KMD lahtrisse 5 mahaarvamisele?')">
                    <input type="hidden" name="csrf" value="<?= h($_SESSION['kmd_inf_csrf']) ?>">
                    <input type="hidden" name="action" value="confirm_purchase">
                    <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                    <input type="hidden" name="snapshot" value="<?= h($invoice['confirmation_hash']) ?>">
                    <?php if (!empty($invoice['classification_error'])): ?><p class="notice"><?= h($invoice['classification_error']) ?></p><?php endif; ?>
                    <?php if (empty($invoice['requires_line_vat'])): ?>
                    <label class="small">Käibemaksu jaotus
                        <select name="vat_purpose"><?php foreach(vatPurposeOptions() as $purpose=>$label): ?>
                            <option value="<?= h($purpose) ?>" <?= ($invoice['vat_purpose']??'ordinary')===$purpose?'selected':'' ?>><?= h($label) ?></option>
                        <?php endforeach; ?></select>
                    </label>
                    <label class="small">Sõiduautode reg-märgid või VIN-id
                        <input name="car_ids" type="text" maxlength="2000" placeholder="Ainult sõiduauto valiku korral, nt 123ABC" value="<?= h(implode(', ',$invoice['car_ids']??[])) ?>">
                    </label>
                    <label class="small">Mahaarvatav KM (€)
                        <input name="deductible_amount" type="text" inputmode="decimal" required value="<?= h(infCsvMoney((int) ($invoice['deductible_cents'] ?? $invoice['deduction_vat'] ?? $invoice['vat']))) ?>">
                    </label>
                    <?php endif; ?>
                    <?php if (canAllocatePurchaseVatLines($invoice)): ?>
                    <details <?= !empty($invoice['vat_line_allocations']) || !empty($invoice['requires_line_vat']) ? 'open' : '' ?>>
                        <summary>Mahaarvamine arveridade kaupa</summary>
                        <?php if (!empty($invoice['requires_line_vat'])): ?>
                        <p class="small">Kontrolli iga rea ostuliiki ja mahaarvatavat summat.</p>
                        <?php else: ?>
                        <label><input type="checkbox" name="use_line_vat" value="1" <?= !empty($invoice['vat_line_allocations']) ? 'checked' : '' ?>>
                            Kasuta allolevaid reajaotusi. Arve kogusumma arvutatakse ridadest.</label>
                        <?php endif; ?>
                        <?php $rowNumber = 0; foreach ($invoice['vat_input_lines'] as $lineId=>$line): $savedLine = $invoice['vat_line_allocations'][$lineId] ?? []; ?>
                            <fieldset><legend>Rida <?= ++$rowNumber ?> · <?= h((string)$line['description']) ?> · <?= h((string)$line['code']) ?> · Neto <?= h(infEuros($line['net'])) ?> · KM <?= h(infEuros($line['vat'])) ?></legend>
                                <label>Ostuliik <select name="vat_line[<?= $lineId ?>][purpose]">
                                    <?php foreach (vatPurposeOptions() as $purpose=>$label): ?><option value="<?= h($purpose) ?>" <?= ($savedLine['vat_purpose'] ?? 'ordinary') === $purpose ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
                                </select></label>
                                <label>Mahaarvatav KM (€) <input name="vat_line[<?= $lineId ?>][amount]" inputmode="decimal"
                                    value="<?= h(infCsvMoney($savedLine['deductible_cents'] ?? $line['vat'])) ?>"></label>
                                <label>Sõiduauto reg-märk või VIN <input name="vat_line[<?= $lineId ?>][cars]"
                                    value="<?= h(implode(', ',$savedLine['car_ids'] ?? [])) ?>"></label>
                            </fieldset>
                        <?php endforeach; ?>
                    </details>
                    <?php endif; ?>
                    <span class="small">Tarnija KM: <?= h(infEuros((int) $invoice['vat'])) ?>.<?php if (!empty($invoice['reverse_vat'])): ?> Pöördmaksu KM: <?= h(infEuros((int) $invoice['reverse_vat'])) ?>.<?php endif; ?> Kui ei kuulu mahaarvamisele, sisesta 0.</span>
                    <span class="small">Salvestamine kannab mahaarvamata osa automaatselt arve algsetele kulu- või varakontodele. Korduv salvestamine ei tekita topeltkannet.</span>
                    <button class="button" type="submit"><?= !empty($invoice['deduction_confirmed']) ? 'Salvesta muudatus' : 'Kinnita kontroll' ?></button>
                </form>
                <?php elseif ($invoiceType === 'purchase' && isset($purchaseExportIssues[$invoice['id']])): ?>
                    <p class="small">Enne kinnitamist lahenda kontrollmärkused:</p>
                    <ul class="small"><?php foreach ($purchaseExportIssues[$invoice['id']] as $issue): ?><li><?= h($issue) ?></li><?php endforeach; ?></ul>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
<?php endforeach; ?>
<p class="actions"><a class="button secondary" href="<?= $usesDataBasedKmd ? 'kmd_export.php' : 'kmd_export_legacy.php' ?>?year=<?= (int) $year ?>&amp;month=<?= (int) $month ?>"><?= $usesDataBasedKmd ? 'Andmepõhise KMD ettevalmistusse' : 'Tagasi KMD ettevalmistusse' ?></a><a class="button secondary" href="kmd_setup.php">Partnerite KMD andmed</a><a class="button secondary" href="index.php">Avalehele</a></p>
</body>
</html>
