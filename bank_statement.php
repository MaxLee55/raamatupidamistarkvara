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

function validDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function moneyValue(string $amount): string
{
    return number_format((float) $amount, 2, ',', ' ') . ' €';
}

$companyId = (int) $_SESSION['company_id'];

$accountId = filter_input(INPUT_GET, 'account_id', FILTER_VALIDATE_INT);
$status = $_GET['status'] ?? 'all';
$startDate = trim($_GET['start_date'] ?? '');
$endDate = trim($_GET['end_date'] ?? '');

$errors = [];

if (!in_array($status, ['all', 'matched', 'unmatched'], true)) {
    $status = 'all';
}

if ($startDate !== '' && !validDate($startDate)) {
    $errors[] = 'Alguskuupäev ei ole kehtiv.';
}

if ($endDate !== '' && !validDate($endDate)) {
    $errors[] = 'Lõppkuupäev ei ole kehtiv.';
}

if (
    $startDate !== ''
    && $endDate !== ''
    && validDate($startDate)
    && validDate($endDate)
    && $startDate > $endDate
) {
    $errors[] = 'Alguskuupäev peab olema enne lõppkuupäeva.';
}

$accountsStmt = $pdo->prepare(
    "SELECT id, code, name
     FROM accounts
     WHERE company_id = ?
       AND is_active = 1
       AND account_type = 'asset'
     ORDER BY code"
);
$accountsStmt->execute([$companyId]);
$accounts = $accountsStmt->fetchAll();

$selectedAccount = null;

if ($accountId) {
    foreach ($accounts as $account) {
        if ((int) $account['id'] === (int) $accountId) {
            $selectedAccount = $account;
            break;
        }
    }

    if (!$selectedAccount) {
        $errors[] = 'Valitud kontot ei leitud.';
    }
}

$rows = [];

$stats = [
    'unmatched_count' => 0,
    'unmatched_debit' => '0.00',
    'unmatched_credit' => '0.00',
    'draft_count' => 0,
    'without_draft_count' => 0,
    'fee_count' => 0,
];

if (!$errors) {
    $statsSql = "
        SELECT
            COUNT(*) AS unmatched_count,
            COALESCE(SUM(
                CASE WHEN bsl.debit_credit = 'D' THEN bsl.amount ELSE 0 END
            ), 0) AS unmatched_debit,
            COALESCE(SUM(
                CASE WHEN bsl.debit_credit = 'C' THEN bsl.amount ELSE 0 END
            ), 0) AS unmatched_credit,
            COALESCE(SUM(
                CASE WHEN bsl.draft_journal_entry_id IS NOT NULL THEN 1 ELSE 0 END
            ), 0) AS draft_count,
            COALESCE(SUM(
                CASE WHEN bsl.draft_journal_entry_id IS NULL THEN 1 ELSE 0 END
            ), 0) AS without_draft_count,
            COALESCE(SUM(
                CASE WHEN bsl.service_fee > 0 THEN 1 ELSE 0 END
            ), 0) AS fee_count
        FROM bank_statement_lines bsl
        WHERE bsl.company_id = ?
          AND bsl.matched_journal_line_id IS NULL
    ";

    $statsParams = [$companyId];

    if ($accountId && $selectedAccount) {
        $statsSql .= " AND bsl.bank_account_id = ?";
        $statsParams[] = (int) $accountId;
    }

    if ($startDate !== '' && validDate($startDate)) {
        $statsSql .= " AND bsl.transaction_date >= ?";
        $statsParams[] = $startDate;
    }

    if ($endDate !== '' && validDate($endDate)) {
        $statsSql .= " AND bsl.transaction_date <= ?";
        $statsParams[] = $endDate;
    }

    $statsStmt = $pdo->prepare($statsSql);
    $statsStmt->execute($statsParams);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    $sql = "
        SELECT
            bsl.id,
            bsl.transaction_date,
            bsl.document_number,
            bsl.counterparty_name,
            bsl.transaction_type,
            bsl.debit_credit,
            bsl.amount,
            bsl.description,
            bsl.service_fee,
            bsl.currency,
            bsl.archive_reference,
            bsl.matched_journal_line_id,
            bsl.draft_journal_entry_id,
            a.code AS account_code,
            a.name AS account_name,
            je.id AS entry_id
        FROM bank_statement_lines bsl
        JOIN accounts a
          ON a.id = bsl.bank_account_id
         AND a.company_id = bsl.company_id
        LEFT JOIN journal_lines jl
          ON jl.id = bsl.matched_journal_line_id
        LEFT JOIN journal_entries je
          ON je.id = jl.entry_id
         AND je.company_id = bsl.company_id
        WHERE bsl.company_id = :company_id
    ";

    $params = ['company_id' => $companyId];

    if ($accountId && $selectedAccount) {
        $sql .= " AND bsl.bank_account_id = :account_id";
        $params['account_id'] = (int) $accountId;
    }

    if ($startDate !== '' && validDate($startDate)) {
        $sql .= " AND bsl.transaction_date >= :start_date";
        $params['start_date'] = $startDate;
    }

    if ($endDate !== '' && validDate($endDate)) {
        $sql .= " AND bsl.transaction_date <= :end_date";
        $params['end_date'] = $endDate;
    }

    if ($status === 'matched') {
        $sql .= " AND bsl.matched_journal_line_id IS NOT NULL";
    } elseif ($status === 'unmatched') {
        $sql .= " AND bsl.matched_journal_line_id IS NULL";
    }

    $sql .= " ORDER BY bsl.transaction_date ASC, bsl.id ASC LIMIT 500";
	//$sql .= " ORDER BY bsl.transaction_date DESC, bsl.id DESC LIMIT 500";   Muutsin, et reastaks alates kuu algusest, parem sisestama hakata.

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}
?>

