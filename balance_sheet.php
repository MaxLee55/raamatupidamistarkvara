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

$asOfDate = isset($_GET['as_of_date']) && is_string($_GET['as_of_date'])
    ? $_GET['as_of_date']
    : date('Y-m-d');

$errors = [];
$assets = [];
$liabilities = [];
$equityAccounts = [];

$totalAssets = 0;
$totalLiabilities = 0;
$totalEquityAccounts = 0;
$totalIncome = 0;
$totalExpenses = 0;

if (!validDate($asOfDate)) {
    $errors[] = 'Vali kehtiv kuupäev.';
}

if (!$errors) {
    $stmt = $pdo->prepare(
        "SELECT
            a.code,
            a.name,
            a.account_type,
            COALESCE(balances.debit_total, 0) AS debit_total,
            COALESCE(balances.credit_total, 0) AS credit_total
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
              AND je.entry_date <= :as_of_date
            GROUP BY jl.account_id
         ) balances ON balances.account_id = a.id
         WHERE a.company_id = :account_company_id
           AND a.account_type IN ('asset', 'liability', 'equity', 'income', 'expense')
         ORDER BY a.code"
    );

    $stmt->execute([
        'entry_company_id' => $companyId,
        'as_of_date' => $asOfDate,
        'account_company_id' => $companyId,
    ]);

    foreach ($stmt->fetchAll() as $row) {
        $debit = toCents((string) $row['debit_total']);
        $credit = toCents((string) $row['credit_total']);

        if ($row['account_type'] === 'asset') {
            $amount = $debit - $credit;
            if ($amount !== 0) {
                $assets[] = [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'amount' => $amount,
                ];
                $totalAssets += $amount;
            }
        } elseif ($row['account_type'] === 'liability') {
            $amount = $credit - $debit;
            if ($amount !== 0) {
                $liabilities[] = [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'amount' => $amount,
                ];
                $totalLiabilities += $amount;
            }
        } elseif ($row['account_type'] === 'equity') {
            $amount = $credit - $debit;
            if ($amount !== 0) {
                $equityAccounts[] = [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'amount' => $amount,
                ];
                $totalEquityAccounts += $amount;
            }
        } elseif ($row['account_type'] === 'income') {
            $totalIncome += $credit - $debit;
        } elseif ($row['account_type'] === 'expense') {
            $totalExpenses += $debit - $credit;
        }
    }
}

// Tulude ja kulude saldo lisatakse omakapitali arvutusse.
$accumulatedProfit = $totalIncome - $totalExpenses;
$totalEquity = $totalEquityAccounts + $accumulatedProfit;
$totalLiabilitiesAndEquity = $totalLiabilities + $totalEquity;
$difference = $totalAssets - $totalLiabilitiesAndEquity;
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bilanss</title>

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
            background: #fff;
            border: 1px solid #dce3ec;
            border-radius: 12px;
        }

        .filter-card {
            max-width: 520px;
            background: #f7f9fc;
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
            margin-top: 14px;
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

        .balance-check {
            margin: 20px 0;
            padding: 16px 20px;
            border: 1px solid #b8dfc7;
            border-radius: 10px;
            background: #eaf7ef;
            color: #176b3a;
            font-weight: 700;
        }

        .balance-check.error {
            border-color: #e5a3a3;
            background: #fff0f0;
            color: #a32121;
        }

        .report-note {
            color: #526273;
            line-height: 1.5;
        }

        @media (max-width: 600px) {
            body {
                margin: 18px;
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

            .balance-check {
                padding: 9pt 11pt;
                border: 1px solid #777;
                border-radius: 0;
                background: #fff !important;
                color: #000 !important;
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

            .report-note {
                font-size: 9pt;
            }
        }
    </style>
</head>
<body>
    <h1>Bilanss</h1>

    <div class="print-toolbar">
        <button class="button" type="button" onclick="window.print()">Prindi / salvesta PDF</button>
    </div>

    <form method="get" class="filter-card">
        <div class="filter-field">
            <label for="as-of-date">Seisuga</label>
            <input
                id="as-of-date"
                type="date"
                name="as_of_date"
                value="<?= h($asOfDate) ?>"
                required
            >
        </div>

        <button class="filter-button" type="submit">Näita</button>
    </form>

    <?php foreach ($errors as $error): ?>
        <p><?= h($error) ?></p>
    <?php endforeach; ?>

    <?php if (!$errors): ?>
        <p>Bilanss seisuga <?= h($asOfDate) ?></p>

        <section class="report-card">
            <h2>Varad</h2>

            <?php if (!$assets): ?>
                <p>Varakontodel selle kuupäeva seisuga saldosid pole.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Konto</th>
                            <th class="number">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assets as $row): ?>
                            <tr>
                                <td><?= h($row['code'] . ' — ' . $row['name']) ?></td>
                                <td class="number"><?= money($row['amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td>Varad kokku</td>
                            <td class="number"><?= money($totalAssets) ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="report-card">
            <h2>Kohustused</h2>

            <?php if (!$liabilities): ?>
                <p>Kohustuste kontodel selle kuupäeva seisuga saldosid pole.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Konto</th>
                            <th class="number">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($liabilities as $row): ?>
                            <tr>
                                <td><?= h($row['code'] . ' — ' . $row['name']) ?></td>
                                <td class="number"><?= money($row['amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td>Kohustused kokku</td>
                            <td class="number"><?= money($totalLiabilities) ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

        <section class="report-card">
            <h2>Omakapital</h2>

            <table>
                <thead>
                    <tr>
                        <th>Konto</th>
                        <th class="number">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$equityAccounts): ?>
                        <tr>
                            <td colspan="2">Omakapitali kontodel saldosid pole.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($equityAccounts as $row): ?>
                            <tr>
                                <td><?= h($row['code'] . ' — ' . $row['name']) ?></td>
                                <td class="number"><?= money($row['amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <tr>
                        <td>Kasum/kahjum tulude ja kulude põhjal</td>
                        <td class="number"><?= money($accumulatedProfit) ?></td>
                    </tr>
                    <tr class="total-row">
                        <td>Omakapital kokku</td>
                        <td class="number"><?= money($totalEquity) ?></td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="report-card">
            <table>
                <tbody>
                    <tr class="total-row">
                        <td>Kohustused ja omakapital kokku</td>
                        <td class="number"><?= money($totalLiabilitiesAndEquity) ?></td>
                    </tr>
                </tbody>
            </table>
        </section>

        <?php if ($difference === 0): ?>
            <p class="balance-check">
                Bilanss klapib. Varad võrdub kohustuste ja omakapitaliga.
            </p>
        <?php else: ?>
            <p class="balance-check error">
                Bilanss ei klapi. Vahe: <?= money($difference) ?>.
                Kontrolli algkandeid ja pearaamatu kandeid.
            </p>
        <?php endif; ?>

        <p class="report-note">
            Kasum või kahjum arvutatakse kinnitatud tulu- ja kulukannete põhjal
            kuni valitud kuupäevani.
        </p>
    <?php endif; ?>

    <p class="screen-only"><a class="button" href="index.php">Tagasi avalehele</a></p>
</body>
</html>