<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function annualCents(string $value): int
{
    $negative = isset($value[0]) && $value[0] === '-';
    $value = ltrim($value, '-');
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
    $cents = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    return $negative ? -$cents : $cents;
}

function annualMoney(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

function annualCheckClass(bool $ok): string
{
    return $ok ? 'status-ok' : 'status-review';
}

$companyId = (int) $_SESSION['company_id'];
$companyStmt = $pdo->prepare('SELECT name, fiscal_year_start FROM companies WHERE id = ?');
$companyStmt->execute([$companyId]);
$company = $companyStmt->fetch(PDO::FETCH_ASSOC);
if (!$company) {
    http_response_code(404);
    exit('Ettevõtte andmeid ei leitud.');
}

$today = new DateTimeImmutable('today');
$fiscalStart = new DateTimeImmutable((string) $company['fiscal_year_start']);
$fiscalMonthDay = $fiscalStart->format('m-d');
$firstYear = (int) $fiscalStart->format('Y');
$currentCalendarYear = (int) $today->format('Y');
$currentFiscalYear = $today->format('m-d') >= $fiscalMonthDay ? $currentCalendarYear : $currentCalendarYear - 1;
$currentFiscalYear = max($firstYear, $currentFiscalYear);
$selectedYear = filter_var($_GET['year'] ?? null, FILTER_VALIDATE_INT);
$selectedYear = $selectedYear === false || $selectedYear === null ? $currentFiscalYear : (int) $selectedYear;
if ($selectedYear < $firstYear || $selectedYear > $currentFiscalYear) {
    $selectedYear = $currentFiscalYear;
}

$periodStart = sprintf('%04d-%s', $selectedYear, $fiscalMonthDay);
$periodStartDate = new DateTimeImmutable($periodStart);
$periodEnd = $periodStartDate->modify('+1 year')->modify('-1 day')->format('Y-m-d');
$previousStart = $periodStartDate->modify('-1 year')->format('Y-m-d');
$previousEnd = $periodStartDate->modify('-1 day')->format('Y-m-d');
$periodEnded = $periodEnd <= $today->format('Y-m-d');
$checks = [];

// Kehtiv aruandekategooria ja kontode seosed ametlike aruanderidadega.
$reportProfileStmt = $pdo->prepare('SELECT report_category, income_statement_scheme FROM annual_report_settings WHERE company_id = ?');
$reportProfileStmt->execute([$companyId]);
$reportProfile = $reportProfileStmt->fetch(PDO::FETCH_ASSOC) ?: ['report_category' => 'small', 'income_statement_scheme' => 2];
$schemeForMapping = (int) $reportProfile['income_statement_scheme'];
$mappingStatusStmt = $pdo->prepare(
    'SELECT COUNT(DISTINCT a.id) AS account_count, COUNT(DISTINCT m.account_id) AS mapped_count
     FROM accounts a
     LEFT JOIN annual_report_account_mappings m
       ON m.account_id = a.id AND m.company_id = a.company_id AND m.income_scheme = ?
     WHERE a.company_id = ?'
);
$mappingStatusStmt->execute([$schemeForMapping, $companyId]);
$mappingStatus = $mappingStatusStmt->fetch(PDO::FETCH_ASSOC) ?: ['account_count' => 0, 'mapped_count' => 0];
$accountCountForReport = (int) $mappingStatus['account_count'];
$mappedAccountCount = (int) $mappingStatus['mapped_count'];
$categoryLabels = ['micro' => 'mikroettevõtja', 'small' => 'väikeettevõtja', 'medium' => 'keskmise suurusega ettevõtja', 'large' => 'suurettevõtja'];
$checks[] = [
    'title' => 'Kontode aruanderidade seosed',
    'ok' => $accountCountForReport === $mappedAccountCount,
    'detail' => $mappedAccountCount . ' / ' . $accountCountForReport . ' kontot seotud · ' . ($categoryLabels[(string) $reportProfile['report_category']] ?? 'aruandekategooria') . ' · skeem ' . $schemeForMapping,
    'link' => 'annual_report_mapping.php', 'link_text' => 'Kontrolli kontoseoseid',
    'extra_link' => 'annual_report_preview.php?year=' . rawurlencode((string) $selectedYear), 'extra_link_text' => 'Ava aruande eelvaade',
];

// Mustandid ja iga kinnitatud pearaamatukande tasakaal.
$draftStmt = $pdo->prepare(
    "SELECT
        (SELECT COUNT(*) FROM journal_entries WHERE company_id = ? AND status = 'draft' AND entry_date BETWEEN ? AND ?)
      + (SELECT COUNT(*) FROM sales_invoices WHERE company_id = ? AND status = 'draft' AND invoice_date BETWEEN ? AND ?)
      + (SELECT COUNT(*) FROM purchase_invoices WHERE company_id = ? AND status = 'draft' AND invoice_date BETWEEN ? AND ?)"
);
$draftStmt->execute([$companyId, $periodStart, $periodEnd, $companyId, $periodStart, $periodEnd, $companyId, $periodStart, $periodEnd]);
$draftCount = (int) $draftStmt->fetchColumn();
$unbalancedStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM (
        SELECT je.id
        FROM journal_entries je
        LEFT JOIN journal_lines jl ON jl.entry_id = je.id
        WHERE je.company_id = ? AND je.status = 'posted' AND je.entry_date BETWEEN ? AND ?
        GROUP BY je.id
        HAVING COUNT(jl.id) < 2 OR COALESCE(SUM(jl.debit), 0) <> COALESCE(SUM(jl.credit), 0)
    ) entries_to_review"
);
$unbalancedStmt->execute([$companyId, $periodStart, $periodEnd]);
$unbalancedCount = (int) $unbalancedStmt->fetchColumn();
$checks[] = [
    'title' => 'Kanded ja mustandid',
    'ok' => $draftCount === 0 && $unbalancedCount === 0,
    'detail' => $draftCount . ' mustandit · ' . $unbalancedCount . ' tasakaalustamata kinnitatud kannet',
    'link' => 'index.php', 'link_text' => 'Avaleht',
];

