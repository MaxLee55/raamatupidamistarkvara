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
require_once __DIR__ . '/year_end_accounting.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$companyId = (int) $_SESSION['company_id'];
$userId = (int) $_SESSION['user_id'];
$errors = [];
$success = (string) ($_GET['message'] ?? '');
$previewRequested = false;
$formCheckKeys = [
    'bank_checked' => 'Pangakontod ja kassad on võrreldud',
    'receivables_checked' => 'Ostjate nõuded ja tarnijate võlad on üle vaadatud',
    'taxes_checked' => 'Maksud ja palgaarvestus on üle vaadatud',
    'assets_checked' => 'Varad, varud ja vajalikud aasta lõpu korrigeerimised on üle vaadatud',
];
$formChecks = [];
foreach ($formCheckKeys as $key => $_label) {
    $formChecks[$key] = !empty($_POST[$key]);
}
$formNotes = trim((string) ($_POST['notes'] ?? ''));
$previewPlan = null;
$today = new DateTimeImmutable('today');

$companyStmt = $pdo->prepare(
    'SELECT fiscal_year_start FROM companies WHERE id = ?'
);
$companyStmt->execute([$companyId]);
$fiscalYearStart = $companyStmt->fetchColumn();

if (!$fiscalYearStart) {
    exit('Ettevõtte majandusaasta algust ei leitud.');
}

$fiscalStart = new DateTimeImmutable((string) $fiscalYearStart);
$fiscalMonthDay = $fiscalStart->format('m-d');
$firstYear = (int) $fiscalStart->format('Y');
$currentYear = (int) $today->format('Y');
$currentFiscalYear = $today->format('m-d') >= $fiscalMonthDay
    ? $currentYear
    : $currentYear - 1;
$currentFiscalYear = max($firstYear, $currentFiscalYear);

$selectedYear = (int) ($_GET['year'] ?? $_POST['year'] ?? $currentFiscalYear);
if ($selectedYear < $firstYear || $selectedYear > $currentFiscalYear) {
    $selectedYear = $currentFiscalYear;
}

$periodStart = sprintf('%04d-%s', $selectedYear, $fiscalMonthDay);
$startDate = new DateTimeImmutable($periodStart);
$periodEnd = $startDate->modify('+1 year')->modify('-1 day')->format('Y-m-d');
$periodEnded = $periodEnd < $today->format('Y-m-d');

