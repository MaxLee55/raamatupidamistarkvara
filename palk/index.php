<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: ../login.php');
    exit;
}

// The existing application does not yet have shared permission checks.
// Employee identifiers are sensitive, so limit this first version to admins.
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Palgamoodulile on ligipääs ainult administraatoril.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__.'/bank_details.php';
ensurePayrollBankSchema($pdo);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$companyId = (int) $_SESSION['company_id'];
$errors = [];
$notice = '';
$form = [
    'first_name' => '',
    'last_name' => '',
    'personal_code' => '',
    'birth_date' => '',
    'email' => '',
    'bank_iban' => '', 'bank_name' => '', 'bank_bic' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
    ) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    }
    $action = (string) ($_POST['action'] ?? 'add_employee');
    if (!$errors && in_array($action, ['delete_employee', 'delete_test_employee_with_history', 'delete_employee_with_history'], true)) {
        $employeeId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$employeeId || $employeeId < 1) {
            $errors[] = 'Töötaja tunnus ei ole kehtiv.';
        } else {
            try {
                $pdo->beginTransaction();
                $employeeStmt = $pdo->prepare('SELECT id, first_name, last_name, is_test_data FROM payroll_employees WHERE id = ? AND company_id = ? FOR UPDATE');
                $employeeStmt->execute([$employeeId, $companyId]);
                $employee = $employeeStmt->fetch();
                if (!$employee) {
                    throw new RuntimeException('Töötajat ei leitud.');
                }
                $historyStmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM payroll_run_items pri
                     JOIN payroll_employments pe ON pe.id = pri.employment_id AND pe.company_id = pri.company_id
                     WHERE pri.company_id = ? AND pe.employee_id = ?'
                );
                $historyStmt->execute([$companyId, $employeeId]);
                $hasHistory = (int) $historyStmt->fetchColumn() > 0;
                if ($hasHistory && ($_POST['confirm_test_data'] ?? '') !== 'yes') {
                    throw new RuntimeException('Kinnita töötaja ja kõigi temaga seotud palgaarvestuse andmete kustutamine.');
                }
                $affectedRuns = [];
                if ($hasHistory) {
                    $itemStmt = $pdo->prepare(
                        'SELECT pri.id, pri.run_id FROM payroll_run_items pri
                         JOIN payroll_employments pe ON pe.id = pri.employment_id AND pe.company_id = pri.company_id
                         WHERE pri.company_id = ? AND pe.employee_id = ? FOR UPDATE'
                    );
                    $itemStmt->execute([$companyId, $employeeId]);
                    foreach ($itemStmt->fetchAll() as $row) {
                        $itemId = (int) $row['id'];
                        $affectedRunId = (int) $row['run_id'];
                        $affectedRuns[$affectedRunId] = true;

                        foreach (['payroll_run_item_calculations', 'payroll_run_item_payments', 'payroll_run_item_components'] as $table) {
                            $deleteChild = $pdo->prepare("DELETE FROM {$table} WHERE company_id = ? AND run_id = ? AND run_item_id = ?");
                            $deleteChild->execute([$companyId, $affectedRunId, $itemId]);
                        }
                        $deleteItem = $pdo->prepare('DELETE FROM payroll_run_items WHERE id = ? AND company_id = ? AND run_id = ?');
                        $deleteItem->execute([$itemId, $companyId, $affectedRunId]);
                    }
                    foreach (array_keys($affectedRuns) as $affectedRunId) {
                        $remaining = $pdo->prepare('SELECT COUNT(*) FROM payroll_run_items WHERE company_id = ? AND run_id = ?');
                        $remaining->execute([$companyId, $affectedRunId]);
                        if ((int) $remaining->fetchColumn() === 0) {
                            $deleteConfirmation = $pdo->prepare('DELETE FROM payroll_run_confirmations WHERE company_id = ? AND run_id = ?');
                            $deleteConfirmation->execute([$companyId, $affectedRunId]);
                            $deleteRun = $pdo->prepare('DELETE FROM payroll_runs WHERE company_id = ? AND id = ?');
                            $deleteRun->execute([$companyId, $affectedRunId]);
                        }
                    }
                }
                $employmentStmt = $pdo->prepare('SELECT id FROM payroll_employments WHERE company_id = ? AND employee_id = ?');
                $employmentStmt->execute([$companyId, $employeeId]);
                $employmentIds = array_map('intval', $employmentStmt->fetchAll(PDO::FETCH_COLUMN));
                foreach ($employmentIds as $employmentId) {
                    foreach (['payroll_employment_events', 'payroll_employment_closures', 'payroll_employment_suspensions', 'payroll_employment_terms'] as $table) {
                        $deleteChild = $pdo->prepare("DELETE FROM {$table} WHERE company_id = ? AND employment_id = ?");
                        $deleteChild->execute([$companyId, $employmentId]);
                    }
                }
                $deleteEmployments = $pdo->prepare('DELETE FROM payroll_employments WHERE company_id = ? AND employee_id = ?');
                $deleteEmployments->execute([$companyId, $employeeId]);
                $deleteSettings = $pdo->prepare('DELETE FROM payroll_employee_tax_settings WHERE company_id = ? AND employee_id = ?');
                $deleteSettings->execute([$companyId, $employeeId]);
                $deleteEmployee = $pdo->prepare('DELETE FROM payroll_employees WHERE id = ? AND company_id = ?');
                $deleteEmployee->execute([$employeeId, $companyId]);
                $pdo->commit();
                $_SESSION['payroll_employee_notice'] = $employee['first_name'] . ' ' . $employee['last_name']
                    . ($hasHistory ? ' ja kõik temaga seotud palgaarvestuse andmed kustutati.' : ' ja tema alusandmed kustutati.');
                header('Location: index.php');
                exit;
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = $e->getMessage();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Palgamooduli töötaja kustutamine ebaõnnestus: ' . $e->getMessage());
                $errors[] = 'Töötaja kustutamine ebaõnnestus.';
            }
        }
    } elseif (!$errors && $action === 'update_employee_bank') {
        $employeeId=filter_var($_POST['employee_id']??null,FILTER_VALIDATE_INT);
        try {
            if(!$employeeId || $employeeId<1) throw new InvalidArgumentException('Vigane töötaja tunnus.');
            updatePayrollBankDetails($pdo,$companyId,$employeeId,$_POST);
            $_SESSION['payroll_employee_notice']='Töötaja pangarekvisiidid salvestati.';
            header('Location: index.php'); exit;
        } catch(InvalidArgumentException $e) { $errors[]=$e->getMessage(); }
          catch(Throwable $e) { error_log($e->getMessage()); $errors[]='Pangarekvisiitide salvestamine ebaõnnestus. Andmeid ei muudetud.'; }
    } elseif (!$errors && $action === 'update_employee_email') {
        $employeeId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
        $email = trim((string) ($_POST['email'] ?? ''));
        if (!$employeeId || $employeeId < 1) {
            $errors[] = 'Töötaja tunnus ei ole kehtiv.';
        } elseif ($email === '') {
            $errors[] = 'Sisesta uus e-posti aadress. Tühja lahtri salvestamine olemasolevat aadressi ei muuda.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || payrollTextLength($email) > 254) {
            $errors[] = 'Sisesta kehtiv e-posti aadress (kuni 254 märki).';
        } else {
            $updateEmail = $pdo->prepare('UPDATE payroll_employees SET email = ? WHERE id = ? AND company_id = ?');
            $updateEmail->execute([$email !== '' ? $email : null, $employeeId, $companyId]);
            $_SESSION['payroll_employee_notice'] = 'Töötaja e-posti aadress salvestati.';
            header('Location: index.php');
            exit;
        }
    } elseif (!$errors && $action === 'add_employee') {
        foreach (array_keys($form) as $field) {
            $form[$field] = trim((string) ($_POST[$field] ?? ''));
        }
        try { $form=array_merge($form,validatePayrollBankDetails($form)); } catch(InvalidArgumentException $e) { $errors[]=$e->getMessage(); }
        if ($form['email'] !== '' && (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || payrollTextLength($form['email']) > 254)) {
            $errors[] = 'Sisesta kehtiv e-posti aadress (kuni 254 märki).';
        }
        if ($form['first_name'] === '' || payrollTextLength($form['first_name']) > 100) {
            $errors[] = 'Eesnimi on kohustuslik ja võib olla kuni 100 märki.';
        }
        if ($form['last_name'] === '' || payrollTextLength($form['last_name']) > 100) {
            $errors[] = 'Perekonnanimi on kohustuslik ja võib olla kuni 100 märki.';
        }
        if ($form['personal_code'] !== '' && !preg_match('/^[0-9]{11}$/', $form['personal_code'])) {
            $errors[] = 'Isikukood peab olema 11 numbrit.';
        }
        if ($form['birth_date'] !== '') {
            $birthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $form['birth_date']);
            if ($birthDate === false || $birthDate->format('Y-m-d') !== $form['birth_date']) {
                $errors[] = 'Sünnikuupäev ei ole kehtiv.';
            }
        }
        if ($form['personal_code'] === '' && $form['birth_date'] === '') {
            $errors[] = 'Kui isikukood puudub, on sünnikuupäev vajalik töötaja tuvastamiseks.';
        }
    } elseif (!$errors) {
        $errors[] = 'Tundmatu toiming.';
    }

    if (!$errors && $action === 'add_employee') {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO payroll_employees
                    (company_id, first_name, last_name, email, personal_code, birth_date, bank_iban,bank_name,bank_bic,is_active, is_test_data, created_by)
                 VALUES
                    (:company_id, :first_name, :last_name, :email, :personal_code, :birth_date,:bank_iban,:bank_name,:bank_bic,1,0,:created_by)'
            );
            $stmt->execute([
                'company_id' => $companyId,
                'first_name' => $form['first_name'],
                'last_name' => $form['last_name'],
                'email' => $form['email'] !== '' ? $form['email'] : null,
                'personal_code' => $form['personal_code'] !== '' ? $form['personal_code'] : null,
                'birth_date' => $form['birth_date'] !== '' ? $form['birth_date'] : null,
                'bank_iban'=>$form['bank_iban']?:null,'bank_name'=>$form['bank_name']?:null,'bank_bic'=>$form['bank_bic']?:null,
                'created_by' => (int) $_SESSION['user_id'],
            ]);
            $form = ['first_name' => '', 'last_name' => '', 'personal_code' => '', 'birth_date' => '', 'email' => '', 'bank_iban'=>'','bank_name'=>'','bank_bic'=>''];
            $notice = 'Töötaja lisati.';
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'Selle isikukoodiga töötaja on selles ettevõttes juba olemas.';
            } else {
                error_log('Palgamooduli töötaja lisamine ebaõnnestus: ' . $e->getMessage());
                $errors[] = 'Töötaja salvestamine ebaõnnestus.';
            }
        }
    }
}

