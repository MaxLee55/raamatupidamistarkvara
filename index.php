<?php
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__.'/journal_draft_lib.php';
ensureJournalCorrectionSchema($pdo);

$companyId = (int) $_SESSION['company_id'];
$today = date('Y-m-d');
$companyNameStmt = $pdo->prepare('SELECT name FROM companies WHERE id = ?');
$companyNameStmt->execute([$companyId]);
$companyName = (string) ($companyNameStmt->fetchColumn() ?: 'Ettevõte');

function entryDateIsValid(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

$draftSalesStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM sales_invoices WHERE company_id = ? AND status = 'draft'"
);
$draftSalesStmt->execute([$companyId]);
$draftSalesCount = (int) $draftSalesStmt->fetchColumn();

$draftPurchaseStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM purchase_invoices WHERE company_id = ? AND status = 'draft'"
);
$draftPurchaseStmt->execute([$companyId]);
$draftPurchaseCount = (int) $draftPurchaseStmt->fetchColumn();

$overdueSalesStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM sales_invoices si
     LEFT JOIN (
        SELECT invoice_id, SUM(gross_amount) AS gross_total
        FROM sales_invoice_lines
        GROUP BY invoice_id
     ) totals ON totals.invoice_id = si.id
     LEFT JOIN (
        SELECT invoice_id, SUM(amount) AS paid_total
        FROM invoice_payments
        GROUP BY invoice_id
     ) payments ON payments.invoice_id = si.id
     WHERE si.company_id = ?
       AND si.status = 'posted'
       AND si.due_date IS NOT NULL
       AND si.due_date < ?
       AND COALESCE(totals.gross_total, 0) > COALESCE(payments.paid_total, 0)"
);
$overdueSalesStmt->execute([$companyId, $today]);
$overdueSalesCount = (int) $overdueSalesStmt->fetchColumn();

$overduePurchaseStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM purchase_invoices pi
     LEFT JOIN (
        SELECT invoice_id, SUM(gross_amount) AS gross_total
        FROM purchase_invoice_lines
        GROUP BY invoice_id
     ) totals ON totals.invoice_id = pi.id
     LEFT JOIN (
        SELECT invoice_id, SUM(amount) AS paid_total
        FROM purchase_invoice_payments
        GROUP BY invoice_id
     ) payments ON payments.invoice_id = pi.id
     WHERE pi.company_id = ?
       AND pi.status = 'posted'
       AND pi.due_date IS NOT NULL
       AND pi.due_date < ?
       AND COALESCE(totals.gross_total, 0) > COALESCE(payments.paid_total, 0)"
);
$overduePurchaseStmt->execute([$companyId, $today]);
$overduePurchaseCount = (int) $overduePurchaseStmt->fetchColumn();

$unpaidSalesStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM sales_invoices si
     LEFT JOIN (
        SELECT invoice_id, SUM(gross_amount) AS gross_total
        FROM sales_invoice_lines
        GROUP BY invoice_id
     ) totals ON totals.invoice_id = si.id
     LEFT JOIN (
        SELECT invoice_id, SUM(amount) AS paid_total
        FROM invoice_payments
        GROUP BY invoice_id
     ) payments ON payments.invoice_id = si.id
     WHERE si.company_id = ?
       AND si.status = 'posted'
       AND COALESCE(totals.gross_total, 0) > COALESCE(payments.paid_total, 0)"
);
$unpaidSalesStmt->execute([$companyId]);
$unpaidSalesCount = (int) $unpaidSalesStmt->fetchColumn();

$unpaidPurchaseStmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM purchase_invoices pi
     LEFT JOIN (
        SELECT invoice_id, SUM(gross_amount) AS gross_total
        FROM purchase_invoice_lines
        GROUP BY invoice_id
     ) totals ON totals.invoice_id = pi.id
     LEFT JOIN (
        SELECT invoice_id, SUM(amount) AS paid_total
        FROM purchase_invoice_payments
        GROUP BY invoice_id
     ) payments ON payments.invoice_id = pi.id
     WHERE pi.company_id = ?
       AND pi.status = 'posted'
       AND COALESCE(totals.gross_total, 0) > COALESCE(payments.paid_total, 0)"
);
$unpaidPurchaseStmt->execute([$companyId]);
$unpaidPurchaseCount = (int) $unpaidPurchaseStmt->fetchColumn();

$unmatchedBankStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM bank_statement_lines
     WHERE company_id = ?
       AND matched_journal_line_id IS NULL'
);
$unmatchedBankStmt->execute([$companyId]);
$unmatchedBankCount = (int) $unmatchedBankStmt->fetchColumn();

$incomingEInvoicesStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM billberry_inbox WHERE company_id = ? AND purchase_invoice_id IS NULL'
);
$incomingEInvoicesStmt->execute([$companyId]);
$incomingEInvoicesCount = (int) $incomingEInvoicesStmt->fetchColumn();

$draftEntriesStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM journal_entries WHERE company_id = ? AND status = 'draft'"
);
$draftEntriesStmt->execute([$companyId]);
$draftEntriesCount = (int) $draftEntriesStmt->fetchColumn();
$perPage = 10;
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = (!$page || $page < 1) ? 1 : $page;

$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');
$entryStatus = $_GET['entry_status'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$filterErrors = [];

if (!in_array($entryStatus, ['all', 'draft', 'posted','cancelled'], true)) {
    $entryStatus = 'all';
}

if ($startDate !== '' && !entryDateIsValid($startDate)) {
    $filterErrors[] = 'Alguskuupäev ei ole kehtiv.';
}

if ($endDate !== '' && !entryDateIsValid($endDate)) {
    $filterErrors[] = 'Lõppkuupäev ei ole kehtiv.';
}

if (
    $startDate !== ''
    && $endDate !== ''
    && entryDateIsValid($startDate)
    && entryDateIsValid($endDate)
    && $startDate > $endDate
) {
    $filterErrors[] = 'Alguskuupäev peab olema enne lõppkuupäeva.';
}

if (function_exists('mb_substr')) {
    $search = mb_substr($search, 0, 100, 'UTF-8');
} else {
    $search = substr($search, 0, 100);
}

$where = ['je.company_id = ?'];
$filterParams = [$_SESSION['company_id']];

if ($startDate !== '' && entryDateIsValid($startDate)) {
    $where[] = 'je.entry_date >= ?';
    $filterParams[] = $startDate;
}

if ($endDate !== '' && entryDateIsValid($endDate)) {
    $where[] = 'je.entry_date <= ?';
    $filterParams[] = $endDate;
}

if ($entryStatus !== 'all') {
    $where[] = 'je.status = ?';
    $filterParams[] = $entryStatus;
}

if ($search !== '') {
    $where[] = "(je.description LIKE ? OR COALESCE(je.document_number, '') LIKE ? OR COALESCE(corrected.document_number,'') LIKE ?)";
    $filterParams[] = '%' . $search . '%';
    $filterParams[] = '%' . $search . '%';
    $filterParams[] = '%' . $search . '%';
}

$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM journal_entries je ' . journalCorrectionJoin() . '
     WHERE ' . $whereSql
);
$countStmt->execute($filterParams);
$totalEntries = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalEntries / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    'SELECT
        je.id,
        je.entry_date,
        je.description,
        je.document_number,
        corrected.id AS corrected_entry_id,corrected.document_number AS corrected_document_number,
        je.status,
        COALESCE(sums.total_debit, 0) AS total_debit,
        COALESCE(sums.total_credit, 0) AS total_credit
     FROM journal_entries je ' . journalCorrectionJoin() . '
     LEFT JOIN (
        SELECT
            entry_id,
            SUM(debit) AS total_debit,
            SUM(credit) AS total_credit
        FROM journal_lines
        GROUP BY entry_id
     ) sums ON sums.entry_id = je.id
     WHERE ' . $whereSql . '
     ORDER BY je.entry_date DESC, je.id DESC
     LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset
);
$stmt->execute($filterParams);
$entries = $stmt->fetchAll();

$firstEntry = $totalEntries > 0 ? $offset + 1 : 0;
$lastEntry = min($offset + count($entries), $totalEntries);

$filterQuery = [
    'start_date' => $startDate,
    'end_date' => $endDate,
    'entry_status' => $entryStatus,
    'search' => $search,
];

