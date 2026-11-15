<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/db.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function money($amount): string
{
    return number_format((float) $amount, 2, ',', ' ') . ' €';
}

$companyId = (int) $_SESSION['company_id'];
$notice = $_SESSION['portfolio_opening_notice'] ?? null;
unset($_SESSION['portfolio_opening_notice']);

$stmt = $pdo->prepare(
    'SELECT
        transaction_date,
        bank_account_number,
        record_type,
        document_number,
        counterparty_name,
        transaction_type,
        debit_credit,
        amount,
        archive_reference,
        description,
        service_fee,
        currency
     FROM portfelli_algsaldo
     WHERE company_id = ?
     ORDER BY transaction_date, id'
);
$stmt->execute([$companyId]);
$rows = $stmt->fetchAll();
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Portfelli algsaldo</title>
    <style>
        body {
            max-width: 1200px;
            margin: 32px auto;
            padding: 0 22px;
            background: #f4f7fb;
            color: #263746;
            font-family: Arial, sans-serif;
        }

        h1, h2 { color: #17324d; }

        .card {
            margin: 18px 0;
            padding: 20px 22px;
            border: 1px solid #dce3ec;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 4px 14px rgba(25, 45, 70, 0.05);
        }

        .muted { color: #667788; line-height: 1.5; }

        .notice {
            padding: 12px 15px;
            border: 1px solid #b8dfc7;
            border-radius: 8px;
            background: #eaf7ef;
            color: #176b3a;
        }

        .error {
            border-color: #e5a3a3;
            background: #fff0f0;
            color: #a32121;
        }

        .actions { display: flex; flex-wrap: wrap; gap: 10px; }

        .button {
            display: inline-block;
            margin: 8px 0;
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

        .button:hover { background: #0f4f83; }

        .table-wrap { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
        }

        th, td {
            padding: 10px 11px;
            border-bottom: 1px solid #e5eaf0;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #f3f6fa;
            color: #526273;
            font-size: 13px;
        }

        .number { text-align: right; white-space: nowrap; }
        .nowrap { white-space: nowrap; }

        @media (max-width: 700px) {
            body { margin: 18px auto; padding: 0 12px; }
        }
    </style>
</head>
<body>
    <h1>Portfelli algsaldo</h1>
    <p class="muted">
        Siin hoitakse enne programmi kasutuselevõttu tehtud väärtpaberitehinguid,
        mida kasutatakse hiljem FIFO soetusmaksumuse arvutuse algandmetena.
        Need read ei ole pangatehingud ega loo pearaamatu kandeid.
    </p>

    <?php if ($notice): ?>
        <p class="notice <?= $notice['type'] === 'error' ? 'error' : '' ?>">
            <?= h($notice['text']) ?>
        </p>
    <?php endif; ?>

    <section class="card">
        <div class="actions">
            <a class="button" href="bank_import.php">Impordi algsaldo CSV</a>
            <a class="button" href="index.php">Tagasi avalehele</a>
        </div>
        <form method="post" action="settings.php"
              onsubmit="return confirm('Hoiatus 1/2: see kustutab aktiivse ettevõtte kõik portfelli algsaldo CSV-st imporditud read. Pangatehinguid ja pearaamatu kandeid ei kustutata. Kas jätkame?') && confirm('Hoiatus 2/2: kustutamist ei saa rakenduses tagasi võtta. Enne kustutamist luuakse kogu andmebaasist SQL-varukoopia. Kas kinnitad algsaldo ridade kustutamise?');">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="clear_portfolio_opening">
            <button class="button" type="submit" style="background:#a83232" <?= count($rows) === 0 ? 'disabled' : '' ?>>Kustuta algsaldo CSV-read</button>
        </form>

        <h2>Salvestatud read (<?= count($rows) ?>)</h2>

        <?php if (!$rows): ?>
            <p>Portfelli algsaldo ridu pole veel lisatud.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Kuupäev</th>
                            <th>Pangakonto</th>
                            <th>Reatüüp</th>
                            <th>Tüüp</th>
                            <th>D/C</th>
                            <th>Saaja/maksja</th>
                            <th>Selgitus</th>
                            <th class="number">Summa</th>
                            <th class="number">Teenustasu</th>
                            <th>Arhiveerimistunnus</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td class="nowrap"><?= h($row['transaction_date']) ?></td>
                                <td class="nowrap"><?= h((string) ($row['bank_account_number'] ?? '')) ?></td>
                                <td><?= h((string) ($row['record_type'] ?? '')) ?></td>
                                <td><?= h((string) ($row['transaction_type'] ?? '')) ?></td>
                                <td><?= h($row['debit_credit']) ?></td>
                                <td><?= h((string) ($row['counterparty_name'] ?? '')) ?></td>
                                <td><?= h((string) ($row['description'] ?? '')) ?></td>
                                <td class="number"><?= h(money($row['amount'])) ?></td>
                                <td class="number"><?= h(money($row['service_fee'])) ?></td>
                                <td><?= h((string) ($row['archive_reference'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</body>
</html>