if ($fiscalStart->format('d') !== '01') {
    $errors[] = 'Aastavahetuse leht eeldab, et majandusaasta algab kuu esimesel päeval, sest kuude sulgemine toimub kalendrikuude kaupa.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (
        empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))
    ) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    } else {
        try {
            if ($action === 'close') {
                $pdo->beginTransaction();
                $companyLockStmt = $pdo->prepare('SELECT id FROM companies WHERE id = ? FOR UPDATE');
                $companyLockStmt->execute([$companyId]);
            }

            $latestStmt = $pdo->prepare(
                'SELECT action, reason
                 FROM accounting_year_events
                 WHERE company_id = ? AND period_start = ?
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $latestStmt->execute([$companyId, $periodStart]);
            $latestEvent = $latestStmt->fetch(PDO::FETCH_ASSOC);
            $latestAction = $latestEvent['action'] ?? null;

            if (in_array($action, ['preview', 'close'], true)) {
                if ($action === 'close') {
                    $submittedPreviewToken = (string) ($_POST['preview_token'] ?? '');
                    if (
                        empty($_SESSION['year_end_preview_token'])
                        || !hash_equals((string) $_SESSION['year_end_preview_token'], $submittedPreviewToken)
                    ) {
                        throw new RuntimeException('Enne kinnitamist koosta uus sulgemise eelvaade.');
                    }
                }
                if (!$periodEnded) {
                    throw new RuntimeException('Aasta saab lõpetatuks märkida alles pärast majandusaasta viimast päeva.');
                }
                if ($latestAction === 'closed') {
                    throw new RuntimeException('See majandusaasta on juba lõpetatuks märgitud.');
                }

                $confirmed = [];
                foreach ($formCheckKeys as $key => $label) {
                    if (!empty($_POST[$key])) {
                        $confirmed[] = $label;
                    }
                }
                if (count($confirmed) !== count($formCheckKeys)) {
                    throw new RuntimeException('Enne lõpetatuks märkimist kinnita kõik kontroll-loendi punktid.');
                }

                if (mb_strlen($formNotes) > 1000) {
                    throw new RuntimeException('Märkused võivad olla kuni 1000 märki.');
                }

                $monthStatesStmt = $pdo->prepare(
                    'SELECT period_start, action
                     FROM accounting_period_events
                     WHERE company_id = ? AND period_start >= ? AND period_start <= ?
                     ORDER BY id'
                );
                $monthStatesStmt->execute([$companyId, $periodStart, $periodEnd]);
                $monthStates = [];
                foreach ($monthStatesStmt->fetchAll(PDO::FETCH_ASSOC) as $monthEvent) {
                    $monthStates[$monthEvent['period_start']] = $monthEvent['action'];
                }

                $missingMonths = [];
                $month = $startDate;
                for ($i = 0; $i < 12; $i++) {
                    $monthStart = $month->modify('first day of this month')->format('Y-m-d');
                    if (($monthStates[$monthStart] ?? null) !== 'closed') {
                        $missingMonths[] = $month->format('m.Y');
                    }
                    $month = $month->modify('first day of next month');
                }
                if ($missingMonths) {
                    throw new RuntimeException(
                        'Kõik 12 kuud peavad olema suletud. Veel sulgemata: ' . implode(', ', $missingMonths) . '.'
                    );
                }

                $draftStmt = $pdo->prepare(
                    "SELECT
                        (SELECT COUNT(*) FROM journal_entries
                         WHERE company_id = ? AND status = 'draft'
                           AND entry_date BETWEEN ? AND ?)
                      + (SELECT COUNT(*) FROM sales_invoices
                         WHERE company_id = ? AND status = 'draft'
                           AND invoice_date BETWEEN ? AND ?)
                      + (SELECT COUNT(*) FROM purchase_invoices
                         WHERE company_id = ? AND status = 'draft'
                           AND invoice_date BETWEEN ? AND ?)"
                );
                $draftStmt->execute([
                    $companyId, $periodStart, $periodEnd,
                    $companyId, $periodStart, $periodEnd,
                    $companyId, $periodStart, $periodEnd,
                ]);
                if ((int) $draftStmt->fetchColumn() > 0) {
                    throw new RuntimeException('Majandusaastas on mustandeid. Kinnita või eemalda need enne lõpetamist.');
                }

                $unbalancedStmt = $pdo->prepare(
                    'SELECT COUNT(*)
                     FROM (
                         SELECT je.id
                         FROM journal_entries je
                         LEFT JOIN journal_lines jl ON jl.entry_id = je.id
                         WHERE je.company_id = ?
                           AND je.status = ?
                           AND je.entry_date BETWEEN ? AND ?
                         GROUP BY je.id
                         HAVING COUNT(jl.id) < 2 OR COALESCE(SUM(jl.debit), 0) <> COALESCE(SUM(jl.credit), 0)
                     ) unbalanced_entries'
                );
                $unbalancedStmt->execute([$companyId, 'posted', $periodStart, $periodEnd]);
                if ((int) $unbalancedStmt->fetchColumn() > 0) {
                    throw new RuntimeException('Leiti tasakaalustamata kinnitatud kanne. Kontrolli kanded enne aasta lõpetamist üle.');
                }

                $previewPlan = yearEndBuildPlan($pdo, $companyId, $periodStart, $periodEnd);
                if ($previewPlan['errors']) {
                    throw new RuntimeException(implode(' ', $previewPlan['errors']));
                }

                if ($action === 'preview') {
                    $previewRequested = true;
                    $_SESSION['year_end_preview_token'] = bin2hex(random_bytes(32));
                } else {
                    $nextStart = $previewPlan['opening_date'];
                    $closingEntryId = yearEndInsertPostedEntry(
                        $pdo,
                        $companyId,
                        $userId,
                        $periodEnd,
                        'Majandusaasta tulude ja kulude sulgemine ' . $selectedYear,
                        $previewPlan['closing_document'],
                        $previewPlan['closing_lines']
                    );
                    $openingEntryId = yearEndInsertPostedEntry(
                        $pdo,
                        $companyId,
                        $userId,
                        $nextStart,
                        'Majandusaasta algsaldod ' . ($selectedYear + 1),
                        $previewPlan['opening_document'],
                        $previewPlan['opening_lines']
                    );

                    $reason = "Kontroll-loend kinnitatud: " . implode('; ', $confirmed);
                    $reason .= "\nSulgemiskanne: " . ($closingEntryId ? '#' . $closingEntryId : 'vajalik sulgemiskanne puudus');
                    $reason .= "\nAlgsaldode kanne: " . ($openingEntryId ? '#' . $openingEntryId : 'bilanss oli null');
                    if ($notes !== '') {
                        $reason .= "\nMärkused: " . $formNotes;
                    }

                    $insertStmt = $pdo->prepare(
                        "INSERT INTO accounting_year_events
                            (company_id, period_start, period_end, action, reason, changed_by)
                         VALUES (?, ?, ?, 'closed', ?, ?)"
                    );
                    $insertStmt->execute([$companyId, $periodStart, $periodEnd, $reason, $userId]);
                    $pdo->commit();
                    unset($_SESSION['year_end_preview_token']);

                    header('Location: year_end.php?year=' . $selectedYear . '&message=closed');
                    exit;
                }
            }

            if ($action === 'reopen') {
                if ($latestAction !== 'closed') {
                    throw new RuntimeException('See majandusaasta ei ole lõpetatuks märgitud.');
                }
                $reason = trim((string) ($_POST['reason'] ?? ''));
                if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
                    throw new RuntimeException('Taasavamise põhjus peab olema 5 kuni 500 märki.');
                }

                $pdo->beginTransaction();
                $companyLockStmt = $pdo->prepare('SELECT id FROM companies WHERE id = ? FOR UPDATE');
                $companyLockStmt->execute([$companyId]);
                $reversalIds = [];
                if (preg_match('/Sulgemiskanne: #(\d+)/u', (string) ($latestEvent['reason'] ?? ''), $match)) {
                    $reversalIds[] = ['id' => (int) $match[1], 'kind' => 'SULG'];
                }
                if (preg_match('/Algsaldode kanne: #(\d+)/u', (string) ($latestEvent['reason'] ?? ''), $match)) {
                    $reversalIds[] = ['id' => (int) $match[1], 'kind' => 'ALG'];
                }
                $reversalEntryIds = [];
                foreach ($reversalIds as $reversal) {
                    $originalId = $reversal['id'];
                    $originalStmt = $pdo->prepare(
                        'SELECT id, entry_date, description
                         FROM journal_entries
                         WHERE id = ? AND company_id = ? AND status = \'posted\'
                         FOR UPDATE'
                    );
                    $originalStmt->execute([$originalId, $companyId]);
                    $original = $originalStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$original) {
                        throw new RuntimeException('Aastavahetuse algset kannet ei leitud; aastat ei avatud.');
                    }
                    $linesStmt = $pdo->prepare(
                        'SELECT account_id, description, debit, credit FROM journal_lines WHERE entry_id = ?'
                    );
                    $linesStmt->execute([$originalId]);
                    $reversalLines = [];
                    foreach ($linesStmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
                        $debit = yearEndCents((string) $line['debit']);
                        $credit = yearEndCents((string) $line['credit']);
                        $reversalLines[] = [
                            'account_id' => (int) $line['account_id'],
                            'description' => 'Pöördkirje: ' . (string) ($line['description'] ?? ''),
                            'debit_cents' => $credit,
                            'credit_cents' => $debit,
                        ];
                    }
                    $reversalDocument = yearEndNextDocumentNumber(
                        $pdo,
                        $companyId,
                        'AA-TAGASI-' . $reversal['kind'] . '-' . $selectedYear
                    );
                    $reversalId = yearEndInsertPostedEntry(
                        $pdo,
                        $companyId,
                        $userId,
                        (string) $original['entry_date'],
                        'Aastavahetuse kande #' . $originalId . ' pööramine',
                        $reversalDocument,
                        $reversalLines
                    );
                    if ($reversalId !== null) {
                        $reversalEntryIds[] = $reversalId;
                    }
                }
                if ($reversalEntryIds) {
                    $reason .= ' (automaatsed pöördkanded #' . implode(', #', $reversalEntryIds) . ')';
                }

                $insertStmt = $pdo->prepare(
                    "INSERT INTO accounting_year_events
                        (company_id, period_start, period_end, action, reason, changed_by)
                     VALUES (?, ?, ?, 'reopened', ?, ?)"
                );
                $insertStmt->execute([$companyId, $periodStart, $periodEnd, $reason, $userId]);
                $pdo->commit();

                header('Location: year_end.php?year=' . $selectedYear . '&message=reopened');
                exit;
            }

            throw new RuntimeException('Tundmatu toiming.');
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log($e->getMessage());
            $errors[] = 'Aastavahetuse toiming ebaõnnestus.';
        }
    }
}

