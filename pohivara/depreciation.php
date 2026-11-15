<?php
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: ../login.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/feature_migration.php';
requireFixedAssetEnhancements($pdo);
require_once dirname(__DIR__) . '/period_lock.php';

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function monthIsValid(string $month): bool {
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
    return $date !== false && $date->format('Y-m') === $month;
}
function addMonth(string $month, int $offset): string {
    return (new DateTimeImmutable($month . '-01'))->modify(($offset >= 0 ? '+' : '') . $offset . ' months')->format('Y-m');
}
function amountFromCents(int $cents): string { return number_format($cents / 100, 2, '.', ''); }
function getPreview(PDO $pdo, int $companyId, string $month): array {
    $assetsStmt = $pdo->prepare(
        "SELECT fa.*, aa.code AS accumulated_code, aa.name AS accumulated_name,
                ea.code AS expense_code, ea.name AS expense_name
         FROM fixed_assets fa
         JOIN accounts aa ON aa.id = fa.accumulated_depreciation_account_id
             AND aa.company_id = fa.company_id AND aa.account_type = 'asset' AND aa.is_active = 1
         JOIN accounts ea ON ea.id = fa.depreciation_expense_account_id
             AND ea.company_id = fa.company_id AND ea.account_type = 'expense' AND ea.is_active = 1
         WHERE fa.company_id = ? AND fa.status = 'active'
         ORDER BY fa.available_date, fa.asset_number"
    );
    $assetsStmt->execute([$companyId]);
    $assets = $assetsStmt->fetchAll(PDO::FETCH_ASSOC);
    $runsStmt = $pdo->prepare(
        'SELECT period_start, amount FROM fixed_asset_depreciation_runs
         WHERE company_id = ? AND asset_id = ? ORDER BY period_start'
    );
    $estimateStmt = $pdo->prepare(
        'SELECT effective_period, months_before_change, carrying_value_at_change,
                residual_value, remaining_life_months
         FROM fixed_asset_estimate_changes
         WHERE company_id = ? AND asset_id = ? AND effective_period <= ?
         ORDER BY effective_period DESC, id DESC LIMIT 1'
    );
    $rows = [];
    $totalCents = 0;
    $blocked = [];
    foreach ($assets as $asset) {
        $startMonth = substr($asset['available_date'], 0, 7);
        $life = (int) $asset['useful_life_months'];
        $openingMonths = (int) $asset['opening_depreciation_months'];
        $openingCents = (int) round((float) $asset['opening_accumulated_depreciation'] * 100);
        $depreciableCents = max(0, (int) round(((float) $asset['acquisition_cost'] - (float) $asset['residual_value']) * 100) - $openingCents);
        $remainingLife = max(0, $life - $openingMonths);
        $baseCents = $remainingLife > 0 ? intdiv($depreciableCents, $remainingLife) : 0;
        $runsStmt->execute([$companyId, (int) $asset['id']]);
        $runs = $runsStmt->fetchAll(PDO::FETCH_ASSOC);
        $runCount = count($runs);
        $estimateStmt->execute([$companyId, (int) $asset['id'], $month . '-01']);
        $estimate = $estimateStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($estimate) {
            // Eelvaate tabel peab näitama selle kuu hinnangut, mitte vara algset lõppväärtust.
            $asset['residual_value'] = $estimate['residual_value'];
        }
        $expectedSequenceValid = true;
        foreach ($runs as $runIndex => $run) {
            if ($run['period_start'] !== addMonth($startMonth, $openingMonths + $runIndex) . '-01') {
                $expectedSequenceValid = false;
                break;
            }
        }
        $offset = ((int) substr($month, 0, 4) - (int) substr($startMonth, 0, 4)) * 12
            + ((int) substr($month, 5, 2) - (int) substr($startMonth, 5, 2));
        $amountCents = 0;
        $message = '';
        $eligible = false;
        if (!$expectedSequenceValid) {
            $message = 'Amortisatsiooni ajaloos on vahe; kontrolli varasemaid kuid.';
            $blocked[] = $asset['asset_number'] . ': ' . $message;
        } elseif ($offset < $openingMonths) {
            $message = 'Selle kuu kulum sisaldub sisestatud algseisus.';
        } elseif ($offset < 0) {
            $message = 'Amortisatsioon algab kasutusvalmis kuust ' . $startMonth . '.';
        } elseif ($offset < $openingMonths + $runCount) {
            $message = 'Selle kuu amortisatsioon on juba arvestatud.';
        } elseif ($offset > $openingMonths + $runCount && $runCount + $openingMonths < $life) {
            $message = 'Enne seda kuud tuleb arvestada ' . addMonth($startMonth, $openingMonths + $runCount) . ' kuu amortisatsioon.';
            $blocked[] = $asset['asset_number'] . ': ' . $message;
        } elseif ($estimate
            ? $offset >= (int) $estimate['months_before_change'] + (int) $estimate['remaining_life_months']
            : ($runCount + $openingMonths >= $life || $offset >= $life)) {
            $message = 'Kasulik eluiga on ammendunud.';
        } elseif ($offset === $openingMonths + $runCount) {
            $scheduleBase = $depreciableCents;
            $scheduleLife = $remainingLife;
            $scheduleOffset = $runCount;
            if ($estimate) {
                $scheduleBase = max(0, (int) round(((float) $estimate['carrying_value_at_change'] - (float) $estimate['residual_value']) * 100));
                $scheduleLife = (int) $estimate['remaining_life_months'];
                $scheduleOffset = $offset - (int) $estimate['months_before_change'];
                $baseCents = $scheduleLife > 0 ? intdiv($scheduleBase, $scheduleLife) : 0;
            }
            $amountCents = $scheduleOffset === $scheduleLife - 1
                ? $scheduleBase - ($baseCents * ($scheduleLife - 1))
                : $baseCents;
            $eligible = $scheduleOffset >= 0 && $scheduleOffset < $scheduleLife && $amountCents > 0;
            $message = $eligible ? 'Valmis arvestamiseks' : 'Amortiseeritav summa on ammendunud.';
        }
        $totalCents += $amountCents;
        $asset['amount_cents'] = $amountCents;
        $asset['eligible'] = $eligible;
        $asset['period_message'] = $message;
        $asset['posted_count'] = $runCount + $openingMonths;
        $asset['expense_code'] = $asset['expense_code'] ?? '';
        $rows[] = $asset;
    }
    return ['rows' => $rows, 'total_cents' => $totalCents, 'blocked' => $blocked];
}