// Kuude sulgemised majandusaasta sees.
$monthStmt = $pdo->prepare(
    'SELECT period_start, action FROM accounting_period_events
     WHERE company_id = ? AND period_start >= ? AND period_start <= ? ORDER BY id'
);
$monthStmt->execute([$companyId, $periodStart, $periodEnd]);
$monthStates = [];
foreach ($monthStmt->fetchAll(PDO::FETCH_ASSOC) as $monthRow) {
    $monthStates[$monthRow['period_start']] = $monthRow['action'];
}
$closedMonths = 0;
$monthRows = [];
$month = $periodStartDate;
for ($i = 0; $i < 12; $i++) {
    $monthKey = $month->modify('first day of this month')->format('Y-m-d');
    $closed = ($monthStates[$monthKey] ?? null) === 'closed';
    if ($closed) $closedMonths++;
    $monthRows[] = ['label' => $month->format('m.Y'), 'closed' => $closed];
    $month = $month->modify('first day of next month');
}
$checks[] = [
    'title' => 'Kuude sulgemine',
    'ok' => $closedMonths === 12,
    'detail' => $closedMonths . ' kuud 12-st suletud' . ($periodEnded ? '' : ' · majandusaasta pole veel lõppenud'),
    'link' => 'closed_periods.php', 'link_text' => 'Ava perioodi sulgemine',
];

