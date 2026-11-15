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

function validDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

$companyId = (int) $_SESSION['company_id'];
$asOfDate = $_GET['as_of_date'] ?? date('Y-m-d');
$errors = [];
$rows = [];
$unassignedBalance = 0;
$unassignedInvoices = 0;

if (!validDate($asOfDate)) {
    $errors[] = 'Vali kehtiv kuupäev.';
}

if (!$errors) {
    // Ettevõtte varakontod, mille hulka kuulub ka ostjate nõuete konto.
    $accountsStmt = $pdo->prepare(
        "SELECT id, code, name
         FROM accounts
         WHERE company_id = :company_id
           AND account_type = 'asset'
         ORDER BY code"
    );
    $accountsStmt->execute(['company_id' => $companyId]);

    $accountsById = [];
    foreach ($accountsStmt->fetchAll(PDO::FETCH_ASSOC) as $account) {
        $accountsById[(int) $account['id']] = $account;
    }

    // Kinnitatud arved ja nende algses pearaamatu kandes kasutatud nõuete konto.
    $invoicesStmt = $pdo->prepare(
        "SELECT
            si.id,
            si.invoice_number,
            si.journal_entry_id,
            COALESCE(
                (SELECT SUM(sil.gross_amount)
                 FROM sales_invoice_lines sil
                 WHERE sil.invoice_id = si.id),
                0
            ) AS invoice_total,
            (SELECT jl.account_id
             FROM journal_lines jl
             JOIN accounts a
               ON a.id = jl.account_id
              AND a.company_id = si.company_id
             WHERE jl.entry_id = si.journal_entry_id
               AND a.account_type = 'asset'
               AND jl.debit > 0
               AND jl.credit = 0
             ORDER BY jl.id
             LIMIT 1) AS receivable_account_id
         FROM sales_invoices si
         WHERE si.company_id = :company_id
           AND si.status = 'posted'
           AND si.invoice_date <= :as_of_date
         ORDER BY si.invoice_date, si.id"
    );
    $invoicesStmt->execute([
        'company_id' => $companyId,
        'as_of_date' => $asOfDate,
    ]);

    $invoiceAccounts = [];
    $subledgerByAccount = [];

    foreach ($invoicesStmt->fetchAll(PDO::FETCH_ASSOC) as $invoice) {
        $invoiceId = (int) $invoice['id'];
        $accountId = (int) ($invoice['receivable_account_id'] ?? 0);
        $amount = toCents((string) $invoice['invoice_total']);

        if ($accountId && isset($accountsById[$accountId])) {
            $invoiceAccounts[$invoiceId] = $accountId;

            if (!isset($subledgerByAccount[$accountId])) {
                $subledgerByAccount[$accountId] = 0;
            }

            $subledgerByAccount[$accountId] += $amount;
        } else {
            $invoiceAccounts[$invoiceId] = null;
            $unassignedBalance += $amount;
            $unassignedInvoices++;
        }
    }

    // Vähendame arvete saldot nende laekumiste võrra, mis olid kuupäevaks registreeritud.
    $paymentsStmt = $pdo->prepare(
        "SELECT ip.invoice_id, ip.amount
         FROM invoice_payments ip
         JOIN sales_invoices si ON si.id = ip.invoice_id
         WHERE si.company_id = :company_id
           AND si.status = 'posted'
           AND si.invoice_date <= :invoice_cutoff
           AND ip.payment_date <= :payment_cutoff"
    );
    $paymentsStmt->execute([
        'company_id' => $companyId,
        'invoice_cutoff' => $asOfDate,
        'payment_cutoff' => $asOfDate,
    ]);

    foreach ($paymentsStmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
        $invoiceId = (int) $payment['invoice_id'];
        $amount = toCents((string) $payment['amount']);
        $accountId = $invoiceAccounts[$invoiceId] ?? null;

        if ($accountId !== null) {
            $subledgerByAccount[$accountId] -= $amount;
        } else {
            $unassignedBalance -= $amount;
        }
    }

    // Pearaamatu saldo sama kuupäeva seisuga.
    $ledgerStmt = $pdo->prepare(
        "SELECT
            jl.account_id,
            COALESCE(SUM(jl.debit - jl.credit), 0) AS balance
         FROM journal_lines jl
         JOIN journal_entries je ON je.id = jl.entry_id
         JOIN accounts a
           ON a.id = jl.account_id
          AND a.company_id = je.company_id
         WHERE je.company_id = :company_id
           AND je.status = 'posted'
           AND je.entry_date <= :as_of_date
           AND a.account_type = 'asset'
         GROUP BY jl.account_id"
    );
    $ledgerStmt->execute([
        'company_id' => $companyId,
        'as_of_date' => $asOfDate,
    ]);

    $ledgerByAccount = [];
    foreach ($ledgerStmt->fetchAll(PDO::FETCH_ASSOC) as $balance) {
        $ledgerByAccount[(int) $balance['account_id']] =
            toCents((string) $balance['balance']);
    }

    $accountIds = array_keys($subledgerByAccount);

    foreach ($accountIds as $accountId) {
        if (!isset($accountsById[$accountId])) {
            continue;
        }

        $subledgerBalance = $subledgerByAccount[$accountId] ?? 0;
        $ledgerBalance = $ledgerByAccount[$accountId] ?? 0;

        $rows[] = [
            'account' => $accountsById[$accountId],
            'subledger' => $subledgerBalance,
            'ledger' => $ledgerBalance,
            'difference' => $ledgerBalance - $subledgerBalance,
        ];
    }

    usort($rows, function (array $a, array $b): int {
        return strcmp($a['account']['code'], $b['account']['code']);
    });
}

