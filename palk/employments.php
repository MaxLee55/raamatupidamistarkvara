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
$notice = '';
$form = [
    'employee_id' => '',
    'started_on' => date('Y-m-d'),
    'work_time_rate' => '1.00',
    'job_title' => '',
    'workplace_address' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
    ) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    }

    foreach (array_keys($form) as $field) {
        $form[$field] = trim((string) ($_POST[$field] ?? ''));
    }
    $form['work_time_rate'] = str_replace(',', '.', $form['work_time_rate']);

    $employeeId = filter_var($form['employee_id'], FILTER_VALIDATE_INT);
    if (!$employeeId || $employeeId < 1) {
        $errors[] = 'Vali töötaja.';
    }
    if (!validDate($form['started_on'])) {
        $errors[] = 'Töötamise alguskuupäev ei ole kehtiv.';
    }
    if (!preg_match('/^(?:0\.[0-9]{1,2}|1(?:\.0{1,2})?)$/', $form['work_time_rate'])) {
        $errors[] = 'Tööaja määr peab olema vahemikus 0,01 kuni 1,00 (täistööaeg on 1,00).';
    }
    if ($form['job_title'] === '' || mb_strlen($form['job_title']) > 250) {
        $errors[] = 'Ametinimetus on kohustuslik ja võib olla kuni 250 märki.';
    }
    if ($form['workplace_address'] === '' || mb_strlen($form['workplace_address']) > 500) {
        $errors[] = 'Töökoha aadress on kohustuslik ja võib olla kuni 500 märki.';
    }

    if (!$errors) {
        $employeeStmt = $pdo->prepare(
            'SELECT id FROM payroll_employees
             WHERE id = :employee_id AND company_id = :company_id AND is_active = 1'
        );
        $employeeStmt->execute(['employee_id' => $employeeId, 'company_id' => $companyId]);
        if (!$employeeStmt->fetchColumn()) {
            $errors[] = 'Valitud töötajat ei leitud või ta ei ole aktiivne.';
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $employmentStmt = $pdo->prepare(
                'INSERT INTO payroll_employments
                    (company_id, employee_id, employment_type_code, started_on, created_by)
                 VALUES (:company_id, :employee_id, 1, :started_on, :created_by)'
            );
            $employmentStmt->execute([
                'company_id' => $companyId,
                'employee_id' => $employeeId,
                'started_on' => $form['started_on'],
                'created_by' => (int) $_SESSION['user_id'],
            ]);
            $employmentId = (int) $pdo->lastInsertId();

            $termsStmt = $pdo->prepare(
                'INSERT INTO payroll_employment_terms
                    (company_id, employment_id, valid_from, work_time_rate,
                     job_title, workplace_address, created_by)
                 VALUES
                    (:company_id, :employment_id, :valid_from, :work_time_rate,
                     :job_title, :workplace_address, :created_by)'
            );
            $termsStmt->execute([
                'company_id' => $companyId,
                'employment_id' => $employmentId,
                'valid_from' => $form['started_on'],
                'work_time_rate' => $form['work_time_rate'],
                'job_title' => $form['job_title'],
                'workplace_address' => $form['workplace_address'],
                'created_by' => (int) $_SESSION['user_id'],
            ]);
            $pdo->commit();
            $notice = 'Töösuhe lisati.';
            $form = [
                'employee_id' => '',
                'started_on' => date('Y-m-d'),
                'work_time_rate' => '1.00',
                'job_title' => '',
                'workplace_address' => '',
            ];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Palgamooduli töösuhte lisamine ebaõnnestus: ' . $e->getMessage());
            $errors[] = 'Töösuhte salvestamine ebaõnnestus.';
        }
    }
}

$employeesStmt = $pdo->prepare(
    'SELECT id, first_name, last_name FROM payroll_employees
     WHERE company_id = :company_id AND is_active = 1
     ORDER BY last_name, first_name, id'
);
$employeesStmt->execute(['company_id' => $companyId]);
$employees = $employeesStmt->fetchAll();