// Pangaväljavõtte perioodi read, mis pole kande ega mustandiga seotud.
$bankStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM bank_statement_lines
     WHERE company_id = ? AND transaction_date BETWEEN ? AND ?
       AND matched_journal_line_id IS NULL AND draft_journal_entry_id IS NULL'
);
$bankStmt->execute([$companyId, $periodStart, $periodEnd]);
$unmatchedBankCount = (int) $bankStmt->fetchColumn();
$checks[] = [
    'title' => 'Pangatehingud',
    'ok' => $unmatchedBankCount === 0,
    'detail' => $unmatchedBankCount . ' sidumata pangarida valitud perioodis',
    'link' => 'bank_statement.php', 'link_text' => 'Ava pangaväljavõte',
];

// Kliendi- ja tarnijaarvete tasumata saldod perioodi lõpu seisuga.
$salesStmt = $pdo->prepare(
    'SELECT si.id, COALESCE(invoice_totals.total, 0) - COALESCE(payment_totals.total, 0) AS outstanding
     FROM sales_invoices si
     LEFT JOIN (SELECT invoice_id, SUM(gross_amount) AS total FROM sales_invoice_lines GROUP BY invoice_id) invoice_totals ON invoice_totals.invoice_id = si.id
     LEFT JOIN (SELECT invoice_id, SUM(amount) AS total FROM invoice_payments WHERE payment_date <= ? GROUP BY invoice_id) payment_totals ON payment_totals.invoice_id = si.id
     WHERE si.company_id = ? AND si.status = \'posted\' AND si.invoice_date <= ?'
);
$salesStmt->execute([$periodEnd, $companyId, $periodEnd]);
$openSalesCount = 0;
$openSalesCents = 0;
foreach ($salesStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $outstanding = annualCents((string) $row['outstanding']);
    if ($outstanding > 0) { $openSalesCount++; $openSalesCents += $outstanding; }
}
$purchaseStmt = $pdo->prepare(
    'SELECT pi.id, COALESCE(invoice_totals.total, 0) - COALESCE(payment_totals.total, 0) AS outstanding
     FROM purchase_invoices pi
     LEFT JOIN (SELECT invoice_id, SUM(gross_amount) AS total FROM purchase_invoice_lines GROUP BY invoice_id) invoice_totals ON invoice_totals.invoice_id = pi.id
     LEFT JOIN (SELECT invoice_id, SUM(amount) AS total FROM purchase_invoice_payments WHERE payment_date <= ? GROUP BY invoice_id) payment_totals ON payment_totals.invoice_id = pi.id
     WHERE pi.company_id = ? AND pi.status = \'posted\' AND pi.invoice_date <= ?'
);
$purchaseStmt->execute([$periodEnd, $companyId, $periodEnd]);
$openPurchaseCount = 0;
$openPurchaseCents = 0;
foreach ($purchaseStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $outstanding = annualCents((string) $row['outstanding']);
    if ($outstanding > 0) { $openPurchaseCount++; $openPurchaseCents += $outstanding; }
}
$checks[] = [
    'title' => 'Ostjate nõuded ja tarnijate võlad',
    'ok' => $openSalesCount === 0 && $openPurchaseCount === 0,
    'detail' => 'Tasumata müügiarveid ' . $openSalesCount . ' (' . annualMoney($openSalesCents) . ') · tasumata ostuarveid ' . $openPurchaseCount . ' (' . annualMoney($openPurchaseCents) . ')',
    'link' => 'receivables_check.php?as_of_date=' . rawurlencode($periodEnd), 'link_text' => 'Kontrolli nõuete saldot',
    'extra_link' => 'purchase_invoices.php?payment_filter=unpaid', 'extra_link_text' => 'Ostuarved',
];

