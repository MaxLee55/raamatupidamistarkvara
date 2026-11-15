<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/db.php';

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function cents(string $value): int
{
    $negative = isset($value[0]) && $value[0] === '-';
    $value = ltrim($value, '-');
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
    $amount = (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    return $negative ? -$amount : $amount;
}
function eur(int $value): string { return number_format($value / 100, 2, ',', ' ') . ' €'; }

$companyId = (int) $_SESSION['company_id'];
$companyStmt = $pdo->prepare('SELECT name, registry_code, fiscal_year_start FROM companies WHERE id = ?');
$companyStmt->execute([$companyId]);
$company = $companyStmt->fetch(PDO::FETCH_ASSOC);
if (!$company) { http_response_code(404); exit('Ettevõtte andmeid ei leitud.'); }
$profileStmt = $pdo->prepare('SELECT report_category, income_statement_scheme FROM annual_report_settings WHERE company_id = ?');
$profileStmt->execute([$companyId]);
$profile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: ['report_category'=>'small', 'income_statement_scheme'=>2];
$incomeScheme = (int) $profile['income_statement_scheme'];
$categoryNames = ['micro'=>'Mikroettevõtja','small'=>'Väikeettevõtja','medium'=>'Keskmise suurusega ettevõtja','large'=>'Suurettevõtja'];

$today = new DateTimeImmutable('today');
$fiscalBase = new DateTimeImmutable((string) $company['fiscal_year_start']);
$monthDay = $fiscalBase->format('m-d');
$firstYear = (int) $fiscalBase->format('Y');
$thisYear = (int) $today->format('Y');
$currentFiscalYear = $today->format('m-d') >= $monthDay ? $thisYear : $thisYear - 1;
$currentFiscalYear = max($firstYear, $currentFiscalYear);
$year = filter_var($_GET['year'] ?? null, FILTER_VALIDATE_INT);
$year = $year === false || $year === null ? $currentFiscalYear : (int) $year;
if ($year < $firstYear || $year > $currentFiscalYear) $year = $currentFiscalYear;
$start = sprintf('%04d-%s', $year, $monthDay);
$startDate = new DateTimeImmutable($start);
$end = $startDate->modify('+1 year')->modify('-1 day')->format('Y-m-d');
$previousStart = $startDate->modify('-1 year')->format('Y-m-d');
$previousEnd = $startDate->modify('-1 day')->format('Y-m-d');

$labels = [
    'bs_cash' => 'Käibevarad · Raha',
    'bs_receivables' => 'Käibevarad · Nõuded ja ettemaksed · Nõuded ostjate vastu',
    'bs_related_receivables' => 'Käibevarad · Nõuded ja ettemaksed · Nõuded seotud osapoolte vastu',
    'bs_tax_receivables' => 'Käibevarad · Nõuded ja ettemaksed · Maksude ettemaksed ja tagasinõuded',
    'bs_loan_receivables' => 'Käibevarad · Nõuded ja ettemaksed · Laenunõuded',
    'bs_other_receivables' => 'Käibevarad · Nõuded ja ettemaksed · Muud nõuded',
    'bs_prepayments' => 'Käibevarad · Nõuded ja ettemaksed · Ettemaksed',
    'bs_inventory' => 'Käibevarad · Varud · Müügiks ostetud kaubad',
    'bs_inventory_materials' => 'Käibevarad · Varud · Tooraine ja materjal',
    'bs_inventory_wip' => 'Käibevarad · Varud · Lõpetamata toodang',
    'bs_inventory_finished' => 'Käibevarad · Varud · Valmistoodang',
    'bs_inventory_prepayments' => 'Käibevarad · Varud · Ettemaksed varude eest',
    'bs_biological_assets' => 'Käibevarad · Bioloogilised varad',
    'bs_financial_assets' => 'Käibevarad · Finantsinvesteeringud',
    'bs_long_term_financial_assets' => 'Põhivarad · Finantsinvesteeringud',
    'bs_long_term_subsidiaries' => 'Põhivarad · Investeeringud tütar- ja sidusettevõtjatesse · Tütarettevõtjate aktsiad ja osad',
    'bs_long_term_associates' => 'Põhivarad · Investeeringud tütar- ja sidusettevõtjatesse · Sidusettevõtjate aktsiad ja osad',
    'bs_long_term_receivables' => 'Põhivarad · Nõuded ja ettemaksed · Nõuded ostjate vastu',
    'bs_long_term_related_receivables' => 'Põhivarad · Nõuded ja ettemaksed · Nõuded seotud osapoolte vastu',
    'bs_long_term_tax_receivables' => 'Põhivarad · Nõuded ja ettemaksed · Maksude ettemaksed ja tagasinõuded',
    'bs_long_term_loan_receivables' => 'Põhivarad · Nõuded ja ettemaksed · Laenunõuded',
    'bs_long_term_other_receivables' => 'Põhivarad · Nõuded ja ettemaksed · Muud nõuded',
    'bs_long_term_prepayments' => 'Põhivarad · Nõuded ja ettemaksed · Ettemaksed',
    'bs_investment_property' => 'Põhivarad · Kinnisvarainvesteeringud',
    'bs_fixed_assets' => 'Põhivarad · Materiaalsed põhivarad',
    'bs_intangible_assets' => 'Põhivarad · Immateriaalsed põhivarad',
    'bs_noncurrent_biological_assets' => 'Põhivarad · Bioloogilised varad',
    'bs_other_noncurrent_assets' => 'Põhivarad · Nõuded ja ettemaksed · Muud nõuded',
    'bs_other_assets' => 'Käibevarad · Nõuded ja ettemaksed · Muud nõuded / ettemaksed',
    'bs_payables' => 'Lühiajalised kohustised · Võlad ja ettemaksed · Võlad tarnijatele',
    'bs_employee_payables' => 'Lühiajalised kohustised · Võlad ja ettemaksed · Võlad töövõtjatele',
    'bs_tax_liabilities' => 'Lühiajalised kohustised · Võlad ja ettemaksed · Maksuvõlad',
    'bs_loans' => 'Lühiajalised kohustised · Laenukohustised',
    'bs_long_term_loans' => 'Pikaajalised kohustised · Laenukohustised',
    'bs_dividend_liabilities' => 'Lühiajalised kohustised · Võlad ja ettemaksed · Muud võlad (sh dividendid)',
    'bs_other_liabilities' => 'Lühiajalised kohustised · Võlad ja ettemaksed · Muud võlad',
    'bs_current_deferred_income' => 'Lühiajalised kohustised · Võlad ja ettemaksed · Tulevaste perioodide tulud',
    'bs_current_advances' => 'Lühiajalised kohustised · Võlad ja ettemaksed · Muud saadud ettemaksed',
    'bs_current_provisions' => 'Lühiajalised kohustised · Eraldised',
    'bs_current_grants' => 'Lühiajalised kohustised · Sihtfinantseerimine',
    'bs_long_term_payables' => 'Pikaajalised kohustised · Võlad ja ettemaksed · Võlad tarnijatele',
    'bs_long_term_employee_payables' => 'Pikaajalised kohustised · Võlad ja ettemaksed · Võlad töövõtjatele',
    'bs_long_term_tax_liabilities' => 'Pikaajalised kohustised · Võlad ja ettemaksed · Maksuvõlad',
    'bs_long_term_other_liabilities' => 'Pikaajalised kohustised · Võlad ja ettemaksed · Muud võlad',
    'bs_long_term_deferred_income' => 'Pikaajalised kohustised · Võlad ja ettemaksed · Tulevaste perioodide tulud',
    'bs_long_term_advances' => 'Pikaajalised kohustised · Võlad ja ettemaksed · Muud saadud ettemaksed',
    'bs_long_term_provisions' => 'Pikaajalised kohustised · Eraldised',
    'bs_long_term_grants' => 'Pikaajalised kohustised · Sihtfinantseerimine',
    'bs_share_capital' => 'Omakapital · Aktsiakapital või osakapital nimiväärtuses',
    'bs_unregistered_share_capital' => 'Omakapital · Registreerimata aktsia- või osakapital',
    'bs_unpaid_share_capital' => 'Omakapital · Sissemaksmata osakapital',
    'bs_share_premium' => 'Omakapital · Ülekurss',
    'bs_treasury_shares' => 'Omakapital · Oma aktsiad või osad',
    'bs_reserve_capital' => 'Omakapital · Kohustuslik reservkapital',
    'bs_other_reserves' => 'Omakapital · Muud reservid',
    'bs_retained_earnings' => 'Omakapital · Eelmiste perioodide jaotamata kasum (kahjum)',
    'bs_current_result' => 'Omakapital · Aruandeaasta kasum (kahjum)',
    'bs_other_equity' => 'Omakapital · Muu omakapital',
    'is_sales' => 'Müügitulu',
    'is_other_operating_income' => 'Muud äritulud',
    'is_financial_income' => 'Kasum (kahjum) finantsinvesteeringutelt',
    'is_subsidiary_result' => 'Kasum (kahjum) tütarettevõtjatelt',
    'is_associate_result' => 'Kasum (kahjum) sidusettevõtjatelt',
    'is_biological_asset_result' => 'Kasum (kahjum) bioloogilistelt varadelt',
    'is_interest_income' => 'Intressitulud',
    'is_other_financial_income' => 'Muud finantstulud ja -kulud',
    'is_other_income' => 'Muud äritulud',
    'is_goods' => $incomeScheme === 2 ? 'Müüdud toodangu (kaupade, teenuste) kulu' : 'Kaubad, toore, materjal ja teenused',
    'is_personnel' => 'Tööjõukulud',
    'is_depreciation' => 'Põhivara kulum ja väärtuse langus',
    'is_admin' => 'Üldhalduskulud',
    'is_distribution' => 'Turustuskulud',
    'is_other_operating_expenses' => 'Muud ärikulud',
    'is_financial_expenses' => 'Intressikulud',
    'is_income_tax' => 'Tulumaks',
    'is_other_expenses' => 'Muud ärikulud',
];

$mappedCountStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM accounts a JOIN annual_report_account_mappings m ON m.account_id = a.id AND m.company_id = a.company_id AND m.income_scheme = ? WHERE a.company_id = ?'
);
$mappedCountStmt->execute([$incomeScheme, $companyId]);
$mappedCount = (int) $mappedCountStmt->fetchColumn();
$accountCountStmt = $pdo->prepare('SELECT COUNT(*) FROM accounts WHERE company_id = ?');
$accountCountStmt->execute([$companyId]);
$accountCount = (int) $accountCountStmt->fetchColumn();