$companyId = (int) $_SESSION['company_id'];
$correctionTableCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'fixed_asset_depreciation_corrections'");
$correctionTableCheck->execute();
$correctionTableReady = (int) $correctionTableCheck->fetchColumn() === 1;
$errors = [];
$month = (string) ($_POST['month'] ?? $_GET['month'] ?? date('Y-m'));
if (!monthIsValid($month)) $month = date('Y-m');
$currentMonth = date('Y-m');
$done = isset($_GET['posted']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    } elseif (!monthIsValid($month)) {
        $errors[] = 'Valitud kuu ei ole korrektne.';
    } elseif ($month > $currentMonth) {
        $errors[] = 'Tulevase kuu amortisatsiooni ei saa ette kanda.';
    } else {
        $periodStart = $month . '-01';
        $periodEnd = (new DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $periodMessage = closedAccountingPeriodMessage($pdo, $companyId, $periodEnd);
        if ($periodMessage !== null) {
            $errors[] = $periodMessage;
        } else {
            try {
                $pdo->beginTransaction();
                $assetLock=$pdo->prepare("SELECT id FROM fixed_assets WHERE company_id=? AND status='active' ORDER BY id FOR UPDATE");
                $assetLock->execute([$companyId]); $assetLock->fetchAll(PDO::FETCH_COLUMN);
                $preview = getPreview($pdo, $companyId, $month);
                if ($preview['blocked']) {
                    throw new RuntimeException(implode(' ', $preview['blocked']));
                }
                $eligibleRows = array_values(array_filter($preview['rows'], static fn(array $row): bool => $row['eligible']));
                if (!$eligibleRows || $preview['total_cents'] < 1) {
                    throw new RuntimeException('Valitud kuus pole uut amortisatsiooni, mida kanda.');
                }

                $accountGroups = [];
                foreach ($eligibleRows as $asset) {
                    $key = (int) $asset['depreciation_expense_account_id'] . ':' . (int) $asset['accumulated_depreciation_account_id'];
                    if (!isset($accountGroups[$key])) {
                        $accountGroups[$key] = [
                            'expense_id' => (int) $asset['depreciation_expense_account_id'],
                            'accumulated_id' => (int) $asset['accumulated_depreciation_account_id'],
                            'cents' => 0,
                        ];
                    }
                    $accountGroups[$key]['cents'] += (int) $asset['amount_cents'];
                }

                $description = 'Põhivara amortisatsioon ' . $month;
                $baseDocumentNumber = 'AMORT-' . str_replace('-', '', $month);
                $usedNumbersStmt = $pdo->prepare(
                    'SELECT document_number FROM journal_entries
                     WHERE company_id = ? AND (document_number = ? OR document_number LIKE ?) FOR UPDATE'
                );
                $usedNumbersStmt->execute([$companyId, $baseDocumentNumber, $baseDocumentNumber . '-%']);
                $usedNumbers = array_fill_keys($usedNumbersStmt->fetchAll(PDO::FETCH_COLUMN), true);
                $documentNumber = $baseDocumentNumber;
                $suffix = 2;
                while (isset($usedNumbers[$documentNumber])) {
                    $documentNumber = $baseDocumentNumber . '-' . str_pad((string) $suffix, 2, '0', STR_PAD_LEFT);
                    $suffix++;
                }

                $entryStmt = $pdo->prepare(
                    "INSERT INTO journal_entries (company_id, entry_date, description, document_number, status, created_by, posted_at)
                     VALUES (?, ?, ?, ?, 'posted', ?, CURRENT_TIMESTAMP)"
                );
                $entryStmt->execute([$companyId, $periodEnd, $description, $documentNumber, (int) $_SESSION['user_id']]);
                $entryId = (int) $pdo->lastInsertId();
                $lineStmt = $pdo->prepare(
                    'INSERT INTO journal_lines (entry_id, account_id, description, debit, credit) VALUES (?, ?, ?, ?, ?)'
                );
                foreach ($accountGroups as $group) {
                    $amount = amountFromCents($group['cents']);
                    $lineStmt->execute([$entryId, $group['expense_id'], 'Põhivara amortisatsioon ' . $month, $amount, '0.00']);
                    $lineStmt->execute([$entryId, $group['accumulated_id'], 'Akumuleeritud kulum ' . $month, '0.00', $amount]);
                }
                $runStmt = $pdo->prepare(
                    'INSERT INTO fixed_asset_depreciation_runs (company_id, asset_id, period_start, amount, journal_entry_id, created_by)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                foreach ($eligibleRows as $asset) {
                    $runStmt->execute([$companyId, (int) $asset['id'], $periodStart, amountFromCents((int) $asset['amount_cents']), $entryId, (int) $_SESSION['user_id']]);
                }
                $pdo->commit();
                header('Location: depreciation.php?month=' . rawurlencode($month) . '&posted=' . $entryId);
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($e instanceof RuntimeException) $errors[] = $e->getMessage();
                else { error_log($e->getMessage()); $errors[] = 'Amortisatsioonikande loomine ebaõnnestus.'; }
            }
        }
    }
}