$previousPageUrl = '?' . http_build_query(
    array_merge($filterQuery, ['page' => max(1, $page - 1)])
);
$nextPageUrl = '?' . http_build_query(
    array_merge($filterQuery, ['page' => min($totalPages, $page + 1)])
);
$pageItems = [];
if ($totalPages <= 9) {
    $pageItems = range(1, $totalPages);
} else {
    $pageItems = range(1, 6);
    if ($page > 8) {
        $pageItems[] = null;
        $windowStart = max(7, $page - 1);
        $windowEnd = min($totalPages - 1, $page + 1);
        for ($pageNumber = $windowStart; $pageNumber <= $windowEnd; $pageNumber++) {
            $pageItems[] = $pageNumber;
        }
    } elseif ($page > 6) {
        for ($pageNumber = 7; $pageNumber <= min(9, $totalPages - 1); $pageNumber++) {
            $pageItems[] = $pageNumber;
        }
    }
    if (end($pageItems) < $totalPages - 1) {
        $pageItems[] = null;
    }
    $pageItems[] = $totalPages;
}
$pageUrls = [];
foreach ($pageItems as $pageNumber) {
    if ($pageNumber !== null) {
        $pageUrls[$pageNumber] = '?' . http_build_query(
            array_merge($filterQuery, ['page' => $pageNumber])
        );
    }
}
?>
<!doctype html>
<html lang="et">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <title>Raamatupidamine</title>
	
	<style>
