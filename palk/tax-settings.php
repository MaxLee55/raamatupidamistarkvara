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
function parseMoney(string $value): ?string
{
    $value = str_replace(["\u{00A0}", ' '], '', trim($value));
    $value = str_replace(',', '.', $value);
    if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/', $value)) {
        return null;
    }
    return number_format((float) $value, 2, '.', '');
}

$companyId = (int) $_SESSION['company_id'];
$employeeId = filter_var($_GET['employee_id'] ?? $_POST['employee_id'] ?? '', FILTER_VALIDATE_INT);
$errors = [];
$notice = '';
$form = [
    'valid_from' => date('Y-m-d'),
    'tax_free_type' => 'none',
    'tax_free_amount' => '0,00',
    'tax_free_application_date' => '',
    'pension_contribution_rate' => '0',
    'pension_rate_checked_on' => '',
    'unemployment_exempt' => '0',
    'social_tax_minimum_exempt' => '0',
];

if (!$employeeId || $employeeId < 1) {
    http_response_code(400);
    exit('Töötaja valik puudub.');
}
$employeeStmt = $pdo->prepare(
    'SELECT id, first_name, last_name, is_active FROM payroll_employees
     WHERE id = :employee_id AND company_id = :company_id'
);
$employeeStmt->execute(['employee_id' => $employeeId, 'company_id' => $companyId]);
$employee = $employeeStmt->fetch();
if (!$employee) {
    http_response_code(404);
    exit('Töötajat ei leitud.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    }
    foreach (array_keys($form) as $field) {
        $form[$field] = trim((string) ($_POST[$field] ?? ($field === 'unemployment_exempt' || $field === 'social_tax_minimum_exempt' ? '0' : '')));
    }
    $amount = parseMoney($form['tax_free_amount']);
    $rate = filter_var($form['pension_contribution_rate'], FILTER_VALIDATE_INT);
    $allowedRates = ['0', '2', '4', '6'];
    if (!validDate($form['valid_from'])) {
        $errors[] = 'Seadete kehtima hakkamise kuupäev ei ole kehtiv.';
    }
    if (!in_array($form['tax_free_type'], ['none', 'general', 'pensioner'], true)) {
        $errors[] = 'Vali maksuvaba tulu liik.';
    }
    if ($amount === null) {
        $errors[] = 'Maksuvaba tulu peab olema korrektne summa, kuni kahe komakohaga.';
    } else {
        $limit = $form['tax_free_type'] === 'pensioner' ? 776.00 : ($form['tax_free_type'] === 'general' ? 700.00 : 0.00);
        if ((float) $amount > $limit) {
            $errors[] = 'Summa ületab valitud maksuvaba tulu liigi piirmäära.';
        }
        if ($form['tax_free_type'] === 'none' && (float) $amount !== 0.0) {
            $errors[] = 'Kui maksuvaba tulu ei rakendata, peab summa olema 0.';
        }
        if ((float) $amount > 0 && !validDate($form['tax_free_application_date'])) {
            $errors[] = 'Maksuvaba tulu rakendamiseks märgi töötaja avalduse kuupäev.';
        }
        if ((float) $amount > 0 && validDate($form['tax_free_application_date'])
            && validDate($form['valid_from']) && $form['tax_free_application_date'] > $form['valid_from']) {
            $errors[] = 'Maksuvaba tulu avaldus ei saa olla hilisem kui seadete kehtima hakkamise kuupäev.';
        }
    }
    if (!in_array((string) $form['pension_contribution_rate'], $allowedRates, true)) {
        $errors[] = 'II samba määr peab olema 0%, 2%, 4% või 6%.';
    }
    if ($rate !== false && !validDate($form['pension_rate_checked_on'])) {
        $errors[] = 'II samba määra kontrollimise kuupäev on vajalik.';
    }
    if ($rate !== false && validDate($form['pension_rate_checked_on']) && validDate($form['valid_from'])
        && $form['pension_rate_checked_on'] > $form['valid_from']) {
        $errors[] = 'II samba kontrollimise kuupäev ei saa olla hilisem kui seadete kehtima hakkamise kuupäev.';
    }
    if (!in_array($form['unemployment_exempt'], ['0', '1'], true)
        || !in_array($form['social_tax_minimum_exempt'], ['0', '1'], true)) {
        $errors[] = 'Maksuerandi valik ei ole kehtiv.';
    }
    if (!$errors) {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO payroll_employee_tax_settings
                    (company_id, employee_id, valid_from, tax_free_type, tax_free_amount,
                     tax_free_application_date, pension_contribution_rate,
                     pension_rate_checked_on, unemployment_exempt,
                     social_tax_minimum_exempt, recorded_by)
                 VALUES
                    (:company_id, :employee_id, :valid_from, :tax_free_type, :tax_free_amount,
                     :tax_free_application_date, :pension_rate, :pension_checked_on,
                     :unemployment_exempt, :social_tax_minimum_exempt, :recorded_by)
                 ON DUPLICATE KEY UPDATE
                    tax_free_type = VALUES(tax_free_type),
                    tax_free_amount = VALUES(tax_free_amount),
                    tax_free_application_date = VALUES(tax_free_application_date),
                    pension_contribution_rate = VALUES(pension_contribution_rate),
                    pension_rate_checked_on = VALUES(pension_rate_checked_on),
                    unemployment_exempt = VALUES(unemployment_exempt),
                    social_tax_minimum_exempt = VALUES(social_tax_minimum_exempt),
                    recorded_by = VALUES(recorded_by)'
            );
            $stmt->execute([
                'company_id' => $companyId,
                'employee_id' => $employeeId,
                'valid_from' => $form['valid_from'],
                'tax_free_type' => $form['tax_free_type'],
                'tax_free_amount' => $amount,
                'tax_free_application_date' => (float) $amount > 0 ? $form['tax_free_application_date'] : null,
                'pension_rate' => (int) $rate,
                'pension_checked_on' => (int) $rate > 0 ? $form['pension_rate_checked_on'] : null,
                'unemployment_exempt' => (int) $form['unemployment_exempt'],
                'social_tax_minimum_exempt' => (int) $form['social_tax_minimum_exempt'],
                'recorded_by' => (int) $_SESSION['user_id'],
            ]);
            $notice = 'Maksuseaded salvestati.';
            $form = [
                'valid_from' => date('Y-m-d'),
                'tax_free_type' => 'none',
                'tax_free_amount' => '0,00',
                'tax_free_application_date' => '',
                'pension_contribution_rate' => '0',
                'pension_rate_checked_on' => '',
                'unemployment_exempt' => '0',
                'social_tax_minimum_exempt' => '0',
            ];
        } catch (PDOException $e) {
            error_log('Palgamooduli maksuseadete salvestamine ebaõnnestus: ' . $e->getMessage());
            $errors[] = 'Maksuseadete salvestamine ebaõnnestus.';
        }
    }
}