$preview = getPreview($pdo, $companyId, $month);
$monthDocumentPrefix = 'AMORT-' . str_replace('-', '', $month);
$monthEntriesStmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT je.id)
     FROM journal_entries je
     JOIN fixed_asset_depreciation_runs r ON r.journal_entry_id = je.id AND r.company_id = je.company_id
     WHERE je.company_id = ? AND je.status = 'posted' AND r.period_start = ?
       AND (je.document_number = ? OR je.document_number LIKE ?)"
);
$monthEntriesStmt->execute([$companyId, $month . '-01', $monthDocumentPrefix, $monthDocumentPrefix . '-%']);
$monthAlreadyPosted = (int) $monthEntriesStmt->fetchColumn() > 0;
$correctedEntryId = isset($_GET['corrected']) && ctype_digit((string) $_GET['corrected']) ? (int) $_GET['corrected'] : 0;
$actionError = trim((string) ($_GET['error'] ?? ''));
$correctableEntries = [];
$reversalOptions = [];
$correctionHistory = [];
if ($correctionTableReady) {
    $correctableStmt = $pdo->prepare(
        "SELECT je.id, je.entry_date, je.document_number, je.description,
                COUNT(DISTINCT r.asset_id) AS asset_count, SUM(r.amount) AS total_amount
         FROM fixed_asset_depreciation_runs r
         JOIN journal_entries je ON je.id = r.journal_entry_id AND je.company_id = r.company_id AND je.status = 'posted'
         WHERE r.company_id = ? AND r.period_start = ?
         GROUP BY je.id, je.entry_date, je.document_number, je.description
         ORDER BY je.entry_date, je.id"
    );
    $correctableStmt->execute([$companyId, $month . '-01']);
    $correctableEntries = $correctableStmt->fetchAll(PDO::FETCH_ASSOC);
    $originalLinesStmt = $pdo->prepare(
        'SELECT jl.account_id, SUM(ROUND(jl.debit * 100)) AS debit_cents,
                SUM(ROUND(jl.credit * 100)) AS credit_cents
         FROM journal_lines jl WHERE jl.entry_id = ?
         GROUP BY jl.account_id ORDER BY jl.account_id'
    );
    $candidateEntriesStmt = $pdo->prepare(
        "SELECT je.id, je.entry_date, je.document_number, je.description
         FROM journal_entries je
         JOIN journal_lines jl ON jl.entry_id = je.id
         WHERE je.company_id = ? AND je.status = 'posted' AND je.entry_date >= ? AND je.id <> ?
           AND NOT EXISTS (
               SELECT 1 FROM fixed_asset_depreciation_corrections fc
               WHERE fc.company_id = je.company_id AND fc.reversal_journal_entry_id = je.id
           )
         GROUP BY je.id, je.entry_date, je.document_number, je.description
         HAVING ROUND(SUM(jl.debit) * 100) = ? AND ROUND(SUM(jl.credit) * 100) = ?
         ORDER BY je.entry_date, je.id"
    );
    $candidateLinesStmt = $pdo->prepare(
        'SELECT jl.account_id, SUM(ROUND(jl.debit * 100)) AS debit_cents,
                SUM(ROUND(jl.credit * 100)) AS credit_cents
         FROM journal_lines jl WHERE jl.entry_id = ?
         GROUP BY jl.account_id ORDER BY jl.account_id'
    );
    foreach ($correctableEntries as $entry) {
        $originalLinesStmt->execute([(int) $entry['id']]);
        $originalLines = [];
        $originalDebitCents = 0;
        $originalCreditCents = 0;
        foreach ($originalLinesStmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $accountId = (int) $line['account_id'];
            $debitCents = (int) $line['debit_cents'];
            $creditCents = (int) $line['credit_cents'];
            $originalLines[$accountId] = [$debitCents, $creditCents];
            $originalDebitCents += $debitCents;
            $originalCreditCents += $creditCents;
        }
        $candidateEntriesStmt->execute([$companyId, $entry['entry_date'], (int) $entry['id'], $originalDebitCents, $originalCreditCents]);
        $matchingReversals = [];
        foreach ($candidateEntriesStmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            $candidateLinesStmt->execute([(int) $candidate['id']]);
            $candidateLines = [];
            foreach ($candidateLinesStmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
                $candidateLines[(int) $line['account_id']] = [(int) $line['debit_cents'], (int) $line['credit_cents']];
            }
            if (count($candidateLines) !== count($originalLines)) continue;
            $isExactReversal = true;
            foreach ($originalLines as $accountId => [$debitCents, $creditCents]) {
                if (!isset($candidateLines[$accountId])
                    || $candidateLines[$accountId][0] !== $creditCents
                    || $candidateLines[$accountId][1] !== $debitCents) {
                    $isExactReversal = false;
                    break;
                }
            }
            if ($isExactReversal) $matchingReversals[] = $candidate;
        }
        $reversalOptions[(int) $entry['id']] = $matchingReversals;
    }
    $correctionHistoryStmt = $pdo->prepare(
        "SELECT c.original_journal_entry_id, c.reversal_journal_entry_id, c.reversal_date,
                c.reason, COUNT(*) AS asset_count, SUM(c.amount) AS total_amount
         FROM fixed_asset_depreciation_corrections c
         WHERE c.company_id = ? AND c.period_start = ?
         GROUP BY c.original_journal_entry_id, c.reversal_journal_entry_id, c.reversal_date, c.reason
         ORDER BY c.reversal_date, c.original_journal_entry_id"
    );
    $correctionHistoryStmt->execute([$companyId, $month . '-01']);
    $correctionHistory = $correctionHistoryStmt->fetchAll(PDO::FETCH_ASSOC);
}
$postedEntry = null;
if ($done && ctype_digit((string) $_GET['posted'])) {
    $postedStmt = $pdo->prepare('SELECT id, description FROM journal_entries WHERE id = ? AND company_id = ?');
    $postedStmt->execute([(int) $_GET['posted'], $companyId]);
    $postedEntry = $postedStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Põhivara amortisatsioon</title>
    <style>
        * { box-sizing: border-box; }
        body { max-width: 1250px; margin: 34px auto; padding: 0 22px; background: #f4f7fb; color: #263746; font-family: Arial, sans-serif; }
        h1, h2 { color: #17324d; }
        .panel { margin-bottom: 18px; padding: 22px; background: #fff; border: 1px solid #dce3ec; border-radius: 12px; }
        .muted { color: #667788; line-height: 1.55; }
        .notice { padding: 12px 14px; border-radius: 8px; line-height: 1.5; background: #fff6df; color: #694e00; }
        .success { background: #eaf7ef; color: #176b3a; }
        .error { background: #fff0f0; color: #9a2929; }
        .toolbar { display: flex; align-items: end; gap: 12px; flex-wrap: wrap; }
        label { display: block; margin-bottom: 6px; font-weight: 700; }
        input[type=month] { min-height: 42px; padding: 8px 10px; border: 1px solid #b8c4d1; border-radius: 7px; font: inherit; }
        .button { display: inline-block; padding: 11px 16px; border: 0; border-radius: 7px; background: #1769aa; color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .secondary { background: #657789; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #e1e7ef; text-align: left; vertical-align: top; }
        th { background: #f3f6fa; color: #33475b; }
        .amount { text-align: right; white-space: nowrap; }
        .status { color: #176b3a; font-weight: 700; }
        .actions { display: flex; flex-wrap: wrap; gap: 9px; margin-top: 18px; }
        .correction-form { display: grid; grid-template-columns: minmax(180px, 1fr) minmax(110px, .45fr) minmax(210px, 1fr) minmax(190px, 1fr) auto; align-items: end; gap: 10px; margin: 14px 0; padding: 14px; border: 1px solid #e1e7ef; border-radius: 9px; background: #f7f9fc; }
        .correction-form strong, .correction-form .notice { grid-column: 1 / -1; }
        .draft-reversal-form { grid-column: 1 / -1; margin: 0; }
        .draft-reversal-form .muted { display: inline; margin-left: 8px; }
        .correction-form label { margin: 0 0 5px; }
        .correction-form select, .correction-form input { width: 100%; min-height: 40px; padding: 8px 10px; border: 1px solid #b8c4d1; border-radius: 7px; font: inherit; }
        .correction-button { background: #a83232; }
        @media (max-width: 700px) { body { margin: 18px auto; padding: 0 14px; } }
        @media (max-width: 900px) { .correction-form { grid-template-columns: 1fr 1fr; } .correction-form .correction-button { grid-column: 1 / -1; justify-self: start; } }
    </style>
</head>
<body>
    <h1>Põhivara amortisatsioon</h1>
    <p class="muted">Vaata kuu amortisatsioon üle enne pearaamatu kande loomist. Kanne vähendab põhivara jääkmaksumust akumuleeritud kulumi kaudu ega muuda pangakontot.</p>
    <?php if ($postedEntry): ?><p class="notice success">Amortisatsioonikanne nr <?= (int) $postedEntry['id'] ?> loodi ja postitati.</p><?php endif; ?>
    <?php if ($correctedEntryId): ?><p class="notice success">Amortisatsioonikanne #<?= $correctedEntryId ?> parandati. Algne ajalugu säilitati ja selle kuu amortisatsiooni saab vajadusel uuesti arvestada.</p><?php endif; ?>
    <?php if ($actionError !== ''): ?><p class="notice error"><?= h($actionError) ?></p><?php endif; ?>
    <?php if (!$correctionTableReady): ?><p class="notice">Amortisatsioonikande parandamise funktsioon vajab andmebaasi lisatabelit. Juhendi järgi impordi fail <strong>pohivara/migration_005_depreciation_corrections.sql</strong>.</p><?php endif; ?>
    <?php foreach ($errors as $error): ?><p class="notice error"><?= h($error) ?></p><?php endforeach; ?>

    <section class="panel">
        <form method="get" class="toolbar">
            <div><label for="month">Arvestuskuu</label><input type="month" id="month" name="month" value="<?= h($month) ?>" max="<?= h($currentMonth) ?>" required></div>
            <button class="button" type="submit">Näita eelvaadet</button>
            <a class="button secondary" href="assets.php">Põhivara register</a>
            <a class="button secondary" href="index.php">Põhivara avalehele</a>
        </form>
        <p class="muted">Lineaarne kuuarvestus algab kasutusvalmis kuust. Kuusumma on (soetusmaksumus − lõppväärtus) / kasulik eluiga kuudes; viimase kuu summat korrigeeritakse ümarduse võrra. Enne esimest amortisatsioonikannet kinnita vara registris ja kontrolli, et algdokument ning pearaamatu soetuskanne klapivad.</p>
    </section>

    <section class="panel">
        <h2><?= h($month) ?> kuu eelvaade</h2>
        <?php if (!$preview['rows']): ?>
            <p class="muted">Kinnitatud põhivara pole veel amortisatsiooni arvestuseks. Ava register ja kinnita kontrollitud mustand.</p>
        <?php else: ?>
            <?php if ($monthAlreadyPosted): ?><p class="notice">Selle kuu kohta on juba kanne olemas. Uus kanne hõlmab ainult selles kuus seni arvestamata põhivara ega korda juba postitatud amortisatsiooni.</p><?php endif; ?>
            <?php if ($preview['blocked']): ?><p class="notice">Mõnel varal on varasem amortisatsioonikuu arvestamata. Kande saab luua alles pärast kuude järjestikku arvestamist.</p><?php endif; ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Vara nr ja nimetus</th><th>Kasutusvalmis</th><th>Soetusmaksumus</th><th>Lõppväärtus</th><th>Kulum kuu eest</th><th>Kulu konto</th><th>Akumuleeritud kulumi konto</th><th>Olek</th></tr></thead>
                <tbody><?php foreach ($preview['rows'] as $asset): ?>
                    <tr>
                        <td><?= h($asset['asset_number'] . ' — ' . $asset['name']) ?></td>
                        <td><?= h($asset['available_date']) ?></td>
                        <td class="amount"><?= number_format((float) $asset['acquisition_cost'], 2, ',', ' ') ?> €</td>
                        <td class="amount"><?= number_format((float) $asset['residual_value'], 2, ',', ' ') ?> €</td>
                        <td class="amount"><?= number_format($asset['amount_cents'] / 100, 2, ',', ' ') ?> €</td>
                        <td><?= h($asset['depreciation_expense_account_id'] . ' · ' . $asset['expense_code'] . ' — ' . $asset['expense_name']) ?></td>
                        <td><?= h($asset['accumulated_depreciation_account_id'] . ' · ' . $asset['accumulated_code'] . ' — ' . $asset['accumulated_name']) ?></td>
                        <td class="<?= $asset['eligible'] ? 'status' : '' ?>"><?= h($asset['period_message']) ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
                <tfoot><tr><th colspan="4">Kokku</th><th class="amount"><?= number_format($preview['total_cents'] / 100, 2, ',', ' ') ?> €</th><th colspan="3"></th></tr></tfoot>
            </table></div>
            <?php if ($preview['total_cents'] > 0 && !$preview['blocked'] && $month <= $currentMonth): ?>
                <form method="post" class="actions" onsubmit="return confirm('Loo selle kuu amortisatsioonikanne? Kanne postitatakse kohe pearaamatusse.');">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="month" value="<?= h($month) ?>">
                    <button class="button" type="submit">Loo ja postita amortisatsioonikanne</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php if ($correctableEntries || $correctionHistory): ?>
        <section class="panel">
            <h2><?= h($month) ?> amortisatsioonikande parandamine</h2>
            <p class="muted">Kasuta ainult siis, kui amortisatsioonikanne on pearaamatus valesti. Ava algne kanne, loo sellest täielik vastupidine paranduskande mustand ja kinnita see. Seejärel vali allpool vastukanne ning lisa põhjus. Programm kontrollib kontosid ja summasid ning lubab kuu uuesti arvestada. Parandused tehakse kronoloogilises järjekorras.</p>
            <?php if ($correctableEntries): ?>
                <?php foreach ($correctableEntries as $entry): $originalId = (int) $entry['id']; ?>
                    <form method="post" action="correct_depreciation.php" class="correction-form" onsubmit="return confirm('Kontrolli veel kord, et vastukanne pöörab valitud amortisatsioonikande täpselt ümber. Jätka?');">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="month" value="<?= h($month) ?>">
                        <input type="hidden" name="original_entry_id" value="<?= $originalId ?>">
                        <strong>Algne kanne <a href="../entry.php?id=<?= $originalId ?>">#<?= $originalId ?></a> · <?= h($entry['document_number']) ?></strong>
                        <span><?= (int) $entry['asset_count'] ?> vara · <?= number_format((float) $entry['total_amount'], 2, ',', ' ') ?> €</span>
                        <?php if ($reversalOptions[$originalId]): ?>
                            <div><label for="reversal-<?= $originalId ?>">Kinnitatud vastukanne</label>
                                <select id="reversal-<?= $originalId ?>" name="reversal_entry_id" required>
                                    <option value="">Vali vastukanne</option>
                                    <?php foreach ($reversalOptions[$originalId] as $candidate): ?>
                                        <option value="<?= (int) $candidate['id'] ?>">#<?= (int) $candidate['id'] ?> · <?= h($candidate['entry_date'] . ' · ' . ($candidate['document_number'] ?: $candidate['description'])) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div><label for="reason-<?= $originalId ?>">Paranduse põhjus</label>
                                <input id="reason-<?= $originalId ?>" name="reason" type="text" maxlength="500" required placeholder="Näiteks vale amortisatsioonisumma">
                            </div>
                            <button class="button correction-button" type="submit">Kinnita parandus</button>
                        <?php else: ?>
                            <p class="notice">Sellele kandele pole veel kinnitatud täpset vastukannet. Kui oled juba käsitsi vastukande loonud, kontrolli seda enne uue tegemist pearaamatus.</p>
                            <form method="post" action="../reverse_entry.php" class="draft-reversal-form" onsubmit="return confirm('Kas luua just sellest amortisatsioonikandest täpne vastukande mustand? Mustand tuleb enne kinnitamist üle kontrollida.');">
                                <input type="hidden" name="entry_id" value="<?= $originalId ?>">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                <button class="button" type="submit">Loo selle kande vastukande mustand</button>
                                <span class="muted">Avaneb kontrollimiseks mustand; pearaamatusse see veel ei postitu.</span>
                            </form>
                        <?php endif; ?>
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($correctionHistory): ?>
                <h3>Paranduste ajalugu</h3>
                <ul><?php foreach ($correctionHistory as $correction): ?>
                    <li>Algne kanne <a href="../entry.php?id=<?= (int) $correction['original_journal_entry_id'] ?>">#<?= (int) $correction['original_journal_entry_id'] ?></a> pöörati vastukandega <a href="../entry.php?id=<?= (int) $correction['reversal_journal_entry_id'] ?>">#<?= (int) $correction['reversal_journal_entry_id'] ?></a> kuupäeval <?= h($correction['reversal_date']) ?> ümber. <?= (int) $correction['asset_count'] ?> vara, <?= number_format((float) $correction['total_amount'], 2, ',', ' ') ?> €. Põhjus: <?= h($correction['reason']) ?></li>
                <?php endforeach; ?></ul>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</body>
</html>