$stmt = $pdo->prepare(
    'SELECT m.report_line_code, a.account_type,
       SUM(CASE WHEN je.entry_date <= :current_end THEN jl.debit - jl.credit ELSE 0 END) AS current_balance_raw,
       SUM(CASE WHEN je.entry_date <= :previous_end THEN jl.debit - jl.credit ELSE 0 END) AS previous_balance_raw,
       SUM(CASE WHEN je.entry_date BETWEEN :current_start AND :current_period_end THEN jl.debit - jl.credit ELSE 0 END) AS current_period_raw,
       SUM(CASE WHEN je.entry_date BETWEEN :previous_start AND :previous_period_end THEN jl.debit - jl.credit ELSE 0 END) AS previous_period_raw
     FROM annual_report_account_mappings m
     JOIN accounts a ON a.id = m.account_id AND a.company_id = m.company_id
     LEFT JOIN journal_lines jl ON jl.account_id = a.id
     LEFT JOIN journal_entries je ON je.id = jl.entry_id AND je.company_id = a.company_id AND je.status = \'posted\'
     WHERE m.company_id = :company_id AND m.income_scheme = :income_scheme
     GROUP BY m.report_line_code, a.account_type'
);
$stmt->execute([
    'current_end' => $end,
    'previous_end' => $previousEnd,
    'current_start' => $start,
    'current_period_end' => $end,
    'previous_start' => $previousStart,
    'previous_period_end' => $previousEnd,
    'company_id' => $companyId,
    'income_scheme' => $incomeScheme,
]);
$balance = [];
$income = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $type = (string) $row['account_type'];
    $line = (string) $row['report_line_code'];
        $normalSign = in_array($type, ['asset', 'expense'], true) ? 1 : -1;
        // Kasumi/kahjumi ja netotulu/kulu read võivad sisaldada ka kulukontosid;
        // nende deebet peab vähendama aruandetulemit mõlemas kasumiaruande skeemis.
        if (in_array($line, ['is_financial_income','is_subsidiary_result','is_associate_result','is_biological_asset_result','is_other_financial_income'], true) && $type === 'expense') $normalSign = -1;
    if (str_starts_with($line, 'bs_') && in_array($type, ['asset', 'liability', 'equity'], true)) {
        $balance[$line]['current'] = ($balance[$line]['current'] ?? 0) + $normalSign * cents((string) $row['current_balance_raw']);
        $balance[$line]['previous'] = ($balance[$line]['previous'] ?? 0) + $normalSign * cents((string) $row['previous_balance_raw']);
    }
    if (str_starts_with($line, 'is_') && in_array($type, ['income', 'expense'], true)) {
        $income[$line]['current'] = ($income[$line]['current'] ?? 0) + $normalSign * cents((string) $row['current_period_raw']);
        $income[$line]['previous'] = ($income[$line]['previous'] ?? 0) + $normalSign * cents((string) $row['previous_period_raw']);
    }
}

