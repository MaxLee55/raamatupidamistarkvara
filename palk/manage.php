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
$employmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$employmentId || $employmentId < 1) {
    http_response_code(404);
    exit('Töösuhet ei leitud.');
}

$flash = $_SESSION['payroll_employment_notice'] ?? '';
unset($_SESSION['payroll_employment_notice']);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
    ) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        try {
            $pdo->beginTransaction();
            $lockStmt = $pdo->prepare(
                'SELECT id, started_on, ended_on FROM payroll_employments
                 WHERE id = :employment_id AND company_id = :company_id FOR UPDATE'
            );
            $lockStmt->execute(['employment_id' => $employmentId, 'company_id' => $companyId]);
            $lockedEmployment = $lockStmt->fetch();
            if (!$lockedEmployment) {
                throw new RuntimeException('Töösuhet ei leitud.');
            }
            if ($lockedEmployment['ended_on'] !== null && $action !== 'reopen') {
                throw new RuntimeException('Lõppenud töösuhet enam muuta ei saa.');
            }
            if ($lockedEmployment['ended_on'] === null && $action === 'reopen') {
                throw new RuntimeException('Töösuhe on juba kehtiv.');
            }

            if ($action === 'reopen') {
                $closureStmt = $pdo->prepare(
                    'SELECT ended_on, basis_code FROM payroll_employment_closures
                     WHERE company_id = ? AND employment_id = ? FOR UPDATE'
                );
                $closureStmt->execute([$companyId, $employmentId]);
                $closure = $closureStmt->fetch();
                $endedOn = $closure['ended_on'] ?? $lockedEmployment['ended_on'];
                $basisCode = $closure['basis_code'] ?? null;
                $oldEndEventStmt = $pdo->prepare(
                    'SELECT id FROM payroll_employment_events
                     WHERE company_id = ? AND employment_id = ? AND event_type = \'ended\' AND event_date = ? LIMIT 1'
                );
                $oldEndEventStmt->execute([$companyId, $employmentId, $endedOn]);
                if (!$oldEndEventStmt->fetchColumn()) {
                    $eventStmt = $pdo->prepare(
                        'INSERT INTO payroll_employment_events
                            (company_id, employment_id, event_type, event_date, basis_code, created_by)
                         VALUES (?, ?, \'ended\', ?, ?, ?)'
                    );
                    $eventStmt->execute([$companyId, $employmentId, $endedOn, $basisCode, (int) $_SESSION['user_id']]);
                }
                $termStmt = $pdo->prepare(
                    'UPDATE payroll_employment_terms SET valid_until = NULL
                     WHERE company_id = ? AND employment_id = ? AND valid_until = ?'
                );
                $termStmt->execute([$companyId, $employmentId, $endedOn]);
                $reopenStmt = $pdo->prepare(
                    'UPDATE payroll_employments SET ended_on = NULL
                     WHERE id = ? AND company_id = ? AND ended_on IS NOT NULL'
                );
                $reopenStmt->execute([$employmentId, $companyId]);
                $clearClosureStmt = $pdo->prepare(
                    'DELETE FROM payroll_employment_closures WHERE company_id = ? AND employment_id = ?'
                );
                $clearClosureStmt->execute([$companyId, $employmentId]);
                $eventStmt = $pdo->prepare(
                    'INSERT INTO payroll_employment_events
                        (company_id, employment_id, event_type, event_date, created_by)
                     VALUES (?, ?, \'reopened\', ?, ?)'
                );
                $eventStmt->execute([$companyId, $employmentId, date('Y-m-d'), (int) $_SESSION['user_id']]);
                $_SESSION['payroll_employment_notice'] = 'Töösuhe avati uuesti. Varasem lõpetamine jäi ajalukku.';
            } elseif ($action === 'change_terms') {
                $effectiveDate = trim((string) ($_POST['valid_from'] ?? ''));
                $rate = str_replace(',', '.', trim((string) ($_POST['work_time_rate'] ?? '')));
                $jobTitle = trim((string) ($_POST['job_title'] ?? ''));
                $address = trim((string) ($_POST['workplace_address'] ?? ''));
                if (!validDate($effectiveDate) || $effectiveDate > date('Y-m-d')) {
                    throw new RuntimeException('Muudatuse jõustumise kuupäev ei ole kehtiv.');
                }
                if (!preg_match('/^(?:0\.[0-9]{1,2}|1(?:\.0{1,2})?)$/', $rate)) {
                    throw new RuntimeException('Tööaja määr peab olema vahemikus 0,01 kuni 1,00.');
                }
                if ($jobTitle === '' || mb_strlen($jobTitle) > 250) {
                    throw new RuntimeException('Ametinimetus on kohustuslik ja võib olla kuni 250 märki.');
                }
                if ($address === '' || mb_strlen($address) > 500) {
                    throw new RuntimeException('Töökoha aadress on kohustuslik ja võib olla kuni 500 märki.');
                }
                $currentTermsStmt = $pdo->prepare(
                    'SELECT id, valid_from FROM payroll_employment_terms
                     WHERE company_id = ? AND employment_id = ? AND valid_until IS NULL
                     ORDER BY valid_from DESC LIMIT 1 FOR UPDATE'
                );
                $currentTermsStmt->execute([$companyId, $employmentId]);
                $currentTerms = $currentTermsStmt->fetch();
                if (!$currentTerms || $effectiveDate <= $currentTerms['valid_from']) {
                    throw new RuntimeException('Muudatuse kuupäev peab olema pärast kehtiva tingimuse alguskuupäeva.');
                }
                $previousDay = (new DateTimeImmutable($effectiveDate))->modify('-1 day')->format('Y-m-d');
                $closeTermsStmt = $pdo->prepare(
                    'UPDATE payroll_employment_terms SET valid_until = ?
                     WHERE id = ? AND company_id = ? AND employment_id = ? AND valid_until IS NULL'
                );
                $closeTermsStmt->execute([$previousDay, $currentTerms['id'], $companyId, $employmentId]);
                $newTermsStmt = $pdo->prepare(
                    'INSERT INTO payroll_employment_terms
                        (company_id, employment_id, valid_from, work_time_rate,
                         job_title, workplace_address, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $newTermsStmt->execute([
                    $companyId, $employmentId, $effectiveDate, $rate,
                    $jobTitle, $address, (int) $_SESSION['user_id'],
                ]);
                $_SESSION['payroll_employment_notice'] = 'Uued töötingimused salvestati ajalooga.';
            } elseif ($action === 'suspend') {
                $startedOn = trim((string) ($_POST['started_on'] ?? ''));
                $basisCode = trim((string) ($_POST['basis_code'] ?? ''));
                if (!validDate($startedOn) || $startedOn < $lockedEmployment['started_on'] || $startedOn > date('Y-m-d')) {
                    throw new RuntimeException('Peatamise kuupäev ei ole kehtiv.');
                }
                if (!preg_match('/^[A-Za-z0-9_-]{1,16}$/', $basisCode)) {
                    throw new RuntimeException('Sisesta MTA klassifikaatorile vastav peatamise aluse kood.');
                }
                $openStmt = $pdo->prepare(
                    'SELECT id FROM payroll_employment_suspensions
                     WHERE company_id = ? AND employment_id = ? AND ended_on IS NULL FOR UPDATE'
                );
                $openStmt->execute([$companyId, $employmentId]);
                if ($openStmt->fetchColumn()) {
                    throw new RuntimeException('Töösuhe on juba peatatud.');
                }
                $lastStmt = $pdo->prepare(
                    'SELECT ended_on FROM payroll_employment_suspensions
                     WHERE company_id = ? AND employment_id = ?
                     ORDER BY started_on DESC LIMIT 1 FOR UPDATE'
                );
                $lastStmt->execute([$companyId, $employmentId]);
                $lastEndedOn = $lastStmt->fetchColumn();
                if ($lastEndedOn && $startedOn <= $lastEndedOn) {
                    throw new RuntimeException('Uus peatamine peab algama pärast viimase peatamise lõppu.');
                }
                $insertStmt = $pdo->prepare(
                    'INSERT INTO payroll_employment_suspensions
                        (company_id, employment_id, started_on, basis_code, created_by)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $insertStmt->execute([$companyId, $employmentId, $startedOn, $basisCode, (int) $_SESSION['user_id']]);
                $_SESSION['payroll_employment_notice'] = 'Peatamise kanne salvestati.';
            } elseif ($action === 'resume') {
                $suspensionId = filter_var($_POST['suspension_id'] ?? null, FILTER_VALIDATE_INT);
                $endedOn = trim((string) ($_POST['ended_on'] ?? ''));
                if (!$suspensionId || !validDate($endedOn)) {
                    throw new RuntimeException('Peatamise lõppkuupäev ei ole kehtiv.');
                }
                $suspensionStmt = $pdo->prepare(
                    'SELECT started_on FROM payroll_employment_suspensions
                     WHERE id = ? AND company_id = ? AND employment_id = ? AND ended_on IS NULL FOR UPDATE'
                );
                $suspensionStmt->execute([$suspensionId, $companyId, $employmentId]);
                $suspensionStart = $suspensionStmt->fetchColumn();
                if (!$suspensionStart || $endedOn < $suspensionStart || $endedOn > date('Y-m-d')) {
                    throw new RuntimeException('Peatamise kirjet ei leitud või lõppkuupäev eelneb algusele.');
                }
                $resumeStmt = $pdo->prepare(
                    'UPDATE payroll_employment_suspensions SET ended_on = ?
                     WHERE id = ? AND company_id = ? AND employment_id = ? AND ended_on IS NULL'
                );
                $resumeStmt->execute([$endedOn, $suspensionId, $companyId, $employmentId]);
                $_SESSION['payroll_employment_notice'] = 'Peatamise lõpp salvestati.';
            } elseif ($action === 'end') {
                $endedOn = trim((string) ($_POST['ended_on'] ?? ''));
                $basisCode = trim((string) ($_POST['basis_code'] ?? ''));
                if (!validDate($endedOn) || $endedOn < $lockedEmployment['started_on'] || $endedOn > date('Y-m-d')) {
                    throw new RuntimeException('Töösuhte lõppkuupäev ei ole kehtiv.');
                }
                if (!preg_match('/^[A-Za-z0-9_-]{1,16}$/', $basisCode)) {
                    throw new RuntimeException('Sisesta MTA klassifikaatorile vastav lõpetamise aluse kood.');
                }
                $openStmt = $pdo->prepare(
                    'SELECT id, started_on FROM payroll_employment_suspensions
                     WHERE company_id = ? AND employment_id = ? AND ended_on IS NULL FOR UPDATE'
                );
                $openStmt->execute([$companyId, $employmentId]);
                $openSuspension = $openStmt->fetch();
                if ($openSuspension && $endedOn < $openSuspension['started_on']) {
                    throw new RuntimeException('Töösuhe ei saa lõppeda enne poolelioleva peatamise algust.');
                }
                if ($openSuspension) {
                    $closeSuspensionStmt = $pdo->prepare(
                        'UPDATE payroll_employment_suspensions SET ended_on = ?
                         WHERE id = ? AND company_id = ? AND employment_id = ? AND ended_on IS NULL'
                    );
                    $closeSuspensionStmt->execute([$endedOn, $openSuspension['id'], $companyId, $employmentId]);
                }
                $closeTermsStmt = $pdo->prepare(
                    'UPDATE payroll_employment_terms SET valid_until = ?
                     WHERE company_id = ? AND employment_id = ? AND valid_until IS NULL'
                );
                $closeTermsStmt->execute([$endedOn, $companyId, $employmentId]);
                $endStmt = $pdo->prepare(
                    'UPDATE payroll_employments SET ended_on = ?
                     WHERE id = ? AND company_id = ? AND ended_on IS NULL'
                );
                $endStmt->execute([$endedOn, $employmentId, $companyId]);
                $closureStmt = $pdo->prepare(
                    'INSERT INTO payroll_employment_closures
                        (company_id, employment_id, ended_on, basis_code, created_by)
                     VALUES (?, ?, ?, ?, ?)'
                );
                $closureStmt->execute([$companyId, $employmentId, $endedOn, $basisCode, (int) $_SESSION['user_id']]);
                $eventStmt = $pdo->prepare(
                    'INSERT INTO payroll_employment_events
                        (company_id, employment_id, event_type, event_date, basis_code, created_by)
                     VALUES (?, ?, \'ended\', ?, ?, ?)'
                );
                $eventStmt->execute([$companyId, $employmentId, $endedOn, $basisCode, (int) $_SESSION['user_id']]);
                $_SESSION['payroll_employment_notice'] = 'Töösuhte lõpetamine salvestati.';
            } else {
                throw new RuntimeException('Tundmatu toiming.');
            }
            $pdo->commit();
            header('Location: manage.php?id=' . $employmentId);
            exit;
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Palgamooduli töösuhte muutmine ebaõnnestus: ' . $e->getMessage());
            $errors[] = 'Toiming ebaõnnestus. Andmeid ei muudetud.';
        }
    }
}

$employmentStmt = $pdo->prepare(
    'SELECT pe.id, pe.started_on, pe.ended_on, e.first_name, e.last_name,
            cl.basis_code AS closure_basis_code
     FROM payroll_employments pe
     JOIN payroll_employees e ON e.id = pe.employee_id AND e.company_id = pe.company_id
     LEFT JOIN payroll_employment_closures cl
       ON cl.employment_id = pe.id AND cl.company_id = pe.company_id
     WHERE pe.id = :employment_id AND pe.company_id = :company_id'
);
$employmentStmt->execute(['employment_id' => $employmentId, 'company_id' => $companyId]);
$employment = $employmentStmt->fetch();
if (!$employment) {
    http_response_code(404);
    exit('Töösuhet ei leitud.');
}

$termsStmt = $pdo->prepare(
    'SELECT valid_from, valid_until, work_time_rate, job_title, workplace_address
     FROM payroll_employment_terms
     WHERE company_id = ? AND employment_id = ?
     ORDER BY valid_from DESC'
);
$termsStmt->execute([$companyId, $employmentId]);
$terms = $termsStmt->fetchAll();
$currentTerms = $terms[0] ?? null;

$suspensionsStmt = $pdo->prepare(
    'SELECT id, started_on, ended_on, basis_code
     FROM payroll_employment_suspensions
     WHERE company_id = ? AND employment_id = ?
     ORDER BY started_on DESC'
);
$suspensionsStmt->execute([$companyId, $employmentId]);
$suspensions = $suspensionsStmt->fetchAll();
$eventsStmt = $pdo->prepare(
    'SELECT event_type, event_date, basis_code, created_at
     FROM payroll_employment_events
     WHERE company_id = ? AND employment_id = ?
     ORDER BY event_date DESC, id DESC'
);
$eventsStmt->execute([$companyId, $employmentId]);
$employmentEvents = $eventsStmt->fetchAll();
$openSuspension = null;
foreach ($suspensions as $suspension) {
    if ($suspension['ended_on'] === null) {
        $openSuspension = $suspension;
        break;
    }
}
$isEnded = $employment['ended_on'] !== null;
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Töösuhte haldus | Palgamoodul</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px; background: #f4f7fb; color: #243447; font-family: Arial, sans-serif; }
        main { max-width: 1100px; margin: 0 auto; }
        header, .panel { margin-bottom: 20px; padding: 22px; background: #fff; border: 1px solid #dce3ec; border-radius: 12px; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        h1, h2, h3 { margin-top: 0; color: #17324d; }
        .muted { color: #68798b; line-height: 1.5; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        label { display: block; color: #33475b; font-size: 14px; font-weight: 600; }
        input { display: block; width: 100%; min-height: 42px; margin-top: 6px; padding: 9px 11px; border: 1px solid #b8c4d1; border-radius: 7px; font: inherit; }
        button, .button { display: inline-block; min-height: 40px; padding: 10px 14px; border: 0; border-radius: 7px; background: #1769aa; color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .danger { background: #9e3434; }
        .actions { margin-top: 14px; }
        .message { padding: 12px 14px; border-radius: 7px; }
        .success { background: #e9f6ed; color: #205c31; }
        .error { background: #fdecec; color: #8d2222; }
        ul { margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 8px; border-bottom: 1px solid #e2e8ef; text-align: left; vertical-align: top; }
        th { color: #526579; font-size: 13px; }
        .code-links { margin-top: 10px; font-size: 14px; }
        @media (max-width: 700px) { body { padding: 14px; } header { align-items: flex-start; flex-direction: column; } .grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <h1><?= h($employment['first_name'] . ' ' . $employment['last_name']) ?> · Töösuhte haldus</h1>
            <div class="muted">Algus <?= h($employment['started_on']) ?> · <?= $isEnded ? 'Lõppenud ' . h($employment['ended_on']) : ($openSuspension ? 'Peatatud' : 'Kehtiv') ?></div>
        </div>
        <a class="button" href="employments.php">Tagasi töösuhete juurde</a>
    </header>

    <?php if ($flash !== ''): ?><p class="message success"><?= h($flash) ?></p><?php endif; ?>
    <?php if ($errors): ?><div class="message error"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <section class="panel">
        <h2>Tingimuste ajalugu</h2>
        <?php if (!$terms): ?><p class="muted">Tingimuste kirjet ei leitud.</p><?php else: ?>
            <table><thead><tr><th>Kehtib alates</th><th>Kehtib kuni</th><th>Tööaja määr</th><th>Ametinimetus</th><th>Töökoha aadress</th></tr></thead><tbody>
            <?php foreach ($terms as $term): ?><tr>
                <td><?= h($term['valid_from']) ?></td><td><?= h($term['valid_until'] ?? 'Kehtiv') ?></td>
                <td><?= h(number_format((float) $term['work_time_rate'], 2, ',', '')) ?></td>
                <td><?= h($term['job_title']) ?></td><td><?= h($term['workplace_address']) ?></td>
            </tr><?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>
    </section>

    <?php if (!$isEnded): ?>
        <section class="panel">
            <h2>Muuda töötingimusi</h2>
            <p class="muted">Lisa uus kehtiv kirje. Eelmine tingimus jääb ajalukku. MTA juhendi järgi tuleb muutunud tööaja määr registrisse märkida 10 kalendripäeva jooksul.</p>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="change_terms">
                <div class="grid">
                    <label>Jõustumise kuupäev<input type="date" name="valid_from" required></label>
                    <label>Tööaja määr (täistööaeg 1,00)<input name="work_time_rate" inputmode="decimal" required></label>
                    <label>Ametinimetus<input name="job_title" maxlength="250" required value="<?= h($currentTerms['job_title'] ?? '') ?>"></label>
                    <label>Töökoha aadress<input name="workplace_address" maxlength="500" required value="<?= h($currentTerms['workplace_address'] ?? '') ?>"></label>
                </div>
                <div class="actions"><button type="submit">Salvesta tingimused</button></div>
            </form>
        </section>

        <?php if ($openSuspension): ?>
            <section class="panel">
                <h2>Lõpeta peatamine</h2>
                <p class="muted">Peatamine algas <?= h($openSuspension['started_on']) ?> (MTA alus <?= h($openSuspension['basis_code']) ?>).</p>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="resume"><input type="hidden" name="suspension_id" value="<?= (int) $openSuspension['id'] ?>">
                    <div class="grid"><label>Peatamise lõppkuupäev<input type="date" name="ended_on" required></label></div>
                    <div class="actions"><button type="submit">Salvesta peatamise lõpp</button></div>
                </form>
            </section>
        <?php else: ?>
            <section class="panel">
                <h2>Peata töösuhe</h2>
                <p class="muted">MTA juhendi järgi tuleb peatamine registreerida 10 kalendripäeva jooksul alates peatamise päevast.</p>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="suspend">
                    <div class="grid">
                        <label>Peatamise alguskuupäev<input type="date" name="started_on" required></label>
                        <label>MTA peatamise aluse kood<input name="basis_code" maxlength="16" required></label>
                    </div>
                    <div class="code-links"><a href="https://ncfailid.emta.ee/index.php/s/3WEm6wtLYp5Pyww/download/tootamise_peatamise_aluste_klassifikaator_.xls" target="_blank" rel="noopener">Ava MTA peatamise aluste klassifikaator</a></div>
                    <div class="actions"><button type="submit">Salvesta peatamine</button></div>
                </form>
            </section>
        <?php endif; ?>

        <section class="panel">
            <h2>Lõpeta töösuhe</h2>
            <p class="muted">Lõpetamise alus peab vastama tegelikule õiguslikule alusele. MTA juhendi järgi tuleb lõpetamine registreerida 10 kalendripäeva jooksul. Katsetuse või eksliku kande saab hiljem taasavamisega tühistada.</p>
            <form method="post" onsubmit="return confirm('Kas soovid töösuhte lõpetada?');">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="end">
                <div class="grid">
                    <label>Töösuhte lõppkuupäev<input type="date" name="ended_on" required></label>
                    <label>MTA lõpetamise aluse kood<input name="basis_code" maxlength="16" required></label>
                </div>
                <div class="code-links"><a href="https://ncfailid.emta.ee/s/2gYYRQKieZEiFED/download/tootamise-lopetamise-aluse-klassifikaator.xlsx" target="_blank" rel="noopener">Ava MTA lõpetamise aluste klassifikaator</a></div>
                <div class="actions"><button class="danger" type="submit">Lõpeta töösuhe</button></div>
            </form>
        </section>
    <?php else: ?>
        <section class="panel">
            <h2>Ava töösuhe uuesti</h2>
            <p class="muted">Kasuta seda juhul, kui lõpetamine oli katsetus või sisestati ekslikult. Töötaja ja palgakanded jäävad alles ning lõpetamise tühistamine salvestatakse ajalukku. See muudab ainult selle rakenduse andmeid; MTA töötamise registri kanne tuleb vajadusel eraldi parandada.</p>
            <form method="post" onsubmit="return confirm('Kas avad töösuhte uuesti ja tühistad rakenduses lõpetamise?');">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="reopen">
                <button type="submit">Ava töösuhe uuesti</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="panel">
        <h2>Peatamiste ajalugu</h2>
        <?php if (!$suspensions): ?><p class="muted">Peatamisi pole.</p><?php else: ?>
            <table><thead><tr><th>Algus</th><th>Lõpp</th><th>MTA alus</th></tr></thead><tbody>
            <?php foreach ($suspensions as $suspension): ?><tr><td><?= h($suspension['started_on']) ?></td><td><?= h($suspension['ended_on'] ?? 'Peatamine pooleli') ?></td><td><?= h($suspension['basis_code']) ?></td></tr><?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>
        <?php if ($isEnded): ?><p class="muted">Lõpetamise alus MTA klassifikaatoris: <?= h($employment['closure_basis_code'] ?? '') ?></p><?php endif; ?>
        <?php if ($employmentEvents): ?>
            <h3>Töösuhte lõpetamise ja taasavamise ajalugu</h3>
            <table><thead><tr><th>Toiming</th><th>Kuupäev</th><th>MTA alus</th></tr></thead><tbody>
            <?php foreach ($employmentEvents as $event): ?><tr>
                <td><?= $event['event_type'] === 'ended' ? 'Lõpetatud' : 'Taas avatud' ?></td>
                <td><?= h($event['event_date']) ?></td><td><?= h($event['basis_code'] ?? '—') ?></td>
            </tr><?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
