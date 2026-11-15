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
require_once __DIR__ . '/bank_document_number.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$companyId = (int) $_SESSION['company_id'];
$userId = (int) $_SESSION['user_id'];
$bankLineId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bankLineId = filter_input(INPUT_POST, 'bank_line_id', FILTER_VALIDATE_INT);
}

$error = '';
$bankRow = null;
$accounts = [];
$selectedCounterAccountId = '';

if (!$bankLineId || $bankLineId < 1) {
    $error = 'Pangaväljavõtte rea number puudub või on vigane.';
}

if (
    $error === ''
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && (
        empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
    )
) {
    $error = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
}

if ($error === '') {
    $rowStmt = $pdo->prepare(
        "SELECT
            bsl.id,
            bsl.bank_account_id,
            bsl.transaction_date,
            bsl.debit_credit,
            bsl.amount,
            bsl.service_fee,
            bsl.description,
            bsl.counterparty_name,
            bsl.document_number,
            bsl.archive_reference,
            bsl.matched_journal_line_id,
            bsl.draft_journal_entry_id,
            a.code AS bank_code,
            a.name AS bank_name
         FROM bank_statement_lines bsl
         JOIN accounts a
           ON a.id = bsl.bank_account_id
          AND a.company_id = bsl.company_id
         WHERE bsl.id = ?
           AND bsl.company_id = ?"
    );
    $rowStmt->execute([$bankLineId, $companyId]);
    $bankRow = $rowStmt->fetch(PDO::FETCH_ASSOC);

    if (!$bankRow) {
        $error = 'Selle ettevõtte pangaväljavõtte rida ei leitud.';
    }
}

if ($error === '' && !empty($bankRow['draft_journal_entry_id'])) {
    header('Location: entry.php?id=' . (int) $bankRow['draft_journal_entry_id']);
    exit;
}

if ($error === '' && !empty($bankRow['matched_journal_line_id'])) {
    $error = 'See pangarida on juba pearaamatu kandega seotud.';
}

