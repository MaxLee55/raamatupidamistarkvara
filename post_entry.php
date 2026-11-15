<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Kasuta kande kinnitamise vormi.');
}

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
) {
    http_response_code(400);
    exit('Vorm aegus. Ava kanne uuesti ja proovi siis.');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/period_lock.php';
require_once __DIR__ . '/cash_balance_guard.php';

$entryId = filter_input(INPUT_POST, 'entry_id', FILTER_VALIDATE_INT);

if (!$entryId) {
    http_response_code(400);
    exit('Vigane kande ID.');
}

function amountToCents(string $amount): ?int
{
    if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $amount)) {
        return null;
    }

    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
    $fraction = str_pad($fraction, 2, '0');

    return ((int) $whole * 100) + (int) substr($fraction, 0, 2);
}

try {
    $pdo->beginTransaction();

    // Lukusta mustand, et seda ei saaks samal ajal kaks korda kinnitada.
    $entryStmt = $pdo->prepare(
        'SELECT id, entry_date, status
         FROM journal_entries
         WHERE id = :entry_id
           AND company_id = :company_id
         FOR UPDATE'
    );
    $companyId = (int) $_SESSION['company_id'];
    $entryStmt->execute([
        'entry_id' => $entryId,
        'company_id' => $companyId,
    ]);
    $entry = $entryStmt->fetch();

    if (!$entry || $entry['status'] !== 'draft') {
        throw new RuntimeException('Seda kannet ei leitud või see pole enam mustand.');
    }

    $periodMessage = closedAccountingPeriodMessage($pdo, $companyId, $entry['entry_date']);
    if ($periodMessage !== null) {
        throw new RuntimeException($periodMessage);
    }

    $linesStmt = $pdo->prepare(
        'SELECT jl.account_id, jl.debit, jl.credit, a.company_id AS account_company_id
         FROM journal_lines jl
         LEFT JOIN accounts a ON a.id = jl.account_id
         WHERE jl.entry_id = :entry_id
         FOR UPDATE'
    );
    $linesStmt->execute(['entry_id' => $entryId]);
    $lines = $linesStmt->fetchAll();

    if (count($lines) < 2) {
        throw new RuntimeException('Kandel peab olema vähemalt kaks rida.');
    }

    $debitTotal = 0;
    $creditTotal = 0;
    $accountDeltas = [];

    foreach ($lines as $line) {
        if ((int) $line['account_company_id'] !== $companyId) {
            throw new RuntimeException('Kõik kande kontod peavad kuuluma sinu ettevõttele.');
        }

        $debit = amountToCents((string) $line['debit']);
        $credit = amountToCents((string) $line['credit']);

        if ($debit === null || $credit === null) {
            throw new RuntimeException('Kandel on vigane summa.');
        }

        // Igal real peab olema kas deebet või kreedit, mitte mõlemad ega kumbki.
        if (($debit > 0 && $credit > 0) || ($debit === 0 && $credit === 0)) {
            throw new RuntimeException('Iga rida peab sisaldama kas deebet- või kreeditsummat.');
        }

        $debitTotal += $debit;
        $creditTotal += $credit;
        $accountId = (int) $line['account_id'];
        $accountDeltas[$accountId] = ($accountDeltas[$accountId] ?? 0) + $debit - $credit;
    }

    if ($debitTotal <= 0 || $debitTotal !== $creditTotal) {
        throw new RuntimeException('Deebet ja kreedit peavad olema võrdsed ning suuremad kui null.');
    }
    assertCashAccountsNotFurtherNegative($pdo, $companyId, $accountDeltas);


    $updateStmt = $pdo->prepare(
        "UPDATE journal_entries
         SET status = 'posted',
             posted_at = CURRENT_TIMESTAMP
         WHERE id = :entry_id
           AND company_id = :company_id
           AND status = 'draft'"
    );
    $updateStmt->execute([
        'entry_id' => $entryId,
        'company_id' => $companyId,
    ]);

    if ($updateStmt->rowCount() !== 1) {
        throw new RuntimeException('Kande kinnitamine ebaõnnestus.');
    }

    $pdo->commit();

    header('Location: entry.php?id=' . $entryId);
    exit;
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($e->getMessage());
    http_response_code(500);
    exit('Kande kinnitamisel tekkis viga.');
}