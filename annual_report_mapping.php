<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/db.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function cents(string $value): int
{
    $negative = isset($value[0]) && $value[0] === '-';
    $value = ltrim($value, '-');
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
    $amount = (int) $whole * 100 + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    return $negative ? -$amount : $amount;
}

function eur(int $value): string
{
    return number_format($value / 100, 2, ',', ' ') . ' €';
}

$companyId = (int) $_SESSION['company_id'];
$profileStmt = $pdo->prepare('SELECT report_category, income_statement_scheme FROM annual_report_settings WHERE company_id = ?');
$profileStmt->execute([$companyId]);
$profile = $profileStmt->fetch(PDO::FETCH_ASSOC) ?: ['report_category' => 'small', 'income_statement_scheme' => 2];
$scheme = (int) $profile['income_statement_scheme'];
$categoryNames = ['micro'=>'Mikroettevõtja','small'=>'Väikeettevõtja','medium'=>'Keskmise suurusega ettevõtja','large'=>'Suurettevõtja'];
$lines = [
    'bs_cash' => ['Bilanss · Käibevarad · Raha', ['asset']],
    'bs_receivables' => ['Bilanss · Käibevarad · Nõuded ja ettemaksed · Nõuded ostjate vastu', ['asset']],
    'bs_related_receivables' => ['Bilanss · Käibevarad · Nõuded ja ettemaksed · Nõuded seotud osapoolte vastu', ['asset']],
    'bs_tax_receivables' => ['Bilanss · Käibevarad · Nõuded ja ettemaksed · Maksude ettemaksed ja tagasinõuded', ['asset']],
    'bs_loan_receivables' => ['Bilanss · Käibevarad · Nõuded ja ettemaksed · Laenunõuded', ['asset']],
    'bs_other_receivables' => ['Bilanss · Käibevarad · Nõuded ja ettemaksed · Muud nõuded', ['asset']],
    'bs_prepayments' => ['Bilanss · Käibevarad · Nõuded ja ettemaksed · Ettemaksed', ['asset']],
    'bs_inventory' => ['Bilanss · Käibevarad · Varud · Müügiks ostetud kaubad', ['asset']],
    'bs_inventory_materials' => ['Bilanss · Käibevarad · Varud · Tooraine ja materjal', ['asset']],
    'bs_inventory_wip' => ['Bilanss · Käibevarad · Varud · Lõpetamata toodang', ['asset']],
    'bs_inventory_finished' => ['Bilanss · Käibevarad · Varud · Valmistoodang', ['asset']],
    'bs_inventory_prepayments' => ['Bilanss · Käibevarad · Varud · Ettemaksed varude eest', ['asset']],
    'bs_biological_assets' => ['Bilanss · Käibevarad · Bioloogilised varad', ['asset']],
    'bs_long_term_subsidiaries' => ['Bilanss · Põhivarad · Investeeringud tütar- ja sidusettevõtjatesse · Tütarettevõtjate aktsiad ja osad', ['asset']],
    'bs_long_term_associates' => ['Bilanss · Põhivarad · Investeeringud tütar- ja sidusettevõtjatesse · Sidusettevõtjate aktsiad ja osad', ['asset']],
    'bs_financial_assets' => ['Bilanss · Käibevarad · Finantsinvesteeringud', ['asset']],
    'bs_long_term_financial_assets' => ['Bilanss · Põhivarad · Finantsinvesteeringud', ['asset']],
    'bs_long_term_receivables' => ['Bilanss · Põhivarad · Nõuded ja ettemaksed · Nõuded ostjate vastu', ['asset']],
    'bs_long_term_related_receivables' => ['Bilanss · Põhivarad · Nõuded ja ettemaksed · Nõuded seotud osapoolte vastu', ['asset']],
    'bs_long_term_tax_receivables' => ['Bilanss · Põhivarad · Nõuded ja ettemaksed · Maksude ettemaksed ja tagasinõuded', ['asset']],
    'bs_long_term_loan_receivables' => ['Bilanss · Põhivarad · Nõuded ja ettemaksed · Laenunõuded', ['asset']],
    'bs_long_term_other_receivables' => ['Bilanss · Põhivarad · Nõuded ja ettemaksed · Muud nõuded', ['asset']],
    'bs_long_term_prepayments' => ['Bilanss · Põhivarad · Nõuded ja ettemaksed · Ettemaksed', ['asset']],
    'bs_investment_property' => ['Bilanss · Põhivarad · Kinnisvarainvesteeringud', ['asset']],
    'bs_fixed_assets' => ['Bilanss · Põhivarad · Materiaalsed põhivarad', ['asset']],
    'bs_intangible_assets' => ['Bilanss · Põhivarad · Immateriaalsed põhivarad', ['asset']],
    'bs_noncurrent_biological_assets' => ['Bilanss · Põhivarad · Bioloogilised varad', ['asset']],
    'bs_other_assets' => ['Bilanss · Käibevarad · Nõuded ja ettemaksed · Muud nõuded', ['asset']],
    'bs_other_noncurrent_assets' => ['Bilanss · Põhivarad · Nõuded ja ettemaksed · Muud nõuded', ['asset']],
    'bs_payables' => ['Bilanss · Lühiajalised kohustised · Võlad ja ettemaksed · Võlad tarnijatele', ['liability']],
    'bs_employee_payables' => ['Bilanss · Lühiajalised kohustised · Võlad ja ettemaksed · Võlad töövõtjatele', ['liability']],
    'bs_tax_liabilities' => ['Bilanss · Lühiajalised kohustised · Võlad ja ettemaksed · Maksuvõlad', ['liability']],
    'bs_loans' => ['Bilanss · Lühiajalised kohustised · Laenukohustised (tasumine 12 kuu jooksul)', ['liability']],
    'bs_long_term_loans' => ['Bilanss · Pikaajalised kohustised · Laenukohustised (tasumine hiljem kui 12 kuu jooksul)', ['liability']],
    'bs_dividend_liabilities' => ['Bilanss · Lühiajalised kohustised · Võlad ja ettemaksed · Muud võlad (sh dividendid)', ['liability']],
    'bs_other_liabilities' => ['Bilanss · Lühiajalised kohustised · Võlad ja ettemaksed · Muud võlad', ['liability']],
    'bs_current_deferred_income' => ['Bilanss · Lühiajalised kohustised · Võlad ja ettemaksed · Tulevaste perioodide tulud', ['liability']],
    'bs_current_advances' => ['Bilanss · Lühiajalised kohustised · Võlad ja ettemaksed · Muud saadud ettemaksed', ['liability']],
    'bs_current_provisions' => ['Bilanss · Lühiajalised kohustised · Eraldised', ['liability']],
    'bs_current_grants' => ['Bilanss · Lühiajalised kohustised · Sihtfinantseerimine', ['liability']],
    'bs_long_term_payables' => ['Bilanss · Pikaajalised kohustised · Võlad ja ettemaksed · Võlad tarnijatele', ['liability']],
    'bs_long_term_employee_payables' => ['Bilanss · Pikaajalised kohustised · Võlad ja ettemaksed · Võlad töövõtjatele', ['liability']],
    'bs_long_term_tax_liabilities' => ['Bilanss · Pikaajalised kohustised · Võlad ja ettemaksed · Maksuvõlad', ['liability']],
    'bs_long_term_other_liabilities' => ['Bilanss · Pikaajalised kohustised · Võlad ja ettemaksed · Muud võlad', ['liability']],
    'bs_long_term_deferred_income' => ['Bilanss · Pikaajalised kohustised · Võlad ja ettemaksed · Tulevaste perioodide tulud', ['liability']],
    'bs_long_term_advances' => ['Bilanss · Pikaajalised kohustised · Võlad ja ettemaksed · Muud saadud ettemaksed', ['liability']],
    'bs_long_term_provisions' => ['Bilanss · Pikaajalised kohustised · Eraldised', ['liability']],
    'bs_long_term_grants' => ['Bilanss · Pikaajalised kohustised · Sihtfinantseerimine', ['liability']],
    'bs_share_capital' => ['Bilanss · Omakapital · Aktsiakapital või osakapital nimiväärtuses', ['equity']],
    'bs_unregistered_share_capital' => ['Bilanss · Omakapital · Registreerimata aktsia- või osakapital', ['equity']],
    'bs_unpaid_share_capital' => ['Bilanss · Omakapital · Sissemaksmata osakapital', ['equity']],
    'bs_share_premium' => ['Bilanss · Omakapital · Ülekurss', ['equity']],
    'bs_treasury_shares' => ['Bilanss · Omakapital · Oma aktsiad või osad', ['equity']],
    'bs_reserve_capital' => ['Bilanss · Omakapital · Kohustuslik reservkapital', ['equity']],
    'bs_other_reserves' => ['Bilanss · Omakapital · Muud reservid', ['equity']],
    'bs_retained_earnings' => ['Bilanss · Omakapital · Eelmiste perioodide jaotamata kasum (kahjum)', ['equity']],
    'bs_current_result' => ['Bilanss · Omakapital · Aruandeaasta kasum (kahjum)', ['equity']],
    'bs_other_equity' => ['Bilanss · Omakapital · Muu omakapital', ['equity']],
    'is_sales' => ['Kasumiaruanne · Müügitulu', ['income']],
    'is_other_operating_income' => ['Kasumiaruanne · Muud äritulud', ['income']],
    'is_financial_income' => ['Kasumiaruanne · Kasum (kahjum) finantsinvesteeringutelt', ['income','expense']],
    'is_subsidiary_result' => ['Kasumiaruanne · Kasum (kahjum) tütarettevõtjatelt', ['income','expense']],
    'is_associate_result' => ['Kasumiaruanne · Kasum (kahjum) sidusettevõtjatelt', ['income','expense']],
    'is_biological_asset_result' => ['Kasumiaruanne · Kasum (kahjum) bioloogilistelt varadelt', ['income','expense']],
    'is_interest_income' => ['Kasumiaruanne · Intressitulud', ['income']],
    'is_other_financial_income' => ['Kasumiaruanne · Muud finantstulud ja -kulud', ['income','expense']],
    'is_other_income' => ['Kasumiaruanne · Muud äritulud', ['income']],
    'is_goods' => [$scheme === 2 ? 'Kasumiaruanne · Müüdud toodangu (kaupade, teenuste) kulu' : 'Kasumiaruanne · Kaubad, toore, materjal ja teenused', ['expense']],
    'is_personnel' => ['Kasumiaruanne · Tööjõukulud', ['expense']],
    'is_depreciation' => ['Kasumiaruanne · Põhivara kulum ja väärtuse langus', ['expense']],
    'is_admin' => ['Kasumiaruanne · Üldhalduskulud (skeem 2)', ['expense']],
    'is_distribution' => ['Kasumiaruanne · Turustuskulud (skeem 2)', ['expense']],
    'is_other_operating_expenses' => ['Kasumiaruanne · Muud ärikulud', ['expense']],
    'is_financial_expenses' => ['Kasumiaruanne · Intressikulud', ['expense']],
    'is_income_tax' => ['Kasumiaruanne · Tulumaks', ['expense']],
    'is_other_expenses' => ['Kasumiaruanne · Muud kulud', ['expense']],
];
if ($scheme === 2) {
    unset($lines['is_personnel'], $lines['is_depreciation']);
    unset($lines['is_other_expenses']);
    $lines['is_other_operating_expenses'][0] = 'Kasumiaruanne · Muud ärikulud';
} else {
    unset($lines['is_admin'], $lines['is_distribution']);
    $lines['is_other_operating_expenses'][0] = 'Kasumiaruanne · Muud tegevuskulud';
}

