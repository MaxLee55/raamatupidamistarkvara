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

function validDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function toCents(string $amount): int
{
    $negative = isset($amount[0]) && $amount[0] === '-';
    $amount = ltrim($amount, '-');
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

    $cents = ((int) $whole * 100)
        + (int) str_pad(substr($fraction, 0, 2), 2, '0');

    return $negative ? -$cents : $cents;
}

function money(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

// Saldo deebet-kreedit kujule.
function balanceSides(int $balance): array
{
    return [
        'debit' => max($balance, 0),
        'credit' => max(-$balance, 0),
    ];
}

$companyId = (int) $_SESSION['company_id'];
$today = date('Y-m-d');

$startDate = isset($_GET['start_date']) && is_string($_GET['start_date'])
    ? $_GET['start_date']
    : date('Y-01-01');

$endDate = isset($_GET['end_date']) && is_string($_GET['end_date'])
    ? $_GET['end_date']
    : $today;

$errors = [];
$balances = [];

if (!validDate($startDate) || !validDate($endDate)) {
    $errors[] = 'Vali kehtiv algus- ja lõpukuupäev.';
} elseif ($startDate > $endDate) {
    $errors[] = 'Perioodi alguskuupäev peab olema enne lõpukuupäeva.';
}

if (!$errors) {
    $accountsStmt = $pdo->prepare(
        'SELECT id, code, name
         FROM accounts
         WHERE company_id = :company_id
         ORDER BY code'
    );
    $accountsStmt->execute(['company_id' => $companyId]);
    $accounts = $accountsStmt->fetchAll();

    foreach ($accounts as $account) {
        $balances[(int) $account['id']] = [
            'account' => $account,
            'opening' => 0,
            'debit' => 0,
            'credit' => 0,
        ];
    }

    // Kanded enne valitud perioodi määravad algsaldo.
    $openingStmt = $pdo->prepare(
        "SELECT jl.account_id, jl.debit, jl.credit
         FROM journal_lines jl
         JOIN journal_entries je ON je.id = jl.entry_id
         JOIN accounts a
           ON a.id = jl.account_id
          AND a.company_id = je.company_id
         WHERE je.company_id = :company_id
           AND je.status = 'posted'
           AND je.entry_date < :start_date"
    );
    $openingStmt->execute([
        'company_id' => $companyId,
        'start_date' => $startDate,
    ]);

    foreach ($openingStmt->fetchAll() as $line) {
        $accountId = (int) $line['account_id'];

        if (isset($balances[$accountId])) {
            $balances[$accountId]['opening'] +=
                toCents((string) $line['debit'])
                - toCents((string) $line['credit']);
        }
    }

    // Valitud perioodi deebet- ja kreeditkäive.
    $periodStmt = $pdo->prepare(
        "SELECT jl.account_id, jl.debit, jl.credit
         FROM journal_lines jl
         JOIN journal_entries je ON je.id = jl.entry_id
         JOIN accounts a
           ON a.id = jl.account_id
          AND a.company_id = je.company_id
         WHERE je.company_id = :company_id
           AND je.status = 'posted'
           AND je.entry_date BETWEEN :start_date AND :end_date"
    );
    $periodStmt->execute([
        'company_id' => $companyId,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);

    foreach ($periodStmt->fetchAll() as $line) {
        $accountId = (int) $line['account_id'];

        if (isset($balances[$accountId])) {
            $balances[$accountId]['debit'] += toCents((string) $line['debit']);
            $balances[$accountId]['credit'] += toCents((string) $line['credit']);
        }
    }
}

$totals = [
    'opening_debit' => 0,
    'opening_credit' => 0,
    'debit' => 0,
    'credit' => 0,
    'closing_debit' => 0,
    'closing_credit' => 0,
];

foreach ($balances as $row) {
    $openingSides = balanceSides($row['opening']);
    $closingBalance = $row['opening'] + $row['debit'] - $row['credit'];
    $closingSides = balanceSides($closingBalance);

    $totals['opening_debit'] += $openingSides['debit'];
    $totals['opening_credit'] += $openingSides['credit'];
    $totals['debit'] += $row['debit'];
    $totals['credit'] += $row['credit'];
    $totals['closing_debit'] += $closingSides['debit'];
    $totals['closing_credit'] += $closingSides['credit'];
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Käibeandmik</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 32px; }
        table { border-collapse: collapse; margin-top: 18px; width: 100%; }
        th, td { padding: 8px; border: 1px solid #ccc; }
        th { text-align: left; }
        .number { text-align: right; white-space: nowrap; }
        .filters { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; }
        .filters label { display: grid; gap: 5px; }
        tfoot { font-weight: bold; }
        .button { display: inline-block; padding: 9px 14px; background: #1769aa; color: white; text-decoration: none; border-radius: 6px; }
		
		.filter-card {
    margin: 20px 0;
    padding: 20px 22px;
    background: #f7f9fc;
    border: 1px solid #dce3ec;
    border-radius: 12px;
    max-width: 720px;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(200px, 1fr));
    gap: 18px;
    align-items: end;
}

.filter-field label {
    display: block;
    margin-bottom: 7px;
    font-weight: 600;
    color: #33475b;
}

.filter-field input {
    box-sizing: border-box;
    width: 100%;
    padding: 11px 12px;
    border: 1px solid #b8c4d1;
    border-radius: 7px;
    background: white;
    font: inherit;
}

.filter-button {
    padding: 11px 20px;
    border: 0;
    border-radius: 7px;
    background: #1769aa;
    color: white;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
}

.filter-button:hover {
    background: #0f4f83;
}

@media (max-width: 600px) {
    .filter-grid {
        grid-template-columns: 1fr;
    }
}

@media print {
    @page {
        size: A4 landscape;
        margin: 12mm;
    }

    body {
        margin: 0;
        color: #000;
        font-size: 9pt;
    }

    .filter-card,
    .print-toolbar,
    .screen-only {
        display: none !important;
    }

    h1 {
        margin-top: 0;
        color: #000;
        font-size: 18pt;
    }

    table {
        width: 100%;
        margin-top: 8pt;
        border-collapse: collapse;
    }

    thead {
        display: table-header-group;
    }

    tr {
        break-inside: avoid;
        page-break-inside: avoid;
    }

    th, td {
        padding: 5pt 6pt;
        border: 0.5pt solid #999;
        font-size: 8pt;
    }

    th {
        background: #eee !important;
        color: #000;
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }

    tfoot {
        display: table-row-group;
        font-weight: bold;
    }
}
    </style>
</head>
<body>
    <h1>Käibeandmik</h1>

    <div class="print-toolbar">
        <button class="button" type="button" onclick="window.print()">Prindi / salvesta PDF</button>
    </div>

    <form method="get" class="filter-card">
    <div class="filter-grid">
        <div class="filter-field">
            <label for="start-date">Alates</label>
            <input
                id="start-date"
                type="date"
                name="start_date"
                value="<?= h($startDate) ?>"
                required
            >
        </div>

        <div class="filter-field">
            <label for="end-date">Kuni</label>
            <input
                id="end-date"
                type="date"
                name="end_date"
                value="<?= h($endDate) ?>"
                required
            >
        </div>

        <div>
            <button class="filter-button" type="submit">Näita</button>
        </div>
    </div>
</form>
    <?php foreach ($errors as $error): ?>
        <p><?= h($error) ?></p>
    <?php endforeach; ?>

    <?php if (!$errors): ?>
        <p>Periood: <?= h($startDate) ?> kuni <?= h($endDate) ?></p>

        <table>
            <thead>
                <tr>
                    <th rowspan="2">Konto</th>
                    <th colspan="2">Algsaldo</th>
                    <th colspan="2">Perioodi käive</th>
                    <th colspan="2">Lõppsaldo</th>
                </tr>
                <tr>
                    <th class="number">Deebet</th>
                    <th class="number">Kreedit</th>
                    <th class="number">Deebet</th>
                    <th class="number">Kreedit</th>
                    <th class="number">Deebet</th>
                    <th class="number">Kreedit</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($balances as $row): ?>
                    <?php
                    $openingSides = balanceSides($row['opening']);
                    $closingBalance = $row['opening'] + $row['debit'] - $row['credit'];
                    $closingSides = balanceSides($closingBalance);
                    ?>
                    <tr>
                        <td>
                            <?= h($row['account']['code'] . ' — ' . $row['account']['name']) ?>
                        </td>
                        <td class="number"><?= money($openingSides['debit']) ?></td>
                        <td class="number"><?= money($openingSides['credit']) ?></td>
                        <td class="number"><?= money($row['debit']) ?></td>
                        <td class="number"><?= money($row['credit']) ?></td>
                        <td class="number"><?= money($closingSides['debit']) ?></td>
                        <td class="number"><?= money($closingSides['credit']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td>Kokku</td>
                    <td class="number"><?= money($totals['opening_debit']) ?></td>
                    <td class="number"><?= money($totals['opening_credit']) ?></td>
                    <td class="number"><?= money($totals['debit']) ?></td>
                    <td class="number"><?= money($totals['credit']) ?></td>
                    <td class="number"><?= money($totals['closing_debit']) ?></td>
                    <td class="number"><?= money($totals['closing_credit']) ?></td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>

    <p class="screen-only"><a class="button" href="index.php">Tagasi avalehele</a></p>
</body>
</html>