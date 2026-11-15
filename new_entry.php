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
require_once __DIR__ . '/period_lock.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function validDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function amountToCents(string $amount): ?int
{
    if ($amount === '') {
        return 0;
    }

    if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $amount)) {
        return null;
    }

    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
    $fraction = str_pad($fraction, 2, '0');

    return ((int) $whole * 100) + (int) substr($fraction, 0, 2);
}

$companyId = (int) $_SESSION['company_id'];

$accountsStmt = $pdo->prepare(
    'SELECT id, code, name
     FROM accounts
     WHERE company_id = :company_id
       AND is_active = 1
     ORDER BY code'
);
$accountsStmt->execute(['company_id' => $companyId]);
$accounts = $accountsStmt->fetchAll();

$receivableAccountIds = [];
foreach ($accounts as $account) {
    if (
        (string) $account['code'] === '1200'
        || mb_stripos((string) $account['name'], 'nõuded ostjate vastu', 0, 'UTF-8') !== false
    ) {
        $receivableAccountIds[(int) $account['id']] = true;
    }
}

$accountIds = [];
foreach ($accounts as $account) {
    $accountIds[(int) $account['id']] = true;
}

$errors = [];
$entryDate = date('Y-m-d');
$description = '';
$documentNumber = '';
$formLines = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
    ) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    }

    $entryDate = trim($_POST['entry_date'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $documentNumber = trim($_POST['document_number'] ?? '');

    $accountInputs = is_array($_POST['account_id'] ?? null) ? $_POST['account_id'] : [];
    $lineDescriptions = is_array($_POST['line_description'] ?? null) ? $_POST['line_description'] : [];
    $debitInputs = is_array($_POST['debit'] ?? null) ? $_POST['debit'] : [];
    $creditInputs = is_array($_POST['credit'] ?? null) ? $_POST['credit'] : [];

    if (!validDate($entryDate)) {
        $errors[] = 'Sisesta kehtiv kuupäev.';
    }
    elseif ($lockMessage = closedAccountingPeriodMessage($pdo, $companyId, $entryDate)) {
        $errors[] = $lockMessage;
    }

    if ($description === '' || mb_strlen($description) > 500) {
        $errors[] = 'Kirjeldus on kohustuslik ja võib olla kuni 500 märki.';
    }

    if (mb_strlen($documentNumber) > 50) {
        $errors[] = 'Dokumendi number võib olla kuni 50 märki.';
    }

    $count = max(
        count($accountInputs),
        count($lineDescriptions),
        count($debitInputs),
        count($creditInputs)
    );

    if ($count > 20) {
        $errors[] = 'Kandel võib olla kuni 20 rida.';
    }

    $cleanLines = [];
    $debitTotal = 0;
    $creditTotal = 0;

    for ($i = 0; $i < min($count, 20); $i++) {
        $accountValue = trim((string) ($accountInputs[$i] ?? ''));
        $lineDescription = trim((string) ($lineDescriptions[$i] ?? ''));
        $debitValue = trim((string) ($debitInputs[$i] ?? ''));
        $creditValue = trim((string) ($creditInputs[$i] ?? ''));

        $formLines[] = [
            'account_id' => $accountValue,
            'description' => $lineDescription,
            'debit' => $debitValue,
            'credit' => $creditValue,
        ];

        // Täiesti tühje ridu ei salvestata.
        if (
            $accountValue === '' &&
            $lineDescription === '' &&
            $debitValue === '' &&
            $creditValue === ''
        ) {
            continue;
        }

        $accountId = filter_var($accountValue, FILTER_VALIDATE_INT);
        $debit = amountToCents($debitValue);
        $credit = amountToCents($creditValue);

        if (!$accountId || !isset($accountIds[$accountId])) {
            $errors[] = 'Vali igale kasutatud reale aktiivne konto.';
            continue;
        }

        if ($debit === null || $credit === null) {
            $errors[] = 'Kontrolli kanderidade summasid.';
            continue;
        }

        if (mb_strlen($lineDescription) > 500) {
            $errors[] = 'Kanderidade kirjeldus võib olla kuni 500 märki.';
            continue;
        }

        if (($debit > 0 && $credit > 0) || ($debit === 0 && $credit === 0)) {
            $errors[] = 'Igal kasutatud real peab olema kas deebet- või kreeditsumma.';
            continue;
        }

        $debitTotal += $debit;
        $creditTotal += $credit;

        $cleanLines[] = [
            'account_id' => $accountId,
            'description' => $lineDescription,
            'debit' => $debitValue === '' ? '0.00' : $debitValue,
            'credit' => $creditValue === '' ? '0.00' : $creditValue,
        ];
    }

    if (count($cleanLines) < 2) {
        $errors[] = 'Kandel peab olema vähemalt kaks kasutatud rida.';
    }

    if ($debitTotal <= 0 || $debitTotal !== $creditTotal) {
        $errors[] = 'Deebet ja kreedit peavad olema võrdsed ning suuremad kui null.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $entryStmt = $pdo->prepare(
                "INSERT INTO journal_entries
                    (company_id, entry_date, description, document_number,
                     status, created_by, posted_at)
                 VALUES
                    (:company_id, :entry_date, :description, :document_number,
                     'draft', :created_by, NULL)"
            );
            $entryStmt->execute([
                'company_id' => $companyId,
                'entry_date' => $entryDate,
                'description' => $description,
                'document_number' => $documentNumber !== '' ? $documentNumber : null,
                'created_by' => (int) $_SESSION['user_id'],
            ]);

            $entryId = (int) $pdo->lastInsertId();

            $lineStmt = $pdo->prepare(
                'INSERT INTO journal_lines
                    (entry_id, account_id, description, debit, credit)
                 VALUES
                    (:entry_id, :account_id, :description, :debit, :credit)'
            );

            foreach ($cleanLines as $line) {
                $lineStmt->execute([
                    'entry_id' => $entryId,
                    'account_id' => $line['account_id'],
                    'description' => $line['description'],
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                ]);
            }

            $pdo->commit();

            header('Location: entry.php?id=' . $entryId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($e->getMessage());
            $errors[] = 'Kande salvestamine ebaõnnestus.';
        }
    }
}