function suggestedLine(array $account, int $scheme): string
{
    $type = (string) $account['account_type'];
    $code = (string) $account['code'];
    $name = mb_strtolower((string) $account['name'], 'UTF-8');
    if ($type === 'asset') {
        if (preg_match('/^(1000|1010|1020|1030)$/', $code)) return 'bs_cash';
        if ($code === '1100' || str_contains($name, 'aktsia') || str_contains($name, 'väärtpaber')) return 'bs_financial_assets';
        if ($code === '1200' || str_contains($name, 'nõue')) return 'bs_receivables';
        if ($code === '1250' || str_contains($name, 'varu')) return 'bs_inventory';
        if ($code === '1500' || str_contains($name, 'materiaalne põhivara')) return 'bs_fixed_assets';
        if (str_contains($name, 'immateriaalne')) return 'bs_intangible_assets';
        return 'bs_other_assets';
    }
    if ($type === 'liability') {
        if ($code === '2200' || str_contains($name, 'laen')) return 'bs_loans';
        if ($code === '2300' || str_contains($name, 'dividend')) return 'bs_dividend_liabilities';
        if ($code === '2100' || preg_match('/^(2110|2120|2130|2140|2150|2160|2170)$/', $code) || str_contains($name, 'maks')) return 'bs_tax_liabilities';
        if ($code === '2010' || str_contains($name, 'töötaj')) return 'bs_employee_payables';
        if ($code === '2000' || str_contains($name, 'tarnij')) return 'bs_payables';
        if (str_contains($name, 'võlg')) return 'bs_other_liabilities';
        return 'bs_other_liabilities';
    }
    if ($type === 'equity') {
        if ($code === '3010' || str_contains($name, 'osakapital')) return 'bs_share_capital';
        if ($code === '3020' || str_contains($name, 'eelmiste perioodide')) return 'bs_retained_earnings';
        if ($code === '3100' || str_contains($name, 'aruandeaasta')) return 'bs_current_result';
        return 'bs_other_equity';
    }
    if ($type === 'income') {
        if ($code === '4000' || str_contains($name, 'müügitulu')) return 'is_sales';
        if (in_array($code, ['4100','4250'], true) || str_contains($name, 'väärtpaber') || str_contains($name, 'dividend')) return 'is_financial_income';
        if ($code === '4200' || str_contains($name, 'intress')) return 'is_interest_income';
        if ($code === '4150' || str_contains($name, 'finant')) return 'is_other_financial_income';
        if ($code === '4300') return 'is_other_income';
        return 'is_other_operating_income';
    }
    if ($type === 'expense') {
        if ($code === '5000') return 'is_goods';
        if ($scheme === 2) {
            if ($code === '6500' || str_contains($name, 'tulumaks')) return 'is_income_tax';
            if ($code === '5050' || str_contains($name, 'väärtpaber')) return 'is_financial_income';
            if (in_array($code, ['6000','6010','6020','6100','6200','6300','6400','6510'], true)) return 'is_admin';
            return 'is_other_operating_expenses';
        }
        if (in_array($code, ['6000', '6010', '6020', '6510'], true) || str_contains($name, 'tööjõu') || str_contains($name, 'sotsiaalmaks') || str_contains($name, 'töötuskindlust')) return 'is_personnel';
        if ($code === '6500' || str_contains($name, 'tulumaks')) return 'is_income_tax';
        if ($code === '5050' || str_contains($name, 'väärtpaber')) return 'is_financial_expenses';
        if ($code === '1500' || str_contains($name, 'kulum')) return 'is_depreciation';
        if ($code === '6200' || $code === '6300' || $code === '6400' || $code === '6100') return 'is_other_operating_expenses';
        return 'is_other_expenses';
    }
    return '';
}