$totalSubledger = 0;
$totalLedger = 0;
foreach ($rows as $row) {
    $totalSubledger += $row['subledger'];
    $totalLedger += $row['ledger'];
}
$totalDifference = $totalLedger - $totalSubledger;
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ostjate nõuete võrdlus</title>
    <style>
        body {
            max-width: 1100px;
            margin: 36px auto;
            padding: 0 22px;
            background: #f4f7fb;
            color: #243447;
            font-family: Arial, sans-serif;
        }

        h1, h2 { color: #17324d; }

        .card {
            margin: 20px 0;
            padding: 20px;
            background: #fff;
            border: 1px solid #dce3ec;
            border-radius: 12px;
        }

        .filter-row {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            gap: 14px;
        }

        label {
            display: grid;
            gap: 7px;
            font-weight: 600;
        }

        input {
            box-sizing: border-box;
            min-height: 42px;
            padding: 9px 11px;
            border: 1px solid #b8c4d1;
            border-radius: 7px;
            background: white;
            font: inherit;
        }

        .button {
            display: inline-block;
            padding: 11px 17px;
            border: 0;
            border-radius: 7px;
            background: #1769aa;
            color: white;
            font: inherit;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        table {
            width: 100%;
            margin-top: 18px;
            border-collapse: collapse;
            background: white;
        }

        th, td {
            padding: 11px 12px;
            border-bottom: 1px solid #e1e7ee;
            text-align: left;
        }

        th {
            background: #eaf0f6;
            color: #17324d;
        }

        .number {
            text-align: right;
            white-space: nowrap;
        }

        .ok {
            color: #176b32;
            font-weight: 700;
        }

        .difference {
            color: #a32121;
            font-weight: 700;
        }

        .notice, .error {
            padding: 12px 14px;
            border-radius: 8px;
        }

        .notice {
            border: 1px solid #ead9a2;
            background: #fff8df;
            color: #715600;
        }

        .error {
            border: 1px solid #e5a1a1;
            background: #fdecec;
            color: #a32121;
        }

        .table-wrap { overflow-x: auto; }

        @media print {
            body { margin: 0; background: white; }
            .no-print { display: none !important; }
            .card { border: 0; padding: 0; }
        }
    </style>
</head>
<body>
    <h1>Ostjate nõuete võrdlus</h1>
    <p>Võrdleb kliendiarvete tasumata saldot pearaamatu varakontode saldoga.</p>

    <?php foreach ($errors as $error): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endforeach; ?>

    <form method="get" class="card no-print">
        <div class="filter-row">
            <label>
                Seisuga kuupäev
                <input
                    type="date"
                    name="as_of_date"
                    value="<?= h($asOfDate) ?>"
                    required
                >
            </label>
            <button class="button" type="submit">Näita võrdlust</button>
        </div>
    </form>

    <?php if (!$errors): ?>
        <p>Võrdluse kuupäev: <strong><?= h($asOfDate) ?></strong></p>

        <?php if (!$rows): ?>
            <p>Võrdlemiseks ei leitud kinnitatud müügiarvete nõuete kontosid.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Konto</th>
                            <th class="number">Arvete saldo</th>
                            <th class="number">Pearaamatu saldo</th>
                            <th class="number">Erinevus</th>
                            <th>Kontroll</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <a href="receivables_detail.php?account_id=<?= (int) $row['account']['id'] ?>&amp;as_of_date=<?= h($asOfDate) ?>">
                                    <?= h($row['account']['code'] . ' — ' . $row['account']['name']) ?>
                                    </a>
                                </td>
                                <td class="number"><?= money($row['subledger']) ?></td>
                                <td class="number"><?= money($row['ledger']) ?></td>
                                <td class="number"><?= money($row['difference']) ?></td>
                                <td class="<?= $row['difference'] === 0 ? 'ok' : 'difference' ?>">
                                    <?= $row['difference'] === 0 ? 'Klapib' : 'Erinevus' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Kokku</th>
                            <th class="number"><?= money($totalSubledger) ?></th>
                            <th class="number"><?= money($totalLedger) ?></th>
                            <th class="number"><?= money($totalDifference) ?></th>
                            <th class="<?= $totalDifference === 0 ? 'ok' : 'difference' ?>">
                                <?= $totalDifference === 0 ? 'Klapib' : 'Erinevus' ?>
                            </th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($unassignedInvoices > 0): ?>
            <p class="notice">
                <?= (int) $unassignedInvoices ?> kinnitatud arve(te) algkandest ei leitud ostjate nõuete kontot.
                Nende arvete tasumata saldo kokku on <?= money($unassignedBalance) ?>.
            </p>
        <?php endif; ?>

        <?php if ($totalDifference !== 0): ?>
            <p class="notice">
                Erinevus tähendab, et pearaamatu saldo ei võrdu arvete ja laekumiste järgi arvutatud saldoga.
                Kontrolli valitud konto käsitsi lisatud kandeid ning arvete ja laekumiste kuupäevi.
            </p>
        <?php endif; ?>

        <p class="no-print">
            <button class="button" type="button" onclick="window.print()">
                Prindi / Salvesta PDF-ina
            </button>
        </p>
    <?php endif; ?>

    <p class="no-print">
        <a class="button" href="index.php">Tagasi avalehele</a>
    </p>
</body>
</html>