while (count($formLines) < 4) {
    $formLines[] = [
        'account_id' => '',
        'description' => '',
        'debit' => '',
        'credit' => '',
    ];
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Uus kanne</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 32px; }
        label { display: block; margin: 12px 0; }
        input, select { padding: 7px; }
        table { border-collapse: collapse; margin: 16px 0; }
        th, td { padding: 8px; border: 1px solid #ccc; text-align: left; }
        .error { color: #b00020; background: #fdecec; border: 1px solid #e5a1a1; padding: 10px 12px; border-radius: 5px; }
		 body {
        max-width: 1150px;
        margin: 36px auto;
        padding: 0 24px;
        background: #f4f7fb;
        color: #243447;
        font-family: Arial, sans-serif;
    }

    h1, h2 { color: #17324d; }

    .entry-form {
        margin-top: 20px;
        padding: 24px;
        background: white;
        border: 1px solid #dce3ec;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(23, 50, 77, .04);
    }

    .entry-form > label {
        display: grid;
        gap: 7px;
        width: min(100%, 420px);
        margin: 0 0 18px;
        color: #33475b;
        font-weight: 600;
    }

    .entry-form input,
    .entry-form select {
        box-sizing: border-box;
        width: 100%;
        min-height: 42px;
        padding: 10px 12px;
        border: 1px solid #b8c4d1;
        border-radius: 7px;
        background: white;
        color: #243447;
        font: inherit;
    }

    .entry-form table {
        width: 100%;
        margin: 14px 0 18px;
        border-collapse: collapse;
        background: white;
    }

    .entry-form th,
    .entry-form td {
        padding: 9px;
        border: 1px solid #dce3ec;
        text-align: left;
    }

    .entry-form th {
        background: #eaf0f6;
        color: #17324d;
    }

    .entry-form td input,
    .entry-form td select {
        min-width: 115px;
    }

    .entry-form td:first-child select {
        min-width: 210px;
    }

    .entry-form button,
    .button {
        display: inline-block;
        min-height: 42px;
        padding: 10px 17px;
        border: 0;
        border-radius: 7px;
        background: #1769aa;
        color: white;
        font: inherit;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
    }

    .entry-form button:hover,
    .button:hover {
        background: #0f4f83;
    }

    .entry-form #add-line {
        margin-right: 8px;
        background: #eaf0f6;
        color: #17324d;
        border: 1px solid #cbd5df;
    }

    .entry-form #add-line:hover {
        background: #dce8f2;
    }

    .error {
        color: #b00020;
        background: #fdecec;
        border: 1px solid #e5a1a1;
        padding: 10px 12px;
        border-radius: 5px;
    }

    .account-warning {
        margin: 14px 0;
        padding: 13px 16px;
        border: 1px solid #e4bd72;
        border-radius: 8px;
        background: #fff8e8;
        color: #704f0b;
        line-height: 1.5;
    }

    .account-warning[hidden] {
        display: none;
    }
    @media (max-width: 700px) {
        body { margin: 22px auto; padding: 0 14px; }
        .entry-form { padding: 16px; }
        .entry-form table { display: block; overflow-x: auto; }
    }
    </style>
</head>
<body>
    <h1>Uus kanne</h1>

    <?php foreach ($errors as $error): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endforeach; ?>

    <?php if (count($accounts) < 2): ?>
        <p>Uue kande jaoks peab ettevõttel olema vähemalt kaks aktiivset kontot.</p>
    <?php else: ?>
        <form method="post" class="entry-form">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

            <label>
                Kuupäev
                <input type="date" name="entry_date" value="<?= h($entryDate) ?>" required>
            </label>

            <label>
                Kirjeldus
                <input type="text" name="description" maxlength="500"
                       value="<?= h($description) ?>" required>
            </label>

            <label>
                Dokumendi number
                <input type="text" name="document_number" maxlength="50"
                       value="<?= h($documentNumber) ?>">
            </label>

            <h2>Kande read</h2>
            <p>Kasuta igal real kas deebetit või kreeditit. Tühjad read jäetakse vahele.</p>

            <table>
                <thead>
                    <tr>
                        <th>Konto</th>
                        <th>Rea kirjeldus</th>
                        <th>Deebet</th>
                        <th>Kreedit</th>
                    </tr>
                </thead>
                <tbody id="lines-body">
                    <?php foreach ($formLines as $line): ?>
                        <tr>
                            <td>
                                <select name="account_id[]">
                                    <option value="">Vali konto</option>
                                    <?php foreach ($accounts as $account): ?>
                                        <option
                                            value="<?= (int) $account['id'] ?>" data-receivables="<?= isset($receivableAccountIds[(int) $account['id']]) ? '1' : '0' ?>"
                                            <?= (string) $line['account_id'] === (string) $account['id'] ? 'selected' : '' ?>
                                        >
                                            <?= h($account['code'] . ' — ' . $account['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="text" name="line_description[]" maxlength="500"
                                       value="<?= h($line['description']) ?>">
                            </td>
                            <td>
                                <input type="number" name="debit[]" min="0" step="0.01"
                                       value="<?= h($line['debit']) ?>">
                            </td>
                            <td>
                                <input type="number" name="credit[]" min="0" step="0.01"
                                       value="<?= h($line['credit']) ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div id="receivable-warning" class="account-warning" role="status" hidden>
                Valisid ostjate nõuete konto. Käsikanne muudab pearaamatut, kuid ei uuenda
                kliendiarve saldot ega kliendi konto väljavõtet. Arve ja laekumise jaoks
                kasuta vastavaid arvevorme.
            </div>
            <button type="button" id="add-line">Lisa rida</button>
            <button type="submit">Salvesta mustand</button>
        </form>
    <?php endif; ?>

    <p><a href="index.php">Tagasi avalehele</a></p>

    <template id="line-template">
        <tr>
            <td>
                <select name="account_id[]">
                    <option value="">Vali konto</option>
                    <?php foreach ($accounts as $account): ?>
                        <option value="<?= (int) $account['id'] ?>" data-receivables="<?= isset($receivableAccountIds[(int) $account['id']]) ? '1' : '0' ?>">
                            <?= h($account['code'] . ' — ' . $account['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="text" name="line_description[]" maxlength="500"></td>
            <td><input type="number" name="debit[]" min="0" step="0.01"></td>
            <td><input type="number" name="credit[]" min="0" step="0.01"></td>
        </tr>
    </template>

    <script>
        const receivableWarning = document.getElementById('receivable-warning');

        function updateReceivableWarning() {
            if (!receivableWarning) {
                return;
            }

            const hasReceivableAccount = Array.from(
                document.querySelectorAll('select[name="account_id[]"]')
            ).some(function (select) {
                const option = select.options[select.selectedIndex];
                return option && option.dataset.receivables === '1';
            });

            receivableWarning.hidden = !hasReceivableAccount;
        }

        document.getElementById('lines-body')?.addEventListener('change', function (event) {
            if (event.target.matches('select[name="account_id[]"]')) {
                updateReceivableWarning();
            }
        });

        updateReceivableWarning();
        document.getElementById('add-line')?.addEventListener('click', function () {
            const body = document.getElementById('lines-body');

            if (body.rows.length >= 20) {
                alert('Kandel võib olla kuni 20 rida.');
                return;
            }

            const template = document.getElementById('line-template');
            body.appendChild(template.content.cloneNode(true));
        });
    </script>
</body>
</html>