<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pangaväljavõte</title>
    <style>
        body {
            margin: 32px;
            font-family: Arial, sans-serif;
            color: #263746;
            background: #f7f9fc;
        }

        h1 {
            color: #17324d;
        }

        .card {
            margin: 20px 0;
            padding: 22px;
            background: #fff;
            border: 1px solid #dce3ec;
            border-radius: 12px;
            box-shadow: 0 4px 14px rgba(25, 45, 70, 0.05);
        }

        .filters {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            gap: 14px;
        }
		
		        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }

        .summary-item {
            padding: 15px;
            border: 1px solid #e1e7ef;
            border-radius: 9px;
            background: #f7f9fc;
        }

        .summary-label {
            display: block;
            margin-bottom: 7px;
            color: #526273;
            font-size: 13px;
            font-weight: 600;
        }

        .summary-value {
            color: #17324d;
            font-size: 21px;
            font-weight: 700;
        }

        .field {
            min-width: 170px;
        }

        .field label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
        }

        .field input,
        .field select {
            box-sizing: border-box;
            width: 100%;
            padding: 10px;
            border: 1px solid #b8c4d1;
            border-radius: 7px;
            background: white;
            font: inherit;
        }

        .button {
            display: inline-block;
            padding: 10px 16px;
            border: 0;
            border-radius: 7px;
            background: #1769aa;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }

        .button:hover {
            background: #0f4f83;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
        }

        th, td {
            padding: 11px 12px;
            border-bottom: 1px solid #e5eaf0;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #f3f6fa;
            color: #526273;
            font-size: 13px;
        }

        .number {
            text-align: right;
            white-space: nowrap;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .badge-matched {
            background: #e5f5ec;
            color: #187342;
        }

        .badge-unmatched {
            background: #fff3d6;
            color: #8a5a00;
        }

        .error {
            padding: 12px 15px;
            border: 1px solid #e5a3a3;
            border-radius: 8px;
            background: #fff0f0;
            color: #a32121;
        }

        .muted {
            color: #667788;
        }

        @media (max-width: 600px) {
            body {
                margin: 16px;
            }
        }
    </style>
</head>
<body>
    <h1>Pangaväljavõte</h1>

    <p>
        Siin on imporditud pangatehingud. Seotud rida vastab juba olemasolevale
        pearaamatu kande reale.
    </p>

    <p>
    <a class="button" href="bank_import.php">Impordi CSV</a>
    <a class="button" href="investment_review.php">Kande ettepanekud ja FIFO</a>
    <a class="button" href="index.php">Avalehele</a>
</p>

<form method="post" action="rematch_bank_statement.php" class="card">
    <input
        type="hidden"
        name="csrf_token"
        value="<?= h($_SESSION['csrf_token']) ?>"
    >
    <button
        class="button"
        type="submit"
        onclick="return confirm('Kas otsin sidumata pangaridadele sobivaid kinnitatud kandeid?')"
    >
        Sobita kanded uuesti
    </button>
</form>
<section class="card">
    <h2>Sidumata ridade ülevaade</h2>

    <p class="muted">
        Arvestab valitud pangakonto ja kuupäevade filtriga.
        D tähendab pangast väljaminekut, C laekumist.
    </p>

    <div class="summary-grid">
        <div class="summary-item">
            <span class="summary-label">Sidumata ridu</span>
            <span class="summary-value">
                <?= (int) $stats['unmatched_count'] ?>
            </span>
        </div>

        <div class="summary-item">
            <span class="summary-label">D — pangast välja</span>
            <span class="summary-value">
                <?= h(moneyValue($stats['unmatched_debit'])) ?>
            </span>
        </div>

        <div class="summary-item">
            <span class="summary-label">C — panka sisse</span>
            <span class="summary-value">
                <?= h(moneyValue($stats['unmatched_credit'])) ?>
            </span>
        </div>

        <div class="summary-item">
            <span class="summary-label">Mustand ootab kontrolli</span>
            <span class="summary-value">
                <?= (int) $stats['draft_count'] ?>
            </span>
        </div>

        <div class="summary-item">
            <span class="summary-label">Mustand veel loomata</span>
            <span class="summary-value">
                <?= (int) $stats['without_draft_count'] ?>
            </span>
        </div>

        <div class="summary-item">
            <span class="summary-label">Teenustasuga kontrollida</span>
            <span class="summary-value">
                <?= (int) $stats['fee_count'] ?>
            </span>
        </div>
    </div>

    <p>
        <a class="button" href="bank_statement.php?status=unmatched">
            Vaata kõiki sidumata ridu
        </a>
    </p>
</section>
    <form method="get" class="card filters">
        <div class="field">
            <label for="account">Pangakonto</label>
            <select id="account" name="account_id">
                <option value="">Kõik kontod</option>
                <?php foreach ($accounts as $account): ?>
                    <option
                        value="<?= (int) $account['id'] ?>"
                        <?= (string) $account['id'] === (string) $accountId ? 'selected' : '' ?>
                    >
                        <?= h($account['code'] . ' — ' . $account['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="start-date">Alates</label>
            <input
                id="start-date"
                type="date"
                name="start_date"
                value="<?= h($startDate) ?>"
            >
        </div>

        <div class="field">
            <label for="end-date">Kuni</label>
            <input
                id="end-date"
                type="date"
                name="end_date"
                value="<?= h($endDate) ?>"
            >
        </div>

        <div class="field">
            <label for="status">Seis</label>
            <select id="status" name="status">
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>
                    Kõik read
                </option>
                <option value="matched" <?= $status === 'matched' ? 'selected' : '' ?>>
                    Seotud
                </option>
                <option value="unmatched" <?= $status === 'unmatched' ? 'selected' : '' ?>>
                    Sidumata
                </option>
            </select>
        </div>

        <button class="button" type="submit">Näita</button>
    </form>

    <?php foreach ($errors as $error): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endforeach; ?>

    <section class="card">
        <h2>Tehingud: <?= count($rows) ?></h2>

        <?php if (!$rows): ?>
            <p class="muted">Valitud tingimustele vastavaid pangatehinguid ei leitud.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Kuupäev</th>
                            <th>Pangakonto</th>
                            <th>Tüüp</th>
                            <th>Saaja/maksja</th>
                            <th>Selgitus</th>
                            <th>D/C</th>
                            <th class="number">Summa</th>
                            <th class="number">Teenustasu</th>
                            <th>Seos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td><?= h($row['transaction_date']) ?></td>
                                <td>
                                    <?= h($row['account_code'] . ' — ' . $row['account_name']) ?>
                                </td>
                                <td><?= h($row['transaction_type'] ?? '') ?></td>
                                <td><?= h($row['counterparty_name'] ?? '') ?></td>
                                <td><?= h($row['description'] ?? '') ?></td>
                                <td><?= h($row['debit_credit']) ?></td>
                                <td class="number">
                                    <?= h(moneyValue($row['amount'])) ?>
                                </td>
                                <td class="number">
                                    <?= h(moneyValue($row['service_fee'])) ?>
                                </td>
                                <td>
                                    <?php if ($row['matched_journal_line_id'] !== null): ?>
                                        <span class="badge badge-matched">Seotud</span>
                                        <?php if ($row['entry_id'] !== null): ?>
                                            <br>
                                            <a href="entry.php?id=<?= (int) $row['entry_id'] ?>">
                                                Ava kanne
                                            </a>
                                        <?php endif; ?>
                                    <?php else: ?>
    <span class="badge badge-unmatched">Sidumata</span>

    <?php if (!empty($row['draft_journal_entry_id'])): ?>
        <br>
        <a href="entry.php?id=<?= (int) $row['draft_journal_entry_id'] ?>">
            Ava mustand
        </a>
    <?php else: ?>
        <br>
        <a href="create_bank_entry_from_statement.php?id=<?= (int) $row['id'] ?>">
            Loo kande mustand
        </a>
    <?php endif; ?>
<?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="muted">Kuvatakse kuni 500 rida.</p>
        <?php endif; ?>
    </section>
</body>
</html>
