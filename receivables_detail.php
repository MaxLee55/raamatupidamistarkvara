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
$accountId = filter_input(INPUT_GET, 'account_id', FILTER_VALIDATE_INT);
$asOfDate = $_GET['as_of_date'] ?? date('Y-m-d');

$errors = [];
$account = null;
$customerBalances = [];
$ledgerLines = [];
$customerTotal = 0;
$ledgerTotal = 0;
$unlinkedCount = 0;
$unlinkedNet = 0;

if (!$accountId || $accountId < 1) {
    $errors[] = 'Konto ID puudub või on vigane.';
}

if (!validDate($asOfDate)) {
    $errors[] = 'Vali kehtiv kuupäev.';
}

if (!$errors) {
    $accountStmt = $pdo->prepare(
        "SELECT id, code, name
         FROM accounts
         WHERE id = :account_id
           AND company_id = :company_id
           AND account_type = 'asset'"
    );
    $accountStmt->execute([
        'account_id' => $accountId,
        'company_id' => $companyId,
    ]);
    $account = $accountStmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        $errors[] = 'Valitud varakontot ei leitud.';
    }
}

if (!$errors) {
    // Leia kinnitatud arved ning nende algkandes kasutatud nõuete konto.
    $invoicesStmt = $pdo->prepare(
        "SELECT
            si.id,
            si.contact_id,
            c.name AS contact_name,
            si.invoice_number,
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
         JOIN contacts c
           ON c.id = si.contact_id
          AND c.company_id = si.company_id
         WHERE si.company_id = :company_id
           AND si.status = 'posted'
           AND si.invoice_date <= :as_of_date
         ORDER BY c.name, si.invoice_date, si.id"
    );
    $invoicesStmt->execute([
        'company_id' => $companyId,
        'as_of_date' => $asOfDate,
    ]);

    $invoiceAccounts = [];

    foreach ($invoicesStmt->fetchAll(PDO::FETCH_ASSOC) as $invoice) {
        if ((int) ($invoice['receivable_account_id'] ?? 0) !== (int) $accountId) {
            continue;
        }

        $invoiceId = (int) $invoice['id'];
        $contactId = (int) $invoice['contact_id'];

        $invoiceAccounts[$invoiceId] = [
            'contact_id' => $contactId,
            'contact_name' => $invoice['contact_name'],
        ];

        if (!isset($customerBalances[$contactId])) {
            $customerBalances[$contactId] = [
                'name' => $invoice['contact_name'],
                'balance' => 0,
                'invoice_count' => 0,
            ];
        }

        $customerBalances[$contactId]['balance'] +=
            toCents((string) $invoice['invoice_total']);
        $customerBalances[$contactId]['invoice_count']++;
    }

    // Lahuta kuupäevaks registreeritud laekumised vastavate arvete saldost.
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

        if (!isset($invoiceAccounts[$invoiceId])) {
            continue;
        }

        $contactId = $invoiceAccounts[$invoiceId]['contact_id'];
        $customerBalances[$contactId]['balance'] -=
            toCents((string) $payment['amount']);
    }

    foreach ($customerBalances as $customer) {
        $customerTotal += $customer['balance'];
    }

    // Pearaamatu kõik kanded valitud kontol kuni võrdluskuupäevani.
    $ledgerStmt = $pdo->prepare(
        "SELECT
            jl.id AS line_id,
            jl.debit,
            jl.credit,
            je.id AS entry_id,
            je.entry_date,
            je.description,
            je.document_number,
            si.id AS invoice_id,
            si.invoice_number,
            invoice_contact.name AS invoice_contact_name,
            ip.id AS payment_id,
            payment_invoice.invoice_number AS payment_invoice_number,
            payment_contact.name AS payment_contact_name
         FROM journal_lines jl
         JOIN journal_entries je ON je.id = jl.entry_id
         LEFT JOIN sales_invoices si
           ON si.journal_entry_id = je.id
          AND si.company_id = je.company_id
         LEFT JOIN contacts invoice_contact
           ON invoice_contact.id = si.contact_id
          AND invoice_contact.company_id = si.company_id
         LEFT JOIN invoice_payments ip
           ON ip.journal_entry_id = je.id
         LEFT JOIN sales_invoices payment_invoice
           ON payment_invoice.id = ip.invoice_id
          AND payment_invoice.company_id = je.company_id
         LEFT JOIN contacts payment_contact
           ON payment_contact.id = payment_invoice.contact_id
          AND payment_contact.company_id = payment_invoice.company_id
         WHERE je.company_id = :company_id
           AND je.status = 'posted'
           AND je.entry_date <= :as_of_date
           AND jl.account_id = :account_id
         ORDER BY je.entry_date, je.id, jl.id"
    );
    $ledgerStmt->execute([
        'company_id' => $companyId,
        'as_of_date' => $asOfDate,
        'account_id' => $accountId,
    ]);

    foreach ($ledgerStmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $debit = toCents((string) $line['debit']);
        $credit = toCents((string) $line['credit']);
        $ledgerTotal += $debit - $credit;

        if (!empty($line['invoice_id'])) {
            $source = 'Müügiarve ' . $line['invoice_number'];
            if (!empty($line['invoice_contact_name'])) {
                $source .= ' — ' . $line['invoice_contact_name'];
            }
            $linked = true;
        } elseif (!empty($line['payment_id'])) {
            $source = 'Laekumine arvele '
                . ($line['payment_invoice_number'] ?? '');
            if (!empty($line['payment_contact_name'])) {
                $source .= ' — ' . $line['payment_contact_name'];
            }
            $linked = true;
        } else {
            $source = 'Käsitsi lisatud või muu kanne';
            $linked = false;
            $unlinkedCount++;
            $unlinkedNet += $debit - $credit;
        }

        $ledgerLines[] = [
            'entry_id' => (int) $line['entry_id'],
            'date' => $line['entry_date'],
            'description' => $line['description'],
            'document_number' => $line['document_number'],
            'source' => $source,
            'linked' => $linked,
            'debit' => $debit,
            'credit' => $credit,
        ];
    }
}

