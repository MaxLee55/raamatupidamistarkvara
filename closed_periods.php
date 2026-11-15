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

$companyId = (int) $_SESSION['company_id'];
$userId = (int) $_SESSION['user_id'];
$errors = [];
$selectedMonth = (new DateTimeImmutable('first day of last month'))->format('Y-m');
$reopenReason = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedMonth = trim((string) ($_POST['period_month'] ?? ''));
    $action = (string) ($_POST['action'] ?? '');
    $reopenReason = trim((string) ($_POST['reason'] ?? ''));

    if (
        empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
    ) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    } elseif (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
        $errors[] = 'Vali korrektne kuu.';
    } else {
        $periodStart = $selectedMonth . '-01';
        $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $periodStart);

        if (!$startDate || $startDate->format('Y-m-d') !== $periodStart) {
            $errors[] = 'Valitud kuu ei ole kehtiv.';
        } else {
            $periodEnd = $startDate->modify('last day of this month')->format('Y-m-d');

            try {
                $latestStmt = $pdo->prepare(
                    'SELECT action
                     FROM accounting_period_events
                     WHERE company_id = ? AND period_start = ?
                     ORDER BY id DESC
                     LIMIT 1'
                );
                $latestStmt->execute([$companyId, $periodStart]);
                $latestAction = $latestStmt->fetchColumn();

                if ($action === 'close') {
                    if ($periodEnd >= date('Y-m-d')) {
                        throw new RuntimeException(
                            'Sulgeda saab ainult lõppenud kuud. Oota kuu lõpuni.'
                        );
                    }

                    if ($latestAction === 'closed') {
                        throw new RuntimeException('See kuu on juba suletud.');
                    }

                    $draftsStmt = $pdo->prepare(
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
                    $draftsStmt->execute([
                        $companyId, $periodStart, $periodEnd,
                        $companyId, $periodStart, $periodEnd,
                        $companyId, $periodStart, $periodEnd,
                    ]);
                    $draftCount = (int) $draftsStmt->fetchColumn();

                    if ($draftCount > 0) {
                        throw new RuntimeException(
                            'Selles kuus on ' . $draftCount
                            . ' kinnitamata mustandit. Kinnita või eemalda need enne kuu sulgemist.'
                        );
                    }

                    $insertStmt = $pdo->prepare(
                        "INSERT INTO accounting_period_events
                            (company_id, period_start, period_end, action, reason, changed_by)
                         VALUES (?, ?, ?, 'closed', NULL, ?)"
                    );
                    $insertStmt->execute([
                        $companyId,
                        $periodStart,
                        $periodEnd,
                        $userId,
                    ]);

                    header('Location: closed_periods.php?message=closed');
                    exit;
                }

                if ($action === 'reopen') {
                    if ($latestAction !== 'closed') {
                        throw new RuntimeException('See kuu ei ole praegu suletud.');
                    }

                    if (mb_strlen($reopenReason) < 5 || mb_strlen($reopenReason) > 500) {
                        throw new RuntimeException(
                            'Taasavamise põhjus peab olema 5 kuni 500 märki.'
                        );
                    }

                    $insertStmt = $pdo->prepare(
                        "INSERT INTO accounting_period_events
                            (company_id, period_start, period_end, action, reason, changed_by)
                         VALUES (?, ?, ?, 'reopened', ?, ?)"
                    );
                    $insertStmt->execute([
                        $companyId,
                        $periodStart,
                        $periodEnd,
                        $reopenReason,
                        $userId,
                    ]);

                    header('Location: closed_periods.php?message=reopened');
                    exit;
                }

                throw new RuntimeException('Tundmatu toiming.');
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            } catch (Throwable $e) {
                error_log($e->getMessage());
                $errors[] = 'Perioodi toiming ebaõnnestus.';
            }
        }
    }
}