$monthStatesStmt = $pdo->prepare(
    'SELECT period_start, action
     FROM accounting_period_events
     WHERE company_id = ? AND period_start >= ? AND period_start <= ?
     ORDER BY id'
);
$monthStatesStmt->execute([$companyId, $periodStart, $periodEnd]);
$monthStates = [];
foreach ($monthStatesStmt->fetchAll(PDO::FETCH_ASSOC) as $monthEvent) {
    $monthStates[$monthEvent['period_start']] = $monthEvent['action'];
}
$months = [];
$month = $startDate;
for ($i = 0; $i < 12; $i++) {
    $monthStart = $month->modify('first day of this month')->format('Y-m-d');
    $months[] = [
        'label' => $month->format('m.Y'),
        'closed' => ($monthStates[$monthStart] ?? null) === 'closed',
    ];
    $month = $month->modify('first day of next month');
}
$closedMonthCount = count(array_filter($months, function ($item) {
    return $item['closed'];
}));

$draftStmt = $pdo->prepare(
    "SELECT
        (SELECT COUNT(*) FROM journal_entries
         WHERE company_id = ? AND status = 'draft'
           AND entry_date BETWEEN ? AND ?)
      + (SELECT COUNT(*) FROM sales_invoices
         WHERE company_id = ? AND status = 'draft'
           AND invoice_date BETWEEN ? AND ?)
      + (SELECT COUNT(*) FROM purchase_invoices
         WHERE company_id = ? AND status = 'draft'
           AND invoice_date BETWEEN ? AND ?)"
);
$draftStmt->execute([
    $companyId, $periodStart, $periodEnd,
    $companyId, $periodStart, $periodEnd,
    $companyId, $periodStart, $periodEnd,
]);
$draftCount = (int) $draftStmt->fetchColumn();

$unbalancedStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM (
         SELECT je.id
         FROM journal_entries je
         LEFT JOIN journal_lines jl ON jl.entry_id = je.id
         WHERE je.company_id = ?
           AND je.status = ?
           AND je.entry_date BETWEEN ? AND ?
         GROUP BY je.id
         HAVING COUNT(jl.id) < 2 OR COALESCE(SUM(jl.debit), 0) <> COALESCE(SUM(jl.credit), 0)
     ) unbalanced_entries'
);
$unbalancedStmt->execute([$companyId, 'posted', $periodStart, $periodEnd]);
$unbalancedCount = (int) $unbalancedStmt->fetchColumn();

$latestStmt = $pdo->prepare(
    'SELECT action, reason, created_at
     FROM accounting_year_events
     WHERE company_id = ? AND period_start = ?
     ORDER BY id DESC
     LIMIT 1'
);
$latestStmt->execute([$companyId, $periodStart]);
$yearEvent = $latestStmt->fetch(PDO::FETCH_ASSOC);
$isClosed = $yearEvent && $yearEvent['action'] === 'closed';

$historyStmt = $pdo->prepare(
    'SELECT e.period_start, e.period_end, e.action, e.reason, e.created_at,
            u.email AS changed_by_email
     FROM accounting_year_events e
     JOIN (
         SELECT period_start, MAX(id) AS latest_id
         FROM accounting_year_events
         WHERE company_id = ?
         GROUP BY period_start
     ) latest ON latest.latest_id = e.id
     JOIN users u ON u.id = e.changed_by
     WHERE e.company_id = ?
     ORDER BY e.period_start DESC
     LIMIT 20'
);
$historyStmt->execute([$companyId, $companyId]);
$history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$years = [];
for ($year = $firstYear; $year <= $currentFiscalYear; $year++) {
    $years[] = $year;
}
$planErrors = [];
$displayPlan = null;
if (!$isClosed) {
    try {
        $displayPlan = yearEndBuildPlan($pdo, $companyId, $periodStart, $periodEnd);
        $planErrors = $displayPlan['errors'];
    } catch (Throwable $e) {
        error_log($e->getMessage());
        $planErrors[] = 'Sulgemise eelvaate arvutamine ebaõnnestus.';
    }
}
$canPrepare = $periodEnded && $closedMonthCount === 12 && $draftCount === 0
    && $unbalancedCount === 0 && !$isClosed && !$errors && !$planErrors;