$difference = $ledgerTotal - $customerTotal;
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nõuete konto detailvaade</title>
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

        .summary {
            display: flex;
            flex-wrap: wrap;
            gap: 14px 28px;
        }

        .summary strong { color: #17324d; }

        table {
            width: 100%;
            margin-top: 16px;
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

        .ok {
            color: #176b32;
            font-weight: 700;
        }

        .difference, .unlinked {
            color: #a32121;
            font-weight: 700;
        }

        .button {
            display: inline-block;
            margin: 8px 8px 8px 0;
            padding: 10px 16px;
            border: 0;
            border-radius: 7px;
            background: #1769aa;
            color: #fff;
            font: inherit;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
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
            body { margin: 0; background: #fff; }
            .no-print { display: none !important; }
            .card { border: 0; padding: 0; }
        }
    </style>
</head>
<body>
    <h1>Nõuete konto detailvaade</h1>

    <?php foreach ($errors as $error): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endforeach; ?>

    <?php if (!$errors): ?>
        <p>
            Konto:
            <strong><?= h($account['code'] . ' — ' . $account['name']) ?></strong>
            · Seisuga <?= h($asOfDate) ?>
        </p>

        <section class="card">
            <h2>Saldode võrdlus</h2>
            <div class="summary">
                <div>Klientide arvete saldo: <strong><?= money($customerTotal) ?></strong></div>
                <div>Pearaamatu saldo: <strong><?= money($ledgerTotal) ?></strong></div>
                <div>Erinevus: <strong><?= money($difference) ?></strong></div>
                <div class="<?= $difference === 0 ? 'ok' : 'difference' ?>">
                    <?= $difference === 0 ? 'Klapib' : 'Erinevus' ?>
                </div>
            </div>
        </section>

        <section class="card">
            <h2>Saldo klientide kaupa</h2>

            <?php if (!$customerBalances): ?>
                <p>Selle kontoga seotud kinnitatud arveid ei leitud.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Klient</th>
                                <th class="number">Kinnitatud arveid</th>
                                <th class="number">Tasumata saldo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customerBalances as $customer): ?>
                                <tr>
                                    <td><?= h($customer['name']) ?></td>
                                    <td class="number"><?= (int) $customer['invoice_count'] ?></td>
                                    <td class="number"><?= money($customer['balance']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Pearaamatu kanded</h2>

            <?php if (!$ledgerLines): ?>
                <p>Sellel kontol valitud kuupäevaks kandeid pole.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Kuupäev</th>
                                <th>Dokumendi nr</th>
                                <th>Kirjeldus</th>
                                <th>Seos</th>
                                <th class="number">Deebet</th>
                                <th class="number">Kreedit</th>
                                <th>Ava</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ledgerLines as $line): ?>
                                <tr>
                                    <td><?= h($line['date']) ?></td>
                                    <td><?= h($line['document_number'] ?? '') ?></td>
                                    <td><?= h($line['description']) ?></td>
                                    <td class="<?= $line['linked'] ? '' : 'unlinked' ?>">
                                        <?= h($line['source']) ?>
                                    </td>
                                    <td class="number"><?= money($line['debit']) ?></td>
                                    <td class="number"><?= money($line['credit']) ?></td>
                                    <td>
                                        <a href="entry.php?id=<?= (int) $line['entry_id'] ?>">
                                            Ava kanne
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($unlinkedCount > 0): ?>
                <p class="notice">
                    <?= (int) $unlinkedCount ?> pearaamatu kandel ei leitud seost müügiarve ega laekumisega.
                    Nende kannete netomõju kontole on <?= money($unlinkedNet) ?>.
                    Ava need kanded ja kontrolli, kas need peavad ostjate nõuete kontot mõjutama.
                </p>
            <?php endif; ?>
        </section>

        <p class="no-print">
            <button class="button" type="button" onclick="window.print()">
                Prindi / Salvesta PDF-ina
            </button>
            <a class="button" href="receivables_check.php?as_of_date=<?= h($asOfDate) ?>">
                Tagasi võrdlusaruandele
            </a>
            <a class="button" href="index.php">Avaleht</a>
        </p>
    <?php endif; ?>
</body>
</html>