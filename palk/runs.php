<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: ../login.php');
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Palgamoodulile on ligipääs ainult administraatoril.');
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once dirname(__DIR__) . '/db.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function validDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

$companyId = (int) $_SESSION['company_id'];
$errors = [];
$notice = $_SESSION['payroll_run_notice'] ?? '';
unset($_SESSION['payroll_run_notice']);
$form = ['work_month' => date('Y-m'), 'planned_payment_date' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
    ) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    }
    $form['work_month'] = trim((string) ($_POST['work_month'] ?? ''));
    $form['planned_payment_date'] = trim((string) ($_POST['planned_payment_date'] ?? ''));
    $monthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $form['work_month'] . '-01');
    if ($monthDate === false || $monthDate->format('Y-m') !== $form['work_month']) {
        $errors[] = 'Vali kehtiv arvestuskuu.';
    }
    if (!validDate($form['planned_payment_date'])) {
        $errors[] = 'Kavandatud väljamakse kuupäev ei ole kehtiv.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO payroll_runs
                (company_id, work_month, planned_payment_date, status, created_by)
             VALUES (:company_id, :work_month, :planned_payment_date, \'draft\', :created_by)'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'work_month' => $monthDate->format('Y-m-d'),
            'planned_payment_date' => $form['planned_payment_date'],
            'created_by' => (int) $_SESSION['user_id'],
        ]);
        $runId = (int) $pdo->lastInsertId();
        header('Location: run.php?id=' . $runId);
        exit;
    }
}

$runsStmt = $pdo->prepare(
    'SELECT pr.id, pr.work_month, pr.planned_payment_date, pr.status, pr.created_at,
            COUNT(pri.id) AS employee_count, COALESCE(SUM(pri.gross_amount), 0) AS gross_total,
            (SELECT COUNT(*) FROM payroll_run_item_payments pp
             WHERE pp.company_id = pr.company_id AND pp.run_id = pr.id) AS paid_count
     FROM payroll_runs pr
     LEFT JOIN payroll_run_items pri
       ON pri.run_id = pr.id AND pri.company_id = pr.company_id
     WHERE pr.company_id = :company_id
     GROUP BY pr.id, pr.work_month, pr.planned_payment_date, pr.status, pr.created_at
     ORDER BY pr.work_month DESC, pr.planned_payment_date DESC, pr.id DESC'
);
$runsStmt->execute(['company_id' => $companyId]);
$runs = $runsStmt->fetchAll();
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Palgaperioodid | Palgamoodul</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px; background: #f4f7fb; color: #243447; font-family: Arial, sans-serif; }
        main { max-width: 1100px; margin: 0 auto; }
        header, .panel { margin-bottom: 20px; padding: 22px; background: #fff; border: 1px solid #dce3ec; border-radius: 12px; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        h1, h2 { margin-top: 0; color: #17324d; }
        .muted { color: #68798b; line-height: 1.5; }
        .fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        label { display: block; color: #33475b; font-size: 14px; font-weight: 600; }
        input { display: block; width: 100%; min-height: 42px; margin-top: 6px; padding: 9px 11px; border: 1px solid #b8c4d1; border-radius: 7px; font: inherit; }
        button, .button { display: inline-block; min-height: 40px; padding: 10px 14px; border: 0; border-radius: 7px; background: #1769aa; color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .actions { margin-top: 16px; }
        .start-payroll { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; }
        .header-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 0; }
        .message { padding: 12px 14px; border-radius: 7px; }
        .error { background: #fdecec; color: #8d2222; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 11px 9px; border-bottom: 1px solid #e2e8ef; text-align: left; }
        th { color: #526579; font-size: 13px; }
        @media (max-width: 700px) { body { padding: 14px; } header { align-items: flex-start; flex-direction: column; } .fields { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<main>
    <header><div><h1>Palgamoodul · Palgaperioodid</h1><div class="muted">Mustand talletab arvestuskuu ja kavandatud väljamakse kuupäeva. Summad on brutotasud; makse ei arvutata.</div></div><div class="header-actions"><a class="button" href="accounting-settings.php">Palgakontode seadistus</a><a class="button" href="tax-payment.php">Registreeri MTA maksude tasumine</a><a class="button" href="index.php">Tagasi töötajate juurde</a></div></header>
    <?php if ($errors): ?><div class="message error"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <section class="panel">
        <h2>Loo palgaperioodi mustand</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <div class="fields">
                <label>Arvestuskuu<input type="month" name="work_month" required value="<?= h($form['work_month']) ?>"></label>
                <label>Kavandatud väljamakse kuupäev<input type="date" name="planned_payment_date" required value="<?= h($form['planned_payment_date']) ?>"></label>
            </div>
            <div class="actions start-payroll"><button type="submit" aria-describedby="start-payroll-hint">Loo mustand</button><span class="muted" id="start-payroll-hint">Alusta palgaarvestust siit</span></div>
        </form>
    </section>

    <section class="panel">
        <h2>Koosta TSD XBRL GL fail</h2>
        <p class="muted">Vali kuu, millal palgad tegelikult välja maksti. Faili lähevad selle kuu kinnitatud ja makstuks märgitud palgaread, millel on salvestatud maksuarvestus. Proovitöötajate andmeid faili ei lisata. Praegu toetab eksport tavalist töölepingut ja Eesti isikukoodiga töötajat. Fail laaditakse sinu arvutisse; e-MTAsse seda automaatselt ei saadeta.</p>
        <p class="muted">Enne e-MTAsse laadimist kontrolli summad üle. Ära laadi sama kuud mitu korda üles, sest sama väljamakse korduv esitamine võib tuua duplikaadi vea.</p>
        <form method="get" action="tsd_export.php" class="fields">
            <label>Väljamakse kuu<input type="month" name="month" required value="<?= h(date('Y-m')) ?>"></label>
            <div class="actions"><button type="submit">Laadi alla TSD XBRL GL fail</button></div>
        </form>
    </section>

    <section class="panel">
        <h2>Palgaperioodid (<?= count($runs) ?>)</h2>
        <?php if (!$runs): ?><p class="muted">Palgaperioode pole veel loodud.</p><?php else: ?>
            <table><thead><tr><th>Arvestuskuu</th><th>Kavandatud väljamakse</th><th>Töötajaid</th><th>Brutotasu kokku</th><th>Staatus</th><th></th></tr></thead><tbody>
            <?php foreach ($runs as $run): ?><tr>
                <td><?= h(substr($run['work_month'], 0, 7)) ?></td>
                <td><?= h($run['planned_payment_date']) ?></td>
                <td><?= (int) $run['employee_count'] ?></td>
                <td><?= h(number_format((float) $run['gross_total'], 2, ',', ' ')) ?> €</td>
                <td><?= $run['status'] === 'draft' ? 'Mustand' : ($run['status'] === 'confirmed' ? 'Kinnitatud · ' . (int) $run['paid_count'] . '/' . (int) $run['employee_count'] . ' makstud' : 'Makstud') ?></td>
                <td><a href="run.php?id=<?= (int) $run['id'] ?>">Ava</a></td>
            </tr><?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
