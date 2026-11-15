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
require_once __DIR__.'/journal_draft_lib.php';
ensureJournalCorrectionSchema($pdo);

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
    $amount=str_replace(',','.',trim($amount));
    if ($amount === '') {
        return 0;
    }

    if (!preg_match('/^\d{1,13}(\.\d{1,2})?$/', $amount)) {
        return null;
    }

    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
    $fraction = str_pad($fraction, 2, '0');

    return (int) $whole * 100 + (int) substr($fraction, 0, 2);
}

$entryId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entryId = filter_input(INPUT_POST, 'entry_id', FILTER_VALIDATE_INT);
}

if (!$entryId) {
    http_response_code(400);
    exit('Vigane kande ID.');
}

$companyId = (int) $_SESSION['company_id'];

$entryStmt = $pdo->prepare(
    'SELECT id, entry_date, description, document_number, status,posted_at,corrects_entry_id
     FROM journal_entries
     WHERE id = :entry_id
       AND company_id = :company_id'
);
$entryStmt->execute([
    'entry_id' => $entryId,
    'company_id' => $companyId,
]);
$entry = $entryStmt->fetch();

if (!$entry || $entry['status'] !== 'draft' || $entry['posted_at']!==null) {
    http_response_code(404);
    exit('Mustandit ei leitud.');
}
$correctionSource=journalCorrectionSource($pdo,$companyId,$entry);

$accountsStmt = $pdo->prepare(
    'SELECT id, code, name
     FROM accounts
     WHERE company_id = :company_id
     ORDER BY code'
);
$accountsStmt->execute(['company_id' => $companyId]);
$accounts = $accountsStmt->fetchAll();

$accountIds = [];
foreach ($accounts as $account) {
    $accountIds[(int) $account['id']] = true;
}

$linesStmt = $pdo->prepare(
    'SELECT account_id, description, debit, credit
     FROM journal_lines
     WHERE entry_id = :entry_id
     ORDER BY id'
);
$linesStmt->execute(['entry_id' => $entryId]);
$originalLines = $linesStmt->fetchAll();

$errors = [];
$description = $entry['description'];
$documentNumber = $entry['document_number'] ?? '';
$entryDate = $entry['entry_date'];

$formLines = [];
foreach ($originalLines as $line) {
    $formLines[] = [
        'account_id' => (string) $line['account_id'],
        'description' => $line['description'] ?? '',
        'debit' => (string) $line['debit'],
        'credit' => (string) $line['credit'],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
    ) {
        $errors[] = 'Vorm aegus. Ava mustand uuesti ja proovi siis.';
    }

    $entryDate = trim($_POST['entry_date'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $documentNumber = trim($_POST['document_number'] ?? '');

    $accountInputs = $_POST['account_id'] ?? [];
    $lineDescriptions = $_POST['line_description'] ?? [];
    $debitInputs = $_POST['debit'] ?? [];
    $creditInputs = $_POST['credit'] ?? [];

    if (!validDate($entryDate)) {
        $errors[] = 'Sisesta kehtiv kuupäev.';
    }
    elseif ($lockMessage = closedAccountingPeriodMessage($pdo, $companyId, $entryDate)) {
        $errors[] = $lockMessage;
    }

    if ($description === '' || preg_match_all('/./us',$description) > 500) {
        $errors[] = 'Kirjeldus on kohustuslik ja võib olla kuni 500 märki.';
    }

    if (preg_match_all('/./us',$documentNumber) > 50) {
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

    $formLines = [];
    $cleanLines = [];
    $debitTotal = 0;
    $creditTotal = 0;

    for ($i = 0; $i < min($count, 20); $i++) {
        $accountValue = trim((string) ($accountInputs[$i] ?? ''));
        $lineDescription = trim((string) ($lineDescriptions[$i] ?? ''));
        $debitValue = str_replace(',','.',trim((string) ($debitInputs[$i] ?? '')));
        $creditValue = str_replace(',','.',trim((string) ($creditInputs[$i] ?? '')));

        $formLines[] = [
            'account_id' => $accountValue,
            'description' => $lineDescription,
            'debit' => $debitValue,
            'credit' => $creditValue,
        ];

        if (
            $accountValue === '' &&
            $lineDescription === '' &&
            $debitValue === '' &&
            $creditValue === ''
        ) {
            continue;
        }

        $accountId = filter_var($accountValue, FILTER_VALIDATE_INT);
        if(preg_match_all('/./us',$lineDescription)>500) { $errors[]='Rea kirjeldus võib olla kuni 500 märki.'; continue; }
        $debit = amountToCents($debitValue);
        $credit = amountToCents($creditValue);

        if (!$accountId || !isset($accountIds[$accountId])) {
            $errors[] = 'Vali igale kasutatud reale oma ettevõtte konto.';
            continue;
        }

        if ($debit === null || $credit === null) {
            $errors[] = 'Kontrolli kanderidade summasid.';
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

            // Kontrolli uuesti, et kanne on endiselt mustand.
            $lockStmt = $pdo->prepare(
                'SELECT status,posted_at
                 FROM journal_entries
                 WHERE id = :entry_id
                   AND company_id = :company_id
                 FOR UPDATE'
            );
            $lockStmt->execute([
                'entry_id' => $entryId,
                'company_id' => $companyId,
            ]);
            $current = $lockStmt->fetch();

            if (!$current || $current['status'] !== 'draft' || $current['posted_at']!==null) {
                throw new RuntimeException('Kanne ei ole enam mustand.');
            }

            $updateStmt = $pdo->prepare(
                'UPDATE journal_entries
                 SET entry_date = :entry_date,
                     description = :description,
                     document_number = :document_number,
                     corrects_entry_id=COALESCE(corrects_entry_id,:corrects_entry_id)
                 WHERE id = :entry_id
                   AND company_id = :company_id
                   AND status = :status'
            );
            $updateStmt->execute([
                'entry_date' => $entryDate,
                'description' => $description,
                'document_number' => $documentNumber !== '' ? $documentNumber : null,
                'corrects_entry_id'=>$correctionSource['id']??null,
                'entry_id' => $entryId,
                'company_id' => $companyId,
                'status' => 'draft',
            ]);

            $deleteStmt = $pdo->prepare(
                'DELETE FROM journal_lines WHERE entry_id = :entry_id'
            );
            $deleteStmt->execute(['entry_id' => $entryId]);

            $insertStmt = $pdo->prepare(
                'INSERT INTO journal_lines
                    (entry_id, account_id, description, debit, credit)
                 VALUES
                    (:entry_id, :account_id, :description, :debit, :credit)'
            );

            foreach ($cleanLines as $line) {
                $insertStmt->execute([
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
            $errors[] = $e instanceof RuntimeException
                ? $e->getMessage()
                : 'Mustandi salvestamine ebaõnnestus.';
        }
    }
}

// Lisa paar tühja rida, et saaks vajadusel kanderidu juurde lisada.
while (count($formLines) < 4) {
    $formLines[] = [
        'account_id' => '',
        'description' => '',
        'debit' => '',
        'credit' => '',
    ];
}
require __DIR__.'/edit_entry_view.php';
