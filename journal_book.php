<?php
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

function validJournalDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function journalMoney($amount): string
{
    return number_format((float) $amount, 2, ',', ' ') . ' €';
}

$companyId = (int) $_SESSION['company_id'];
$today = date('Y-m-d');
$startDate = (string) ($_GET['start_date'] ?? date('Y-01-01'));
$endDate = (string) ($_GET['end_date'] ?? $today);
$status = (string) ($_GET['status'] ?? 'posted');
$search = trim((string) ($_GET['search'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$errors = [];

if (!validJournalDate($startDate) || !validJournalDate($endDate)) {
    $errors[] = 'Vali kehtiv algus- ja lõpukuupäev.';
} elseif ($startDate > $endDate) {
    $errors[] = 'Perioodi alguskuupäev peab olema enne lõpukuupäeva.';
}
if (!in_array($status, ['all', 'posted', 'draft'], true)) {
    $status = 'posted';
}
if (function_exists('mb_substr')) {
    $search = mb_substr($search, 0, 100, 'UTF-8');
} else {
    $search = substr($search, 0, 100);
}

$entries = [];
$linesByEntry = [];
$summary = ['entry_count' => 0, 'total_debit' => 0, 'total_credit' => 0];
$totalPages = 1;
$totalEntries = 0;

if (!$errors) {
    $where = 'je.company_id = :company_id AND je.entry_date BETWEEN :start_date AND :end_date';
    $params = [
        'company_id' => $companyId,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ];
    if ($status !== 'all') {
        $where .= ' AND je.status = :status';
        $params['status'] = $status;
    }
    if ($search !== '') {
        $where .= " AND (je.description LIKE :description_search OR COALESCE(je.document_number, '') LIKE :document_search)";
        $params['description_search'] = '%' . $search . '%';
        $params['document_search'] = '%' . $search . '%';
    }

    $summaryStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT je.id) AS entry_count,
                COALESCE(SUM(jl.debit), 0) AS total_debit,
                COALESCE(SUM(jl.credit), 0) AS total_credit
         FROM journal_entries je
         LEFT JOIN journal_lines jl ON jl.entry_id = je.id
         WHERE $where"
    );
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: $summary;
    $totalEntries = (int) $summary['entry_count'];
    $totalPages = max(1, (int) ceil($totalEntries / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $entriesStmt = $pdo->prepare(
        "SELECT je.id, je.entry_date, je.document_number, je.description, je.status,
                COALESCE(SUM(jl.debit), 0) AS total_debit,
                COALESCE(SUM(jl.credit), 0) AS total_credit,
                COUNT(jl.id) AS line_count
         FROM journal_entries je
         LEFT JOIN journal_lines jl ON jl.entry_id = je.id
         WHERE $where
         GROUP BY je.id, je.entry_date, je.document_number, je.description, je.status
         ORDER BY je.entry_date DESC, je.id DESC
         LIMIT $perPage OFFSET $offset"
    );
    $entriesStmt->execute($params);
    $entries = $entriesStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($entries) {
        $ids = array_map(static fn(array $entry): int => (int) $entry['id'], $entries);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $linesStmt = $pdo->prepare(
            "SELECT jl.entry_id, jl.description AS line_description, jl.debit, jl.credit,
                    a.code, a.name
             FROM journal_lines jl
             JOIN accounts a ON a.id = jl.account_id AND a.company_id = ?
             WHERE jl.entry_id IN ($marks)
             ORDER BY jl.entry_id, jl.id"
        );
        $linesStmt->execute(array_merge([$companyId], $ids));
        foreach ($linesStmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $linesByEntry[(int) $line['entry_id']][] = $line;
        }
    }
}

$queryBase = [
    'start_date' => $startDate,
    'end_date' => $endDate,
    'status' => $status,
];
if ($search !== '') $queryBase['search'] = $search;
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Päevaraamat</title>
    <style>
        * { box-sizing: border-box; }
        body { max-width: 1380px; margin: 28px auto; padding: 0 20px; color: #243447; background: #f4f7fb; font-family: Arial, sans-serif; }
        h1, h2 { color: #17324d; }
        .muted { color: #65778a; }
        .card { margin: 18px 0; padding: 20px; border: 1px solid #dce3ec; border-radius: 12px; background: #fff; box-shadow: 0 2px 8px #17324d0a; }
        .filters { display: flex; flex-wrap: wrap; gap: 14px; align-items: end; }
        .filters label { display: grid; gap: 6px; color: #40566b; font-weight: 600; }
        input, select { min-height: 42px; padding: 8px 10px; border: 1px solid #b8c6d4; border-radius: 7px; background: #fff; font: inherit; }
        .button { display: inline-block; min-height: 42px; padding: 11px 16px; border: 0; border-radius: 7px; background: #1769aa; color: #fff; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
        .button:hover { background: #0f548c; }
        .secondary { background: #5f7081; }
        .summary { display: flex; flex-wrap: wrap; gap: 12px 28px; align-items: center; }
        .summary strong { color: #17324d; }
        .entry { overflow: hidden; margin: 18px 0; border: 1px solid #dce3ec; border-radius: 10px; background: #fff; }
        .entry-head { display: flex; flex-wrap: wrap; gap: 8px 18px; align-items: baseline; padding: 13px 16px; background: #f3f6fa; border-bottom: 1px solid #dce3ec; }
        .entry-head .description { flex: 1 1 320px; font-weight: 600; }
        .entry-head a { color: #1769aa; text-decoration: none; }
        .badge { padding: 3px 8px; border-radius: 20px; font-size: 12px; font-weight: 700; }
        .posted { color: #176b3a; background: #e7f5ec; }
        .draft { color: #795500; background: #fff3cf; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 9px 12px; border-bottom: 1px solid #e7edf3; text-align: left; vertical-align: top; }
        th { color: #526273; font-size: 13px; }
        .number { text-align: right; white-space: nowrap; }
        .entry-total { font-weight: 700; background: #fafbfd; }
        .period-total { display:flex; flex-wrap:wrap; gap:14px 28px; font-weight:700; }
        .pagination { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:18px; }
        .error { padding:12px 15px; border:1px solid #e5a3a3; border-radius:8px; background:#fff0f0; color:#a32121; }
        @media print {
            body { max-width:none; margin:0; padding:0; background:#fff; color:#000; }
            .no-print, .filters-card, .pagination { display:none !important; }
            .card, .entry { box-shadow:none; border-color:#bbb; break-inside:avoid; }
            .entry-head { background:#f1f1f1 !important; print-color-adjust:exact; }
            a { color:#000 !important; text-decoration:none !important; }
        }
    </style>
</head>
<body>
    <header class="no-print">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
            <h1>Päevaraamat</h1>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <button class="button secondary" type="button" onclick="window.print()">Prindi</button>
                <a class="button" href="index.php">Avalehele</a>
            </div>
        </div>
        <p class="muted">Kronoloogiline ülevaade kannetest ja nende deebet- ning kreeditridadest.</p>
    </header>

    <section class="card filters-card no-print">
        <form method="get" class="filters">
            <label>Alates<input type="date" name="start_date" value="<?= h($startDate) ?>" required></label>
            <label>Kuni<input type="date" name="end_date" value="<?= h($endDate) ?>" required></label>
            <label>Olek
                <select name="status">
                    <option value="posted" <?= $status === 'posted' ? 'selected' : '' ?>>Kinnitatud</option>
                    <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Mustand</option>
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Kõik</option>
                </select>
            </label>
            <label>Otsi kirjeldust või dokumendi nr
                <input type="search" name="search" maxlength="100" value="<?= h($search) ?>" placeholder="Otsi...">
            </label>
            <button class="button" type="submit">Näita</button>
            <a class="button secondary" href="journal_book.php">Tühjenda filtrid</a>
        </form>
    </section>

    <?php foreach ($errors as $error): ?><p class="error"><?= h($error) ?></p><?php endforeach; ?>

    <?php if (!$errors): ?>
        <section class="card">
            <div class="summary">
                <span>Periood: <strong><?= h($startDate) ?> – <?= h($endDate) ?></strong></span>
                <span>Kandeid: <strong><?= number_format($totalEntries, 0, ',', ' ') ?></strong></span>
                <span>Deebet kokku: <strong><?= journalMoney($summary['total_debit']) ?></strong></span>
                <span>Kreedit kokku: <strong><?= journalMoney($summary['total_credit']) ?></strong></span>
            </div>
        </section>

        <?php if (!$entries): ?>
            <section class="card"><p>Valitud perioodis kandeid ei leitud.</p></section>
        <?php else: ?>
            <?php foreach ($entries as $entry): $entryId = (int) $entry['id']; ?>
                <article class="entry">
                    <div class="entry-head">
                        <span><?= h($entry['entry_date']) ?></span>
                        <span><?= h($entry['document_number'] ?: 'Dokumendi nr puudub') ?></span>
                        <span class="description"><a href="entry.php?id=<?= $entryId ?>"><?= h($entry['description']) ?></a></span>
                        <span class="badge <?= $entry['status'] === 'posted' ? 'posted' : 'draft' ?>"><?= $entry['status'] === 'posted' ? 'Kinnitatud' : 'Mustand' ?></span>
                    </div>
                    <div style="overflow-x:auto">
                        <table>
                            <thead><tr><th>Konto</th><th>Rea kirjeldus</th><th class="number">Deebet</th><th class="number">Kreedit</th></tr></thead>
                            <tbody>
                            <?php foreach ($linesByEntry[$entryId] ?? [] as $line): ?>
                                <tr>
                                    <td><?= h($line['code'] . ' — ' . $line['name']) ?></td>
                                    <td><?= h((string) ($line['line_description'] ?? '')) ?></td>
                                    <td class="number"><?= journalMoney($line['debit']) ?></td>
                                    <td class="number"><?= journalMoney($line['credit']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="entry-total"><td colspan="2">Kande kokku</td><td class="number"><?= journalMoney($entry['total_debit']) ?></td><td class="number"><?= journalMoney($entry['total_credit']) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </article>
            <?php endforeach; ?>

            <div class="pagination no-print">
                <span class="muted">Kanded <?= number_format(($page - 1) * $perPage + 1, 0, ',', ' ') ?>–<?= number_format(min($page * $perPage, $totalEntries), 0, ',', ' ') ?> kokku <?= number_format($totalEntries, 0, ',', ' ') ?>-st · Leht <?= $page ?> / <?= $totalPages ?></span>
                <?php if ($page > 1): ?><a class="button" href="?<?= h(http_build_query($queryBase + ['page' => $page - 1])) ?>">Eelmised</a><?php endif; ?>
                <?php if ($page < $totalPages): ?><a class="button" href="?<?= h(http_build_query($queryBase + ['page' => $page + 1])) ?>">Järgmised</a><?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>