$historyStmt = $pdo->prepare(
    'SELECT valid_from, tax_free_type, tax_free_amount, tax_free_application_date,
            pension_contribution_rate, pension_rate_checked_on,
            unemployment_exempt, social_tax_minimum_exempt
     FROM payroll_employee_tax_settings
     WHERE company_id = :company_id AND employee_id = :employee_id
     ORDER BY valid_from DESC'
);
$historyStmt->execute(['company_id' => $companyId, 'employee_id' => $employeeId]);
$history = $historyStmt->fetchAll();
$taxFreeLabels = ['none' => 'Ei rakendata', 'general' => 'Üldine', 'pensioner' => 'Vanaduspensionieas'];
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Maksuseaded | Palgamoodul</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px; background: #f4f7fb; color: #243447; font-family: Arial, sans-serif; }
        main { max-width: 1050px; margin: 0 auto; }
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
        @media (max-width: 700px) { body { padding: 14px; } header { align-items: flex-start; flex-direction: column; } .fields { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <h1>Palgamoodul · Maksuseaded</h1>
            <div class="muted"><?= h($employee['first_name'] . ' ' . $employee['last_name']) ?></div>
        </div>
        <a class="button" href="index.php">Tagasi töötajate juurde</a>
    </header>

    <?php if ($notice !== ''): ?><p class="message success"><?= h($notice) ?></p><?php endif; ?>
    <?php if ($errors): ?><div class="message error"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <section class="panel">
        <h2>Lisa maksuseaded</h2>
        <p class="muted">„Kehtib alates” on esimene väljamakse kuupäev, mille puhul neid seadeid kasutatakse. Arvutus valib väljamakse kuupäeval viimase sama või varasema seaderea. Kui parandad varasema perioodi arvestust, lisa selle perioodi kuupäevaga ajalooline seaderida. Sisesta maksuvaba tulu töötaja kirjaliku avalduse järgi ja kontrolli II samba määr Pensionikeskuse registrist.</p>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="employee_id" value="<?= (int) $employeeId ?>">
            <div class="fields">
                <label>Kehtib alates<input type="date" name="valid_from" required value="<?= h($form['valid_from']) ?>"></label>
                <label>Maksuvaba tulu liik
                    <select name="tax_free_type" required>
                        <option value="none" <?= $form['tax_free_type'] === 'none' ? 'selected' : '' ?>>Ei rakendata</option>
                        <option value="general" <?= $form['tax_free_type'] === 'general' ? 'selected' : '' ?>>Üldine (kuni 700 € kuus)</option>
                        <option value="pensioner" <?= $form['tax_free_type'] === 'pensioner' ? 'selected' : '' ?>>Vanaduspensionieas (kuni 776 € kuus)</option>
                    </select>
                </label>
                <label>Maksuvaba summa kuus<input name="tax_free_amount" inputmode="decimal" required value="<?= h($form['tax_free_amount']) ?>"></label>
                <label>Töötaja avalduse kuupäev<input type="date" name="tax_free_application_date" value="<?= h($form['tax_free_application_date']) ?>"></label>
                <label>II samba maksemäär
                    <select name="pension_contribution_rate" required>
                        <?php foreach (['0', '2', '4', '6'] as $rateOption): ?>
                            <option value="<?= h($rateOption) ?>" <?= $form['pension_contribution_rate'] === $rateOption ? 'selected' : '' ?>><?= h($rateOption) ?>%</option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>II samba määra kontrollimise kuupäev<input type="date" name="pension_rate_checked_on" value="<?= h($form['pension_rate_checked_on']) ?>"></label>
            </div>
        <p class="muted">2026. aastal on üldise maksuvaba tulu piirmäär 700 € kuus ja vanaduspensionieas 776 € kuus. Rakenda ainult töötaja avalduses märgitud summat. II samba määr võib olla 0%, 2%, 4% või 6%.</p>
            <div class="fields">
                <label><span><input style="display:inline;width:auto;min-height:auto" type="checkbox" name="unemployment_exempt" value="1" <?= $form['unemployment_exempt'] === '1' ? 'checked' : '' ?>> Töötaja 1,6% töötuskindlustusmakse kinnipidamise erand kehtib</span></label>
                <label><span><input style="display:inline;width:auto;min-height:auto" type="checkbox" name="social_tax_minimum_exempt" value="1" <?= $form['social_tax_minimum_exempt'] === '1' ? 'checked' : '' ?>> Sotsiaalmaksu kuumäära miinimumkohustuse erand kehtib</span></label>
            </div>
            <p class="muted">Märgi erand ainult pärast töötamise registrist või Maksu- ja Tolliametist kontrollimist. Erandite kehtivus algab ülal valitud kuupäevast.</p>
            <div class="actions"><button type="submit">Salvesta maksuseaded</button></div>
        </form>
    </section>

    <section class="panel">
        <h2>Varasemad maksuseaded</h2>
        <?php if (!$history): ?><p class="muted">Maksuseadeid pole veel lisatud.</p><?php else: ?>
            <table>
                <thead><tr><th>Kehtib alates</th><th>Maksuvaba tulu</th><th>Avalduspäev</th><th>II sammas</th><th>Kontrollitud</th><th>TK erand</th><th>Sotsiaalmaksu miinimumi erand</th></tr></thead>
                <tbody><?php foreach ($history as $row): ?>
                    <tr>
                        <td><?= h($row['valid_from']) ?></td>
                        <td><?= h($taxFreeLabels[$row['tax_free_type']] ?? '—') ?>, <?= number_format((float) $row['tax_free_amount'], 2, ',', ' ') ?> €</td>
                        <td><?= h($row['tax_free_application_date'] ?? '—') ?></td>
                        <td><?= (int) $row['pension_contribution_rate'] ?>%</td>
                        <td><?= h($row['pension_rate_checked_on'] ?? '—') ?></td>
                        <td><?= (int) $row['unemployment_exempt'] === 1 ? 'Jah' : 'Ei' ?></td>
                        <td><?= (int) $row['social_tax_minimum_exempt'] === 1 ? 'Jah' : 'Ei' ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
