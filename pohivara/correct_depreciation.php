<?php
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: ../login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('See toiming tuleb teha amortisatsiooni lehe vormi kaudu.');
}
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/period_lock.php';

$companyId = (int) $_SESSION['company_id'];
$userId = (int) $_SESSION['user_id'];
$token = (string) ($_POST['csrf_token'] ?? '');
$originalId = filter_var($_POST['original_entry_id'] ?? null, FILTER_VALIDATE_INT);
$reversalId = filter_var($_POST['reversal_entry_id'] ?? null, FILTER_VALIDATE_INT);
$reason = trim((string) ($_POST['reason'] ?? ''));
$returnMonth = (string) ($_POST['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $returnMonth)) $returnMonth = date('Y-m');

function cents(string|float|int $amount): int { return (int) round((float) $amount * 100); }
function exactJournalLines(PDO $pdo, int $companyId, int $entryId): array {
    $stmt = $pdo->prepare(
        'SELECT jl.account_id, SUM(ROUND(jl.debit * 100)) AS debit_cents,
                SUM(ROUND(jl.credit * 100)) AS credit_cents
         FROM journal_lines jl
         JOIN journal_entries je ON je.id = jl.entry_id AND je.company_id = ?
         JOIN accounts a ON a.id = jl.account_id AND a.company_id = je.company_id
         WHERE jl.entry_id = ?
         GROUP BY jl.account_id ORDER BY jl.account_id'
    );
    $stmt->execute([$companyId, $entryId]);
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $result[(int) $line['account_id']] = [
            (int) $line['debit_cents'], (int) $line['credit_cents'],
        ];
    }
    return $result;
}

if (!$originalId || !$reversalId || $originalId === $reversalId) {
    http_response_code(400);
    exit('Vali parandatav amortisatsioonikanne ja selle vastukanne.');
}
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(400);
    exit('Vorm aegus. Mine amortisatsiooni lehele tagasi ja proovi uuesti.');
}
if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
    http_response_code(400);
    exit('Paranduse põhjus peab olema 3–500 märki.');
}