$currentIncome = 0; $previousIncome = 0; $currentExpenses = 0; $previousExpenses = 0;
foreach ($income as $line => $amounts) {
    if (in_array($line, ['is_sales','is_other_operating_income','is_financial_income','is_subsidiary_result','is_associate_result','is_biological_asset_result','is_interest_income','is_other_financial_income','is_other_income'], true)) {
        $currentIncome += $amounts['current'] ?? 0;
        $previousIncome += $amounts['previous'] ?? 0;
    } else {
        $currentExpenses += $amounts['current'] ?? 0;
        $previousExpenses += $amounts['previous'] ?? 0;
    }
}
$profitCurrent = $currentIncome - $currentExpenses;
$profitPrevious = $previousIncome - $previousExpenses;
$balance['bs_current_result']['current'] = ($balance['bs_current_result']['current'] ?? 0) + $profitCurrent;
$balance['bs_current_result']['previous'] = ($balance['bs_current_result']['previous'] ?? 0) + $profitPrevious;

$currentAssetLines = ['bs_cash','bs_financial_assets','bs_receivables','bs_related_receivables','bs_tax_receivables','bs_loan_receivables','bs_other_receivables','bs_prepayments','bs_inventory','bs_inventory_materials','bs_inventory_wip','bs_inventory_finished','bs_inventory_prepayments','bs_biological_assets','bs_other_assets'];
$noncurrentAssetLines = ['bs_long_term_subsidiaries','bs_long_term_associates','bs_long_term_financial_assets','bs_long_term_receivables','bs_long_term_related_receivables','bs_long_term_tax_receivables','bs_long_term_loan_receivables','bs_long_term_other_receivables','bs_long_term_prepayments','bs_investment_property','bs_fixed_assets','bs_noncurrent_biological_assets','bs_intangible_assets','bs_other_noncurrent_assets'];
$currentLiabilityLines = ['bs_loans','bs_payables','bs_employee_payables','bs_tax_liabilities','bs_other_liabilities','bs_current_deferred_income','bs_current_advances','bs_current_provisions','bs_current_grants','bs_dividend_liabilities'];
$noncurrentLiabilityLines = ['bs_long_term_loans','bs_long_term_payables','bs_long_term_employee_payables','bs_long_term_tax_liabilities','bs_long_term_other_liabilities','bs_long_term_deferred_income','bs_long_term_advances','bs_long_term_provisions','bs_long_term_grants'];
$assetLines = array_merge($currentAssetLines, $noncurrentAssetLines);
$liabilityLines = array_merge($currentLiabilityLines, $noncurrentLiabilityLines);
$equityLines = ['bs_other_equity','bs_share_capital','bs_unregistered_share_capital','bs_unpaid_share_capital','bs_share_premium','bs_treasury_shares','bs_reserve_capital','bs_other_reserves','bs_retained_earnings','bs_current_result'];
$balanceTotals = [];
foreach (['current','previous'] as $period) {
    $currentAssets = 0; $noncurrentAssets = 0; $currentLiabilities = 0; $noncurrentLiabilities = 0; $equity = 0;
    foreach ($currentAssetLines as $line) $currentAssets += $balance[$line][$period] ?? 0;
    foreach ($noncurrentAssetLines as $line) $noncurrentAssets += $balance[$line][$period] ?? 0;
    foreach ($currentLiabilityLines as $line) $currentLiabilities += $balance[$line][$period] ?? 0;
    foreach ($noncurrentLiabilityLines as $line) $noncurrentLiabilities += $balance[$line][$period] ?? 0;
    foreach ($equityLines as $line) $equity += $balance[$line][$period] ?? 0;
    $assets = $currentAssets + $noncurrentAssets;
    $liabilities = $currentLiabilities + $noncurrentLiabilities;
    $balanceTotals[$period] = [
        'current_assets' => $currentAssets, 'noncurrent_assets' => $noncurrentAssets, 'assets' => $assets,
        'current_liabilities' => $currentLiabilities, 'noncurrent_liabilities' => $noncurrentLiabilities,
        'liabilities' => $liabilities, 'equity' => $equity,
        'liabilities_equity' => $liabilities + $equity, 'difference' => $assets - $liabilities - $equity,
    ];
}
$incomeGroups = [
    'Tulud' => ['is_sales','is_other_operating_income','is_financial_income','is_interest_income','is_other_financial_income','is_other_income'],
    'Kulud' => ['is_goods','is_personnel','is_depreciation','is_other_operating_expenses','is_financial_expenses','is_income_tax','is_other_expenses'],
];
$scheme2Rows = [];
if ($incomeScheme === 2) {
    $lineValue = static fn(string $line, string $period): int => (int) ($income[$line][$period] ?? 0);
    $makeRow = static function (string $label, callable $current, callable $previous): array {
        return ['label'=>$label, 'current'=>$current(), 'previous'=>$previous()];
    };
    $sales = $makeRow('Müügitulu', fn()=>$lineValue('is_sales','current'), fn()=>$lineValue('is_sales','previous'));
    $costOfSales = $makeRow('Müüdud toodangu (kaupade, teenuste) kulu', fn()=>$lineValue('is_goods','current'), fn()=>$lineValue('is_goods','previous'));
    $grossCurrent = $sales['current'] - $costOfSales['current'];
    $grossPrevious = $sales['previous'] - $costOfSales['previous'];
    $distribution = $makeRow('Turustuskulud', fn()=>$lineValue('is_distribution','current'), fn()=>$lineValue('is_distribution','previous'));
    $admin = $makeRow('Üldhalduskulud', fn()=>$lineValue('is_admin','current') + $lineValue('is_personnel','current') + $lineValue('is_depreciation','current'), fn()=>$lineValue('is_admin','previous') + $lineValue('is_personnel','previous') + $lineValue('is_depreciation','previous'));
    $otherIncome = $makeRow('Muud äritulud', fn()=>$lineValue('is_other_operating_income','current') + $lineValue('is_other_income','current'), fn()=>$lineValue('is_other_operating_income','previous') + $lineValue('is_other_income','previous'));
    $otherOperatingExpense = $makeRow('Muud ärikulud', fn()=>$lineValue('is_other_operating_expenses','current') + $lineValue('is_other_expenses','current'), fn()=>$lineValue('is_other_operating_expenses','previous') + $lineValue('is_other_expenses','previous'));
    $biologicalResult = $makeRow('Kasum (kahjum) bioloogilistelt varadelt', fn()=>$lineValue('is_biological_asset_result','current'), fn()=>$lineValue('is_biological_asset_result','previous'));
    $operatingCurrent = $grossCurrent + $biologicalResult['current'] - $distribution['current'] - $admin['current'] + $otherIncome['current'] - $otherOperatingExpense['current'];
    $operatingPrevious = $grossPrevious + $biologicalResult['previous'] - $distribution['previous'] - $admin['previous'] + $otherIncome['previous'] - $otherOperatingExpense['previous'];
    $investmentResult = $makeRow('Kasum (kahjum) finantsinvesteeringutelt', fn()=>$lineValue('is_financial_income','current'), fn()=>$lineValue('is_financial_income','previous'));
    $interestIncome = $makeRow('Intressitulud', fn()=>$lineValue('is_interest_income','current'), fn()=>$lineValue('is_interest_income','previous'));
    $interestExpense = $makeRow('Intressikulud', fn()=>$lineValue('is_financial_expenses','current'), fn()=>$lineValue('is_financial_expenses','previous'));
    $otherFinance = $makeRow('Muud finantstulud ja -kulud', fn()=>$lineValue('is_other_financial_income','current'), fn()=>$lineValue('is_other_financial_income','previous'));
    $subsidiaryResult = $makeRow('Kasum (kahjum) tütarettevõtjatelt', fn()=>$lineValue('is_subsidiary_result','current'), fn()=>$lineValue('is_subsidiary_result','previous'));
    $associateResult = $makeRow('Kasum (kahjum) sidusettevõtjatelt', fn()=>$lineValue('is_associate_result','current'), fn()=>$lineValue('is_associate_result','previous'));
    $pretaxCurrent = $operatingCurrent + $subsidiaryResult['current'] + $associateResult['current'] + $investmentResult['current'] + $interestIncome['current'] - $interestExpense['current'] + $otherFinance['current'];
    $pretaxPrevious = $operatingPrevious + $subsidiaryResult['previous'] + $associateResult['previous'] + $investmentResult['previous'] + $interestIncome['previous'] - $interestExpense['previous'] + $otherFinance['previous'];
    $tax = $makeRow('Tulumaks', fn()=>$lineValue('is_income_tax','current'), fn()=>$lineValue('is_income_tax','previous'));
    $scheme2Rows = [
        $sales, $costOfSales,
        ['label'=>'Brutokasum (-kahjum)', 'current'=>$grossCurrent, 'previous'=>$grossPrevious, 'total'=>true],
        $biologicalResult, $distribution, $admin, $otherIncome, $otherOperatingExpense,
        ['label'=>'Ärikasum (-kahjum)', 'current'=>$operatingCurrent, 'previous'=>$operatingPrevious, 'total'=>true],
        $subsidiaryResult, $associateResult, $investmentResult, $interestIncome, $interestExpense, $otherFinance,
        ['label'=>'Kasum enne tulumaksustamist', 'current'=>$pretaxCurrent, 'previous'=>$pretaxPrevious, 'total'=>true],
        $tax,
        ['label'=>'Aruandeaasta kasum (-kahjum)', 'current'=>$profitCurrent, 'previous'=>$profitPrevious, 'total'=>true],
    ];
}

