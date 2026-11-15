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

function toCents($amount): int
{
    return (int) round((float) $amount * 100);
}

$companyId = (int) $_SESSION['company_id'];
$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: bank_statement.php');
    exit;
}

if (
    empty($_SESSION['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
) {
    $error = 'Vorm aegus. Värskenda pangaväljavõtte lehte ja proovi uuesti.';
}

$matchedCount = 0;
$ambiguousCount = 0;
$feeCount = 0;

if ($error === '') {
    try {
        $pdo->beginTransaction();

        $unmatchedStmt = $pdo->prepare(
            "SELECT
                bsl.id,
                bsl.bank_account_id,
                bsl.transaction_date,
                bsl.debit_credit,
                bsl.amount,
                bsl.service_fee
             FROM bank_statement_lines bsl
             JOIN accounts a
               ON a.id = bsl.bank_account_id
              AND a.company_id = bsl.company_id
             WHERE bsl.company_id = ?
               AND bsl.matched_journal_line_id IS NULL
             ORDER BY bsl.transaction_date, bsl.id
             FOR UPDATE"
        );
        $unmatchedStmt->execute([$companyId]);
        $unmatchedRows = $unmatchedStmt->fetchAll(PDO::FETCH_ASSOC);

        $candidateStmt = $pdo->prepare(
            "SELECT jl.id, jl.debit, jl.credit
             FROM journal_lines jl
             JOIN journal_entries je
               ON je.id = jl.entry_id
             WHERE jl.account_id = ?
               AND je.company_id = ?
               AND je.status = 'posted'
               AND je.entry_date = ?
               AND NOT EXISTS (
                   SELECT 1
                   FROM bank_statement_lines bsl
                   WHERE bsl.matched_journal_line_id = jl.id
               )"
        );

        $updateStmt = $pdo->prepare(
            "UPDATE bank_statement_lines
             SET matched_journal_line_id = ?
             WHERE id = ?
               AND company_id = ?
               AND matched_journal_line_id IS NULL"
        );

        foreach ($unmatchedRows as $bankRow) {
            // Pangatehinguga seotud teenustasu vajab eraldi kontrolli.
            if (toCents($bankRow['service_fee']) !== 0) {
                $feeCount++;
                continue;
            }

            $direction = strtoupper((string) $bankRow['debit_credit']);
            $amountCents = toCents($bankRow['amount']);

            if (!in_array($direction, ['D', 'C'], true) || $amountCents <= 0) {
                continue;
            }

            $candidateStmt->execute([
                (int) $bankRow['bank_account_id'],
                $companyId,
                $bankRow['transaction_date'],
            ]);

            $matches = [];

            while ($candidate = $candidateStmt->fetch(PDO::FETCH_ASSOC)) {
                $debitCents = toCents($candidate['debit']);
                $creditCents = toCents($candidate['credit']);

                $isMatch = $direction === 'C'
                    ? ($debitCents === $amountCents && $creditCents === 0)
                    : ($creditCents === $amountCents && $debitCents === 0);

                if ($isMatch) {
                    $matches[] = (int) $candidate['id'];
                }
            }

            if (count($matches) === 1) {
                $updateStmt->execute([
                    $matches[0],
                    (int) $bankRow['id'],
                    $companyId,
                ]);

                if ($updateStmt->rowCount() === 1) {
                    $matchedCount++;
                }
            } elseif (count($matches) > 1) {
                $ambiguousCount++;
            }
        }

        $pdo->commit();

        $message = 'Uuesti sobitamine lõpetatud. Seotud: ' . $matchedCount
            . '. Mitme võimaliku vastega read: ' . $ambiguousCount
            . '. Teenustasuga read jäid kontrollimiseks: ' . $feeCount . '.';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = 'Sobitamine ebaõnnestus. Kontrolli andmebaasi ja proovi uuesti.';
    }
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pangaridade sobitamine</title>
    <style>
        body {
            margin: 32px;
            font-family: Arial, sans-serif;
            color: #263746;
            background: #f7f9fc;
        }

        .card {
            max-width: 760px;
            padding: 22px;
            border: 1px solid #dce3ec;
            border-radius: 12px;
            background: #fff;
        }

        .success, .error {
            padding: 14px 16px;
            border-radius: 8px;
        }

        .success {
            border: 1px solid #b8dfc7;
            background: #eaf7ef;
            color: #176b3a;
        }

        .error {
            border: 1px solid #e5a3a3;
            background: #fff0f0;
            color: #a32121;
        }

        .button {
            display: inline-block;
            margin: 8px 8px 0 0;
            padding: 10px 16px;
            border-radius: 7px;
            background: #1769aa;
            color: #fff;
            font-weight: 600;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>Pangaridade sobitamine</h1>

        <?php if ($error !== ''): ?>
            <p class="error"><?= h($error) ?></p>
        <?php else: ?>
            <p class="success"><?= h($message) ?></p>
        <?php endif; ?>

        <a class="button" href="bank_statement.php">Tagasi pangaväljavõttele</a>
        <a class="button" href="index.php">Avalehele</a>
    </main>
</body>
</html>