.status-cancelled{background:#edf0f4;color:#637184}
   .entries-card {
    margin-top: 18px;
    padding: 22px;
    background: #fff;
    border: 1px solid #e1e7ef;
    border-radius: 14px;
    box-shadow: 0 4px 14px rgba(25, 45, 70, 0.06);
}

.entries-heading {
    margin: 0 0 16px;
    color: #17324d;
}

.table-wrap {
    overflow-x: auto;
    border: 1px solid #e1e7ef;
    border-radius: 10px;
}

.entries-table {
    width: 100%;
    border-collapse: collapse;
    text-align: left;
}

.entries-table th {
    padding: 13px 16px;
    background: #f3f6fa;
    color: #526273;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: .03em;
}

.entries-table td {
    padding: 14px 16px;
    border-top: 1px solid #e9edf2;
    color: #263746;
}

.entries-table tbody tr:hover {
    background: #f7faff;
}

.entries-table a {
    color: #1769aa;
    font-weight: 600;
    text-decoration: none;
}

.entries-table a:hover {
    text-decoration: underline;
}

.status-badge {
    display: inline-block;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    text-transform: capitalize;
}

.status-posted {
    background: #e5f5ec;
    color: #187342;
}

.status-draft {
    background: #fff3d6;
    color: #8a5a00;
}

.empty-state {
    margin: 0;
    padding: 18px;
    border-radius: 10px;
    background: #f7f9fc;
    color: #526273;
}
.pagination {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
    align-items: center;
    gap: 12px;
    margin-top: 18px;
}

.pagination-info {
    grid-column: 1;
    color: #526273;
    font-weight: 600;
}
.pagination-controls {
    grid-column: 2;
    justify-self: center;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.pagination-pages {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
}
.pagination .page-number {
    display: inline-block;
    min-width: 18px;
    padding: 4px 2px;
    text-align: center;
    background: transparent;
    color: #1769aa;
    border-radius: 0;
    text-decoration: underline;
    font-weight: 600;
}
.pagination .page-number[aria-current="page"] {
    color: #17324d;
    cursor: default;
    text-decoration: none;
    font-weight: 700;
}
.pagination-step {
    padding: 8px 4px;
    color: #1769aa;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
}
.pagination-step:hover {
    text-decoration: underline;
}
.pagination-ellipsis {
    padding: 8px 3px;
    color: #526273;
}
@media (max-width: 700px) {
    .pagination {
        grid-template-columns: 1fr;
    }
    .pagination-info,
    .pagination-controls {
        grid-column: 1;
    }
    .pagination-controls {
        justify-self: center;
    }
}
.entry-filters {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    gap: 12px;
    margin: 16px 0;
    padding: 16px;
    background: #f7f9fc;
    border: 1px solid #dce3ec;
    border-radius: 10px;
}

.entry-filters label {
    display: grid;
    gap: 6px;
    color: #526273;
    font-weight: 600;
}

.entry-filters input,
.entry-filters select {
    min-height: 38px;
    padding: 7px 9px;
    border: 1px solid #b8c4d1;
    border-radius: 6px;
    background: #fff;
    font: inherit;
}
.entry-filters input,
.entry-filters select {
    box-sizing: border-box;
    height: 38px;
}

.entry-filters input[type="date"],
.entry-filters select {
    color: #1769aa;
    font-weight: 600;
}

.entry-filters input[type="date"]::-webkit-datetime-edit,
.entry-filters input[type="date"]::-webkit-datetime-edit-text,
.entry-filters input[type="date"]::-webkit-datetime-edit-month-field,
.entry-filters input[type="date"]::-webkit-datetime-edit-day-field,
.entry-filters input[type="date"]::-webkit-datetime-edit-year-field {
    color: #1769aa;
}
	
	.button {
        display: inline-block;
        padding: 10px 16px;
        background-color: #1769aa;
        color: white;
        text-decoration: none;
        border-radius: 6px;
        font-weight: bold;
    }

    .button:hover {
        background-color: #0f4f83;
    }
	
	
	
	.main-nav {
    display: flex;
    justify-content: center;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px 30px;
    min-width: 0;
}

.nav-menu { position: relative; }
.nav-menu summary {
    list-style: none;
    cursor: pointer;
    padding: 10px 2px;
    color: #17324d;
    font-weight: 700;
    white-space: nowrap;
}
.nav-menu summary::-webkit-details-marker { display: none; }
.nav-menu[open] summary, .nav-menu summary:hover { color: #1769aa; }
.submenu {
    position: absolute;
    z-index: 20;
    top: 100%;
    left: -10px;
    display: grid;
    gap: 2px;
    min-width: 230px;
    padding: 9px;
    border: 1px solid #dce3ec;
    border-radius: 10px;
    background: #fff;
    box-shadow: 0 8px 22px rgba(25, 45, 70, .14);
}
.submenu a {
    display: block;
    padding: 8px 10px;
    border-radius: 6px;
    color: #24445f;
    text-decoration: none;
    white-space: nowrap;
}
.submenu a:hover, .submenu a:focus-visible { background: #f1f6fa; color: #1769aa; }
.header-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-self: end;
    gap: 18px;
    white-space: nowrap;
}
.header-actions a {
    padding: 10px 2px;
    color: #17324d;
    font-weight: 700;
    text-decoration: none;
}
.header-actions a:hover, .header-actions a:focus-visible { color: #1769aa; text-decoration: underline; }
.page-header {
    display: grid;
    grid-template-columns: minmax(180px, 1fr) auto minmax(180px, 1fr);
    align-items: center;
    gap: 8px 24px;
    margin-bottom: 20px;
    padding: 8px 16px;
    border: 1px solid #c6d2df;
    border-radius: 12px;
    background: #e1e8f0;
}

@media (max-width: 1000px) {
    .page-header { grid-template-columns: minmax(0, 1fr) auto; }
    .company-name { grid-column: 1; }
    .main-nav { grid-column: 1 / -1; grid-row: 2; }
    .header-actions { grid-column: 2; grid-row: 1; }
}
@media (max-width: 760px) {
    .main-nav { gap: 4px 16px; }
    .header-actions { gap: 12px; }
    .submenu { left: 0; }
}
.company-name {
    min-width: 0;
    color: #17324d;
    font-size: 16px;
    font-weight: 700;
    overflow-wrap: anywhere;
}
    .external-links {
        margin: 28px 0 16px; padding: 20px; border: 1px solid #dce3ec;
        border-radius: 14px; background: #f7f9fc;
    }
    .external-links h2 {
        margin: 0 0 14px; color: #526273; font-size: 13px; font-weight: 700;
        text-transform: uppercase; letter-spacing: .04em;
    }
    .external-link-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
    .external-link {
        display: flex; min-height: 76px; align-items: center; justify-content: center;
        padding: 14px; border: 1px solid #dce3ec; border-radius: 10px;
        background: #fff; text-decoration: none;
        transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
    }
    .external-link:hover {
        transform: translateY(-2px); border-color: #b8c9d8;
        box-shadow: 0 5px 14px rgba(25, 45, 70, .10);
    }
    .external-link:focus-visible { outline: 3px solid #8fc5ef; outline-offset: 2px; }
    .external-link svg { display: block; width: min(100%, 190px); height: 44px; }
    .external-links-note { margin: 13px 0 0; color: #667788; font-size: 13px; }
    @media (max-width: 700px) { .external-link-grid { grid-template-columns: repeat(2, minmax(130px, 1fr)); } }
    @media (max-width: 380px) { .external-link-grid { grid-template-columns: 1fr; } }
.attention-panel {
    margin: 22px 0;
    padding: 20px;
    background: #fff;
    border: 1px solid #dce3ec;
    border-radius: 14px;
    box-shadow: 0 4px 14px rgba(25, 45, 70, 0.05);
}
.attention-panel h2 { margin: 0 0 14px; color: #17324d; }
.attention-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 12px; }
.attention-item { padding: 14px; background: #f7f9fc; border: 1px solid #e1e7ef; border-radius: 10px; }
.attention-item h3 { margin: 0 0 8px; color: #33475b; font-size: 15px; }
.attention-total { margin: 0 0 9px; color: #17324d; font-size: 24px; font-weight: 700; }
.attention-links { display: grid; gap: 6px; }
.attention-links a { color: #1769aa; font-weight: 600; text-decoration: none; }
.attention-links a:hover { text-decoration: underline; }
@media (max-width: 1100px) { .attention-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
@media (max-width: 900px) { .attention-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 520px) { .attention-grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<?php if(!empty($_SESSION['journal_draft_notice'])): ?><p style="max-width:1200px;margin:16px auto;padding:12px 18px;border-radius:8px;background:#e9f6ed;color:#205c31"><?= htmlspecialchars($_SESSION['journal_draft_notice'],ENT_QUOTES,'UTF-8') ?></p><?php unset($_SESSION['journal_draft_notice']); endif; ?>
    <header class="page-header">
        <div class="company-name"><?= htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') ?></div>
        <nav class="main-nav" aria-label="Põhimenüü">
            <details class="nav-menu">
                <summary>Raamatupidamine</summary>
                <div class="submenu">
                    <a href="journal_book.php">Päevaraamat</a>
                    <a href="ledger.php">Pearaamat</a>
                    <a href="cash_flows.php">Rahavood</a>
                    <a href="trial_balance.php">Käibeandmik</a>
                    <a href="vat_report.php">Käibemaksu aruanne</a>
                    <a href="vd_report.php">VD — EL müügi aruanne</a>
                    <a href="import_vat_documents.php">Tollidokumendid ja impordi KM</a>
                    <a href="tax_codes.php">Käibemaksukoodid</a>
                    <a href="kmd_inf_preview.php">KMD INF eelvaade</a>
                    <a href="receivables_check.php">Ostjate nõuete võrdlus</a>
                    <a href="income_statement.php">Kasumiaruanne</a>
                    <a href="balance_sheet.php">Bilanss</a>
                    <a href="annual_report_prep.php">Aastaaruande ettevalmistus</a>
                    <a href="pohivara/index.php">Põhivara</a>
                </div>
            </details>
            <details class="nav-menu">
                <summary>Kliendid ja müük</summary>
                <div class="submenu">
                    <a href="contacts.php">Kliendid ja tarnijad</a>
                    <a href="create_invoice.php">Uus müügiarve</a>
                    <a href="invoices.php">Müügiarved</a>
                </div>
            </details>
            <details class="nav-menu">
                <summary>Ost</summary>
                <div class="submenu">
                    <a href="create_purchase_invoice.php">Uus ostuarve</a>
                    <a href="purchase_invoices.php">Ostuarved</a>
                    <a href="billberry_inbox.php">Saabunud e-arved</a>
                </div>
            </details>
            <details class="nav-menu">
                <summary>Ladu</summary>
                <div class="submenu">
                    <a href="ladu/">Lao ülevaade</a>
                    <a href="ladu/products.php">Tootekaardid</a>
                    <a href="ladu/warehouses.php">Laod ja asukohad</a>
                    <a href="ladu/movements.php">Kauba liikumised</a>
                    <a href="ladu/stock_card.php">Laokaart</a>
                    <a href="ladu/stocktake.php">Inventuur</a>
                </div>
            </details>
            <details class="nav-menu">
                <summary>Pank</summary>
                <div class="submenu">
                    <a href="investment_review.php">Pangaväljavõte</a>
                    <a href="security_fx.php">Väärtpaberitehingud</a>
                    <a href="bank_import.php">Impordi CSV</a>
                    <a href="lhv_merge.php">Ühenda LHV CSV-d</a>
                </div>
            </details>
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
            <details class="nav-menu">
                <summary>Palgad</summary>
                <div class="submenu">
                    <a href="palk/">Töötajad</a>
                    <a href="palk/employments.php">Töösuhted</a>
                    <a href="palk/runs.php">Palgaperioodid ja TSD</a>
                    <a href="palk/tsd_export.php?validate_sample=1&amp;month=<?= date('Y-m') ?>">Kontrolli TSD faili näidisandmetega</a>
                </div>
            </details>
            <?php endif; ?>
        </nav>
        <div class="header-actions">
            <a href="settings.php">Seaded</a>
            <a href="logout.php">Logi välja</a>
        </div>
    </header>
    <script>
        const navMenus = document.querySelectorAll('.nav-menu');
        const hoverMenusEnabled = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

        if (hoverMenusEnabled) {
            navMenus.forEach(function (menu) {
                menu.addEventListener('pointerenter', function () {
                    navMenus.forEach(function (otherMenu) {
                        if (otherMenu !== menu) otherMenu.removeAttribute('open');
                    });
                    menu.setAttribute('open', '');
                });
                menu.addEventListener('pointerleave', function () {
                    menu.removeAttribute('open');
                });
                menu.querySelector('summary').addEventListener('click', function (event) {
                    if (event.detail > 0) {
                        event.preventDefault();
                        menu.setAttribute('open', '');
                    }
                });
            });
        }

        document.addEventListener('click', function (event) {
            navMenus.forEach(function (menu) {
                if (!menu.contains(event.target)) {
                    menu.removeAttribute('open');
                }
            });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                navMenus.forEach(function (menu) {
                    menu.removeAttribute('open');
                });
            }
        });
    </script>
	    <section class="attention-panel" aria-labelledby="attention-heading">
        <h2 id="attention-heading">Vajab tähelepanu</h2>
        <div class="attention-grid">
            <article class="attention-item">
                <h3>Kinnitamata arved</h3>
                <p class="attention-total"><?= $draftSalesCount + $draftPurchaseCount ?></p>
                <div class="attention-links">
                    <a href="invoices.php?payment_filter=draft">Müügiarved: <?= $draftSalesCount ?></a>
                    <a href="purchase_invoices.php?payment_filter=draft">Ostuarved: <?= $draftPurchaseCount ?></a>
                </div>
            </article>
            <article class="attention-item">
                <h3>Tähtaja ületanud arved</h3>
                <p class="attention-total"><?= $overdueSalesCount + $overduePurchaseCount ?></p>
                <div class="attention-links">
                    <a href="invoices.php?payment_filter=overdue">Müügiarved: <?= $overdueSalesCount ?></a>
                    <a href="purchase_invoices.php?payment_filter=overdue">Ostuarved: <?= $overduePurchaseCount ?></a>
                </div>
            </article>
            <article class="attention-item">
                <h3>Maksmata arved</h3>
                <p class="attention-total"><?= $unpaidSalesCount + $unpaidPurchaseCount ?></p>
                <div class="attention-links">
                    <a href="invoices.php?payment_filter=unpaid">Müügiarved: <?= $unpaidSalesCount ?></a>
                    <a href="purchase_invoices.php?payment_filter=unpaid">Ostuarved: <?= $unpaidPurchaseCount ?></a>
                </div>
            </article>
            <article class="attention-item">
                <h3>Sidumata pangatehingud</h3>
                <p class="attention-total"><?= $unmatchedBankCount ?></p>
                <div class="attention-links">
                    <a href="investment_review.php">Vaata sidumata ridu</a>
                </div>
            </article>
            <article class="attention-item">
                <h3>Saabunud e-arved</h3>
                <p class="attention-total"><?= $incomingEInvoicesCount ?></p>
                <div class="attention-links">
                    <a href="billberry_inbox.php">Vaata töötlemata arveid</a>
                </div>
            </article>
            <article class="attention-item">
                <h3>Mustandkanded</h3>
                <p class="attention-total"><?= $draftEntriesCount ?></p>
                <div class="attention-links">
                    <a href="index.php?entry_status=draft">Vaata mustandkandeid</a>
                </div>
            </article>
        </div>
    </section>
<p>
    <a class="button" href="new_entry.php">Lisa uus kanne</a>
    
</p>
    <section class="entries-card">
    <h2 class="entries-heading">Kanded</h2>

     <form method="get" class="entry-filters">
    <label>
        Alates
        <input type="date" name="start_date" value="<?= htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8') ?>">
    </label>

    <label>
        Kuni
        <input type="date" name="end_date" value="<?= htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8') ?>">
    </label>

    <label>
        Olek
        <select name="entry_status">
            <option value="all" <?= $entryStatus === 'all' ? 'selected' : '' ?>>Kõik</option>
            <option value="draft" <?= $entryStatus === 'draft' ? 'selected' : '' ?>>Mustand</option>
            <option value="cancelled" <?= $entryStatus === 'cancelled' ? 'selected' : '' ?>>Tühistatud</option>
            <option value="posted" <?= $entryStatus === 'posted' ? 'selected' : '' ?>>Kinnitatud</option>
        </select>
    </label>

    <label>
        Kirjeldus või dokumendi nr
        <input
            type="search"
            name="search"
            maxlength="100"
            value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>"
            placeholder="Otsi..."
        >
    </label>

    <button class="button" type="submit">Otsi</button>
    <a class="button" href="index.php">Tühjenda filtrid</a>
</form>

<?php foreach ($filterErrors as $filterError): ?>
    <p class="error"><?= htmlspecialchars($filterError, ENT_QUOTES, 'UTF-8') ?></p>
<?php endforeach; ?>

    <?php if (!$entries): ?>
        <p class="empty-state">Valitud tingimustele vastavaid kandeid ei leitud.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="entries-table">
                <thead>
    <tr>
        <th>Kuupäev</th>
		<th>Dokumendi nr</th>
        <th>Kirjeldus</th>
        <th>Deebet kokku</th>
        <th>Kreedit kokku</th>
        <th>Olek</th>
    </tr>
</thead>
                <tbody>
                    <?php foreach ($entries as $entry): ?>
                        <?php
                        $statusClass = $entry['status'] === 'posted'
                            ? 'status-posted'
                            : ($entry['status']==='cancelled'?'status-cancelled':'status-draft');
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($entry['entry_date'], ENT_QUOTES, 'UTF-8') ?></td>
							<td><?= htmlspecialchars($entry['document_number'] ?? '', ENT_QUOTES, 'UTF-8') ?><?php if(!empty($entry['corrected_entry_id'])): ?><small style="display:block;margin-top:5px;color:#526579">Parandatav dokument: <a href="entry.php?id=<?= (int)$entry['corrected_entry_id'] ?>"><?= htmlspecialchars($entry['corrected_document_number']?:('Kanne #'.$entry['corrected_entry_id']),ENT_QUOTES,'UTF-8') ?></a></small><?php endif; ?></td>
                            <td>
                                <a href="entry.php?id=<?= (int) $entry['id'] ?>">
                                    <?= htmlspecialchars($entry['description'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </td>
                            
							<td><?= number_format((float) $entry['total_debit'], 2, ',', ' ') ?> €</td>
                            <td><?= number_format((float) $entry['total_credit'], 2, ',', ' ') ?> €</td>
                            <td>
                                <span class="status-badge <?= $statusClass ?>">
                                    <?= htmlspecialchars(['draft'=>'Mustand','posted'=>'Kinnitatud','cancelled'=>'Tühistatud'][$entry['status']]??$entry['status'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
		        <div class="pagination">
            <span class="pagination-info">
                Kanded <?= $firstEntry ?>–<?= $lastEntry ?> kokku <?= $totalEntries ?>-st
                · Leht <?= $page ?> / <?= $totalPages ?>
            </span>

            <div class="pagination-controls">
            <?php if ($page > 1): ?>
                <a class="pagination-step" href="<?= htmlspecialchars($previousPageUrl, ENT_QUOTES, 'UTF-8') ?>">← Eelmine</a>
            <?php endif; ?>
            <nav class="pagination-pages" aria-label="Kannete leheküljed">
                <?php foreach ($pageItems as $pageNumber): ?>
                    <?php if ($pageNumber === null): ?>
                        <span class="pagination-ellipsis" aria-hidden="true">…</span>
                    <?php else: ?>
                        <?php $pageUrl = $pageUrls[$pageNumber]; ?>
                    <?php if ($pageNumber === $page): ?>
                        <span class="button page-number" aria-current="page" aria-label="Leht <?= $pageNumber ?>, valitud"><?= $pageNumber ?></span>
                    <?php else: ?>
                        <a class="button page-number" href="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>" aria-label="Ava leht <?= $pageNumber ?>"><?= $pageNumber ?></a>
                    <?php endif; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>

            <?php if ($page < $totalPages): ?>
                <a class="pagination-step" href="<?= htmlspecialchars($nextPageUrl, ENT_QUOTES, 'UTF-8') ?>">Järgmine →</a>
            <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
    <footer class="external-links">
        <h2>Pangad ja e-teenused</h2>
        <div class="external-link-grid">
            <a class="external-link" href="https://www.swedbank.ee/business/d2d/ebanking?language=EST" target="_blank" rel="noopener noreferrer" aria-label="Ava Swedbanki ärikliendi internetipank uues vahelehes">
                
				<img src="pildid/swedi.png?v=1" alt="Logo" width="160" height="50">
            </a>
            <a class="external-link" href="https://e.seb.ee/ib/login" target="_blank" rel="noopener noreferrer" aria-label="Ava SEB internetipank uues vahelehes">
                
				<img src="pildid/seb.png?v=1" alt="Logo" width="170" height="50">
            </a>
            <a class="external-link" href="https://www.lhv.ee/ibank/" target="_blank" rel="noopener noreferrer" aria-label="Ava LHV internetipank uues vahelehes">
                
				<img src="pildid/lhv.png?v=1" alt="Logo" width="140" height="40">
            </a>
			<a class="external-link" href="https://i.cooppank.ee/sso/?lang=et&return=https%3A%2F%2Fi.cooppank.ee%2Fi%2F%3Flang%3Det" target="_blank" rel="noopener noreferrer" aria-label="Ava LHV internetipank uues vahelehes">
                
				<img src="pildid/coopp.png?v=1" alt="Logo" width="140" height="45">
            </a>
			<a class="external-link" href="https://luminor.ee/" target="_blank" rel="noopener noreferrer" aria-label="Ava LHV internetipank uues vahelehes">
                
				<img src="pildid/luminor2.jpg?v=1" alt="Logo" width="120" height="45">
            </a>
            <a class="external-link" href="https://www.emta.ee/" target="_blank" rel="noopener noreferrer" aria-label="Ava Maksu- ja Tolliameti veebileht uues vahelehes">
                
				<img src="pildid/maksu.png?v=1" alt="Logo" width="190" height="75">
            </a>
            <a class="external-link" href="useful_links.php" aria-label="Ava raamatupidamisalaste kasulike linkide leht">
                <svg viewBox="0 0 220 48" role="img" aria-labelledby="useful-title">
                    <title id="useful-title">Kasulik teada</title>
                    <path d="M10 12c8-3 15-2 21 2v22c-6-4-13-5-21-2zm21 2c6-4 13-5 21-2v22c-8-3-15-2-21 2z" fill="none" stroke="#1769aa" stroke-width="3" stroke-linejoin="round"/>
                    <path d="M31 15v21" stroke="#1769aa" stroke-width="2"/>
                    <text x="62" y="31" fill="#17324d" font-family="Arial, sans-serif" font-size="20" font-weight="700">Kasulik teada</text>
                </svg>
            </a>
            <a class="external-link" href="manual.php" aria-label="Ava programmi kasutusjuhend">
                <svg viewBox="0 0 220 48" role="img" aria-labelledby="manual-title">
                    <title id="manual-title">Kasutusjuhend</title>
                    <path d="M13 8h27v32H13z" fill="none" stroke="#1769aa" stroke-width="3" stroke-linejoin="round"/>
                    <path d="M19 17h15M19 23h15M19 29h12" stroke="#1769aa" stroke-width="2" stroke-linecap="round"/>
                    <text x="51" y="31" fill="#17324d" font-family="Arial, sans-serif" font-size="20" font-weight="700">Kasutusjuhend</text>
                </svg>
            </a>
        </div>
        <p class="external-links-note">Lingid avanevad uues vahelehes. Enne panka sisselogimist kontrolli, et aadressiribal oleks panga ametlik domeen.</p>
    </footer>
</body>
</html>
