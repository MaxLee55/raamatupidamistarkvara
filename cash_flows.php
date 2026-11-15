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

function cashDateIsValid(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function cashCents($value): int
{
    return (int) round((float) $value * 100);
}

function cashMoney(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

$companyId = (int) $_SESSION['company_id'];
$today = date('Y-m-d');
$startDate = (string) ($_GET['start_date'] ?? date('Y-01-01'));
$endDate = (string) ($_GET['end_date'] ?? $today);
$selectedAccountId = (int) ($_GET['account_id'] ?? 0);
$errors = [];
$accounts = [];
$accountById = [];
$entries = [];
$linesByEntry = [];
$openingBalance = 0;
$totalInflows = 0;
$totalOutflows = 0;
$internalTransferTotal = 0;
$internalTransferCount = 0;
$closingBalance = 0;

$accountStmt = $pdo->prepare(
    "SELECT id, code, name
     FROM accounts
     WHERE company_id = ? AND account_type = 'asset' AND code REGEXP '^10[0-9]{2}$'
     ORDER BY code"
);
$accountStmt->execute([$companyId]);
$accounts = $accountStmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($accounts as $account) $accountById[(int) $account['id']] = $account;

if (!cashDateIsValid($startDate) || !cashDateIsValid($endDate)) {
    $errors[] = 'Vali kehtiv algus- ja lõpukuupäev.';
} elseif ($startDate > $endDate) {
    $errors[] = 'Perioodi alguskuupäev peab olema enne lõpukuupäeva.';
}
if ($selectedAccountId > 0 && !isset($accountById[$selectedAccountId])) {
    $errors[] = 'Valitud kassa- või pangakontot ei leitud.';
}

if (!$errors) {
    $accountFilter = '';
    $accountParams = [];
    if ($selectedAccountId > 0) {
        $accountFilter = ' AND a.id = ?';
        $accountParams[] = $selectedAccountId;
    }

    $openingStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
         FROM journal_lines jl
         JOIN journal_entries je ON je.id = jl.entry_id
         JOIN accounts a ON a.id = jl.account_id AND a.company_id = je.company_id
         WHERE je.company_id = ? AND je.status = 'posted' AND je.entry_date < ?
           AND a.account_type = 'asset' AND a.code REGEXP '^10[0-9]{2}$'$accountFilter"
    );
    $openingStmt->execute(array_merge([$companyId, $startDate], $accountParams));
    $openingBalance = cashCents($openingStmt->fetchColumn());

    // Kogume rahaliikumise kande kaupa. Kõigi kontode vaates tasakaalus
    // pangakontodevaheline ülekanne ei ole ettevõtte rahavoog.
    $movementStmt = $pdo->prepare(
        "SELECT SUM(CASE WHEN cash_debit > cash_credit THEN cash_debit - cash_credit ELSE 0 END) AS inflows,
                SUM(CASE WHEN cash_credit > cash_debit THEN cash_credit - cash_debit ELSE 0 END) AS outflows,
                SUM(CASE WHEN cash_debit > 0 AND cash_credit > 0 AND cash_debit = cash_credit THEN cash_debit ELSE 0 END) AS internal_transfers,
                SUM(CASE WHEN cash_debit > 0 AND cash_credit > 0 AND cash_debit = cash_credit THEN 1 ELSE 0 END) AS internal_count
         FROM (
             SELECT je.id,
                    SUM(jl.debit) AS cash_debit,
                    SUM(jl.credit) AS cash_credit
             FROM journal_entries je
             JOIN journal_lines jl ON jl.entry_id = je.id
             JOIN accounts a ON a.id = jl.account_id AND a.company_id = je.company_id
             WHERE je.company_id = ? AND je.status = 'posted'
               AND je.entry_date BETWEEN ? AND ?
               AND a.account_type = 'asset' AND a.code REGEXP '^10[0-9]{2}$'$accountFilter
             GROUP BY je.id
         ) cash_by_entry"
    );
    $movementStmt->execute(array_merge([$companyId, $startDate, $endDate], $accountParams));
    $movement = $movementStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalInflows = cashCents($movement['inflows'] ?? 0);
    $totalOutflows = cashCents($movement['outflows'] ?? 0);
    $internalTransferTotal = cashCents($movement['internal_transfers'] ?? 0);
    $internalTransferCount = (int) ($movement['internal_count'] ?? 0);
    $closingBalance = $openingBalance + $totalInflows - $totalOutflows;

    $entriesStmt = $pdo->prepare(
        "SELECT je.id, je.entry_date, je.document_number, je.description,
                SUM(jl.debit) AS cash_debit, SUM(jl.credit) AS cash_credit
         FROM journal_entries je
         JOIN journal_lines jl ON jl.entry_id = je.id
         JOIN accounts a ON a.id = jl.account_id AND a.company_id = je.company_id
         WHERE je.company_id = ? AND je.status = 'posted'
           AND je.entry_date BETWEEN ? AND ?
           AND a.account_type = 'asset' AND a.code REGEXP '^10[0-9]{2}$'$accountFilter
         GROUP BY je.id, je.entry_date, je.document_number, je.description
         ORDER BY je.entry_date DESC, je.id DESC"
    );
    $entriesStmt->execute(array_merge([$companyId, $startDate, $endDate], $accountParams));
    $entries = $entriesStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($entries) {
        $ids = array_map(static fn(array $entry): int => (int) $entry['id'], $entries);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $linesStmt = $pdo->prepare(
            "SELECT jl.entry_id, jl.account_id, jl.description AS line_description,
                    jl.debit, jl.credit, a.code, a.name
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

function cashEntryDetails(array $lines, int $selectedAccountId, array $accountById): array
{
    $debit = 0;
    $credit = 0;
    $counterparts = [];
    foreach ($lines as $line) {
        $isCash = preg_match('/^10[0-9]{2}$/', (string) $line['code']) === 1;
        if ($isCash && ($selectedAccountId === 0 || (int) $line['account_id'] === $selectedAccountId)) {
            $debit += cashCents($line['debit']);
            $credit += cashCents($line['credit']);
        }
        if (!$isCash) {
            $counterparts[] = $line['code'] . ' — ' . $line['name'];
        } elseif ($selectedAccountId > 0 && (int) $line['account_id'] !== $selectedAccountId && isset($accountById[(int) $line['account_id']])) {
            $other = $accountById[(int) $line['account_id']];
            $counterparts[] = $other['code'] . ' — ' . $other['name'] . ' (ülekanne)';
        }
    }
    return ['debit' => $debit, 'credit' => $credit, 'counterparts' => array_values(array_unique($counterparts))];
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rahavood</title>
    <style>
        * { box-sizing: border-box; }
        body { max-width: 1380px; margin: 28px auto; padding: 0 20px; color: #243447; background: #f4f7fb; font-family: Arial, sans-serif; }
        h1, h2 { color: #17324d; }
        .muted { color: #65778a; }
        .card { margin: 18px 0; padding: 20px; border: 1px solid #dce3ec; border-radius: 12px; background: #fff; box-shadow: 0 2px 8px #17324d0a; }
        .filters { display:flex; flex-wrap:wrap; gap:14px; align-items:end; }
        .filters label { display:grid; gap:6px; color:#40566b; font-weight:600; }
        input, select { min-height:42px; padding:8px 10px; border:1px solid #b8c6d4; border-radius:7px; background:#fff; font:inherit; }
        .button { display:inline-block; min-height:42px; padding:11px 16px; border:0; border-radius:7px; background:#1769aa; color:#fff; font:inherit; font-weight:600; text-decoration:none; cursor:pointer; }
        .button:hover { background:#0f548c; }
        .secondary { background:#5f7081; }
        .summary-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:12px; }
        .summary-item { padding:13px 15px; border:1px solid #e1e7ef; border-radius:9px; background:#f7f9fc; }
        .summary-item span { display:block; margin-bottom:6px; color:#65778a; font-size:13px; }
        .summary-item strong { color:#17324d; font-size:19px; }
        table { width:100%; border-collapse:collapse; background:#fff; }
        th,td { padding:10px 12px; border-bottom:1px solid #e5eaf0; text-align:left; vertical-align:top; }
        th { background:#f3f6fa; color:#526273; white-space:nowrap; }
        .number { text-align:right; white-space:nowrap; }
        .entry-title a { color:#1769aa; text-decoration:none; font-weight:600; }
        .entry-title a:hover { text-decoration:underline; }
        .inflow { color:#176b3a; font-weight:600; }
        .outflow { color:#9a3131; font-weight:600; }
        .internal { color:#526273; font-weight:600; }
        .table-wrap { overflow-x:auto; }
        .error { padding:12px 15px; border:1px solid #e5a3a3; border-radius:8px; background:#fff0f0; color:#a32121; }
        @media print {
            body { max-width:none; margin:0; padding:0; background:#fff; color:#000; }
            .no-print { display:none !important; }
            .card { box-shadow:none; border-color:#bbb; }
            th { background:#eee !important; print-color-adjust:exact; }
            a { color:#000 !important; text-decoration:none !important; }
        }
    </style>
</head>
<body>
    <header class="no-print">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
            <h1>Rahavood</h1>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <button class="button secondary" type="button" onclick="window.print()">Prindi</button>
                <a class="button" href="index.php">Avalehele</a>
            </div>
        </div>
        <p class="muted">Kassa- ja pangakontode kinnitatud liikumised valitud perioodil.</p>
    </header>

    <section class="card no-print">
        <form method="get" class="filters">
            <label>Alates<input type="date" name="start_date" value="<?= h($startDate) ?>" required></label>
            <label>Kuni<input type="date" name="end_date" value="<?= h($endDate) ?>" required></label>
            <label>Pangakonto
                <select name="account_id">
                    <option value="0">Kõik kassa- ja pangakontod</option>
                    <?php foreach ($accounts as $account): ?>
                        <option value="<?= (int) $account['id'] ?>" <?= $selectedAccountId === (int) $account['id'] ? 'selected' : '' ?>><?= h($account['code'] . ' — ' . $account['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="button" type="submit">Näita</button>
            <a class="button secondary" href="cash_flows.php">Tühjenda filtrid</a>
        </form>
    </section>

    <?php foreach ($errors as $error): ?><p class="error"><?= h($error) ?></p><?php endforeach; ?>

    <?php if (!$errors): ?>
        <section class="card">
            <p class="muted">Periood <?= h($startDate) ?> kuni <?= h($endDate) ?> · Arvestuses on ainult kinnitatud kanded.</p>
            <div class="summary-grid">
                <div class="summary-item"><span>Algsaldo</span><strong><?= cashMoney($openingBalance) ?></strong></div>
                <div class="summary-item"><span>Laekumised</span><strong><?= cashMoney($totalInflows) ?></strong></div>
                <div class="summary-item"><span>Väljamaksed</span><strong><?= cashMoney($totalOutflows) ?></strong></div>
                <?php if ($selectedAccountId === 0 && $internalTransferCount > 0): ?><div class="summary-item"><span>Kontodevahelised ülekanded (neutraalsed)</span><strong><?= cashMoney($internalTransferTotal) ?></strong></div><?php endif; ?>
                <div class="summary-item"><span>Lõppsaldo</span><strong><?= cashMoney($closingBalance) ?></strong></div>
            </div>
            <?php if ($selectedAccountId === 0): ?><p class="muted">Kõigi kontode vaates ei loeta kontoülekandeid laekumiseks ega väljamakseks. Ühe konto vaates kajastuvad need selle konto liikumisena.</p><?php endif; ?>
        </section>

        <section class="card">
            <h2>Tehingud</h2>
            <?php if (!$entries): ?>
                <p>Valitud perioodis rahaliikumisi ei leitud.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Kuupäev</th><th>Dokumendi nr</th><th>Kirjeldus</th><th>Vastaskonto(d)</th><th class="number">Laekumine</th><th class="number">Väljamakse</th></tr></thead>
                        <tbody>
                        <?php foreach ($entries as $entry):
                            $entryId = (int) $entry['id'];
                            $details = cashEntryDetails($linesByEntry[$entryId] ?? [], $selectedAccountId, $accountById);
                            $net = $details['debit'] - $details['credit'];
                            $isInternal = $selectedAccountId === 0
                                && cashCents($entry['cash_debit']) > 0
                                && cashCents($entry['cash_credit']) > 0
                                && cashCents($entry['cash_debit']) === cashCents($entry['cash_credit']);
                            $inflow = $isInternal ? 0 : max(0, $net);
                            $outflow = $isInternal ? 0 : max(0, -$net);
                            $counterpartText = $details['counterparts'] ? implode(', ', $details['counterparts']) : ($isInternal ? 'Kassa- või pangakontode vaheline liikumine' : '—');
                            $description = trim((string) $entry['description']);
                        ?>
                            <tr>
                                <td><?= h($entry['entry_date']) ?></td>
                                <td><?= h((string) ($entry['document_number'] ?? '')) ?></td>
                                <td class="entry-title"><a href="entry.php?id=<?= $entryId ?>"><?= h($description) ?></a><?php if ($isInternal): ?><br><span class="internal">Kontodevaheline ülekanne</span><?php elseif ($inflow > 0): ?><br><span class="inflow">Laekumine</span><?php elseif ($outflow > 0): ?><br><span class="outflow">Väljamakse</span><?php endif; ?></td>
                                <td><?= h($counterpartText) ?></td>
                                <td class="number"><?= $inflow ? '<span class="inflow">' . h(cashMoney($inflow)) . '</span>' : '—' ?></td>
                                <td class="number"><?= $outflow ? '<span class="outflow">' . h(cashMoney($outflow)) . '</span>' : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr><th colspan="4">Perioodi kokku</th><th class="number"><?= cashMoney($totalInflows) ?></th><th class="number"><?= cashMoney($totalOutflows) ?></th></tr></tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</body>
</html>