if (($_GET['download'] ?? '') === 'xbrl') {
    $xbrlErrors = [];
    $registryCode = trim((string) ($company['registry_code'] ?? ''));
    if (!preg_match('/^[0-9]{8}$/', $registryCode)) {
        $xbrlErrors[] = 'Ettevõtte registrikood peab olema 8-kohaline.';
    }
    if ((string) $profile['report_category'] !== 'small' || $incomeScheme !== 2) {
        $xbrlErrors[] = 'Esimene XBRL eksport toetab praegu väikeettevõtja aruannet ja kasumiaruande skeemi 2.';
    }

    // We only export concepts confirmed by the supplied RIK-generated XBRL sample.
    // Do not silently omit populated lines for which the exact taxonomy concept is not mapped yet.
    // The official taxonomy reports trade, tax, employee and other payables as
    // aggregate "payables and prepayments" concepts, not as separate facts.
    $supportedBalanceLines = [
        'bs_cash','bs_financial_assets','bs_inventory','bs_inventory_materials','bs_inventory_wip','bs_inventory_finished','bs_inventory_prepayments',
        'bs_loans','bs_long_term_loans','bs_payables','bs_employee_payables','bs_tax_liabilities','bs_dividend_liabilities','bs_other_liabilities',
        'bs_current_deferred_income','bs_current_advances','bs_long_term_payables','bs_long_term_employee_payables','bs_long_term_tax_liabilities',
        'bs_long_term_other_liabilities','bs_long_term_deferred_income','bs_long_term_advances',
        'bs_share_capital','bs_retained_earnings','bs_current_result'
    ];
    foreach ($balance as $lineCode => $amounts) {
        if (!in_array($lineCode, $supportedBalanceLines, true)
            && ((int) ($amounts['current'] ?? 0) !== 0 || (int) ($amounts['previous'] ?? 0) !== 0)) {
            $xbrlErrors[] = 'Bilansi rida vajab enne eksporti taksonoomiaseost: ' . ($labels[$lineCode] ?? $lineCode) . '.';
        }
    }
    $supportedIncomeLines = ['is_sales','is_goods','is_distribution','is_admin','is_other_operating_expenses','is_other_expenses','is_financial_income','is_interest_income','is_other_financial_income'];
    foreach ($income as $lineCode => $amounts) {
        if (!in_array($lineCode, $supportedIncomeLines, true)
            && ((int) ($amounts['current'] ?? 0) !== 0 || (int) ($amounts['previous'] ?? 0) !== 0)) {
            $xbrlErrors[] = 'Kasumiaruande rida vajab enne eksporti taksonoomiaseost: ' . ($labels[$lineCode] ?? $lineCode) . '.';
        }
    }

    $templatePath = __DIR__ . '/annual_report_templates/Vormid_template.xsd';
    if (!is_file($templatePath)) {
        $xbrlErrors[] = 'XBRL-i vormiskeemi näidisfail puudub.';
    }
    if ($xbrlErrors) {
        http_response_code(422);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="et"><meta charset="utf-8"><title>XBRL eksport vajab täiendamist</title><body style="font-family:Arial,sans-serif;max-width:850px;margin:40px auto;padding:0 18px;color:#263746"><h1>XBRL eksport vajab täiendamist</h1><p>Faili ei loodud, sest kontroll leidis järgmised punktid:</p><ul>';
        foreach ($xbrlErrors as $error) echo '<li>' . h($error) . '</li>';
        echo '</ul><p>Kontrolli kontoseoseid ja ettevõtte registrikoodi, seejärel proovi uuesti.</p><p><a href="annual_report_preview.php?year=' . (int) $year . '">Tagasi aruande eelvaatesse</a></p></body></html>';
        exit;
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<xbrli:xbrl xmlns:iso4217="http://www.xbrl.org/2003/iso4217"'
        . ' xmlns:et-gaap="http://xbrl.eesti.ee/taxonomy/et-gaap_2026-01-01/"'
        . ' xmlns:xlink="http://www.w3.org/1999/xlink"'
        . ' xmlns:link="http://www.xbrl.org/2003/linkbase"'
        . ' xmlns:xbrli="http://www.xbrl.org/2003/instance"'
        . ' xmlns:xbrldi="http://xbrl.org/2006/xbrldi">' . "\n"
        . '  <link:schemaRef xlink:type="simple" xlink:href="Vormid_' . $registryCode . '.xsd"/>' . "\n";

    $addContext = static function (string $id, string $entity, string $periodType, string $startOrInstant, ?string $end = null): string {
        $identifier = htmlspecialchars($entity, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $period = $periodType === 'instant'
            ? '<xbrli:instant>' . $startOrInstant . '</xbrli:instant>'
            : '<xbrli:startDate>' . $startOrInstant . '</xbrli:startDate><xbrli:endDate>' . (string) $end . '</xbrli:endDate>';
        return '  <xbrli:context id="' . $id . '"><xbrli:entity><xbrli:identifier scheme="http://xbrl.eesti.ee/estonian_commercial_register">'
            . $identifier . '</xbrli:identifier></xbrli:entity><xbrli:period>' . $period . '</xbrli:period></xbrli:context>' . "\n";
    };
    $xml .= $addContext('I1', $registryCode, 'instant', $end);
    $xml .= $addContext('I2', $registryCode, 'instant', $previousEnd);
    $xml .= $addContext('D1', $registryCode, 'duration', $start, $end);
    $xml .= $addContext('D2', $registryCode, 'duration', $previousStart, $previousEnd);
    $xml .= '  <xbrli:unit id="valuuta"><xbrli:measure>iso4217:EUR</xbrli:measure></xbrli:unit>' . "\n";

    $roundEuro = static fn(int $cents): int => (int) round($cents / 100, 0, PHP_ROUND_HALF_UP);
    $fact = static function (string $name, int $amount, string $context) use ($roundEuro): string {
        return '  <et-gaap:' . $name . ' contextRef="' . $context . '" unitRef="valuuta" decimals="0">'
            . $roundEuro($amount) . '</et-gaap:' . $name . '>' . "\n";
    };
    $inventoryLines = ['bs_inventory','bs_inventory_materials','bs_inventory_wip','bs_inventory_finished','bs_inventory_prepayments'];
    foreach (['current'=>'I1','previous'=>'I2'] as $period => $context) {
        $inventory = 0;
        foreach ($inventoryLines as $lineCode) $inventory += (int) ($balance[$lineCode][$period] ?? 0);
        $currentAssets = (int) $balanceTotals[$period]['current_assets'];
        $noncurrentAssets = (int) $balanceTotals[$period]['noncurrent_assets'];
        $currentLiabilities = (int) $balanceTotals[$period]['current_liabilities'];
        $noncurrentLiabilities = (int) $balanceTotals[$period]['noncurrent_liabilities'];
        $shortTermPayables = 0;
        foreach (['bs_payables','bs_employee_payables','bs_tax_liabilities','bs_dividend_liabilities','bs_other_liabilities','bs_current_deferred_income','bs_current_advances'] as $lineCode) {
            $shortTermPayables += (int) ($balance[$lineCode][$period] ?? 0);
        }
        $longTermPayables = 0;
        foreach (['bs_long_term_payables','bs_long_term_employee_payables','bs_long_term_tax_liabilities','bs_long_term_other_liabilities','bs_long_term_deferred_income','bs_long_term_advances'] as $lineCode) {
            $longTermPayables += (int) ($balance[$lineCode][$period] ?? 0);
        }
        $xml .= $fact('CashAndCashEquivalents', (int) ($balance['bs_cash'][$period] ?? 0), $context);
        $xml .= $fact('ShortTermFinancialInvestments', (int) ($balance['bs_financial_assets'][$period] ?? 0), $context);
        $xml .= $fact('Inventories', $inventory, $context);
        $xml .= $fact('CurrentAssets', $currentAssets, $context);
        $xml .= $fact('NonCurrentAssets', $noncurrentAssets, $context);
        $xml .= $fact('Assets', $currentAssets + $noncurrentAssets, $context);
        $xml .= $fact('ShortTermLoanLiabilities', (int) ($balance['bs_loans'][$period] ?? 0), $context);
        $xml .= $fact('ShortTermPayablesAndPrepayments', $shortTermPayables, $context);
        $xml .= $fact('CurrentLiabilities', $currentLiabilities, $context);
        $xml .= $fact('LongTermLoanLiabilities', (int) ($balance['bs_long_term_loans'][$period] ?? 0), $context);
        $xml .= $fact('LongTermPayablesAndPrepayments', $longTermPayables, $context);
        $xml .= $fact('NonCurrentLiabilities', $noncurrentLiabilities, $context);
        $xml .= $fact('Liabilities', $currentLiabilities + $noncurrentLiabilities, $context);
        $xml .= $fact('IssuedCapital', (int) ($balance['bs_share_capital'][$period] ?? 0), $context);
        $xml .= $fact('RetainedEarningsLoss', (int) ($balance['bs_retained_earnings'][$period] ?? 0), $context);
        $xml .= $fact('AnnualPeriodProfitLoss', (int) ($balance['bs_current_result'][$period] ?? 0), $context);
        $xml .= $fact('Equity', (int) $balanceTotals[$period]['equity'], $context);
        $xml .= $fact('LiabilitiesAndEquity', (int) $balanceTotals[$period]['liabilities_equity'], $context);
    }

    foreach (['current'=>'D1','previous'=>'D2'] as $period => $context) {
        $getIncome = static fn(string $lineCode): int => (int) ($income[$lineCode][$period] ?? 0);
        $sales = $getIncome('is_sales');
        $cost = $getIncome('is_goods');
        $distribution = $getIncome('is_distribution');
        $otherExpense = $getIncome('is_admin') + $getIncome('is_other_operating_expenses') + $getIncome('is_other_expenses');
        $total = $period === 'current' ? (int) $profitCurrent : (int) $profitPrevious;
        $beforeTax = 0;
        foreach ($scheme2Rows as $row) if (($row['label'] ?? '') === 'Kasum enne tulumaksustamist') $beforeTax = (int) $row[$period];
        if ($period === 'previous') {
            // The previous-period subtotal is read from the same scheme-2 row.
            foreach ($scheme2Rows as $row) if (($row['label'] ?? '') === 'Kasum enne tulumaksustamist') $beforeTax = (int) $row['previous'];
        }
        $xml .= $fact('Revenue', $sales, $context);
        $xml .= $fact('CostOfSales', -$cost, $context);
        $xml .= $fact('TotalGrossProfitLoss', $sales - $cost, $context);
        $xml .= $fact('DistributionCosts', -$distribution, $context);
        $xml .= $fact('OtherExpense', -$otherExpense, $context);
        $operating = $sales - $cost - $distribution - $otherExpense;
        $xml .= $fact('TotalProfitLoss', $operating, $context);
        $xml .= $fact('GainLossFromFinancialInvestments', $getIncome('is_financial_income'), $context);
        $xml .= $fact('InterestIncome', $getIncome('is_interest_income'), $context);
        $xml .= $fact('OtherFinancialIncomeAndExpense', $getIncome('is_other_financial_income'), $context);
        $xml .= $fact('TotalProfitLossBeforeTax', $beforeTax, $context);
        $xml .= $fact('TotalAnnualPeriodProfitLoss', $total, $context);
    }
    $xml .= '</xbrli:xbrl>';

    $schemaName = 'Vormid_' . $registryCode . '.xsd';
    $schemaContents = file_get_contents($templatePath);
    if ($schemaContents === false) {
        http_response_code(500);
        exit('XBRL-i vormiskeemi lugemine ebaõnnestus.');
    }
    // Build a small ZIP archive using the standard "store" method. This keeps
    // the exporter usable even when PHP's optional ZipArchive extension is off.
    $zipFiles = [
        'Aruanne_' . $registryCode . '.xbrl' => $xml,
        $schemaName => $schemaContents,
    ];
    $zipBody = '';
    $zipCentral = '';
    $zipOffset = 0;
    $zipCount = 0;
    $zipNow = getdate();
    $zipDosTime = (($zipNow['hours'] & 31) << 11) | (($zipNow['minutes'] & 63) << 5) | ((int) ($zipNow['seconds'] / 2) & 31);
    $zipDosDate = ((max(1980, $zipNow['year']) - 1980) << 9) | ($zipNow['mon'] << 5) | $zipNow['mday'];
    foreach ($zipFiles as $fileName => $fileContents) {
        $fileName = (string) $fileName;
        $nameLength = strlen($fileName);
        $dataLength = strlen($fileContents);
        $crc = crc32($fileContents);
        if ($crc < 0) $crc += 4294967296;
        $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $zipDosTime, $zipDosDate, $crc, $dataLength, $dataLength, $nameLength, 0);
        $zipBody .= $localHeader . $fileName . $fileContents;
        $zipCentral .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $zipDosTime, $zipDosDate, $crc, $dataLength, $dataLength, $nameLength, 0, 0, 0, 0, 0, $zipOffset) . $fileName;
        $zipOffset += strlen($localHeader) + $nameLength + $dataLength;
        $zipCount++;
    }
    $zipArchive = $zipBody . $zipCentral . pack('VvvvvVVv', 0x06054b50, 0, 0, $zipCount, $zipCount, strlen($zipCentral), strlen($zipBody), 0);
    $downloadName = 'majandusaasta_aruanne_' . $year . '_' . $registryCode . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . (string) strlen($zipArchive));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $zipArchive;
    exit;
}