// Varude FIFO-väärtuse ja varukontode pearaamatu saldo võrdlus.
$stockStmt = $pdo->prepare(
    'SELECT p.id, p.inventory_account_id,
            COALESCE(SUM(CASE WHEN m.id IS NOT NULL AND l.quantity_in > 0 THEN l.total_cost
                              WHEN m.id IS NOT NULL THEN -l.total_cost ELSE 0 END), 0) AS stock_value,
            COALESCE(SUM(CASE WHEN m.id IS NOT NULL THEN l.quantity_in - l.quantity_out ELSE 0 END), 0) AS quantity
     FROM inventory_products p
     LEFT JOIN inventory_movement_lines l ON l.product_id = p.id
     LEFT JOIN inventory_movements m ON m.id = l.movement_id
        AND m.company_id = p.company_id AND m.status = \'posted\' AND m.movement_date <= ?
     WHERE p.company_id = ?
     GROUP BY p.id, p.inventory_account_id'
);
$stockStmt->execute([$periodEnd, $companyId]);
$stockByAccount = [];
$unmappedStockCount = 0;
foreach ($stockStmt->fetchAll(PDO::FETCH_ASSOC) as $stockRow) {
    $value = annualCents((string) $stockRow['stock_value']);
    if (!$stockRow['inventory_account_id']) {
        if ((float) $stockRow['quantity'] !== 0.0 || $value !== 0) $unmappedStockCount++;
        continue;
    }
    $accountId = (int) $stockRow['inventory_account_id'];
    $stockByAccount[$accountId] = ($stockByAccount[$accountId] ?? 0) + $value;
}
$stockLedgerStmt = $pdo->prepare(
    'SELECT a.id, a.account_type, COALESCE(SUM(CASE WHEN je.id IS NOT NULL THEN jl.debit - jl.credit ELSE 0 END), 0) AS balance
     FROM accounts a
     LEFT JOIN journal_lines jl ON jl.account_id = a.id
     LEFT JOIN journal_entries je ON je.id = jl.entry_id
        AND je.company_id = a.company_id AND je.status = \'posted\' AND je.entry_date <= ?
     WHERE a.company_id = ? GROUP BY a.id, a.account_type'
);
$stockLedgerStmt->execute([$periodEnd, $companyId]);
$stockLedgerByAccount = [];
foreach ($stockLedgerStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $stockLedgerByAccount[(int) $row['id']] = [
        'type' => (string) $row['account_type'], 'balance' => annualCents((string) $row['balance']),
    ];
}
$stockDifferenceCount = 0;
foreach ($stockByAccount as $accountId => $stockValue) {
    $ledger = $stockLedgerByAccount[$accountId]['balance'] ?? 0;
    $type = $stockLedgerByAccount[$accountId]['type'] ?? '';
    if ($type !== 'asset' || $ledger !== $stockValue) $stockDifferenceCount++;
}
$checks[] = [
    'title' => 'Varude FIFO ja pearaamatu võrdlus',
    'ok' => $stockDifferenceCount === 0 && $unmappedStockCount === 0,
    'detail' => $stockDifferenceCount . ' erinevat varukontot · ' . $unmappedStockCount . ' tootekaarti varukontota',
    'link' => 'ladu/reconciliation.php?date=' . rawurlencode($periodEnd), 'link_text' => 'Ava varude võrdlus',
];

// Palga mustandid ja perioodi lõpuks tasumata palgalehed.
$payrollDraftStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM payroll_runs WHERE company_id = ? AND status = 'draft' AND work_month BETWEEN ? AND ?"
);
$payrollDraftStmt->execute([$companyId, $periodStart, $periodEnd]);
$payrollDraftRuns = (int) $payrollDraftStmt->fetchColumn();
$payrollUnpaidStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM payroll_run_items pri
     JOIN payroll_runs pr ON pr.id = pri.run_id AND pr.company_id = pri.company_id
     WHERE pri.company_id = ? AND pr.work_month BETWEEN ? AND ? AND pr.status <> 'draft'
       AND NOT EXISTS (
           SELECT 1 FROM payroll_run_item_payments payment
           WHERE payment.company_id = pri.company_id AND payment.run_id = pri.run_id
             AND payment.run_item_id = pri.id AND payment.actual_payment_date <= ?
       )"
);
$payrollUnpaidStmt->execute([$companyId, $periodStart, $periodEnd, $periodEnd]);
$unpaidPayrollRows = (int) $payrollUnpaidStmt->fetchColumn();
$checks[] = [
    'title' => 'Palgaarvestus',
    'ok' => $payrollDraftRuns === 0 && $unpaidPayrollRows === 0,
    'detail' => $payrollDraftRuns . ' mustandperioodi · ' . $unpaidPayrollRows . ' maksmata palgarida',
    'link' => 'palk/runs.php', 'link_text' => 'Ava palgamoodul',
];