$eventsStmt = $pdo->prepare(
    'SELECT e.period_start, e.period_end, e.action, e.reason, e.created_at,
            u.email AS changed_by_email
     FROM accounting_period_events e
     JOIN (
         SELECT period_start, MAX(id) AS latest_id
         FROM accounting_period_events
         WHERE company_id = ?
         GROUP BY period_start
     ) latest ON latest.latest_id = e.id
     JOIN users u ON u.id = e.changed_by
     WHERE e.company_id = ?
     ORDER BY e.period_start DESC
     LIMIT 36'
);
$eventsStmt->execute([$companyId, $companyId]);
$events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);
$message = (string) ($_GET['message'] ?? '');
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Perioodi sulgemine</title>
    <style>
        body {
            max-width: 1050px;
            margin: 36px auto;
            padding: 0 22px;
            background: #f4f7fb;
            color: #263746;
            font-family: Arial, sans-serif;
        }
        h1, h2 { color: #17324d; }
        .card {
            margin: 20px 0;
            padding: 22px;
            border: 1px solid #dce3ec;
            border-radius: 12px;
            background: #fff;
        }
        .form-row {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            align-items: end;
        }
        label {
            display: grid;
            gap: 7px;
            color: #33475b;
            font-weight: 600;
        }
        input {
            min-height: 42px;
            padding: 8px 11px;
            border: 1px solid #b8c4d1;
            border-radius: 7px;
            font: inherit;
        }
        button, .button {
            display: inline-block;
            min-height: 42px;
            padding: 10px 16px;
            border: 0;
            border-radius: 7px;
            background: #1769aa;
            color: #fff;
            font: inherit;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }
        button:hover, .button:hover { background: #0f4f83; }
        .secondary { background: #657789; }
        .danger { background: #b4232d; }
        .note, .success, .error {
            padding: 12px 15px;
            border-radius: 8px;
        }
        .note { background: #eaf3fb; color: #17324d; }
        .success { background: #eaf7ef; color: #176b3a; }
        .error { background: #fff0f0; color: #a32121; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 11px; border-bottom: 1px solid #e5eaf0; text-align: left; }
        th { background: #f3f6fa; }
        .muted { color: #667788; }
        @media (max-width: 650px) {
            body { margin: 20px auto; padding: 0 14px; }
        }
    </style>
</head>
<body>
    <h1>Perioodi sulgemine</h1>
    <p class="note">
        Sulgemine takistab uute mustandite ja kannete kinnitamist sellesse kuusse.
        Sulgeda saab ainult lõppenud kuu, kus pole kinnitamata kandeid ega arveid.
        Suletud kuu taasavamiseks tuleb lisada põhjus.
    </p>

    <?php foreach ($errors as $error): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endforeach; ?>

    <?php if ($message === 'closed'): ?>
        <p class="success">Kuu suleti.</p>
    <?php elseif ($message === 'reopened'): ?>
        <p class="success">Kuu avati uuesti.</p>
    <?php endif; ?>

    <section class="card">
        <h2>Sulge kuu</h2>
        <form method="post" class="form-row">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="close">
            <label>
                Kuu
                <input type="month" name="period_month" value="<?= h($selectedMonth) ?>" required>
            </label>
            <button type="submit" onclick="return confirm('Kas sulged selle kuu?')">
                Sulge kuu
            </button>
        </form>
    </section>

    <section class="card">
        <h2>Sulgemiste ajalugu</h2>
        <?php if (!$events): ?>
            <p class="muted">Ühtegi kuud pole veel suletud.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Periood</th>
                        <th>Olek</th>
                        <th>Viimane toiming</th>
                        <th>Põhjus</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td><?= h($event['period_start']) ?> kuni <?= h($event['period_end']) ?></td>
                            <td><?= $event['action'] === 'closed' ? 'Suletud' : 'Avatud' ?></td>
                            <td><?= h($event['created_at'] . ' — ' . $event['changed_by_email']) ?></td>
                            <td><?= h($event['reason'] ?? '') ?></td>
                            <td>
                                <?php if ($event['action'] === 'closed'): ?>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="action" value="reopen">
                                        <input type="hidden" name="period_month" value="<?= h(substr($event['period_start'], 0, 7)) ?>">
                                        <label>
                                            Taasavamise põhjus
                                            <input type="text" name="reason" maxlength="500" required>
                                        </label>
                                        <button class="danger" type="submit"
                                            onclick="return confirm('Kas avad kuu uuesti?')">
                                            Ava kuu uuesti
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
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