if (($_GET['download'] ?? '') === 'csv') {
    $filename = 'majandusaasta_aruanne_' . $year . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        http_response_code(500);
        exit('CSV-faili loomine ebaõnnestus.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    $writeCsv = static function (array $fields) use ($output): void {
        fputcsv($output, $fields, ';', '"', '');
    };
    $csvAmount = static fn(int $cents): string => number_format($cents / 100, 2, ',', '');
    $writeCsv(['Ettevõte', (string) $company['name']]);
    $writeCsv(['Aruandekategooria', $categoryNames[(string) $profile['report_category']] ?? '']);
    $writeCsv(['Kasumiaruande skeem', (string) $incomeScheme]);
    $writeCsv(['Aruandeperiood', $start . ' kuni ' . $end]);
    $writeCsv(['Võrdlusperiood', $previousStart . ' kuni ' . $previousEnd]);
    $writeCsv([]);
    $writeCsv(['Aruandeosa', 'Ametlik aruanderida', 'Perioodi lõpp / periood', 'Summa EUR', 'Võrdlusperioodi lõpp / periood', 'Võrdlussumma EUR']);
    $writeBalanceCsv = static function (string $section, array $lineCodes, string $totalLabel, string $totalKey) use ($writeCsv, $labels, $balance, $balanceTotals, $csvAmount, $end, $previousEnd): void {
        foreach ($lineCodes as $lineCode) {
            $currentValue = (int) ($balance[$lineCode]['current'] ?? 0);
            $previousValue = (int) ($balance[$lineCode]['previous'] ?? 0);
            if ($currentValue === 0 && $previousValue === 0) continue;
            $writeCsv([$section, $labels[$lineCode] ?? $lineCode, $end, $csvAmount($currentValue), $previousEnd, $csvAmount($previousValue)]);
        }
        $writeCsv([$section, $totalLabel, $end, $csvAmount((int) $balanceTotals['current'][$totalKey]), $previousEnd, $csvAmount((int) $balanceTotals['previous'][$totalKey])]);
    };
    $writeBalanceCsv('Bilanss · Käibevarad', $currentAssetLines, 'Kokku käibevarad', 'current_assets');
    $writeBalanceCsv('Bilanss · Põhivarad', $noncurrentAssetLines, 'Kokku põhivarad', 'noncurrent_assets');
    $writeCsv(['Bilanss', 'KOKKU VARAD', $end, $csvAmount((int) $balanceTotals['current']['assets']), $previousEnd, $csvAmount((int) $balanceTotals['previous']['assets'])]);
    $writeBalanceCsv('Bilanss · Lühiajalised kohustised', $currentLiabilityLines, 'Kokku lühiajalised kohustised', 'current_liabilities');
    $writeBalanceCsv('Bilanss · Pikaajalised kohustised', $noncurrentLiabilityLines, 'Kokku pikaajalised kohustised', 'noncurrent_liabilities');
    $writeCsv(['Bilanss', 'Kokku kohustised', $end, $csvAmount((int) $balanceTotals['current']['liabilities']), $previousEnd, $csvAmount((int) $balanceTotals['previous']['liabilities'])]);
    $writeBalanceCsv('Bilanss · Omakapital', $equityLines, 'Kokku omakapital', 'equity');
    $writeCsv(['Bilanss', 'KOKKU KOHUSTISED JA OMAKAPITAL', $end, $csvAmount((int) $balanceTotals['current']['liabilities_equity']), $previousEnd, $csvAmount((int) $balanceTotals['previous']['liabilities_equity'])]);
    if ($incomeScheme === 2) {
        foreach ($scheme2Rows as $row) {
            $currentValue = (int) $row['current'];
            $previousValue = (int) $row['previous'];
            if (empty($row['total']) && $currentValue === 0 && $previousValue === 0) continue;
            $writeCsv(['Kasumiaruanne · skeem 2', (string) $row['label'], $start . ' kuni ' . $end, $csvAmount($currentValue), $previousStart . ' kuni ' . $previousEnd, $csvAmount($previousValue)]);
        }
    } else {
        foreach ($incomeGroups as $heading => $lineCodes) {
            foreach ($lineCodes as $lineCode) {
                $currentValue = (int) ($income[$lineCode]['current'] ?? 0);
                $previousValue = (int) ($income[$lineCode]['previous'] ?? 0);
                if ($currentValue === 0 && $previousValue === 0) continue;
                $writeCsv(['Kasumiaruanne · skeem 1 · ' . $heading, $labels[$lineCode] ?? $lineCode, $start . ' kuni ' . $end, $csvAmount($currentValue), $previousStart . ' kuni ' . $previousEnd, $csvAmount($previousValue)]);
            }
        }
        $writeCsv(['Kasumiaruanne · skeem 1', 'Aruandeaasta kasum (kahjum)', $start . ' kuni ' . $end, $csvAmount($profitCurrent), $previousStart . ' kuni ' . $previousEnd, $csvAmount($profitPrevious)]);
    }
    fclose($output);
    exit;
}
$downloadPdf = ($_GET['download'] ?? '') === 'pdf';
if ($downloadPdf) {
    ob_start();
}
?>
<!doctype html>
<html lang="et"><head>
    <link rel="icon" type="image/png" href="favicon.png?v=1"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Aruanderidade eelvaade</title>
