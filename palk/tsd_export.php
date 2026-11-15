<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: ../login.php');
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Palgamoodulile on ligipääs ainult administraatoril.');
}

require_once dirname(__DIR__) . '/db.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function amountToCents(string $amount): int
{
    if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($amount), $matches)) {
        throw new RuntimeException('Mõne palgarea summa ei ole korrektne.');
    }
    return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
}

function xml(string $name, string $value, string $attributes = ''): string
{
    return '<' . $name . ($attributes !== '' ? ' ' . $attributes : '') . '>'
        . h($value) . '</' . $name . '>';
}

function moneyXml(int $cents): string
{
    return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

function showExportError(string $message): void
{
    http_response_code(422);
    $safeMessage = h($message);
    echo '<!doctype html><html lang="et"><head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>TSD faili ei koostatud</title><style>body{margin:0;padding:24px;background:#f4f7fb;color:#243447;font-family:Arial,sans-serif}'
        . 'main{max-width:760px;margin:40px auto;padding:24px;background:#fff;border:1px solid #dce3ec;border-radius:12px}'
        . 'h1{color:#17324d}.error{padding:14px;background:#fdecec;color:#8d2222;border-radius:8px}'
        . 'a{display:inline-block;margin-top:16px;padding:10px 14px;background:#1769aa;color:#fff;border-radius:7px;text-decoration:none;font-weight:700}</style></head>'
        . '<body><main><h1>TSD faili ei koostatud</h1><p class="error">' . $safeMessage . '</p>'
        . '<a href="runs.php">Tagasi palgaperioodide juurde</a></main></body></html>';
    exit;
}

$month = trim((string) ($_GET['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    showExportError('Vali kehtiv väljamakse kuu.');
}

$monthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
if (!$monthDate || $monthDate->format('Y-m') !== $month) {
    showExportError('Vali kehtiv väljamakse kuu.');
}

$companyId = (int) $_SESSION['company_id'];
$companyStmt = $pdo->prepare('SELECT name, registry_code FROM companies WHERE id = ?');
$companyStmt->execute([$companyId]);
$company = $companyStmt->fetch();
if (!$company || !preg_match('/^\d{8}$/', trim((string) $company['registry_code']))) {
    showExportError('Ettevõtte registrikood peab olema seadetes sisestatud 8-kohalise numbrina.');
}

$startDate = $monthDate->format('Y-m-01');
$endDate = $monthDate->modify('last day of this month')->format('Y-m-d');
$validateSample = ($_GET['validate_sample'] ?? '') === '1';
if ($validateSample) {
    $items = [];
} else {
$itemsStmt = $pdo->prepare(
    'SELECT
        pr.id AS run_id,
        pr.work_month,
        pr.status AS run_status,
        pri.id AS run_item_id,
        pri.gross_amount AS item_gross_amount,
        pe.employment_type_code,
        e.first_name,
        e.last_name,
        e.personal_code,
        e.is_test_data,
        payment.actual_payment_date,
        calc.id AS calculation_id,
        calc.calculation_date,
        calc.setting_valid_from,
        calc.gross_amount,
        calc.pension_amount,
        calc.employee_unemployment_amount,
        calc.tax_free_amount,
        calc.income_tax_amount,
        calc.employer_unemployment_amount,
        calc.social_tax_amount,
        calc.social_tax_minimum_applied,
        tax_setting.tax_free_type
     FROM payroll_run_item_payments payment
     JOIN payroll_run_items pri
       ON pri.id = payment.run_item_id
      AND pri.company_id = payment.company_id
      AND pri.run_id = payment.run_id
     JOIN payroll_runs pr
       ON pr.id = payment.run_id
      AND pr.company_id = payment.company_id
     JOIN payroll_employments pe
       ON pe.id = pri.employment_id
      AND pe.company_id = pri.company_id
     JOIN payroll_employees e
       ON e.id = pe.employee_id
      AND e.company_id = pe.company_id
     JOIN payroll_run_item_calculations calc
       ON calc.id = (
          SELECT c2.id
          FROM payroll_run_item_calculations c2
          WHERE c2.company_id = pri.company_id
            AND c2.run_id = pri.run_id
            AND c2.run_item_id = pri.id
          ORDER BY c2.id DESC
          LIMIT 1
       )
     LEFT JOIN payroll_employee_tax_settings tax_setting
       ON tax_setting.company_id = calc.company_id
      AND tax_setting.employee_id = e.id
      AND tax_setting.valid_from = calc.setting_valid_from
     WHERE payment.company_id = :company_id
       AND payment.actual_payment_date BETWEEN :start_date AND :end_date
       AND pr.status IN (\'confirmed\', \'paid\')
     ORDER BY payment.actual_payment_date, e.last_name, e.first_name, pri.id'
);
$itemsStmt->execute([
    'company_id' => $companyId,
    'start_date' => $startDate,
    'end_date' => $endDate,
]);
$items = $itemsStmt->fetchAll();
}

$testCount = 0;
$payments = [];
$errors = [];
if ($validateSample) {
    // Ainult kohalikuks XML-i koostamise prooviks. Seda näidisrida ei loeta
    // andmebaasist ega saadeta MTA-le.
    $samplePaymentDate = $monthDate->setDate(
        (int) $monthDate->format('Y'),
        (int) $monthDate->format('m'),
        min(8, (int) $monthDate->format('t'))
    )->format('Y-m-d');
    $payments[] = [
        'run_id' => 0,
        'run_item_id' => 0,
        'first_name' => 'Näidis',
        'last_name' => 'Töötaja',
        'personal_code' => '39912319999',
        'actual_payment_date' => $samplePaymentDate,
        'tax_free_type' => 'general',
        'gross_cents' => 180000,
        'tsd01_date' => $monthDate->format('Y-m-01'),
        'pension_cents' => 0,
        'employee_unemployment_cents' => 2880,
        'allowance_cents' => 70000,
        'income_tax_cents' => 23566,
        'employer_unemployment_cents' => 1440,
        'social_tax_cents' => 59400,
        'social_tax_base_cents' => 180000,
    ];
} else {
foreach ($items as $item) {
    if ((int) $item['is_test_data'] === 1) {
        $testCount++;
        continue;
    }

    if ((int) $item['employment_type_code'] !== 1) {
        $errors[] = $item['first_name'] . ' ' . $item['last_name']
            . ': lepinguliigi eksport pole veel selle failiga toetatud.';
        continue;
    }
    if (!preg_match('/^\d{11}$/', (string) $item['personal_code'])) {
        $errors[] = $item['first_name'] . ' ' . $item['last_name'] . ': Eesti isikukood puudub või on vigane.';
        continue;
    }
    $calculationDate = trim((string) ($item['calculation_date'] ?? ''));
    $calculationDateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $calculationDate);
    $paymentDate = trim((string) ($item['actual_payment_date'] ?? ''));
    $paymentDateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate);
    if (
        $calculationDateValue === false
        || $calculationDateValue->format('Y-m-d') !== $calculationDate
        || $paymentDateValue === false
        || $paymentDateValue->format('Y-m-d') !== $paymentDate
    ) {
        $errors[] = $item['first_name'] . ' ' . $item['last_name']
            . ': palgaarvestuse või väljamakse kuupäev puudub või ei ole korrektne.';
        continue;
    }
    if ($calculationDate > $paymentDate) {
        $errors[] = $item['first_name'] . ' ' . $item['last_name']
            . ': palgaarvestuse kuupäev ei tohi olla väljamakse kuupäevast hilisem.';
        continue;
    }
    if ($item['tax_free_type'] === null) {
        $errors[] = $item['first_name'] . ' ' . $item['last_name']
            . ': palgaarvestuses kasutatud maksuseade puudub.';
        continue;
    }
    if (!in_array($item['tax_free_type'], ['none', 'general', 'pensioner'], true)) {
        $errors[] = $item['first_name'] . ' ' . $item['last_name'] . ': maksuvaba tulu liik pole toetatud.';
        continue;
    }

    try {
        $grossCents = amountToCents((string) $item['gross_amount']);
        $itemGrossCents = amountToCents((string) $item['item_gross_amount']);
        $pensionCents = amountToCents((string) $item['pension_amount']);
        $unemploymentCents = amountToCents((string) $item['employee_unemployment_amount']);
        $allowanceCents = amountToCents((string) $item['tax_free_amount']);
        $incomeTaxCents = amountToCents((string) $item['income_tax_amount']);
        $employerUnemploymentCents = amountToCents((string) $item['employer_unemployment_amount']);
        $socialTaxCents = amountToCents((string) $item['social_tax_amount']);
    } catch (RuntimeException $e) {
        $errors[] = $item['first_name'] . ' ' . $item['last_name'] . ': ' . $e->getMessage();
        continue;
    }

    if ($grossCents <= 0 || $grossCents !== $itemGrossCents) {
        $errors[] = $item['first_name'] . ' ' . $item['last_name']
            . ': palgarea ja viimase maksuarvestuse brutotasud ei klapi.';
        continue;
    }
    if ($item['tax_free_type'] === 'none' && $allowanceCents !== 0) {
        $errors[] = $item['first_name'] . ' ' . $item['last_name']
            . ': maksuarvestuse maksuvaba summa ei vasta maksuseadele.';
        continue;
    }
    if ($allowanceCents > 0 && !in_array($item['tax_free_type'], ['general', 'pensioner'], true)) {
        $errors[] = $item['first_name'] . ' ' . $item['last_name']
            . ': maksuvaba tulu seade ei vasta salvestatud arvestusele.';
        continue;
    }

    $item['gross_cents'] = $grossCents;
    $item['tsd01_date'] = $calculationDate;
    $item['pension_cents'] = $pensionCents;
    $item['employee_unemployment_cents'] = $unemploymentCents;
    $item['allowance_cents'] = $allowanceCents;
    $item['income_tax_cents'] = $incomeTaxCents;
    $item['employer_unemployment_cents'] = $employerUnemploymentCents;
    $item['social_tax_cents'] = $socialTaxCents;
    $item['social_tax_base_cents'] = (int) $item['social_tax_minimum_applied'] === 1
        ? 88600
        : $grossCents;
    $payments[] = $item;
}
}

if ($errors) {
    showExportError(implode(' ', $errors));
}
if (!$payments) {
    showExportError($testCount > 0
        ? 'Valitud kuul leiti ainult proovitöötaja väljamakseid. Proovitöötajaid TSD faili ei lisata.'
        : 'Valitud kuul ei ole kinnitatud ja makstuks märgitud päris töötajate palgaridu koos maksuarvestusega.');
}

$contextId = 'now';
$registryCode = trim((string) $company['registry_code']);
$createdAt = new DateTimeImmutable();
$uniqueId = $registryCode . '-' . $createdAt->format('Y-m-d\TH:i:s')
    . ':' . str_pad((string) (int) floor(((float) $createdAt->format('u')) / 1000), 3, '0', STR_PAD_LEFT);
$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
    . '<xbrli:xbrl xmlns:xbrli="http://www.xbrl.org/2003/instance"'
    . ' xmlns:xlink="http://www.w3.org/1999/xlink"'
    . ' xmlns:xbrll="http://www.xbrl.org/2003/linkbase"'
    . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
    . ' xmlns:gl-usk="http://www.xbrl.org/int/gl/usk/2015-03-25"'
    . ' xmlns:gl-cor="http://www.xbrl.org/int/gl/cor/2015-03-25"'
    . ' xmlns:gl-ext="http://www.xbrl.org/int/gl/ext/2015-03-25"'
    . ' xmlns:iso639="http://www.xbrl.org/2005/iso639"'
    . ' xmlns:iso4217="http://www.xbrl.org/2003/iso4217"'
    . ' xmlns:gl-plt="http://www.xbrl.org/int/gl/plt/2015-03-25"'
    . ' xmlns:gl-bus="http://www.xbrl.org/int/gl/bus/2015-03-25"'
    . ' xmlns:gl-muc="http://www.xbrl.org/int/gl/muc/2015-03-25"'
    . ' xsi:schemaLocation="http://www.xbrl.org/int/gl/plt/2015-03-25 https://www.xbrl.org/taxonomy/int/gl/2015-03-25/plt/case-c-b-m-u-t/gl-plt-all-2015-03-25.xsd">' . "\n"
    . '  <xbrll:schemaRef xlink:href="https://www.xbrl.org/taxonomy/int/gl/2015-03-25/plt/case-c-b-m-u-t/gl-plt-all-2015-03-25.xsd" xlink:arcrole="http://www.w3.org/1999/xlink/properties/linkbase" xlink:type="simple"/>' . "\n"
    . '  <xbrli:context id="' . $contextId . '"><xbrli:entity><xbrli:identifier scheme="http://www.ariregister.rik.ee">'
    . h($registryCode) . '</xbrli:identifier></xbrli:entity><xbrli:period><xbrli:instant>'
    . $createdAt->format('Y-m-d') . '</xbrli:instant></xbrli:period></xbrli:context>' . "\n"
    . '  <xbrli:unit id="eur"><xbrli:measure>iso4217:EUR</xbrli:measure></xbrli:unit>' . "\n"
    . '  <xbrli:unit id="NotUsed"><xbrli:measure>pure</xbrli:measure></xbrli:unit>' . "\n"
    . '  <gl-cor:accountingEntries><gl-cor:documentInfo>' . "\n"
    . xml('gl-cor:entriesType', 'other', 'contextRef="now"')
    . xml('gl-cor:uniqueID', $uniqueId, 'contextRef="now"')
    . xml('gl-cor:language', 'iso639:et', 'contextRef="now"')
    . xml('gl-cor:creationDate', $createdAt->format('Y-m-d'), 'contextRef="now"')
    . xml('gl-bus:creator', 'Raamatupidamisprogramm', 'contextRef="now"')
    . xml('gl-cor:entriesComment', 'Andmepõhise TSD väljamaksete andmed', 'contextRef="now"')
    . xml('gl-cor:periodCoveredStart', $startDate, 'contextRef="now"')
    . xml('gl-cor:periodCoveredEnd', $endDate, 'contextRef="now"')
    . xml('gl-bus:sourceApplication', 'Raamatupidamisprogramm XAMPP', 'contextRef="now"')
    . xml('gl-muc:defaultCurrency', 'iso4217:eur', 'contextRef="now"')
    . '</gl-cor:documentInfo><gl-cor:entityInformation><gl-bus:organizationIdentifiers>'
    . xml('gl-bus:organizationIdentifier', $registryCode, 'contextRef="now"')
    . xml('gl-bus:organizationDescription', 'ARIREGISTRIKOOD', 'contextRef="now"')
    . '</gl-bus:organizationIdentifiers></gl-cor:entityInformation>'
    . '<gl-cor:entryHeader><gl-cor:entryNumber contextRef="now">EE0201001</gl-cor:entryNumber>';

foreach ($payments as $index => $payment) {
    $lineNumber = 'PAYROLL-' . (int) $payment['run_id'] . '-' . (int) $payment['run_item_id'];
    $counter = $index + 1;
    $personalIdentifier = 'EE' . (string) $payment['personal_code'];
    $xml .= '<gl-cor:entryDetail>'
        . xml('gl-cor:lineNumber', $lineNumber, 'contextRef="now"')
        . xml('gl-cor:lineNumberCounter', (string) $counter, 'contextRef="now" unitRef="NotUsed"')
        . '<gl-cor:account>'
        . '<gl-cor:accountSub>'
        . xml('gl-cor:accountSubID', '10', 'contextRef="now"')
        . xml('gl-cor:accountSubType', 'VALJAMLIIK2023ap', 'contextRef="now"')
        . '</gl-cor:accountSub><gl-cor:accountSub>'
        . xml('gl-cor:accountSubID', 'R', 'contextRef="now"')
        . xml('gl-cor:accountSubType', 'TOIMINGUTYYP2023ap', 'contextRef="now"')
        . '</gl-cor:accountSub></gl-cor:account>'
        . xml('gl-cor:amount', moneyXml($payment['gross_cents']), 'contextRef="now" decimals="2" unitRef="eur"')
        . '<gl-cor:identifierReference>'
        . xml('gl-cor:identifierCode', $personalIdentifier, 'contextRef="now"')
        . xml('gl-cor:identifierDescription', 'ISIKUKOOD', 'contextRef="now"')
        . '</gl-cor:identifierReference>'
        . xml('gl-cor:documentDate', (string) $payment['actual_payment_date'], 'contextRef="now"')
        . '<gl-bus:measurable>'
        . xml('gl-bus:measurableID', 'TSD01', 'contextRef="now"')
        . xml('gl-bus:measurableIDSchema', 'SYNDMUS2017ap', 'contextRef="now"')
        . xml('gl-bus:measurableStartDateTime', (string) $payment['tsd01_date'], 'contextRef="now"')
        . '</gl-bus:measurable>';
    if ($payment['allowance_cents'] > 0) {
        $allowanceCode = $payment['tax_free_type'] === 'pensioner' ? 'MVT_650' : 'MVT_610';
        $xml .= '<gl-bus:measurable>'
            . xml('gl-bus:measurableDescription', $allowanceCode, 'contextRef="now"')
            . xml('gl-bus:measurableQuantity', moneyXml($payment['allowance_cents']), 'contextRef="now" decimals="2" unitRef="eur"')
            . '</gl-bus:measurable>';
    }

    $xml .= '<gl-bus:measurable>'
        . xml('gl-bus:measurableDescription', 'TSD_SMVM', 'contextRef="now"')
        . xml('gl-bus:measurableQuantity', moneyXml($payment['social_tax_base_cents']), 'contextRef="now" decimals="2" unitRef="eur"')
        . '</gl-bus:measurable>';

    $taxes = [
        'TSD_SM' => $payment['social_tax_cents'],
        'TSD_KP' => $payment['pension_cents'],
        'TSD_TK' => $payment['employee_unemployment_cents'],
        'TSD_TTK' => $payment['employer_unemployment_cents'],
        'TSD_KTM' => $payment['income_tax_cents'],
    ];
    foreach ($taxes as $taxCode => $taxCents) {
        $xml .= '<gl-cor:taxes>'
            . xml('gl-cor:taxAmount', moneyXml($taxCents), 'contextRef="now" decimals="2" unitRef="eur"')
            . xml('gl-cor:taxCode', $taxCode, 'contextRef="now"')
            . '</gl-cor:taxes>';
    }
    $xml .= '</gl-cor:entryDetail>';
}

$xml .= '</gl-cor:entryHeader></gl-cor:accountingEntries></xbrli:xbrl>';

if ($validateSample) {
    $checks = [];
    $domAvailable = class_exists(DOMDocument::class);
    $dom = $domAvailable ? new DOMDocument() : null;
    $wellFormed = $domAvailable && $dom->loadXML($xml, LIBXML_NONET);
    $checks[] = ['PHP XML-i kontrollija on saadaval', $domAvailable];
    $checks[] = ['Koostatud XML on korrektselt loetav', $wellFormed];
    $checks[] = ['Näidisrida kasutab ainult kunstlikke töötajaandmeid', true];
    $xmlChecksPassed = false;
    if ($wellFormed) {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('gl-cor', 'http://www.xbrl.org/int/gl/cor/2015-03-25');
        $xpath->registerNamespace('gl-bus', 'http://www.xbrl.org/int/gl/bus/2015-03-25');
        $grossNode = $xpath->query('//gl-cor:entryDetail/gl-cor:amount')->item(0);
        $taxes = [];
        foreach ($xpath->query('//gl-cor:entryDetail/gl-cor:taxes') as $taxNode) {
            $code = $xpath->query('./gl-cor:taxCode', $taxNode)->item(0)?->textContent;
            $amount = $xpath->query('./gl-cor:taxAmount', $taxNode)->item(0)?->textContent;
            if ($code !== null && $amount !== null) {
                $taxes[$code] = $amount;
            }
        }
        $allowanceNode = $xpath->query('//gl-bus:measurable[gl-bus:measurableDescription="MVT_610"]/gl-bus:measurableQuantity')->item(0);
        $xmlChecksPassed = $grossNode !== null
            && $grossNode->textContent === '1800.00'
            && ($taxes['TSD_SM'] ?? null) === '594.00'
            && ($taxes['TSD_KP'] ?? null) === '0.00'
            && ($taxes['TSD_TK'] ?? null) === '28.80'
            && ($taxes['TSD_TTK'] ?? null) === '14.40'
            && ($taxes['TSD_KTM'] ?? null) === '235.66'
            && $allowanceNode !== null
            && $allowanceNode->textContent === '700.00';
    }
    $checks[] = ['XML sisaldab näite brutotasu, makse ja maksuvaba tulu', $xmlChecksPassed];

    $schemaCandidates = [
        __DIR__ . '/xsd/local-XBRL-GL-REC-2015-03-25/gl/plt/case-c-b-m-u-t/gl-plt-all-2015-03-25.xsd',
        dirname(__DIR__) . '/mta-tsd-package-review/xbrl-gl-offline-validation/XBRL-GL-REC-2015-03-25/gl/plt/case-c-b-m-u-t/gl-plt-all-2015-03-25.xsd',
        __DIR__ . '/xsd/gl-plt-all-2015-03-25.xsd',
    ];
    $schemaPath = null;
    foreach ($schemaCandidates as $candidate) {
        if (is_file($candidate)) {
            $schemaPath = $candidate;
            break;
        }
    }
    $schemaValid = null;
    $schemaMessages = [];
    if ($wellFormed && $schemaPath !== null) {
        libxml_use_internal_errors(true);
        $schemaValid = $dom->schemaValidate($schemaPath);
        foreach (libxml_get_errors() as $libxmlError) {
            $schemaMessages[] = trim($libxmlError->message);
        }
        libxml_clear_errors();
        libxml_use_internal_errors(false);
    }

    header('Content-Type: text/html; charset=UTF-8');
    $passed = !in_array(false, array_column($checks, 1), true) && $schemaValid === true;
    echo '<!doctype html><html lang="et"><head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>TSD XML-i kohalik kontroll</title><style>body{margin:0;padding:24px;background:#f4f7fb;color:#243447;font-family:Arial,sans-serif}'
        . 'main{max-width:800px;margin:32px auto;padding:24px;background:#fff;border:1px solid #dce3ec;border-radius:12px}'
        . 'li{margin:10px 0}.ok{color:#137333}.bad{color:#a12626}.note{padding:12px;background:#fff6dc;border-radius:8px}'
        . 'a{display:inline-block;margin-top:16px;padding:10px 14px;background:#1769aa;color:#fff;border-radius:7px;text-decoration:none;font-weight:700}</style></head><body><main>'
        . '<h1>TSD XML-i kohalik kontroll</h1><p class="note">See kasutas kunstlikku näidisrida. XML-i ei laaditud alla ega saadetud MTA-le.</p><ul>';
    foreach ($checks as [$label, $ok]) {
        echo '<li class="' . ($ok ? 'ok' : 'bad') . '">' . ($ok ? 'Korras: ' : 'Viga: ') . h($label) . '</li>';
    }
    if ($schemaValid === true) {
        echo '<li class="ok">Korras: XML vastab kohalikule XBRL GL XSD-skeemile.</li>';
    } elseif ($schemaValid === false) {
        echo '<li class="bad">Viga: XML ei vasta kohalikule XBRL GL XSD-skeemile.</li>';
        foreach ($schemaMessages as $message) {
            echo '<li class="bad">' . h($message) . '</li>';
        }
    } else {
        echo '<li class="bad">XSD-kontrolli ei saanud teha: DOM/XML laiendus või kohalik XSD-skeem puudub.</li>';
    }
    echo '</ul><p>' . ($passed ? 'Kohalik näidiskontroll läbis olemasolevad kontrollid.' : 'Kohalik näidiskontroll leidis probleemi.') . '</p>'
        . '<a href="runs.php">Tagasi palgaperioodide juurde</a></main></body></html>';
    exit;
}

header('Content-Type: application/xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="TSD_' . $month . '_' . $registryCode . '.xml"');
header('Content-Length: ' . strlen($xml));
header('Cache-Control: no-store, private');
echo $xml;
