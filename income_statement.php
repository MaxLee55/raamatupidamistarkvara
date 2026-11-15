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

$companyId = (int) $_SESSION['company_id'];

$startDate = isset($_GET['start_date']) && is_string($_GET['start_date'])
    ? $_GET['start_date']
    : date('Y-01-01');

$endDate = isset($_GET['end_date']) && is_string($_GET['end_date'])
    ? $_GET['end_date']
    : date('Y-m-d');

$errors = [];
$incomeRows = [];
$expenseRows = [];
$incomeTotal = 0;
$expenseTotal = 0;

if (!validDate($startDate) || !validDate($endDate)) {
    $errors[] = 'Vali kehtiv algus- ja lõpukuupäev.';
} elseif ($startDate > $endDate) {
    $errors[] = 'Alguskuupäev peab olema enne lõpukuupäeva.';
}

if (!$errors) {
    $stmt = $pdo->prepare(
        "SELECT
            a.id,
            a.code,
            a.name,
            a.account_type,
            COALESCE(period_totals.debit_total, 0) AS debit_total,
            COALESCE(period_totals.credit_total, 0) AS credit_total
         FROM accounts a
         LEFT JOIN (
            SELECT
                jl.account_id,
                SUM(jl.debit) AS debit_total,
                SUM(jl.credit) AS credit_total
            FROM journal_lines jl
            JOIN journal_entries je ON je.id = jl.entry_id
            WHERE je.company_id = :entry_company_id
              AND je.status = 'posted'
              AND je.entry_date BETWEEN :start_date AND :end_date
            GROUP BY jl.account_id
         ) period_totals ON period_totals.account_id = a.id
         WHERE a.company_id = :account_company_id
           AND a.account_type IN ('income', 'expense')
         ORDER BY a.account_type, a.code"
    );

    $stmt->execute([
        'entry_company_id' => $companyId,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'account_company_id' => $companyId,
    ]);

    foreach ($stmt->fetchAll() as $row) {
        $debit = toCents((string) $row['debit_total']);
        $credit = toCents((string) $row['credit_total']);

        if ($row['account_type'] === 'income') {
            // Tulu tavapärane saldo on kreedit.
            $amount = $credit - $debit;

            if ($amount !== 0) {
                $incomeRows[] = [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'amount' => $amount,
                ];
                $incomeTotal += $amount;
            }
        } else {
            // Kulu tavapärane saldo on deebet.
            $amount = $debit - $credit;

            if ($amount !== 0) {
                $expenseRows[] = [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'amount' => $amount,
                ];
                $expenseTotal += $amount;
            }
        }
    }
}

$profit = $incomeTotal - $expenseTotal;
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kasumiaruanne</title>

    <style>
        body {
            margin: 32px;
            font-family: Arial, sans-serif;
            color: #263746;
        }

        h1, h2 {
            color: #17324d;
        }

        .filter-card,
        .report-card {
            margin: 20px 0;
            padding: 20px 22px;
            background: #f7f9fc;
            border: 1px solid #dce3ec;
            border-radius: 12px;
        }

        .filter-card {
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
            color: #33475b;
            font-weight: 600;
        }

        .filter-field input {
            box-sizing: border-box;
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #b8c4d1;
            border-radius: 7px;
            background: #fff;
            font: inherit;
        }

        .filter-button,
        .button {
            display: inline-block;
            padding: 10px 17px;
            border: 0;
            border-radius: 7px;
            background: #1769aa;
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .filter-button:hover,
        .button:hover {
            background: #0f4f83;
        }

        .report-card {
            background: #fff;
        }

        .report-card h2 {
            margin-top: 0;
        }

        table {
            width: 100%;
            margin-top: 12px;
            border-collapse: collapse;
        }

        th, td {
            padding: 11px 13px;
            border-bottom: 1px solid #e5eaf0;
            text-align: left;
        }

        th {
            background: #f3f6fa;
            color: #526273;
            font-size: 13px;
            text-transform: uppercase;
        }

        .number {
            text-align: right;
            white-space: nowrap;
        }

        .total-row td {
            border-top: 2px solid #dce3ec;
            font-weight: 700;
        }

        .result-card {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: center;
            margin: 20px 0;
            padding: 20px 22px;
            border-radius: 12px;
            background: #eaf3fb;
            color: #17324d;
            font-size: 20px;
            font-weight: 700;
        }

        .error {
            padding: 12px 15px;
            border: 1px solid #e5a3a3;
            border-radius: 8px;
            background: #fff0f0;
            color: #a32121;
        }

        @media (max-width: 600px) {
            body {
                margin: 18px;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .result-card {
                align-items: flex-start;
                flex-direction: column;
            }
        }

        @media print {
            @page {
                size: A4 portrait;
                margin: 14mm;
            }

            body {
                margin: 0;
                color: #000;
                font-size: 10pt;
            }

            .filter-card,
            .print-toolbar,
            .screen-only {
                display: none !important;
            }

            h1, h2 {
                color: #000;
            }

            h1 {
                margin-top: 0;
                font-size: 18pt;
            }

            .report-card {
                margin: 0;
                padding: 8pt 0;
                border: 0;
                border-radius: 0;
                background: #fff;
                box-shadow: none;
                break-inside: avoid-page;
                page-break-inside: avoid;
            }

            .result-card {
                margin: 12pt 0;
                padding: 10pt;
                border: 1px solid #777;
                border-radius: 0;
                background: #fff !important;
                color: #000 !important;
                font-size: 14pt;
                break-inside: avoid-page;
            }

            table {
                margin-top: 6pt;
            }

            thead {
                display: table-header-group;
            }

            tr {
                break-inside: avoid;
                page-break-inside: avoid;
            }

            th, td {
                padding: 6pt 8pt;
                border-bottom: 0.5pt solid #bbb;
                font-size: 9pt;
            }

            th {
                background: #eee !important;
                color: #000;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
        }
    </style>
</head>
<body>
    <h1>Kasumiaruanne</h1>

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
        <p class="error"><?= h($error) ?></p>
    <?php endforeach; ?>

    <?php if (!$errors): ?>
        <p>Periood: <?= h($startDate) ?> kuni <?= h($endDate) ?></p>

        <section class="report-card">
            <h2>Tulud</h2>

            <?php if (!$incomeRows): ?>
                <p>Valitud perioodil tulukontodel kandeid pole.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Konto</th>
                            <th class="number">Summa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($incomeRows as $row): ?>
                            <tr>
                                <td><?= h($row['code'] . ' — ' . $row['name']) ?></td>
                                <td class="number"><?= money($row['amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td>Tulud kokku</td>
                            <td class="number"><?= money($incomeTotal) ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="report-card">
            <h2>Kulud</h2>

            <?php if (!$expenseRows): ?>
                <p>Valitud perioodil kulukontodel kandeid pole.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Konto</th>
                            <th class="number">Summa</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenseRows as $row): ?>
                            <tr>
                                <td><?= h($row['code'] . ' — ' . $row['name']) ?></td>
                                <td class="number"><?= money($row['amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td>Kulud kokku</td>
                            <td class="number"><?= money($expenseTotal) ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <div class="result-card">
            <span><?= $profit >= 0 ? 'Kasum' : 'Kahjum' ?></span>
            <span><?= money(abs($profit)) ?></span>
        </div>
    <?php endif; ?>

    <p class="screen-only"><a class="button" href="index.php">Tagasi avalehele</a></p>
</body>
</html>