// Palgamaksude kontodel olev saldo on ülevaatamiseks, mitte automaatne viga.
$taxStmt = $pdo->prepare(
    "SELECT a.code, a.name, COALESCE(SUM(jl.credit - jl.debit), 0) AS balance
     FROM accounts a
     LEFT JOIN journal_lines jl ON jl.account_id = a.id
     LEFT JOIN journal_entries je ON je.id = jl.entry_id
        AND je.company_id = a.company_id AND je.status = 'posted' AND je.entry_date <= ?
     WHERE a.company_id = ? AND a.account_type = 'liability'
       AND (a.code IN ('2010','2110','2120','2130','2140','2150','2160','2170')
            OR a.name LIKE '%tulumaksu%' OR a.name LIKE '%sotsiaalmaksu%'
            OR a.name LIKE '%töötuskindlust%' OR a.name LIKE '%kogumispension%')
     GROUP BY a.id, a.code, a.name ORDER BY a.code"
);
$taxStmt->execute([$periodEnd, $companyId]);
$taxBalances = [];
foreach ($taxStmt->fetchAll(PDO::FETCH_ASSOC) as $taxRow) {
    $balance = annualCents((string) $taxRow['balance']);
    if ($balance !== 0) $taxBalances[] = ['label' => $taxRow['code'] . ' — ' . $taxRow['name'], 'balance' => $balance];
}

// Majandusaasta sulgemise seis.
$yearEventStmt = $pdo->prepare(
    'SELECT action FROM accounting_year_events WHERE company_id = ? AND period_start = ? ORDER BY id DESC LIMIT 1'
);
$yearEventStmt->execute([$companyId, $periodStart]);
$yearClosed = $yearEventStmt->fetchColumn() === 'closed';

