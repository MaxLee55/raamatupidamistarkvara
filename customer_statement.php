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

function validDate(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

$companyId = (int) $_SESSION['company_id'];
$today = date('Y-m-d');

$startDate = $_GET['start_date'] ?? date('Y-01-01');
$endDate = $_GET['end_date'] ?? $today;
$selectedContactId = filter_input(INPUT_GET, 'contact_id', FILTER_VALIDATE_INT);

$errors = [];
$contact = null;
$transactions = [];
$openingBalance = 0;
$closingBalance = 0;
$periodInvoices = 0;
$periodPayments = 0;

$contactsStmt = $pdo->prepare(
    "SELECT id, name, registry_code
     FROM contacts
     WHERE company_id = ?
       AND contact_type IN ('customer', 'both')
     ORDER BY name"
);
$contactsStmt->execute([$companyId]);
$contacts = $contactsStmt->fetchAll(PDO::FETCH_ASSOC);

if (!validDate($startDate) || !validDate($endDate)) {
    $errors[] = 'Vali kehtiv algus- ja lõpukuupäev.';
} elseif ($startDate > $endDate) {
    $errors[] = 'Alguskuupäev peab olema enne lõpukuupäeva.';
}

if ($selectedContactId) {
    foreach ($contacts as $item) {
        if ((int) $item['id'] === $selectedContactId) {
            $contact = $item;
            break;
        }
    }

    if (!$contact) {
        $errors[] = 'Valitud klienti ei leitud.';
    }
}

if ($contact && !$errors) {
    $invoicesStmt = $pdo->prepare(
        "SELECT
            si.id,
            si.invoice_number,
            si.invoice_date,
            COALESCE(SUM(sil.gross_amount), 0) AS gross_total
         FROM sales_invoices si
         LEFT JOIN sales_invoice_lines sil ON sil.invoice_id = si.id
         WHERE si.company_id = :company_id
           AND si.contact_id = :contact_id
           AND si.status = 'posted'
         GROUP BY si.id, si.invoice_number, si.invoice_date
         ORDER BY si.invoice_date, si.id"
    );
    $invoicesStmt->execute([
        'company_id' => $companyId,
        'contact_id' => $selectedContactId,
    ]);

    foreach ($invoicesStmt->fetchAll(PDO::FETCH_ASSOC) as $invoice) {
        $transactions[] = [
            'date' => $invoice['invoice_date'],
            'sort_order' => 1,
            'id' => (int) $invoice['id'],
            'type' => 'Müügiarve',
            'reference' => $invoice['invoice_number'],
            'description' => 'Kinnitatud müügiarve',
            'debit' => toCents((string) $invoice['gross_total']),
            'credit' => 0,
        ];
    }

    $paymentsStmt = $pdo->prepare(
        "SELECT
            ip.id,
            ip.payment_date,
            ip.amount,
            ip.notes,
            si.invoice_number
         FROM invoice_payments ip
         JOIN sales_invoices si ON si.id = ip.invoice_id
         WHERE si.company_id = :company_id
           AND si.contact_id = :contact_id
           AND si.status = 'posted'
         ORDER BY ip.payment_date, ip.id"
    );
    $paymentsStmt->execute([
        'company_id' => $companyId,
        'contact_id' => $selectedContactId,
    ]);

    foreach ($paymentsStmt->fetchAll(PDO::FETCH_ASSOC) as $payment) {
        $transactions[] = [
            'date' => $payment['payment_date'],
            'sort_order' => 2,
            'id' => (int) $payment['id'],
            'type' => 'Laekumine',
            'reference' => $payment['invoice_number'],
            'description' => $payment['notes'] ?: 'Arve laekumine',
            'debit' => 0,
            'credit' => toCents((string) $payment['amount']),
        ];
    }

    usort($transactions, function (array $a, array $b): int {
        return [$a['date'], $a['sort_order'], $a['id']]
            <=> [$b['date'], $b['sort_order'], $b['id']];
    });

    foreach ($transactions as $transaction) {
        $change = $transaction['debit'] - $transaction['credit'];

        if ($transaction['date'] < $startDate) {
            $openingBalance += $change;
        } elseif ($transaction['date'] <= $endDate) {
            $periodInvoices += $transaction['debit'];
            $periodPayments += $transaction['credit'];
        }
    }

    $closingBalance = $openingBalance;
    $visibleTransactions = [];

    foreach ($transactions as $transaction) {
        if ($transaction['date'] >= $startDate && $transaction['date'] <= $endDate) {
            $closingBalance += $transaction['debit'] - $transaction['credit'];
            $transaction['running_balance'] = $closingBalance;
            $visibleTransactions[] = $transaction;
        }
    }

    $transactions = $visibleTransactions;
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kliendi konto väljavõte</title>
    <style>
        body {
            max-width: 1200px;
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

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 15px;
            align-items: end;
        }

        label {
            display: grid;
            gap: 7px;
            font-weight: 600;
        }

        input, select {
            box-sizing: border-box;
            width: 100%;
            min-height: 42px;
            padding: 9px 11px;
            border: 1px solid #b8c4d1;
            border-radius: 7px;
            background: #fff;
            font: inherit;
        }

        .button {
            display: inline-block;
            padding: 11px 17px;
            border: 0;
            border-radius: 7px;
            background: #1769aa;
            color: #fff;
            font: inherit;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        table {
            width: 100%;
            margin-top: 18px;
            border-collapse: collapse;
            background: #fff;
        }

        th, td {
            padding: 10px 12px;
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

        .error {
            padding: 12px;
            border: 1px solid #e5a1a1;
            border-radius: 7px;
            background: #fdecec;
            color: #a32121;
        }

        .summary {
            display: flex;
            flex-wrap: wrap;
            gap: 14px 28px;
        }

        .summary strong { color: #17324d; }

        .table-wrap { overflow-x: auto; }

        @media print {
            body { margin: 0; background: #fff; }
            .no-print { display: none !important; }
            .card { border: 0; padding: 0; }
        }
    </style>
</head>
<body>
    <h1>Kliendi konto väljavõte</h1>

    <?php foreach ($errors as $error): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endforeach; ?>

    <form method="get" class="card no-print">
        <div class="filter-grid">
            <label>
                Klient
                <select name="contact_id" required>
                    <option value="">Vali klient</option>
                    <?php foreach ($contacts as $item): ?>
                        <option
                            value="<?= (int) $item['id'] ?>"
                            <?= $selectedContactId === (int) $item['id'] ? 'selected' : '' ?>
                        >
                            <?= h($item['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                Alates
                <input type="date" name="start_date" value="<?= h($startDate) ?>" required>
            </label>

            <label>
                Kuni
                <input type="date" name="end_date" value="<?= h($endDate) ?>" required>
            </label>

            <button class="button" type="submit">Näita väljavõtet</button>
        </div>
    </form>

    <?php if ($contact && !$errors): ?>
        <section class="card">
            <h2><?= h($contact['name']) ?></h2>
            <p>
                Periood: <?= h($startDate) ?> kuni <?= h($endDate) ?>
                <?php if (!empty($contact['registry_code'])): ?>
                    · Registrikood: <?= h($contact['registry_code']) ?>
                <?php endif; ?>
            </p>

            <div class="summary">
                <div>Algsaldo: <strong><?= money($openingBalance) ?></strong></div>
                <div>Arved: <strong><?= money($periodInvoices) ?></strong></div>
                <div>Laekumised: <strong><?= money($periodPayments) ?></strong></div>
                <div>Lõppsaldo: <strong><?= money($closingBalance) ?></strong></div>
            </div>

            <?php if (!$transactions): ?>
                <p>Valitud perioodis arveid ega laekumisi ei ole.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Kuupäev</th>
                                <th>Liik</th>
                                <th>Arve nr</th>
                                <th>Kirjeldus</th>
                                <th class="number">Arve</th>
                                <th class="number">Laekumine</th>
                                <th class="number">Saldo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): ?>
                                <tr>
                                    <td><?= h($transaction['date']) ?></td>
                                    <td><?= h($transaction['type']) ?></td>
                                    <td><?= h($transaction['reference']) ?></td>
                                    <td><?= h($transaction['description']) ?></td>
                                    <td class="number"><?= money($transaction['debit']) ?></td>
                                    <td class="number"><?= money($transaction['credit']) ?></td>
                                    <td class="number"><?= money($transaction['running_balance']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <p class="no-print">
                <button class="button" type="button" onclick="window.print()">
                    Prindi / Salvesta PDF-ina
                </button>
            </p>
        </section>
    <?php endif; ?>

    <p class="no-print">
        <a class="button" href="contacts.php">Tagasi kontaktide juurde</a>
        <a class="button" href="index.php">Avaleht</a>
    </p>
</body>
</html>