try {
    $pdo->beginTransaction();

    $originalStmt = $pdo->prepare(
        "SELECT id, entry_date, description, document_number, status FROM journal_entries
         WHERE id = ? AND company_id = ? FOR UPDATE"
    );
    $originalStmt->execute([$originalId, $companyId]);
    $original = $originalStmt->fetch(PDO::FETCH_ASSOC);
    if (!$original || $original['status'] !== 'posted'
        || !preg_match('/^AMORT-\d{6}(?:-\d+)?$/', (string) $original['document_number'])) {
        throw new RuntimeException('Valitud algkanne ei ole põhivara amortisatsiooni kinnitatud kanne.');
    }

    $runsStmt = $pdo->prepare(
        'SELECT r.id, r.asset_id, r.period_start, r.amount, fa.asset_number
         FROM fixed_asset_depreciation_runs r
         JOIN fixed_assets fa ON fa.id = r.asset_id AND fa.company_id = r.company_id
         WHERE r.company_id = ? AND r.journal_entry_id = ? FOR UPDATE'
    );
    $runsStmt->execute([$companyId, $originalId]);
    $runs = $runsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$runs) throw new RuntimeException('Selle kande aktiivset amortisatsiooniarvestust ei leitud või see on juba parandatud.');
    $periods = array_unique(array_column($runs, 'period_start'));
    if (count($periods) !== 1) throw new RuntimeException('Kanne seostub mitme arvestuskuuga; automaatset parandust ei saa teha.');
    $periodStart = (string) reset($periods);
    $monthPrefix = 'AMORT-' . str_replace('-', '', substr($periodStart, 0, 7));
    if (!str_starts_with((string) $original['document_number'], $monthPrefix)) {
        throw new RuntimeException('Kande dokumendinumber ei klapi amortisatsiooni arvestuskuuga.');
    }

    $reversalStmt = $pdo->prepare(
        "SELECT id, entry_date, description, document_number, status FROM journal_entries
         WHERE id = ? AND company_id = ? FOR UPDATE"
    );
    $reversalStmt->execute([$reversalId, $companyId]);
    $reversal = $reversalStmt->fetch(PDO::FETCH_ASSOC);
    if (!$reversal || $reversal['status'] !== 'posted') throw new RuntimeException('Valitud vastukanne pole kinnitatud.');
    if ($reversal['entry_date'] < $original['entry_date']) throw new RuntimeException('Vastukande kuupäev ei saa olla algkandest varasem.');
    $originalLines = exactJournalLines($pdo, $companyId, $originalId);
    $reversalLines = exactJournalLines($pdo, $companyId, $reversalId);
    if (!$originalLines || count($originalLines) !== count($reversalLines)) {
        throw new RuntimeException('Vastukande read ei pööra algset kannet täielikult ümber.');
    }
    foreach ($originalLines as $accountId => [$debit, $credit]) {
        if (!isset($reversalLines[$accountId])
            || $reversalLines[$accountId][0] !== $credit
            || $reversalLines[$accountId][1] !== $debit) {
            throw new RuntimeException('Vastukande deebet- ja kreeditsummad ei klapi algse kande kontode ja summadega.');
        }
    }

    $assetIds = array_values(array_unique(array_map(static fn(array $run): int => (int) $run['asset_id'], $runs)));
    $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
    $laterRunStmt = $pdo->prepare(
        "SELECT fa.asset_number, MIN(r.period_start) AS first_later_period
         FROM fixed_asset_depreciation_runs r
         JOIN fixed_assets fa ON fa.id = r.asset_id AND fa.company_id = r.company_id
         WHERE r.company_id = ? AND r.asset_id IN ($placeholders) AND r.period_start > ?
         GROUP BY r.asset_id ORDER BY MIN(r.period_start) LIMIT 1"
    );
    $laterRunStmt->execute(array_merge([$companyId], $assetIds, [$periodStart]));
    $laterRun = $laterRunStmt->fetch(PDO::FETCH_ASSOC);
    if ($laterRun) {
        throw new RuntimeException('Vara ' . $laterRun['asset_number'] . ' hilisem amortisatsioon (' . substr($laterRun['first_later_period'], 0, 7) . ') tuleb enne tagasi pöörata. Paranda amortisatsioonikuud kronoloogilises järjekorras.');
    }

    $disposalStmt = $pdo->prepare(
        "SELECT fa.asset_number, d.disposal_date FROM fixed_asset_disposals d
         JOIN fixed_assets fa ON fa.id = d.asset_id AND fa.company_id = d.company_id
         WHERE d.company_id = ? AND d.asset_id IN ($placeholders) AND d.status = 'posted'
           AND d.disposal_date >= ? LIMIT 1"
    );
    $disposalStmt->execute(array_merge([$companyId], $assetIds, [$periodStart]));
    $laterDisposal = $disposalStmt->fetch(PDO::FETCH_ASSOC);
    if ($laterDisposal) {
        throw new RuntimeException('Vara ' . $laterDisposal['asset_number'] . ' on hiljem müüdud või maha kantud. Paranda esmalt kõrvaldamine.');
    }

    $periodMessage = closedAccountingPeriodMessage($pdo, $companyId, $reversal['entry_date']);
    if ($periodMessage !== null) throw new RuntimeException($periodMessage);

    $historyStmt = $pdo->prepare(
        'INSERT INTO fixed_asset_depreciation_corrections
            (company_id, asset_id, period_start, amount, original_journal_entry_id,
             reversal_journal_entry_id, reversal_date, reason, reversed_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($runs as $run) {
        $historyStmt->execute([
            $companyId, (int) $run['asset_id'], $periodStart, $run['amount'],
            $originalId, $reversalId, $reversal['entry_date'], $reason, $userId,
        ]);
    }
    $deleteStmt = $pdo->prepare('DELETE FROM fixed_asset_depreciation_runs WHERE company_id = ? AND journal_entry_id = ?');
    $deleteStmt->execute([$companyId, $originalId]);
    if ($deleteStmt->rowCount() !== count($runs)) throw new RuntimeException('Amortisatsiooniregistri ridu ei õnnestunud tervikuna uuendada.');

    $pdo->commit();
    header('Location: depreciation.php?month=' . rawurlencode(substr($periodStart, 0, 7)) . '&corrected=' . $originalId);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof RuntimeException) {
        header('Location: depreciation.php?month=' . rawurlencode($returnMonth) . '&error=' . rawurlencode($e->getMessage()));
        exit;
    }
    error_log($e->getMessage());
    header('Location: depreciation.php?month=' . rawurlencode($returnMonth) . '&error=' . rawurlencode('Amortisatsioonikande parandamine ebaõnnestus.'));
    exit;
}