if ($error === '') {
    $accountsStmt = $pdo->prepare(
        "SELECT id, code, name
         FROM accounts
         WHERE company_id = ?
           AND is_active = 1
           AND id <> ?
         ORDER BY code"
    );
    $accountsStmt->execute([$companyId, (int) $bankRow['bank_account_id']]);
    $accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($error === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedCounterAccountId = trim((string) ($_POST['counter_account_id'] ?? ''));

    try {
        $pdo->beginTransaction();

        $lockStmt = $pdo->prepare(
            "SELECT
                bsl.*,
                a.code AS bank_code,
                a.name AS bank_name
             FROM bank_statement_lines bsl
             JOIN accounts a
               ON a.id = bsl.bank_account_id
              AND a.company_id = bsl.company_id
             WHERE bsl.id = ?
               AND bsl.company_id = ?
             FOR UPDATE"
        );
        $lockStmt->execute([$bankLineId, $companyId]);
        $lockedRow = $lockStmt->fetch(PDO::FETCH_ASSOC);

        if (!$lockedRow) {
            throw new DomainException('Pangaväljavõtte rida ei leitud.');
        }

        if (!empty($lockedRow['matched_journal_line_id'])) {
            throw new DomainException('See pangarida on juba seotud.');
        }

        if (!empty($lockedRow['draft_journal_entry_id'])) {
            $existingEntryId = (int) $lockedRow['draft_journal_entry_id'];
            $pdo->commit();
            header('Location: entry.php?id=' . $existingEntryId);
            exit;
        }

    $date = $lockedRow['transaction_date'];
    $periodMessage = closedAccountingPeriodMessage($pdo, $companyId, $date);
    if ($periodMessage !== null) {
        throw new DomainException($periodMessage);
    }

        if ((float) $lockedRow['service_fee'] !== 0.0) {
            throw new DomainException(
                'Selle tehinguga on seotud teenustasu. Loo sellele kanne käsitsi, et tasu õigesti kajastada.'
            );
        }

        $counterAccountId = filter_var(
            $selectedCounterAccountId,
            FILTER_VALIDATE_INT
        );

        if (!$counterAccountId) {
            throw new DomainException('Vali vastaskonto.');
        }

        $counterAccountStmt = $pdo->prepare(
            "SELECT id
             FROM accounts
             WHERE id = ?
               AND company_id = ?
               AND is_active = 1"
        );
        $counterAccountStmt->execute([$counterAccountId, $companyId]);

        if (!$counterAccountStmt->fetchColumn()) {
            throw new DomainException('Valitud konto ei ole selle ettevõtte aktiivne konto.');
        }

        $direction = strtoupper((string) $lockedRow['debit_credit']);
        $amount = number_format((float) $lockedRow['amount'], 2, '.', '');

        if (!in_array($direction, ['D', 'C'], true) || (float) $amount <= 0) {
            throw new DomainException('Pangareal on vigane suund või summa.');
        }

        $descriptionParts = ['Pangatehing'];

        if (!empty($lockedRow['counterparty_name'])) {
            $descriptionParts[] = $lockedRow['counterparty_name'];
        }

        if (!empty($lockedRow['description'])) {
            $descriptionParts[] = $lockedRow['description'];
        }

        $entryDescription = mb_substr(
            implode(' — ', $descriptionParts),
            0,
            500
        );
        $documentNumber = nextBankDocumentNumber($pdo, $companyId);

        $entryStmt = $pdo->prepare(
            "INSERT INTO journal_entries
                (company_id, entry_date, description, document_number,
                 status, created_by, posted_at)
             VALUES
                (?, ?, ?, ?, 'draft', ?, NULL)"
        );
        $entryStmt->execute([
            $companyId,
            $lockedRow['transaction_date'],
            $entryDescription,
            $documentNumber,
            $userId,
        ]);

        $entryId = (int) $pdo->lastInsertId();

        $lineStmt = $pdo->prepare(
            "INSERT INTO journal_lines
                (entry_id, account_id, description, debit, credit)
             VALUES (?, ?, ?, ?, ?)"
        );

        if ($direction === 'D') {
            // D tähendab pangakontolt raha väljaminekut.
            $lineStmt->execute([
                $entryId,
                (int) $lockedRow['bank_account_id'],
                'Pangast välja',
                '0.00',
                $amount,
            ]);
            $lineStmt->execute([
                $entryId,
                $counterAccountId,
                'Tehingu vastaskonto',
                $amount,
                '0.00',
            ]);
        } else {
            // C tähendab pangakontole raha laekumist.
            $lineStmt->execute([
                $entryId,
                (int) $lockedRow['bank_account_id'],
                'Panka laekunud',
                $amount,
                '0.00',
            ]);
            $lineStmt->execute([
                $entryId,
                $counterAccountId,
                'Tehingu vastaskonto',
                '0.00',
                $amount,
            ]);
        }

        $linkStmt = $pdo->prepare(
            "UPDATE bank_statement_lines
             SET draft_journal_entry_id = ?
             WHERE id = ?
               AND company_id = ?
               AND matched_journal_line_id IS NULL
               AND draft_journal_entry_id IS NULL"
        );
        $linkStmt->execute([$entryId, $bankLineId, $companyId]);

        if ($linkStmt->rowCount() !== 1) {
            throw new DomainException(
                'Pangarea seis muutus. Värskenda pangaväljavõtet ja kontrolli seda uuesti.'
            );
        }

        $pdo->commit();

        header('Location: entry.php?id=' . $entryId);
        exit;
    } catch (DomainException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log($exception->getMessage());
        $error = 'Kande mustandi loomine ebaõnnestus.';
    }
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Loo pangatehingu kande mustand</title>
    <style>
        body {
            max-width: 850px;
            margin: 32px auto;
            padding: 0 20px;
            background: #f4f7fb;
            color: #243447;
            font-family: Arial, sans-serif;
        }

        h1, h2 {
            color: #17324d;
        }

        .card {
            margin: 20px 0;
            padding: 22px;
            border: 1px solid #dce3ec;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 2px 8px rgba(23, 50, 77, .04);
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 14px;
        }

        .summary div {
            padding: 12px;
            border-radius: 8px;
            background: #f3f6fa;
        }

        .summary strong {
            display: block;
            margin-bottom: 5px;
            color: #526273;
            font-size: 13px;
        }

        label {
            display: grid;
            gap: 7px;
            max-width: 520px;
            margin: 16px 0;
            font-weight: 600;
        }

        select {
            box-sizing: border-box;
            min-height: 42px;
            padding: 10px 12px;
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

        .button:hover {
            background: #0f4f83;
        }

        .error {
            padding: 12px 15px;
            border: 1px solid #e5a3a3;
            border-radius: 8px;
            background: #fdecec;
            color: #a32121;
        }

        .note {
            padding: 12px 15px;
            border: 1px solid #ead9a2;
            border-radius: 8px;
            background: #fff8df;
            color: #715600;
        }
    </style>
</head>
<body>
    <h1>Loo pangatehingu kande mustand</h1>

    <?php if ($error !== ''): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endif; ?>

    <?php if ($bankRow): ?>
        <section class="card">
            <h2>Pangatehingu andmed</h2>

            <div class="summary">
                <div>
                    <strong>Kuupäev</strong>
                    <?= h($bankRow['transaction_date']) ?>
                </div>
                <div>
                    <strong>Pangakonto</strong>
                    <?= h($bankRow['bank_code'] . ' — ' . $bankRow['bank_name']) ?>
                </div>
                <div>
                    <strong>Suund ja summa</strong>
                    <?= h($bankRow['debit_credit']) ?> —
                    <?= number_format((float) $bankRow['amount'], 2, ',', ' ') ?> €
                </div>
                <div>
                    <strong>Saaja/maksja</strong>
                    <?= h($bankRow['counterparty_name'] ?? '') ?>
                </div>
            </div>

            <?php if (!empty($bankRow['description'])): ?>
                <p><strong>Selgitus:</strong> <?= h($bankRow['description']) ?></p>
            <?php endif; ?>

            <p class="note">
                Luuakse mustand, mitte kinnitatud kanne.
                Vali tehingule sobiv vastaskonto ning kontrolli kanne enne kinnitamist üle.
            </p>
        </section>

        <?php if ($error === '' || $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <form method="post" class="card">
                <input type="hidden" name="bank_line_id" value="<?= (int) $bankRow['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

                <label for="counter-account">
                    Tehingu vastaskonto
                    <select id="counter-account" name="counter_account_id" required>
                        <option value="">Vali konto</option>
                        <?php foreach ($accounts as $account): ?>
                            <option
                                value="<?= (int) $account['id'] ?>"
                                <?= (string) $account['id'] === $selectedCounterAccountId ? 'selected' : '' ?>
                            >
                                <?= h($account['code'] . ' — ' . $account['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <button
                    class="button"
                    type="submit"
                    onclick="return confirm('Kas lood sellest pangatehingust kande mustandi?')"
                >
                    Loo kande mustand
                </button>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <p><a class="button" href="bank_statement.php">Tagasi pangaväljavõttele</a></p>
</body>
</html>