$previewReady = $previewRequested && $previewPlan !== null && !$errors;

?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Aastavahetus</title>
    <style>
        body { max-width: 1100px; margin: 36px auto; padding: 0 22px; background: #f4f7fb; color: #263746; font-family: Arial, sans-serif; }
        h1, h2 { color: #17324d; }
        .card { margin: 20px 0; padding: 22px; border: 1px solid #dce3ec; border-radius: 12px; background: #fff; }
        .row { display: flex; flex-wrap: wrap; gap: 14px; align-items: end; }
        label { display: grid; gap: 7px; color: #33475b; font-weight: 600; }
        select, input, textarea { min-height: 42px; padding: 8px 11px; border: 1px solid #b8c4d1; border-radius: 7px; font: inherit; }
        textarea { min-height: 80px; width: min(100%, 650px); }
        button, .button { display: inline-block; min-height: 42px; padding: 10px 16px; border: 0; border-radius: 7px; background: #1769aa; color: #fff; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
        button:hover, .button:hover { background: #0f4f83; }
        .secondary { background: #657789; }
        .danger { background: #b4232d; }
        .note, .success, .error { padding: 12px 15px; border-radius: 8px; }
        .note { background: #eaf3fb; color: #17324d; }
        .success { background: #eaf7ef; color: #176b3a; }
        .error { background: #fff0f0; color: #a32121; }
        .muted { color: #667788; }
        .checks { display: grid; gap: 12px; margin: 18px 0; }
        .checks label { display: flex; align-items: flex-start; gap: 10px; font-weight: 400; }
        .checks input { min-height: 18px; margin-top: 2px; }
        .months { display: grid; grid-template-columns: repeat(4, minmax(120px, 1fr)); gap: 10px; margin: 16px 0; }
        .month { padding: 12px; border-radius: 8px; background: #fff0f0; color: #9d2525; }
        .month.closed { background: #eaf7ef; color: #176b3a; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 11px; border-bottom: 1px solid #e5eaf0; text-align: left; vertical-align: top; }
        th { background: #f3f6fa; }
        @media (max-width: 650px) { body { margin: 20px auto; padding: 0 14px; } .months { grid-template-columns: repeat(2, 1fr); } }
    </style>
</head>
<body>
    <h1>Aastavahetus</h1>
    <p class="note">
        Siin saad enne majandusaasta lõpetatuks märkimist kontrollida aruandeid ja sulgemisi.
        Esmalt vaata üle sulgemiskande ja uue aasta algsaldode eelvaade. Alles kinnitamisel luuakse kanded pearaamatusse.
    </p>

    <?php foreach ($errors as $error): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endforeach; ?>

    <?php if ($success === 'closed'): ?>
        <p class="success">Majandusaasta toimingud salvestati pearaamatusse ning aasta märgiti lõpetatuks.</p>
    <?php elseif ($success === 'reopened'): ?>
        <p class="success">Majandusaasta märgiti uuesti avatuks. Programmi loodud sulgemis- ja algsaldo kanded pöörati tagasi; suletud kuud tuleb vajadusel eraldi avada perioodi sulgemise lehel.</p>
    <?php endif; ?>

    <section class="card">
        <h2>Vali majandusaasta</h2>
        <form method="get" class="row">
            <label>
                Majandusaasta algusaasta
                <select name="year">
                    <?php foreach ($years as $year): ?>
                        <option value="<?= (int) $year ?>" <?= $year === $selectedYear ? 'selected' : '' ?>>
                            <?= (int) $year ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Näita</button>
        </form>
        <p><strong>Periood:</strong> <?= h($periodStart) ?> kuni <?= h($periodEnd) ?></p>
        <p>
            <a class="button secondary" href="trial_balance.php?start_date=<?= h($periodStart) ?>&amp;end_date=<?= h($periodEnd) ?>">Käibeandmik</a>
            <a class="button secondary" href="income_statement.php?start_date=<?= h($periodStart) ?>&amp;end_date=<?= h($periodEnd) ?>">Kasumiaruanne</a>
            <a class="button secondary" href="balance_sheet.php?as_of_date=<?= h($periodEnd) ?>">Bilanss</a>
        </p>
    </section>

    <section class="card">
        <h2>Kontroll enne lõpetamist</h2>
        <?php if ($fiscalStart->format('d') === '01'): ?>
            <div class="months">
                <?php foreach ($months as $item): ?>
                    <div class="month <?= $item['closed'] ? 'closed' : '' ?>">
                        <?= h($item['label']) ?> — <?= $item['closed'] ? 'Suletud' : 'Avatud' ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p><strong>Suletud kuud:</strong> <?= $closedMonthCount ?>/12</p>
        <p><strong>Mustandeid majandusaastas:</strong> <?= $draftCount ?></p>
        <p><strong>Tasakaalustamata kinnitatud kandeid:</strong> <?= $unbalancedCount ?></p>
        <?php if (!$periodEnded): ?>
            <p class="note">Majandusaasta lõpeb <?= h($periodEnd) ?>. Lõpetatuks saab selle märkida pärast seda kuupäeva.</p>
        <?php endif; ?>
    </section>

    <?php if (!$isClosed): ?>
        <section class="card">
            <h2>Valmista majandusaasta sulgemine ette</h2>
            <p class="muted">Kinnitused on käsitsi tehtavad kontrollid; rakendus ei kontrolli nende sisu automaatselt.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="year" value="<?= (int) $selectedYear ?>">
                <input type="hidden" name="action" value="preview">
                <div class="checks">
                    <label><input type="checkbox" name="bank_checked" value="1" required <?= $formChecks['bank_checked'] ? 'checked' : '' ?>> Pangakontode ja kassa saldod on väljavõtetega võrreldud.</label>
                    <label><input type="checkbox" name="receivables_checked" value="1" required <?= $formChecks['receivables_checked'] ? 'checked' : '' ?>> Ostjate nõuded ja tarnijate võlad on üle vaadatud.</label>
                    <label><input type="checkbox" name="taxes_checked" value="1" required <?= $formChecks['taxes_checked'] ? 'checked' : '' ?>> Maksud ja palgaarvestus on üle vaadatud.</label>
                    <label><input type="checkbox" name="assets_checked" value="1" required <?= $formChecks['assets_checked'] ? 'checked' : '' ?>> Varad, varud ja vajalikud aasta lõpu korrigeerimised on üle vaadatud.</label>
                </div>
                <label>
                    Märkused (valikuline)
                    <textarea name="notes" maxlength="1000"><?= h($formNotes) ?></textarea>
                </label>
                <p><button type="submit" <?= $canPrepare ? '' : 'disabled' ?>>Valmista sulgemine ette</button></p>
                <?php if (!$canPrepare): ?>
                    <p class="muted">Eelvaate nupp aktiveerub, kui aasta on läbi, kõik kuud suletud, mustandid puuduvad, kanded klapivad ja algsaldo saab arvutada.</p>
                <?php endif; ?>
            </form>
            <?php foreach ($planErrors as $planError): ?>
                <p class="error"><?= h($planError) ?></p>
            <?php endforeach; ?>
            <?php if ($previewReady): ?>
                <div class="note">
                    <strong>Kasumiaruande tulem:</strong>
                    <?php if ($previewPlan['result_net_debit'] < 0): ?>
                        kasum <?= number_format(abs($previewPlan['result_net_debit']) / 100, 2, ',', ' ') ?> €
                    <?php elseif ($previewPlan['result_net_debit'] > 0): ?>
                        kahjum <?= number_format($previewPlan['result_net_debit'] / 100, 2, ',', ' ') ?> €
                    <?php else: ?>
                        0,00 €
                    <?php endif; ?>
                </div>
                <h3>Sulgemiskanne · <?= h($previewPlan['closing_document']) ?> · <?= h($periodEnd) ?></h3>
                <?php if ($previewPlan['closing_lines']): ?>
                    <table><thead><tr><th>Konto</th><th>Deebet</th><th>Kreedit</th></tr></thead><tbody>
                    <?php foreach ($previewPlan['closing_lines'] as $line): ?>
                        <tr><td><?= h($line['account_label']) ?></td><td><?= number_format($line['debit_cents'] / 100, 2, ',', ' ') ?> €</td><td><?= number_format($line['credit_cents'] / 100, 2, ',', ' ') ?> €</td></tr>
                    <?php endforeach; ?>
                    <tr><th>Kokku</th><th><?= number_format($previewPlan['closing_debit'] / 100, 2, ',', ' ') ?> €</th><th><?= number_format($previewPlan['closing_credit'] / 100, 2, ',', ' ') ?> €</th></tr>
                    </tbody></table>
                <?php else: ?><p>Perioodis pole tulude ja kulude saldosid, mida sulgeda.</p><?php endif; ?>
                <h3>Uue majandusaasta algsaldo · <?= h($previewPlan['opening_document']) ?> · <?= h($previewPlan['opening_date']) ?></h3>
                <?php if ($previewPlan['opening_lines']): ?>
                    <table><thead><tr><th>Konto</th><th>Deebet</th><th>Kreedit</th></tr></thead><tbody>
                    <?php foreach ($previewPlan['opening_lines'] as $line): ?>
                        <tr><td><?= h($line['account_label']) ?></td><td><?= number_format($line['debit_cents'] / 100, 2, ',', ' ') ?> €</td><td><?= number_format($line['credit_cents'] / 100, 2, ',', ' ') ?> €</td></tr>
                    <?php endforeach; ?>
                    <tr><th>Kokku</th><th><?= number_format($previewPlan['opening_debit'] / 100, 2, ',', ' ') ?> €</th><th><?= number_format($previewPlan['opening_credit'] / 100, 2, ',', ' ') ?> €</th></tr>
                    </tbody></table>
                <?php else: ?><p>Perioodi lõpu bilansisaldod on nullis; algkannet ei ole vaja.</p><?php endif; ?>
                <p class="note">Kinnitamisel postitatakse ülal nähtavad kanded ja majandusaasta lukustatakse. Tulu ega kulu ei kanta automaatselt kontole 3020.</p>
                <form method="post" class="row">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="year" value="<?= (int) $selectedYear ?>">
                    <input type="hidden" name="action" value="close">
                    <input type="hidden" name="preview_token" value="<?= h((string) ($_SESSION['year_end_preview_token'] ?? '')) ?>">
                    <?php foreach ($formChecks as $key => $checked): if ($checked): ?>
                        <input type="hidden" name="<?= h($key) ?>" value="1">
                    <?php endif; endforeach; ?>
                    <input type="hidden" name="notes" value="<?= h($formNotes) ?>">
                    <button type="submit" class="danger" onclick="return confirm('Kinnitamisel luuakse pearaamatusse sulgemiskanne ja järgmise aasta algsaldo. Kas jätkan?')">Kinnita aastavahetus</button>
                </form>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <section class="card">
            <h2>Majandusaasta on lõpetatuks märgitud</h2>
            <p><?= nl2br(h((string) $yearEvent['reason'])) ?></p>
            <p class="muted">Märgitud <?= h($yearEvent['created_at']) ?>. Aasta uuesti avamisel pööratakse programmi loodud kanded tagasi, kuid suletud kuud tuleb eraldi avada.</p>
            <form method="post" class="row">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="year" value="<?= (int) $selectedYear ?>">
                <input type="hidden" name="action" value="reopen">
                <label>
                    Taasavamise põhjus
                    <input type="text" name="reason" maxlength="500" required>
                </label>
                <button class="danger" type="submit" onclick="return confirm('Kas avad majandusaasta uuesti?')">Ava aasta uuesti</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="card">
        <h2>Aastavahetuste ajalugu</h2>
        <?php if (!$history): ?>
            <p class="muted">Aastavahetuse toiminguid pole veel salvestatud.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Periood</th><th>Olek</th><th>Kuupäev ja kasutaja</th><th>Märkus</th></tr></thead>
                <tbody>
                <?php foreach ($history as $item): ?>
                    <tr>
                        <td><?= h($item['period_start']) ?> kuni <?= h($item['period_end']) ?></td>
                        <td><?= $item['action'] === 'closed' ? 'Lõpetatuks märgitud' : 'Uuesti avatud' ?></td>
                        <td><?= h($item['created_at'] . ' — ' . $item['changed_by_email']) ?></td>
                        <td><?= nl2br(h((string) ($item['reason'] ?? ''))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <p><a class="button secondary" href="settings.php">Tagasi seadetesse</a></p>
    <p><a class="button" href="index.php">Tagasi avalehele</a></p>
</body>
</html>