$years = range($firstYear, $currentFiscalYear);
$allChecksOk = count(array_filter($checks, static fn(array $check): bool => !$check['ok'])) === 0;
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Aastaaruande ettevalmistus</title>
    <style>
        *{box-sizing:border-box}body{max-width:1200px;margin:32px auto;padding:0 20px;background:#f4f7fb;color:#263746;font-family:Arial,sans-serif}h1,h2,h3{color:#17324d}.card{margin:16px 0;padding:20px;background:#fff;border:1px solid #dce3ec;border-radius:12px;box-shadow:0 4px 14px rgba(25,45,70,.04)}.topbar,.actions,.filters{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.muted{color:#667788;line-height:1.5}.filters{justify-content:flex-start}.filters label{display:grid;gap:6px;font-weight:600;color:#33475b}.filters input,.filters select{min-height:42px;padding:9px 11px;border:1px solid #b8c4d1;border-radius:7px;background:#fff;font:inherit}.button,button{display:inline-block;min-height:40px;padding:10px 14px;border:0;border-radius:7px;background:#1769aa;color:#fff;font:inherit;font-weight:600;text-decoration:none;cursor:pointer}.secondary{background:#657789}.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(205px,1fr));gap:12px}.summary-item{padding:14px;border:1px solid #e1e7ef;border-radius:9px;background:#f8fafc}.summary-item strong{display:block;margin-bottom:7px;color:#526273}.summary-item span{font-size:18px;font-weight:700;color:#17324d}.checks{display:grid;gap:10px}.check{display:grid;grid-template-columns:minmax(220px,1fr) auto auto;align-items:center;gap:12px;padding:13px 14px;border:1px solid #e1e7ef;border-radius:9px;background:#fbfcfe}.status-ok{color:#176b3a;font-weight:700}.status-review{color:#9a5b00;font-weight:700}.check a{white-space:nowrap;color:#1769aa;font-weight:600;text-decoration:none}.check a:hover{text-decoration:underline}.months{display:grid;grid-template-columns:repeat(6,minmax(90px,1fr));gap:8px}.month{padding:9px;border-radius:7px;background:#fff0f0;color:#9d2525;text-align:center}.month.closed{background:#eaf7ef;color:#176b3a}.balance-list{display:grid;gap:6px}.balance-row{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid #e9edf2}.note{padding:13px 15px;border-radius:8px;background:#eaf3fb;color:#17324d;line-height:1.5}.warning{padding:13px 15px;border:1px solid #ead9a3;border-radius:8px;background:#fff8e6;color:#71520a;line-height:1.5}.success{padding:13px 15px;border:1px solid #b8dfc7;border-radius:8px;background:#eaf7ef;color:#176b3a;line-height:1.5}@media(max-width:700px){body{margin:18px auto;padding:0 12px}.check{grid-template-columns:1fr;align-items:start}.months{grid-template-columns:repeat(3,minmax(70px,1fr))}}
    </style>
</head>
<body>
    <header class="topbar">
        <div><h1>Aastaaruande ettevalmistus</h1><p class="muted"><?= h((string) $company['name']) ?> · kontrolli perioodi andmeid enne aastaaruande koostamist e-äriregistris.</p></div>
        <div class="actions"><a class="button secondary" href="year_end.php?year=<?= $selectedYear ?>">Aastavahetus</a><a class="button secondary" href="index.php">Avalehele</a></div>
    </header>

    <section class="card">
        <form method="get" class="filters">
            <label>Majandusaasta algusaasta
                <select name="year"><?php foreach ($years as $year): ?><option value="<?= (int) $year ?>" <?= $year === $selectedYear ? 'selected' : '' ?>><?= (int) $year ?></option><?php endforeach; ?></select>
            </label>
            <button type="submit">Näita</button>
        </form>
        <p><strong>Periood:</strong> <?= h($periodStart) ?> kuni <?= h($periodEnd) ?></p>
        <div class="summary-grid">
            <div class="summary-item"><strong>Majandusaasta</strong><span><?= $periodEnded ? 'Lõppenud' : 'Pooleli' ?></span></div>
            <div class="summary-item"><strong>Kuude sulgemine</strong><span><?= $closedMonths ?>/12</span></div>
            <div class="summary-item"><strong>Kontrollnimekiri</strong><span><?= $allChecksOk ? 'Esialgsed kontrollid korras' : 'Vajab ülevaatust' ?></span></div>
            <div class="summary-item"><strong>Aastavahetus</strong><span><?= $yearClosed ? 'Tehtud' : 'Tegemata' ?></span></div>
        </div>
    </section>

    <?php if ($allChecksOk && $periodEnded): ?>
        <p class="success">Automaatsed põhikontrollid ei leidnud takistusi. See ei asenda algdokumentide, aruandelisade ega aruande sisu inimese poolset kontrolli.</p>
    <?php elseif (!$periodEnded): ?>
        <p class="note">Valitud majandusaasta pole veel lõppenud. Vaade aitab aasta jooksul saldosid jälgida; lõplik ettevalmistus tee pärast perioodi lõppu.</p>
    <?php endif; ?>

    <section class="card">
        <h2>Ettevalmistuse kontrollnimekiri</h2>
        <div class="checks">
            <?php foreach ($checks as $check): ?>
                <div class="check">
                    <strong><?= h($check['title']) ?></strong>
                    <span class="<?= annualCheckClass($check['ok']) ?>"><?= h($check['detail']) ?></span>
                    <span><a href="<?= h($check['link']) ?>"><?= h($check['link_text']) ?> →</a><?php if (!empty($check['extra_link'])): ?><br><a href="<?= h($check['extra_link']) ?>"><?= h($check['extra_link_text']) ?> →</a><?php endif; ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card">
        <h2>Kuude sulgemine</h2>
        <p class="muted">Aastavahetuse toimingu jaoks peavad kõik majandusaasta kuud olema suletud.</p>
        <div class="months"><?php foreach ($monthRows as $monthRow): ?><div class="month <?= $monthRow['closed'] ? 'closed' : '' ?>"><?= h($monthRow['label']) ?> · <?= $monthRow['closed'] ? 'Suletud' : 'Avatud' ?></div><?php endforeach; ?></div>
    </section>

    <section class="card">
        <h2>Aruanded ja võrdlusperiood</h2>
        <p class="muted">Vaata aruandeaasta ja eelmise aasta aruandeid kõrvuti. E-äriregistri aruandevormi valik sõltub ettevõtte suuruskategooriast ning nõutavatest lisadest.</p>
        <div class="actions">
            <a class="button secondary" href="annual_report_profile.php">Aruandeprofiil</a>
            <a class="button" href="annual_report_preview.php?year=<?= $selectedYear ?>">Aruanderidade eelvaade</a>
            <a class="button" href="annual_report_mapping.php">Seo kontod aruanderidadega</a>
            <a class="button" href="trial_balance.php?start_date=<?= rawurlencode($periodStart) ?>&amp;end_date=<?= rawurlencode($periodEnd) ?>">Aasta käibeandmik</a>
            <a class="button" href="income_statement.php?start_date=<?= rawurlencode($periodStart) ?>&amp;end_date=<?= rawurlencode($periodEnd) ?>">Kasumiaruanne <?= (int) $selectedYear ?></a>
            <a class="button secondary" href="income_statement.php?start_date=<?= rawurlencode($previousStart) ?>&amp;end_date=<?= rawurlencode($previousEnd) ?>">Kasumiaruanne <?= (int) $selectedYear - 1 ?></a>
            <a class="button" href="balance_sheet.php?as_of_date=<?= rawurlencode($periodEnd) ?>">Bilanss <?= (int) $selectedYear ?></a>
            <a class="button secondary" href="balance_sheet.php?as_of_date=<?= rawurlencode($previousEnd) ?>">Bilanss <?= (int) $selectedYear - 1 ?></a>
            <a class="button" href="cash_flows.php?start_date=<?= rawurlencode($periodStart) ?>&amp;end_date=<?= rawurlencode($periodEnd) ?>">Rahavood</a>
        </div>
    </section>

    <section class="card">
        <h2>Maksud ja palgakohustused perioodi lõpus</h2>
        <?php if (!$taxBalances): ?>
            <p class="muted">Tuvastatud palga- ja tulumaksukohustuste kontodel perioodi lõpu saldot ei ole.</p>
        <?php else: ?>
            <p class="muted">Need saldod võivad olla aasta lõpus tasumata kohustused. Kontrolli, et need vastaksid deklaratsioonidele ja maksetele.</p>
            <div class="balance-list">
                <?php foreach ($taxBalances as $balance): ?><div class="balance-row"><span><?= h($balance['label']) ?></span><strong><?= h(annualMoney($balance['balance'])) ?></strong></div><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p><a href="vat_report.php?start_date=<?= rawurlencode($periodStart) ?>&amp;end_date=<?= rawurlencode($periodEnd) ?>">Ava käibemaksu aruanne →</a> · <a href="palk/runs.php">Ava palgamoodul →</a></p>
    </section>

    <p class="warning"><strong>Oluline:</strong> see leht valmistab andmed ette ega koosta ega esita veel e-äriregistri majandusaasta aruande XBRL-faili. MTA jaoks kasutatav XBRL GL on teise eesmärgiga vorming. Aastaaruanne tuleb kontrollida, täita vajalikud lisad ja esitada e-äriregistris.</p>
</body>
</html>
