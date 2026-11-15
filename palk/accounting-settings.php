<?php
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: ../login.php');
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Palgamooduli seadete muutmiseks on ligipääs ainult administraatoril.');
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once dirname(__DIR__) . '/db.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$companyId = (int) $_SESSION['company_id'];
$userId = (int) $_SESSION['user_id'];
$errors = [];
$notice = '';
$fieldTypes = [
    'wages_expense_account_id' => ['expense', 'Palgakulu konto'],
    'employer_social_tax_expense_account_id' => ['expense', 'Sotsiaalmaksukulu konto'],
    'employer_unemployment_expense_account_id' => ['expense', 'Tööandja töötuskindlustusmakse kulu konto'],
    'income_tax_liability_account_id' => ['liability', 'Tulumaksu kohustuse konto'],
    'pension_liability_account_id' => ['liability', 'II samba kohustuse konto'],
    'employee_unemployment_liability_account_id' => ['liability', 'Töötaja töötuskindlustuse kohustuse konto'],
    'employer_unemployment_liability_account_id' => ['liability', 'Tööandja töötuskindlustuse kohustuse konto'],
    'social_tax_liability_account_id' => ['liability', 'Sotsiaalmaksu kohustuse konto'],
];
$form = array_fill_keys(array_keys($fieldTypes), '');

$accountsStmt = $pdo->prepare(
    'SELECT id, code, name, account_type
     FROM accounts WHERE company_id = ? AND is_active = 1
     ORDER BY code'
);
$accountsStmt->execute([$companyId]);
$accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);
$accountsByType = ['expense' => [], 'liability' => []];
foreach ($accounts as $account) {
    if (isset($accountsByType[$account['account_type']])) {
        $accountsByType[$account['account_type']][] = $account;
    }
}

$settingsStmt = $pdo->prepare('SELECT * FROM payroll_account_settings WHERE company_id = ?');
$settingsStmt->execute([$companyId]);
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
foreach ($form as $key => $_) {
    $form[$key] = (string) ($settings[$key] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    }
    foreach ($fieldTypes as $field => [$expectedType]) {
        $value = filter_var($_POST[$field] ?? null, FILTER_VALIDATE_INT);
        $form[$field] = is_int($value) && $value > 0 ? (string) $value : '';
        if ($form[$field] === '') {
            $errors[] = 'Vali kõigile väljadele konto.';
            continue;
        }
        $valid = false;
        foreach ($accountsByType[$expectedType] as $account) {
            if ((int) $account['id'] === (int) $form[$field]) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            $errors[] = 'Valitud konto ei ole aktiivne või selle kontotüüp ei sobi: ' . $fieldTypes[$field][1] . '.';
        }
    }
    if (!$errors) {
        $saveStmt = $pdo->prepare(
            'INSERT INTO payroll_account_settings
                (company_id, wages_expense_account_id, employer_tax_expense_account_id,
                 employer_social_tax_expense_account_id, employer_unemployment_expense_account_id,
                 income_tax_liability_account_id, pension_liability_account_id,
                 employee_unemployment_liability_account_id, employer_unemployment_liability_account_id,
                 social_tax_liability_account_id, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                wages_expense_account_id = VALUES(wages_expense_account_id),
                employer_tax_expense_account_id = VALUES(employer_tax_expense_account_id),
                employer_social_tax_expense_account_id = VALUES(employer_social_tax_expense_account_id),
                employer_unemployment_expense_account_id = VALUES(employer_unemployment_expense_account_id),
                income_tax_liability_account_id = VALUES(income_tax_liability_account_id),
                pension_liability_account_id = VALUES(pension_liability_account_id),
                employee_unemployment_liability_account_id = VALUES(employee_unemployment_liability_account_id),
                employer_unemployment_liability_account_id = VALUES(employer_unemployment_liability_account_id),
                social_tax_liability_account_id = VALUES(social_tax_liability_account_id),
                updated_by = VALUES(updated_by)'
        );
        $saveStmt->execute([
            $companyId,
            (int) $form['wages_expense_account_id'],
            (int) $form['employer_social_tax_expense_account_id'],
            (int) $form['employer_social_tax_expense_account_id'],
            (int) $form['employer_unemployment_expense_account_id'],
            (int) $form['income_tax_liability_account_id'],
            (int) $form['pension_liability_account_id'],
            (int) $form['employee_unemployment_liability_account_id'],
            (int) $form['employer_unemployment_liability_account_id'],
            (int) $form['social_tax_liability_account_id'],
            $userId,
        ]);
        $notice = 'Palgakontode seadistus salvestati.';
    }
}

function payrollAccountOptions(array $options, string $selected): string
{
    $html = '<option value="">Vali konto</option>';
    foreach ($options as $account) {
        $id = (int) $account['id'];
        $isSelected = (string) $id === $selected ? ' selected' : '';
        $label = htmlspecialchars($account['code'] . ' — ' . $account['name'], ENT_QUOTES, 'UTF-8');
        $html .= '<option value="' . $id . '"' . $isSelected . '>' . $label . '</option>';
    }
    return $html;
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Palgakontode seadistus</title>
    <style>
        *{box-sizing:border-box}body{max-width:1100px;margin:32px auto;padding:0 20px;background:#f4f7fb;color:#263746;font-family:Arial,sans-serif}h1,h2{color:#17324d}.panel{margin:18px 0;padding:22px;background:#fff;border:1px solid #dce3ec;border-radius:12px}.topbar,.actions{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}label{display:grid;gap:7px;color:#33475b;font-weight:600}select{width:100%;min-height:42px;padding:9px 11px;border:1px solid #b8c4d1;border-radius:7px;background:#fff;font:inherit}.muted{color:#667788;line-height:1.5}.message{padding:12px 14px;border-radius:8px}.error{background:#fff0f0;color:#a32121}.success{background:#eaf7ef;color:#176b3a}.button,button{display:inline-block;min-height:42px;padding:10px 15px;border:0;border-radius:7px;background:#1769aa;color:#fff;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}.secondary{background:#657789}@media(max-width:700px){body{padding:0 12px}.fields{grid-template-columns:1fr}}
    </style>
</head>
<body>
<header class="topbar"><div><h1>Palgakontode seadistus</h1><p class="muted">Määra kontoplaanist palgakulu ja maksukohustuste kontod. Seadistust kasutatakse automaatselt palgaarvestuse pearaamatukannete loomisel.</p></div><a class="button secondary" href="runs.php">Tagasi palgaperioodide juurde</a></header>
<?php if ($errors): ?><div class="message error"><ul><?php foreach (array_unique($errors) as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($notice !== ''): ?><p class="message success"><?= h($notice) ?></p><?php endif; ?>
<section class="panel">
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <div class="fields">
            <?php foreach ($fieldTypes as $field => [$type, $label]): ?>
                <label><?= h($label) ?>
                    <select name="<?= h($field) ?>" required><?= payrollAccountOptions($accountsByType[$type], $form[$field]) ?></select>
                </label>
            <?php endforeach; ?>
        </div>
        <p class="muted">Palgakande deebetisse lähevad brutopalk, sotsiaalmaksukulu ja tööandja töötuskindlustusmakse kulu. Kreeditisse lähevad netopalk valitud pangakontolt ning töötaja ja tööandja maksude kohustused. Sama kohustuste kontot võib valida mitmele maksuliigile; nende summad ühendatakse kandes.</p>
        <div class="actions"><button type="submit">Salvesta palgakontod</button></div>
    </form>
</section>
</body>
</html>