$employmentListStmt = $pdo->prepare(
    'SELECT pe.id, pe.started_on, pe.ended_on,
            CASE
                WHEN pe.ended_on IS NOT NULL THEN \'Lõppenud\'
                WHEN EXISTS (
                    SELECT 1 FROM payroll_employment_suspensions pes
                    WHERE pes.company_id = pe.company_id
                      AND pes.employment_id = pe.id
                      AND pes.ended_on IS NULL
                ) THEN \'Peatatud\'
                ELSE \'Kehtiv\'
            END AS employment_status,
            e.first_name, e.last_name,
            pet.work_time_rate, pet.job_title, pet.workplace_address
     FROM payroll_employments pe
     JOIN payroll_employees e
       ON e.id = pe.employee_id AND e.company_id = pe.company_id
     LEFT JOIN payroll_employment_terms pet
       ON pet.employment_id = pe.id AND pet.company_id = pe.company_id
      AND pet.valid_until IS NULL
     WHERE pe.company_id = :company_id
     ORDER BY pe.started_on DESC, e.last_name, e.first_name'
);
$employmentListStmt->execute(['company_id' => $companyId]);
$employments = $employmentListStmt->fetchAll();
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Töösuhted | Palgamoodul</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px; background: #f4f7fb; color: #243447; font-family: Arial, sans-serif; }
        main { max-width: 1150px; margin: 0 auto; }
        header, .panel { margin-bottom: 20px; padding: 22px; background: #fff; border: 1px solid #dce3ec; border-radius: 12px; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        h1, h2 { margin-top: 0; color: #17324d; }
        .muted { color: #68798b; line-height: 1.5; }
        .fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        label { display: block; color: #33475b; font-size: 14px; font-weight: 600; }
        input, select { display: block; width: 100%; min-height: 42px; margin-top: 6px; padding: 9px 11px; border: 1px solid #b8c4d1; border-radius: 7px; font: inherit; }
        button, .button { display: inline-block; min-height: 40px; padding: 10px 14px; border: 0; border-radius: 7px; background: #1769aa; color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .actions { margin-top: 16px; }
        .message { padding: 12px 14px; border-radius: 7px; }
        .success { background: #e9f6ed; color: #205c31; }
        .error { background: #fdecec; color: #8d2222; }
        ul { margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 11px 9px; border-bottom: 1px solid #e2e8ef; text-align: left; vertical-align: top; }
        th { color: #526579; font-size: 13px; }
        @media (max-width: 760px) { body { padding: 14px; } header { align-items: flex-start; flex-direction: column; } .fields { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <h1>Palgamoodul · Töösuhted</h1>
            <div class="muted">Töölepingu alusel töötamine (TÖR-i töötamise liik 1). Maksuarvestust siin veel ei tehta.</div>
        </div>
        <a class="button" href="index.php">Tagasi töötajate juurde</a>
    </header>

    <?php if ($notice !== ''): ?><p class="message success"><?= h($notice) ?></p><?php endif; ?>
    <?php if ($errors): ?>
        <div class="message error"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <section class="panel">
        <h2>Lisa töösuhe</h2>
        <?php if (!$employees): ?>
            <p class="muted">Lisa enne töötaja isikuandmed töötajate registri lehel.</p>
        <?php else: ?>
            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <div class="fields">
                    <label>Töötaja
                        <select name="employee_id" required>
                            <option value="">Vali töötaja</option>
                            <?php foreach ($employees as $employee): ?>
                                <option value="<?= (int) $employee['id'] ?>" <?= $form['employee_id'] === (string) $employee['id'] ? 'selected' : '' ?>><?= h($employee['first_name'] . ' ' . $employee['last_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Töötamise alguskuupäev<input type="date" name="started_on" required value="<?= h($form['started_on']) ?>"></label>
                    <label>Tööaja määr<input name="work_time_rate" inputmode="decimal" required value="<?= h($form['work_time_rate']) ?>" aria-describedby="rate-help"></label>
                    <label>Ametinimetus<input name="job_title" maxlength="250" required value="<?= h($form['job_title']) ?>"></label>
                    <label>Töökoha aadress<input name="workplace_address" maxlength="500" required value="<?= h($form['workplace_address']) ?>"></label>
                </div>
                <p id="rate-help" class="muted">MTA järgi on täistööaja määr 1,00; näiteks 0,50 tähendab poole kohaga tööd. Aadress salvestatakse praegu tekstina.</p>
                <div class="actions"><button type="submit">Salvesta töösuhe</button></div>
            </form>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Töösuhted (<?= count($employments) ?>)</h2>
        <?php if (!$employments): ?>
            <p class="muted">Töösuhteid pole veel lisatud.</p>
        <?php else: ?>
            <table>
                <thead><tr><th>Töötaja</th><th>Algus</th><th>Staatus</th><th>Tööaja määr</th><th>Ametinimetus</th><th>Haldus</th></tr></thead>
                <tbody>
                <?php foreach ($employments as $employment): ?>
                    <tr>
                        <td><?= h($employment['first_name'] . ' ' . $employment['last_name']) ?></td>
                        <td><?= h($employment['started_on']) ?></td>
                        <td><?= h($employment['employment_status']) ?></td>
                        <td><?= $employment['work_time_rate'] === null ? 'Andmed puuduvad' : h(number_format((float) $employment['work_time_rate'], 2, ',', '')) ?></td>
                        <td><?= h($employment['job_title'] ?? '') ?></td>
                        <td><a href="manage.php?id=<?= (int) $employment['id'] ?>">Ava</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
