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

function centsToDecimal(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

$companyId = (int) $_SESSION['company_id'];
$userId = (int) $_SESSION['user_id'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: bank_import.php');
    exit;
}

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
) {
    $error = 'Vorm aegus. Mine tagasi, loo eelvaade uuesti ja proovi uuesti.';
}

$preview = $_SESSION['bank_import_preview'] ?? null;

if (!$error && (
    !is_array($preview)
    || (int) ($preview['company_id'] ?? 0) !== $companyId
    || empty($preview['bank_account_id'])
    || !preg_match('/^[a-f0-9]{32}$/', (string) ($preview['import_batch_id'] ?? ''))
    || empty($preview['rows'])
    || !is_array($preview['rows'])
)) {
    $error = 'Impordi eelvaadet ei leitud. Loo pangaväljavõttest uus eelvaade.';
}

if (!$error) {
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
        $error = 'Valitud pangakonto ei ole selle ettevõtte aktiivne varakonto.';
    }
}

$imported = 0;
$matched = 0;
$duplicates = 0;
$unmatched = 0;

if (!$error) {
    try {
        $pdo->beginTransaction();

        $matchStmt = $pdo->prepare(
            "SELECT jl.id, jl.debit, jl.credit
             FROM journal_lines jl
             JOIN journal_entries je ON je.id = jl.entry_id
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

        $duplicateLineStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM bank_statement_lines
             WHERE company_id = ?
               AND bank_account_id = ?
               AND transaction_date = ?
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
               AND (import_batch_id IS NULL OR import_batch_id <> ?)"
        );

        $insertStmt = $pdo->prepare(
            "INSERT INTO bank_statement_lines (
                company_id,
                bank_account_id,
                transaction_date,
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
                matched_journal_line_id,
                created_by,
                import_batch_id,
                source_file_name
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )"
        );

        // Tuvasta eelmistest importidest juba olemas olevad koopiad, kuid ära
        // lase selle kontrolliga sama CSV faili korduvaid identseid ridu ära kaotada.
        $existingDuplicateCounts = [];
        $currentBatchOccurrences = [];

        foreach ($preview['rows'] as $row) {
            $date = (string) ($row['date'] ?? '');
            $direction = strtoupper((string) ($row['direction'] ?? ''));
            $amountCents = (int) ($row['amount_cents'] ?? 0);
            $feeCents = (int) ($row['fee_cents'] ?? 0);
            $archiveReference = trim((string) ($row['archive_reference'] ?? ''));
            $archiveReference = $archiveReference !== '' ? $archiveReference : null;
            $documentNumber = trim((string) ($row['document_number'] ?? ''));
            $documentNumber = $documentNumber !== '' ? $documentNumber : null;
            $description = trim((string) ($row['description'] ?? ''));
            $description = $description !== '' ? $description : null;
            $counterpartyName = trim((string) ($row['counterparty_name'] ?? ''));
            $counterpartyName = $counterpartyName !== '' ? $counterpartyName : null;
            $counterpartyBankCode = trim((string) ($row['bank_code'] ?? ''));
            $counterpartyBankCode = $counterpartyBankCode !== '' ? $counterpartyBankCode : null;
            $transactionType = trim((string) ($row['type'] ?? ''));
            $transactionType = $transactionType !== '' ? $transactionType : null;
            $referenceNumber = trim((string) ($row['reference_number'] ?? ''));
            $referenceNumber = $referenceNumber !== '' ? $referenceNumber : null;
            $serviceFee = centsToDecimal($feeCents);
            $currency = trim((string) ($row['currency'] ?? 'EUR')) ?: 'EUR';
            $amount = centsToDecimal($amountCents);

            if (
                !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                || !in_array($direction, ['D', 'C'], true)
                || $amountCents <= 0
            ) {
                throw new RuntimeException('Eelvaates leidus vigaste andmetega rida.');
            }

            // Arhiveerimistunnus ei pruugi olla unikaalne. Võrdleme kõiki
            // välju ja arvestame mitu korda sama rida käesolevas failis esineb.
            $duplicateValues = [
                $companyId,
                $bankAccountId,
                $date,
                $documentNumber,
                $counterpartyName,
                $counterpartyBankCode,
                $transactionType,
                $direction,
                $amount,
                $referenceNumber,
                $archiveReference,
                $description,
                $serviceFee,
                $currency,
            ];
            $duplicateKey = hash('sha256', serialize($duplicateValues));
            $currentBatchOccurrences[$duplicateKey] = ($currentBatchOccurrences[$duplicateKey] ?? 0) + 1;

            if (!array_key_exists($duplicateKey, $existingDuplicateCounts)) {
                $duplicateLineStmt->execute(array_merge($duplicateValues, [(string) $preview['import_batch_id']]));
                $existingDuplicateCounts[$duplicateKey] = (int) $duplicateLineStmt->fetchColumn();
            }

            // Kordus jäetakse vahele ainult siis, kui varasemates importides
            // on selle identse rea eksemplare vähemalt sama palju.
            if ($currentBatchOccurrences[$duplicateKey] <= $existingDuplicateCounts[$duplicateKey]) {
                $duplicates++;
                continue;
            }

            $matchedJournalLineId = null;

            // Seome ainult üheselt sobiva kande. Teenustasuga read
            // jäävad käsitsi kontrollimiseks.
            if ($feeCents === 0) {
                $matchStmt->execute([$bankAccountId, $companyId, $date]);
                $candidates = [];

                while ($candidate = $matchStmt->fetch(PDO::FETCH_ASSOC)) {
                    $candidateDebit = (int) round((float) $candidate['debit'] * 100);
                    $candidateCredit = (int) round((float) $candidate['credit'] * 100);

                    $isMatch = $direction === 'C'
                        ? ($candidateDebit === $amountCents && $candidateCredit === 0)
                        : ($candidateCredit === $amountCents && $candidateDebit === 0);

                    if ($isMatch) {
                        $candidates[] = (int) $candidate['id'];
                    }
                }

                if (count($candidates) === 1) {
                    $matchedJournalLineId = $candidates[0];
                }
            }

            $insertStmt->execute([
                $companyId,
                $bankAccountId,
                $date,
                $documentNumber,
                $counterpartyName,
                $counterpartyBankCode,
                $transactionType,
                $direction,
                $amount,
                $referenceNumber,
                $archiveReference,
                $description,
                $serviceFee,
                $currency,
                $matchedJournalLineId,
                $userId,
                (string) $preview['import_batch_id'],
                trim((string) ($preview['source_file_name'] ?? '')) ?: null,
            ]);

            $imported++;

            if ($matchedJournalLineId !== null) {
                $matched++;
            } else {
                $unmatched++;
            }
        }

        $pdo->commit();
        unset($_SESSION['bank_import_preview']);

        $message = 'Import lõpetatud. Lisatud: ' . $imported
            . ', automaatselt seotud: ' . $matched
            . ', kontrollimiseks sidumata: ' . $unmatched
            . ', juba olemas ja vahele jäetud: ' . $duplicates . '.';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = 'Import ebaõnnestus. Kontrolli, et tabeli veerud vastavad loodud struktuurile.';
    }
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pangaväljavõtte import</title>
    <style>
        body {
            margin: 32px;
            font-family: Arial, sans-serif;
            color: #263746;
        }

        .card {
            max-width: 760px;
            padding: 22px;
            border: 1px solid #dce3ec;
            border-radius: 12px;
            background: #f7f9fc;
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
            margin-top: 12px;
            padding: 11px 18px;
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
        <h1>Pangaväljavõtte import</h1>

        <?php if ($error !== ''): ?>
            <p class="error"><?= h($error) ?></p>
        <?php else: ?>
            <p class="success"><?= h($message) ?></p>
        <?php endif; ?>

        <a class="button" href="bank_import.php">Tagasi pangaväljavõtte juurde</a>
        <a class="button" href="index.php">Avalehele</a>
    </main>
</body>
</html>