<?php if ($downloadPdf): ?>
<style>
@page { margin: 36pt 36pt 42pt; }
body { font: 9pt "DejaVu Sans", sans-serif; color: #263746; }
h1, h2 { color: #17324d; }
h1 { font-size: 19pt; margin: 0 0 8pt; }
h2 { font-size: 13pt; margin: 16pt 0 5pt; page-break-after: avoid; }
.report-meta { margin: 0 0 10pt; line-height: 1.55; }
table { width: 100%; border-collapse: collapse; }
thead { display: table-header-group; }
tr { page-break-inside: avoid; }
th, td { padding: 5pt 6pt; text-align: left; border-bottom: 0.5pt solid #dce3ec; }
th { background: #edf2f8; color: #17324d; }
.num { width: 20%; text-align: right; white-space: nowrap; }
.total td { font-weight: bold; background: #f4f7fb; border-top: 0.5pt solid #acbac9; }
.ok, .review { padding: 5pt 7pt; margin: 5pt 0; }
.ok { color: #176b3a; background: #eaf7ef; }
.review { color: #9b2525; background: #fff0f0; }
</style>
<?php else: ?>
<style>*{box-sizing:border-box}body{max-width:1250px;margin:30px auto;padding:0 18px;background:#f4f7fb;color:#263746;font-family:Arial,sans-serif}h1,h2{color:#17324d}.card{margin:16px 0;padding:20px;background:#fff;border:1px solid #dce3ec;border-radius:12px;box-shadow:0 4px 14px rgba(25,45,70,.04)}.topbar,.actions,.filters{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.muted{color:#667788;line-height:1.5}.button,button{display:inline-block;min-height:40px;padding:10px 14px;border:0;border-radius:7px;background:#1769aa;color:#fff;font:inherit;font-weight:600;text-decoration:none;cursor:pointer}.secondary{background:#657789}label{display:grid;gap:6px;font-weight:600}select{min-height:42px;padding:8px;border:1px solid #b8c4d1;border-radius:7px;font:inherit}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e2e8ef;text-align:left}th{background:#f2f5f9;color:#42566b}.num{text-align:right;white-space:nowrap}.total td{font-weight:700;border-top:2px solid #cbd6e2}.ok{padding:13px;background:#eaf7ef;border:1px solid #b8dfc7;border-radius:8px;color:#176b3a;font-weight:700}.review{padding:13px;background:#fff0f0;border:1px solid #edc4c4;border-radius:8px;color:#9b2525;font-weight:700}.note{padding:13px 15px;border-radius:8px;background:#eaf3fb;color:#17324d;line-height:1.5}.table-wrap{overflow-x:auto}@media(max-width:700px){body{margin:18px auto;padding:0 12px}}</style>
<?php endif; ?>
</head><body>
<?php if ($downloadPdf): ?>
<header>
    <h1>Majandusaasta aruandeandmed</h1>
    <p class="report-meta">
        <strong><?= h((string) $company['name']) ?></strong> · registrikood <?= h((string) $company['registry_code']) ?><br>
        <?= h($categoryNames[(string) $profile['report_category']] ?? '') ?> · kasumiaruande skeem <?= $incomeScheme ?><br>
        Aruandeperiood <?= h($start) ?> kuni <?= h($end) ?><br>
        Võrdlusperiood <?= h($previousStart) ?> kuni <?= h($previousEnd) ?>
    </p>
</header>
<?php else: ?>
<header class="topbar"><div><h1>Aruanderidade eelvaade</h1><p class="muted"><?= h((string) $company['name']) ?> · <?= h($categoryNames[(string)$profile['report_category']] ?? '') ?> · kasumiaruande skeem <?= $incomeScheme ?>.</p></div><div class="actions"><a class="button" href="annual_report_preview.php?year=<?= $year ?>&amp;download=xbrl">Laadi XBRL aruanne alla</a><a class="button secondary" href="annual_report_preview.php?year=<?= $year ?>&amp;download=csv">Laadi aruandeandmed CSV-na</a><a class="button secondary" href="annual_report_preview.php?year=<?= $year ?>&amp;download=pdf">Laadi aruandeandmed PDF-na</a><a class="button secondary" href="annual_report_profile.php">Aruandeprofiil</a><a class="button secondary" href="annual_report_prep.php?year=<?= $year ?>">Aruande ettevalmistus</a><a class="button secondary" href="annual_report_mapping.php">Muuda kontoseoseid</a></div></header>
<section class="card"><form method="get" class="filters"><label>Majandusaasta algusaasta<select name="year"><?php for ($y = $firstYear; $y <= $currentFiscalYear; $y++): ?><option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></label><button type="submit">Näita</button></form><p><strong>Valitud periood:</strong> <?= h($start) ?> kuni <?= h($end) ?> · <strong>võrdlus:</strong> <?= h($previousStart) ?> kuni <?= h($previousEnd) ?></p></section>
<?php endif; ?>
<?php if ($mappedCount < $accountCount): ?><p class="review">Kaardistatud on <?= $mappedCount ?> kontot <?= $accountCount ?>-st. Seo ülejäänud kontod enne eelvaate kasutamist.</p><?php endif; ?>
<?php if (!$downloadPdf): ?><p class="note">XBRL-ekspordi esimene versioon sisaldab bilansi ja kasumiaruande skeemi 2 põhiandmeid. Kui mõnel aruandereal puudub täpne taksonoomiaseos, eksport peatub ja näitab puuduva rea. XBRL-i laadimine võib e-Äriregistris juba sisestatud vormiandmed asendada; proovi esmalt testaruandega ning täida pärast importi lisad ja muud aruande osad. CSV jääb käsitsi sisestamise abifailiks.</p>
<?php endif; ?>
<section class="card"><h2>Bilanss</h2><div class="table-wrap"><table><thead><tr><th>Rida</th><th class="num"><?= $downloadPdf ? h($end) : $year . ' lõpus' ?></th><th class="num"><?= $downloadPdf ? h($previousEnd) : ($year - 1) . ' lõpus' ?></th></tr></thead><tbody>
<?php foreach ([['Käibevarad',$currentAssetLines,'current_assets','Kokku käibevarad'],['Põhivarad',$noncurrentAssetLines,'noncurrent_assets','Kokku põhivarad']] as [$heading,$groupLines,$totalKey,$totalLabel]): ?><tr><th colspan="3"><?= h($heading) ?></th></tr><?php foreach ($groupLines as $line): if (!isset($balance[$line])) continue; ?><tr><td><?= h($labels[$line]) ?></td><td class="num"><?= h(eur((int) ($balance[$line]['current'] ?? 0))) ?></td><td class="num"><?= h(eur((int) ($balance[$line]['previous'] ?? 0))) ?></td></tr><?php endforeach; ?><tr class="total"><td><?= h($totalLabel) ?></td><td class="num"><?= h(eur($balanceTotals['current'][$totalKey])) ?></td><td class="num"><?= h(eur($balanceTotals['previous'][$totalKey])) ?></td></tr><?php endforeach; ?>
<tr class="total"><td>KOKKU VARAD</td><td class="num"><?= h(eur($balanceTotals['current']['assets'])) ?></td><td class="num"><?= h(eur($balanceTotals['previous']['assets'])) ?></td></tr>
<?php foreach ([['Lühiajalised kohustised',$currentLiabilityLines,'current_liabilities','Kokku lühiajalised kohustised'],['Pikaajalised kohustised',$noncurrentLiabilityLines,'noncurrent_liabilities','Kokku pikaajalised kohustised']] as [$heading,$groupLines,$totalKey,$totalLabel]): ?><tr><th colspan="3"><?= h($heading) ?></th></tr><?php foreach ($groupLines as $line): if (!isset($balance[$line])) continue; ?><tr><td><?= h($labels[$line]) ?></td><td class="num"><?= h(eur((int) ($balance[$line]['current'] ?? 0))) ?></td><td class="num"><?= h(eur((int) ($balance[$line]['previous'] ?? 0))) ?></td></tr><?php endforeach; ?><tr class="total"><td><?= h($totalLabel) ?></td><td class="num"><?= h(eur($balanceTotals['current'][$totalKey])) ?></td><td class="num"><?= h(eur($balanceTotals['previous'][$totalKey])) ?></td></tr><?php endforeach; ?>
<tr class="total"><td>Kokku kohustised</td><td class="num"><?= h(eur($balanceTotals['current']['liabilities'])) ?></td><td class="num"><?= h(eur($balanceTotals['previous']['liabilities'])) ?></td></tr>
<tr><th colspan="3">Omakapital</th></tr><?php foreach ($equityLines as $line): if (!isset($balance[$line])) continue; ?><tr><td><?= h($labels[$line]) ?></td><td class="num"><?= h(eur((int) ($balance[$line]['current'] ?? 0))) ?></td><td class="num"><?= h(eur((int) ($balance[$line]['previous'] ?? 0))) ?></td></tr><?php endforeach; ?>
<tr class="total"><td>Kokku omakapital</td><td class="num"><?= h(eur($balanceTotals['current']['equity'])) ?></td><td class="num"><?= h(eur($balanceTotals['previous']['equity'])) ?></td></tr>
<tr class="total"><td>KOKKU KOHUSTISED JA OMAKAPITAL</td><td class="num"><?= h(eur($balanceTotals['current']['liabilities_equity'])) ?></td><td class="num"><?= h(eur($balanceTotals['previous']['liabilities_equity'])) ?></td></tr></tbody></table></div>
<?php foreach (['current'=>'Valitud aasta','previous'=>'Võrdlusaasta'] as $period=>$periodLabel): $isBalanced = $balanceTotals[$period]['difference'] === 0; ?><p class="<?= $isBalanced ? 'ok' : 'review' ?>"><?= h($periodLabel) ?> bilansi erinevus: <?= h(eur($balanceTotals[$period]['difference'])) ?> · <?= $isBalanced ? 'bilanss klapib' : 'vajab kontrollimist' ?></p><?php endforeach; ?></section>
<section class="card"><h2>Kasumiaruanne · skeem <?= $incomeScheme ?></h2><div class="table-wrap"><table><thead><tr><th>Rida</th><th class="num"><?= $year ?></th><th class="num"><?= $year - 1 ?></th></tr></thead><tbody>
<?php if ($incomeScheme === 2): foreach ($scheme2Rows as $row): ?><tr class="<?= !empty($row['total']) ? 'total' : '' ?>"><td><?= h($row['label']) ?></td><td class="num"><?= h(eur((int)$row['current'])) ?></td><td class="num"><?= h(eur((int)$row['previous'])) ?></td></tr><?php endforeach; else: ?>
<?php foreach ($incomeGroups as $heading=>$groupLines): ?><tr><th colspan="3"><?= h($heading) ?></th></tr><?php foreach ($groupLines as $line): if (!isset($income[$line])) continue; ?><tr><td><?= h($labels[$line]) ?></td><td class="num"><?= h(eur((int) ($income[$line]['current'] ?? 0))) ?></td><td class="num"><?= h(eur((int) ($income[$line]['previous'] ?? 0))) ?></td></tr><?php endforeach; ?><?php endforeach; ?>
<tr class="total"><td>Tulud kokku</td><td class="num"><?= h(eur($currentIncome)) ?></td><td class="num"><?= h(eur($previousIncome)) ?></td></tr><tr class="total"><td>Kulud kokku</td><td class="num"><?= h(eur($currentExpenses)) ?></td><td class="num"><?= h(eur($previousExpenses)) ?></td></tr><tr class="total"><td>Aruandeaasta kasum või kahjum</td><td class="num"><?= h(eur($profitCurrent)) ?></td><td class="num"><?= h(eur($profitPrevious)) ?></td></tr><?php endif; ?></tbody></table></div></section>
</body></html>
<?php
if ($downloadPdf) {
    $html = ob_get_clean();
    try {
        require_once __DIR__ . '/vendor/autoload.php';
        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('isJavascriptEnabled', false);
        $pdf = new \Dompdf\Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(487, 810, 'Leht {PAGE_NUM} / {PAGE_COUNT}', $font, 8, [0.35, 0.42, 0.50]);
        $contents = $pdf->output();
    } catch (Throwable $error) {
        error_log('Aastaaruande PDF: ' . $error->getMessage());
        http_response_code(500);
        exit('Aruande PDF-faili loomine ebaõnnestus.');
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="majandusaasta_aruandeandmed_' . $year . '.pdf"');
    header('Cache-Control: no-store');
    echo $contents;
    exit;
}