$errors = [];
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) $_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    } else {
        $postedMappings = $_POST['mapping'] ?? [];
        if (!is_array($postedMappings)) $postedMappings = [];
        $accountsStmt = $pdo->prepare('SELECT id, account_type FROM accounts WHERE company_id = ?');
        $accountsStmt->execute([$companyId]);
        $accountTypes = [];
        foreach ($accountsStmt->fetchAll(PDO::FETCH_ASSOC) as $accountRow) $accountTypes[(int) $accountRow['id']] = (string) $accountRow['account_type'];

        foreach ($accountTypes as $accountId => $type) {
            $lineCode = (string) ($postedMappings[$accountId] ?? '');
            if (!isset($lines[$lineCode]) || !in_array($type, $lines[$lineCode][1], true)) {
                $errors[] = 'Vali igale kontole sobiv aruanderida.';
                break;
            }
        }
        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $saveStmt = $pdo->prepare(
                    'INSERT INTO annual_report_account_mappings (company_id, account_id, income_scheme, report_line_code)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE report_line_code = VALUES(report_line_code), updated_at = CURRENT_TIMESTAMP'
                );
                foreach ($accountTypes as $accountId => $_type) {
                    $saveStmt->execute([$companyId, $accountId, $scheme, (string) $postedMappings[$accountId]]);
                }
                $pdo->commit();
                $message = 'Kontode aruanderidade seosed salvestati.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log($e->getMessage());
                $errors[] = 'Seoste salvestamine ebaõnnestus. Kontrolli, kas andmebaasi uuendus on tehtud.';
            }
        }
    }
}

