<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php';

function centsToDecimal(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

function returnToImport(string $type, string $text): void
{
    $_SESSION['bank_import_notice'] = [
        'type' => $type,
        'text' => $text,
    ];
    header('Location: bank_import.php');
    exit;
}

$companyId = (int) $_SESSION['company_id'];
$userId = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: bank_import.php');
    exit;
}

if (
    empty($_SESSION['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
) {
    returnToImport('error', 'Vorm aegus. Loo CSV-failist uus eelvaade ja proovi uuesti.');
}

$preview = $_SESSION['bank_import_preview'] ?? null;

if (
    !is_array($preview)
    || (int) ($preview['company_id'] ?? 0) !== $companyId
    || empty($preview['bank_account_id'])
    || empty($preview['rows'])
    || !is_array($preview['rows'])
) {
    returnToImport('error', 'CSV eelvaadet ei leitud. Loo pangafailist uus eelvaade.');
}

$bankAccountId = (int) $preview['bank_account_id'];
$accountStmt = $pdo->prepare(
    "SELECT id
     FROM accounts
     WHERE id = ?
       AND company_id = ?
       AND is_active = 1
       AND account_type = 'asset'"
);
$accountStmt->execute([$bankAccountId, $companyId]);

if (!$accountStmt->fetchColumn()) {
    returnToImport('error', 'Valitud pangakonto ei ole selle ettevõtte aktiivne varakonto.');
}

$imported = 0;
$duplicates = 0;

try {
    $pdo->beginTransaction();

    $duplicateStmt = $pdo->prepare(
        "SELECT id
         FROM portfelli_algsaldo
         WHERE company_id = ?
           AND bank_account_id = ?
           AND bank_account_number <=> ?
           AND transaction_date = ?
           AND record_type <=> ?
           AND document_number <=> ?
           AND counterparty_name <=> ?
           AND counterparty_bank_code <=> ?
           AND transaction_type <=> ?
           AND debit_credit = ?
           AND amount = ?
           AND reference_number <=> ?
           AND archive_reference <=> ?
           AND description <=> ?
           AND service_fee = ?
           AND currency = ?
         LIMIT 1"
    );

    $insertStmt = $pdo->prepare(
        "INSERT INTO portfelli_algsaldo (
            company_id,
            bank_account_id,
            bank_account_number,
            transaction_date,
            record_type,
            document_number,
            counterparty_name,
            counterparty_bank_code,
            transaction_type,
            debit_credit,
            amount,
            reference_number,
            archive_reference,
            description,
            service_fee,
            currency,
            created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    foreach ($preview['rows'] as $row) {
        $date = (string) ($row['date'] ?? '');
        $direction = strtoupper((string) ($row['direction'] ?? ''));
        $amountCents = (int) ($row['amount_cents'] ?? 0);
        $feeCents = (int) ($row['fee_cents'] ?? 0);

        if (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || !in_array($direction, ['D', 'C'], true)
            || $amountCents <= 0
        ) {
            throw new RuntimeException('Eelvaates leidus vigase andmestikuga rida.');
        }

        $accountNumber = trim((string) ($row['account_number'] ?? ''));
        $accountNumber = $accountNumber !== '' ? $accountNumber : null;
        $recordType = trim((string) ($row['record_type'] ?? ''));
        $recordType = $recordType !== '' ? $recordType : null;
        $documentNumber = trim((string) ($row['document_number'] ?? ''));
        $documentNumber = $documentNumber !== '' ? $documentNumber : null;
        $counterpartyName = trim((string) ($row['counterparty_name'] ?? ''));
        $counterpartyName = $counterpartyName !== '' ? $counterpartyName : null;
        $bankCode = trim((string) ($row['bank_code'] ?? ''));
        $bankCode = $bankCode !== '' ? $bankCode : null;
        $transactionType = trim((string) ($row['type'] ?? ''));
        $transactionType = $transactionType !== '' ? $transactionType : null;
        $referenceNumber = trim((string) ($row['reference_number'] ?? ''));
        $referenceNumber = $referenceNumber !== '' ? $referenceNumber : null;
        $archiveReference = trim((string) ($row['archive_reference'] ?? ''));
        $archiveReference = $archiveReference !== '' ? $archiveReference : null;
        $description = trim((string) ($row['description'] ?? ''));
        $description = $description !== '' ? $description : null;
        $amount = centsToDecimal($amountCents);
        $serviceFee = centsToDecimal($feeCents);
        $currency = trim((string) ($row['currency'] ?? 'EUR')) ?: 'EUR';

        $duplicateStmt->execute([
            $companyId,
            $bankAccountId,
            $accountNumber,
            $date,
            $recordType,
            $documentNumber,
            $counterpartyName,
            $bankCode,
            $transactionType,
            $direction,
            $amount,
            $referenceNumber,
            $archiveReference,
            $description,
            $serviceFee,
            $currency,
        ]);

        if ($duplicateStmt->fetchColumn()) {
            $duplicates++;
            continue;
        }

        $insertStmt->execute([
            $companyId,
            $bankAccountId,
            $accountNumber,
            $date,
            $recordType,
            $documentNumber,
            $counterpartyName,
            $bankCode,
            $transactionType,
            $direction,
            $amount,
            $referenceNumber,
            $archiveReference,
            $description,
            $serviceFee,
            $currency,
            $userId,
        ]);

        $imported++;
    }

    $pdo->commit();
    unset($_SESSION['bank_import_preview']);

    $_SESSION['portfolio_opening_notice'] = [
        'type' => 'success',
        'text' => 'Portfelli algsaldosse lisati ' . $imported
            . ' rida; täielikult korduvaid ridu jäeti vahele ' . $duplicates . '.',
    ];
    header('Location: portfelli_algsaldo.php');
    exit;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Portfelli algsaldo salvestamine ebaõnnestus: ' . $exception->getMessage());
    returnToImport(
        'error',
        'Portfelli algsaldo salvestamine ebaõnnestus. Ühtegi eelvaate rida ei salvestatud.'
    );
}
?>