$notice = $_SESSION['payroll_employee_notice'] ?? $notice;
unset($_SESSION['payroll_employee_notice']);
$listStmt = $pdo->prepare(
    'SELECT e.id, e.first_name, e.last_name, e.email,e.bank_iban,e.bank_name,e.bank_bic,e.is_active, e.created_at,
            EXISTS (
                SELECT 1 FROM payroll_employments pe
                JOIN payroll_run_items pri ON pri.employment_id = pe.id AND pri.company_id = pe.company_id
                WHERE pe.company_id = e.company_id AND pe.employee_id = e.id
            ) AS has_payroll_history
     FROM payroll_employees
     e WHERE e.company_id = :company_id
     ORDER BY e.last_name, e.first_name, e.id'
);
$listStmt->execute(['company_id' => $companyId]);
$employees = $listStmt->fetchAll();
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Töötajad | Palgamoodul</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px; background: #f4f7fb; color: #243447; font-family: Arial, sans-serif; }
        main { max-width: 1250px; margin: 0 auto; }
        header, .panel { margin-bottom: 20px; padding: 22px; background: #fff; border: 1px solid #dce3ec; border-radius: 12px; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        h1, h2 { margin-top: 0; color: #17324d; }
        .muted { color: #68798b; line-height: 1.5; }
        .fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        label { display: block; color: #33475b; font-size: 14px; font-weight: 600; }
        input { display: block; width: 100%; min-height: 42px; margin-top: 6px; padding: 9px 11px; border: 1px solid #b8c4d1; border-radius: 7px; font: inherit; }
        button, .button { display: inline-block; min-height: 40px; padding: 10px 14px; border: 0; border-radius: 7px; background: #1769aa; color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .actions { margin-top: 16px; }
        .message { padding: 12px 14px; border-radius: 7px; }
        .success { background: #e9f6ed; color: #205c31; }
        .error { background: #fdecec; color: #8d2222; }
        ul { margin: 0; }
        .table-wrap { overflow-x:auto; } details { margin-top:8px; } summary { color:#1769aa;cursor:pointer; } details label { margin:10px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 11px 9px; border-bottom: 1px solid #e2e8ef; text-align: left; }
        th { color: #526579; font-size: 13px; }
        @media (max-width: 640px) { body { padding: 14px; } header { align-items: flex-start; flex-direction: column; } .fields { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <h1>Palgamoodul · Töötajad</h1>
            <div class="muted">Töötajate register ja palgaarvestuse alusandmed.</div>
        </div>
        <div>
            <a class="button" href="runs.php">Palgaperioodid</a>
            <a class="button" href="employments.php">Töösuhted</a>
            <a class="button" href="../index.php">Tagasi raamatupidamisse</a>
        </div>
    </header>

    <?php if ($notice !== ''): ?><p class="message success"><?= h($notice) ?></p><?php endif; ?>
    <?php if ($errors): ?>
        <div class="message error"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <section class="panel">
        <h2>Lisa töötaja</h2>
        <p class="muted">Isikukood ja sünnikuupäev on tundlikud isikuandmed. Sünnikuupäeva küsi ainult siis, kui isikukood puudub.</p>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="add_employee">
            <div class="fields">
                <label>Eesnimi<input name="first_name" maxlength="100" required value="<?= h($form['first_name']) ?>"></label>
                <label>Perekonnanimi<input name="last_name" maxlength="100" required value="<?= h($form['last_name']) ?>"></label>
                <label>Isikukood<input name="personal_code" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" value="<?= h($form['personal_code']) ?>"></label>
                <label>Sünnikuupäev, kui isikukood puudub<input type="date" name="birth_date" value="<?= h($form['birth_date']) ?>"></label>
                <label>E-post palgateatise saatmiseks<input type="email" name="email" maxlength="254" value="<?= h($form['email']) ?>"></label>
                <label>Konto nr (IBAN)<input name="bank_iban" maxlength="42" autocomplete="off" value="<?= h($form['bank_iban']) ?>" placeholder="EE…"></label>
                <label>Panga nimi<input name="bank_name" maxlength="120" value="<?= h($form['bank_name']) ?>"></label>
                <label>BIC / SWIFT<input name="bank_bic" maxlength="11" value="<?= h($form['bank_bic']) ?>" autocapitalize="characters"></label>
            </div>
            <div class="actions"><button type="submit">Salvesta töötaja</button></div>
        </form>
    </section>

    <section class="panel">
        <h2>Töötajad (<?= count($employees) ?>)</h2>
        <?php if (!$employees): ?>
            <p class="muted">Töötajaid pole veel lisatud.</p>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Nimi</th><th>E-post</th><th>Pangarekvisiidid</th><th>Staatus</th><th>Lisatud</th><th>Toimingud</th></tr></thead>
                <tbody>
                <?php foreach ($employees as $employee): ?>
                    <tr>
                        <td><?= h($employee['first_name'] . ' ' . $employee['last_name']) ?></td>
                        <td>
                            <small style="display:block;margin-bottom:5px;color:#647589">Salvestatud: <?= h((string) ($employee['email'] ?? '—')) ?></small>
                            <form method="post" autocomplete="off" style="display:flex;gap:6px;min-width:220px">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="update_employee_email">
                                <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                                <input type="email" name="email" maxlength="254" autocomplete="off" value="" placeholder="sisesta uus e-post" style="min-width:0">
                                <button type="submit">Salvesta</button>
                            </form>
                        </td>
                        <td><span class="muted"><?= h($employee['bank_iban']?:'Lisamata') ?></span><details><summary>Muuda pangarekvisiite</summary>
                            <form method="post" autocomplete="off" style="min-width:230px;margin-top:12px">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="update_employee_bank"><input type="hidden" name="employee_id" value="<?= (int)$employee['id'] ?>">
                                <?php $bankValues=(($_POST['action']??'')==='update_employee_bank' && (int)($_POST['employee_id']??0)===(int)$employee['id'])?$_POST:$employee; ?>
                                <label>Konto nr (IBAN)<input name="bank_iban" maxlength="42" value="<?= h((string)($bankValues['bank_iban']??'')) ?>"></label>
                                <label>Panga nimi<input name="bank_name" maxlength="120" value="<?= h((string)($bankValues['bank_name']??'')) ?>"></label>
                                <label>BIC / SWIFT<input name="bank_bic" maxlength="11" value="<?= h((string)($bankValues['bank_bic']??'')) ?>"></label>
                                <button type="submit" style="margin-top:10px">Salvesta pangarekvisiidid</button>
                            </form></details></td>
                        <td><?= (int) $employee['is_active'] === 1 ? 'Aktiivne' : 'Mitteaktiivne' ?></td>
                        <td><?= h(substr((string) $employee['created_at'], 0, 10)) ?></td>
                        <td>
                            <a href="tax-settings.php?employee_id=<?= (int) $employee['id'] ?>">Maksuseaded</a>
                            <?php if ((int) $employee['has_payroll_history'] === 1): ?>
                                <form method="post" style="display:inline-block;margin-left:10px" onsubmit="return confirm('Kas kustutad töötaja ja kõik tema palgaread, maksuarvutused, palgalehed, maksete kuupäevad ning töösuhteandmed jäädavalt?')">
                                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="delete_employee_with_history">
                                    <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                                    <input type="hidden" name="confirm_test_data" value="yes">
                                    <button type="submit" style="margin-left:8px;background:#9e3434">Kustuta</button>
                                </form>
                            <?php else: ?>
                                <form method="post" style="display:inline-block;margin-left:10px" onsubmit="return confirm('Kas kustutan töötaja ning tema töösuhete ja maksuseadete andmed jäädavalt?')">
                                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="delete_employee">
                                    <input type="hidden" name="employee_id" value="<?= (int) $employee['id'] ?>">
                                    <button type="submit" style="background:#9e3434">Kustuta</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
