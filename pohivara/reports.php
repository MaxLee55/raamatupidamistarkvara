<?php
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) { header('Location: ../login.php'); exit; }
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/feature_migration.php';
requireFixedAssetEnhancements($pdo);

$companyId = (int) $_SESSION['company_id'];
$type = (string) ($_GET['type'] ?? 'register');
$month = (string) ($_GET['month'] ?? date('Y-m'));
if (!in_array($type, ['register', 'schedule'], true)) { http_response_code(400); exit('Aruande liik ei sobi.'); }
if ($type === 'schedule' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) { http_response_code(400); exit('Arvestuskuu ei sobi.'); }

function csvCell($value): string {
    $value = (string) $value;
    if (preg_match('/^[\s]*[=+@-]/u', $value)) $value = "'" . $value;
    return '"' . str_replace('"', '""', $value) . '"';
}
function csvRow(array $values): void { echo implode(';', array_map('csvCell', $values)) . "\r\n"; }

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="pohivara-' . $type . ($type === 'schedule' ? '-' . $month : '') . '.csv"');
echo "\xEF\xBB\xBF";

if ($type === 'register') {
    $stmt = $pdo->prepare(
        'SELECT fa.asset_number, fa.name, fa.category, fa.status, fa.acquisition_date, fa.available_date,
                fa.source_document, fa.acquisition_cost, fa.residual_value, fa.opening_accumulated_depreciation,
                fa.opening_depreciation_months, fa.useful_life_months, COALESCE(SUM(r.amount), 0) AS posted_depreciation
         FROM fixed_assets fa
         LEFT JOIN fixed_asset_depreciation_runs r ON r.company_id = fa.company_id AND r.asset_id = fa.id
         WHERE fa.company_id = ?
         GROUP BY fa.id
         ORDER BY fa.asset_number'
    );
    $stmt->execute([$companyId]);
    csvRow(['Vara nr', 'Nimetus', 'Kategooria', 'Olek', 'Soetamise kuupäev', 'Kasutusvalmis', 'Algdokument', 'Soetusmaksumus', 'Lõppväärtus', 'Algseisu kulum', 'Varasemalt arvestatud kuud', 'Eluiga kuudes', 'Kulum süsteemis', 'Bilansiline jääk']);
    while ($a = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $dep = (float) $a['opening_accumulated_depreciation'] + (float) $a['posted_depreciation'];
        $carryingValue = $a['status'] === 'disposed' ? 0 : max(0, (float) $a['acquisition_cost'] - $dep);
        csvRow([$a['asset_number'], $a['name'], $a['category'], $a['status'], $a['acquisition_date'], $a['available_date'], $a['source_document'], $a['acquisition_cost'], $a['residual_value'], $a['opening_accumulated_depreciation'], $a['opening_depreciation_months'], $a['useful_life_months'], number_format($dep, 2, '.', ''), number_format($carryingValue, 2, '.', '')]);
    }
    exit;
}

$assetStmt = $pdo->prepare("SELECT * FROM fixed_assets WHERE company_id = ? AND status = 'active' ORDER BY available_date, asset_number");
$assetStmt->execute([$companyId]);
$runsStmt = $pdo->prepare('SELECT period_start, amount FROM fixed_asset_depreciation_runs WHERE company_id = ? AND asset_id = ? ORDER BY period_start');
$termStmt = $pdo->prepare('SELECT effective_period, months_before_change, carrying_value_at_change, residual_value, remaining_life_months FROM fixed_asset_estimate_changes WHERE company_id = ? AND asset_id = ? AND effective_period <= ? ORDER BY effective_period DESC, id DESC LIMIT 1');
csvRow(['Arvestuskuu', 'Vara nr', 'Nimetus', 'Soetusmaksumus', 'Lõppväärtus', 'Varem arvestatud kulum', 'Kuu amortisatsioon', 'Staatus']);
while ($a = $assetStmt->fetch(PDO::FETCH_ASSOC)) {
    $runsStmt->execute([$companyId, (int) $a['id']]);
    $runs = $runsStmt->fetchAll(PDO::FETCH_ASSOC);
    $termStmt->execute([$companyId, (int) $a['id'], $month . '-01']);
    $term = $termStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $residualForMonth = $term ? $term['residual_value'] : $a['residual_value'];
    $availableMonth = substr($a['available_date'], 0, 7);
    $offset = ((int) substr($month, 0, 4) - (int) substr($availableMonth, 0, 4)) * 12 + (int) substr($month, 5, 2) - (int) substr($availableMonth, 5, 2);
    $openingMonths = (int) $a['opening_depreciation_months'];
    $openingCents = (int) round((float) $a['opening_accumulated_depreciation'] * 100);
    $baseCents = max(0, (int) round(((float) $a['acquisition_cost'] - (float) $a['residual_value']) * 100) - $openingCents);
    $life = max(0, (int) $a['useful_life_months'] - $openingMonths);
    $monthlyCents = $life > 0 ? intdiv($baseCents, $life) : 0;
    $state = 'Valmis arvestamiseks'; $amount = 0;
    if ($offset < 0) $state = 'Kasutusvalmis kuupäev pole veel käes';
    elseif ($offset < $openingMonths) $state = 'Sisaldub algseisus';
    else {
        foreach ($runs as $i => $run) {
            $expectedMonth = (new DateTimeImmutable($availableMonth . '-01'))->modify('+' . ($openingMonths + $i) . ' months')->format('Y-m-01');
            if ($run['period_start'] !== $expectedMonth) { $state = 'Varasemate kuude arvestuses on vahe'; break; }
        }
        if ($state === 'Valmis arvestamiseks') {
            $expectedOffset = $openingMonths + count($runs);
            if ($offset < $expectedOffset) $state = 'Juba arvestatud';
            elseif ($offset > $expectedOffset) $state = 'Varasem kuu tuleb enne arvestada';
            elseif ($term) {
                $termIndex = ((int) substr($term['effective_period'], 0, 4) - (int) substr($availableMonth, 0, 4)) * 12 + (int) substr($term['effective_period'], 5, 2) - (int) substr($availableMonth, 5, 2);
                $termMonth = $offset - (int) $term['months_before_change'];
                $termLife = (int) $term['remaining_life_months'];
                $termBase = max(0, (int) round(((float) $term['carrying_value_at_change'] - (float) $term['residual_value']) * 100));
                if ($offset < $termIndex || $termMonth < 0 || $termMonth >= $termLife) $state = 'Amortiseeritav summa on ammendunud';
                else { $termMonthly = intdiv($termBase, max(1, $termLife)); $amount = $termMonth === $termLife - 1 ? $termBase - $termMonthly * ($termLife - 1) : $termMonthly; }
            } elseif ($offset >= (int) $a['useful_life_months']) $state = 'Kasulik eluiga on ammendunud';
            else $amount = $offset === (int) $a['useful_life_months'] - 1 ? $baseCents - $monthlyCents * max(0, $life - 1) : $monthlyCents;
        }
    }
    csvRow([$month, $a['asset_number'], $a['name'], $a['acquisition_cost'], $residualForMonth, number_format(($openingCents + array_sum(array_map(static fn($r) => (int) round((float) $r['amount'] * 100), $runs))) / 100, 2, '.', ''), number_format($amount / 100, 2, '.', ''), $state]);
}