$accountsStmt = $pdo->prepare(
    'SELECT a.id, a.code, a.name, a.account_type, a.is_active,
            m.report_line_code,
            COALESCE(SUM(CASE WHEN je.id IS NOT NULL THEN jl.debit - jl.credit ELSE 0 END), 0) AS raw_balance
     FROM accounts a
     LEFT JOIN annual_report_account_mappings m ON m.account_id = a.id AND m.company_id = a.company_id AND m.income_scheme = ?
     LEFT JOIN journal_lines jl ON jl.account_id = a.id
     LEFT JOIN journal_entries je ON je.id = jl.entry_id AND je.company_id = a.company_id AND je.status = \'posted\' AND je.entry_date <= CURRENT_DATE
     WHERE a.company_id = ?
     GROUP BY a.id, a.code, a.name, a.account_type, a.is_active, m.report_line_code
     ORDER BY a.code'
);
$accountsStmt->execute([$scheme, $companyId]);
$accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);
$mappedCount = 0;
foreach ($accounts as &$account) {
    $account['line_code'] = (string) ($account['report_line_code'] ?: suggestedLine($account, $scheme));
    if (!isset($lines[$account['line_code']]) || !in_array($account['account_type'], $lines[$account['line_code']][1], true)) {
        $account['line_code'] = suggestedLine($account, $scheme);
    }
    if ($account['line_code'] !== '') $mappedCount++;
    $raw = cents((string) $account['raw_balance']);
    $account['balance_cents'] = in_array($account['account_type'], ['asset', 'expense'], true) ? $raw : -$raw;
    $account['mapping_warning'] = '';
    if ((string) $account['code'] === '1100') {
        $account['mapping_warning'] = 'Kontrolli liigitust: lühiajaliselt kauplemiseks hoitavad väärtpaberid on käibevara; pikaajalised investeeringud põhivara finantsinvesteeringud.';
    } elseif ((string) $account['code'] === '2200') {
        $account['mapping_warning'] = 'Kontrolli laenulepingut: 12 kuu jooksul tasutav osa on lühiajaline, hiljem tasutav osa pikaajaline. Segaosa korral jaga kontod või saldo tähtaja järgi.';
    }
}
unset($account);
$balancesByLine = [];
foreach ($accounts as $account) {
    $balancesByLine[$account['line_code']] = ($balancesByLine[$account['line_code']] ?? 0) + $account['balance_cents'];
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kontode seosed majandusaasta aruandega</title>
    <style>
        *{box-sizing:border-box}body{max-width:1250px;margin:30px auto;padding:0 18px;background:#f4f7fb;color:#263746;font-family:Arial,sans-serif}h1,h2{color:#17324d}.card{margin:16px 0;padding:20px;background:#fff;border:1px solid #dce3ec;border-radius:12px;box-shadow:0 4px 14px rgba(25,45,70,.04)}.topbar,.actions{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.muted{color:#667788;line-height:1.5}.button,button{display:inline-block;min-height:40px;padding:10px 14px;border:0;border-radius:7px;background:#1769aa;color:#fff;font:inherit;font-weight:600;text-decoration:none;cursor:pointer}.secondary{background:#657789}.note{padding:13px 15px;border-radius:8px;background:#eaf3fb;color:#17324d;line-height:1.5}.success{padding:12px 14px;border:1px solid #b8dfc7;border-radius:8px;background:#eaf7ef;color:#176b3a}.error{padding:12px 14px;border:1px solid #edc4c4;border-radius:8px;background:#fff0f0;color:#9b2525}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:900px}th,td{padding:10px 9px;border-bottom:1px solid #e2e8ef;text-align:left;vertical-align:middle}th{background:#f2f5f9;color:#42566b}select{width:100%;min-height:40px;padding:8px;border:1px solid #b8c4d1;border-radius:6px;background:#fff;font:inherit}.num{text-align:right;white-space:nowrap}.badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#edf2f7;color:#536477;font-size:12px}.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.summary div{padding:13px;border:1px solid #e1e7ef;border-radius:8px;background:#f8fafc}.summary strong{display:block;color:#17324d;font-size:18px;margin-top:5px}.sticky{position:sticky;bottom:0;padding:12px;background:rgba(244,247,251,.95);border-top:1px solid #dce3ec;text-align:right}
    </style>
</head>
<body>
<header class="topbar"><div><h1>Kontode seosed majandusaasta aruandega</h1><p class="muted">Aruandeprofiil: <?= h($categoryNames[(string)$profile['report_category']] ?? '') ?> · kasumiaruande skeem <?= $scheme ?>. Skeemide kontoseosed salvestatakse eraldi.</p></div><div class="actions"><a class="button secondary" href="annual_report_profile.php">Muuda aruandeprofiili</a><a class="button secondary" href="annual_report_preview.php">Aruanderidade eelvaade</a><a class="button secondary" href="annual_report_prep.php">Tagasi aruande ettevalmistusse</a><a class="button secondary" href="accounts.php">Kontoplaan</a></div></header>
<div class="note"><strong>Ametlike ridade kaardistus:</strong> valikud kasutavad raamatupidamise seaduse bilansiskeemi nimetusi ja RTJ 2 kasumiaruande skeemi <?= $scheme ?> ridu. E-äriregistri väikese ettevõtja vorm võib osa ridu koondada. Laenude ning finantsinvesteeringute tähtaeg ja eesmärk tuleb kontrollida; allpool kuvatakse nende kontode juures täpsem hoiatus.</div>
<?php if ($message !== ''): ?><p class="success"><?= h($message) ?></p><?php endif; ?>
<?php foreach ($errors as $error): ?><p class="error"><?= h($error) ?></p><?php endforeach; ?>
<section class="card"><div class="summary"><div>Kontosid<strong><?= count($accounts) ?></strong></div><div>Esialgse või salvestatud seosega<strong><?= $mappedCount ?> / <?= count($accounts) ?></strong></div><div>Vaba valik<strong>Muuda iga rida rippmenüüst</strong></div></div></section>
<form method="post">
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
    <section class="card"><h2>Kontoplaani kaardistus</h2><p class="muted">Saldo on kogu pearaamatu kinnitatud kannete netosaldo tänase seisuga. See aitab seoseid kontrollida, kuid ei asenda valitud majandusaasta aruande kuupäeva saldoarvutust.</p><div class="table-wrap"><table><thead><tr><th>Konto</th><th>Tüüp</th><th>Aruanderida</th><th class="num">Saldo</th><th>Seose allikas</th></tr></thead><tbody>
    <?php foreach ($accounts as $account): $type = (string) $account['account_type']; $current = $account['line_code']; $saved = (string) ($account['report_line_code'] ?? ''); ?>
        <tr><td><?= h((string) $account['code'] . ' — ' . (string) $account['name']) ?><?= !(int) $account['is_active'] ? ' <span class="badge">Mitteaktiivne</span>' : '' ?><?php if ($account['mapping_warning'] !== ''): ?><div class="muted" style="max-width:360px;color:#8a5a00;font-size:13px;margin-top:5px"><?= h($account['mapping_warning']) ?></div><?php endif; ?></td><td><?= h(['asset'=>'Vara','liability'=>'Kohustus','equity'=>'Omakapital','income'=>'Tulu','expense'=>'Kulu'][$type] ?? $type) ?></td><td><select name="mapping[<?= (int) $account['id'] ?>]" required><?php foreach ($lines as $lineCode => [$label, $allowedTypes]): if (!in_array($type, $allowedTypes, true)) continue; ?><option value="<?= h($lineCode) ?>" <?= $current === $lineCode ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></td><td class="num"><?= h(eur((int) $account['balance_cents'])) ?></td><td><?= $saved !== '' ? '<span class="badge">Salvestatud</span>' : '<span class="badge">Esialgne soovitus</span>' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></section>
    <section class="card"><h2>Rühmade saldode kontroll</h2><p class="muted">Rühmade summad koondatakse valitud rippmenüüde järgi. Tulu ja kohustus kuvatakse kreeditnormaalse saldo märgiga.</p><div class="table-wrap"><table><thead><tr><th>Aruanderida</th><th class="num">Koondsaldo</th></tr></thead><tbody><?php foreach ($lines as $lineCode => [$label, $_types]): if (!array_key_exists($lineCode, $balancesByLine)) continue; ?><tr><td><?= h($label) ?></td><td class="num"><?= h(eur((int) $balancesByLine[$lineCode])) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
    <div class="sticky"><button type="submit">Salvesta kontode seosed</button></div>
</form>
</body></html>
