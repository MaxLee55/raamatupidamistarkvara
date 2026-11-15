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

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }

$companyId = (int) $_SESSION['company_id'];
$errors = [];
$success = isset($_GET['saved']);
$accountTypes = [
    'asset_account_id' => ['type' => 'asset', 'label' => 'Põhivara konto'],
    'accumulated_depreciation_account_id' => ['type' => 'asset', 'label' => 'Akumuleeritud kulumi konto'],
    'depreciation_expense_account_id' => ['type' => 'expense', 'label' => 'Amortisatsioonikulu konto'],
];

$accountStmt = $pdo->prepare('SELECT id, code, name, account_type FROM accounts WHERE company_id = ? AND is_active = 1 ORDER BY code');
$accountStmt->execute([$companyId]);
$accounts = $accountStmt->fetchAll(PDO::FETCH_ASSOC);
$settingsStmt = $pdo->prepare('SELECT * FROM fixed_asset_settings WHERE company_id = ?');
$settingsStmt->execute([$companyId]);
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$values = [];
foreach ($accountTypes as $field => $_config) {
    $values[$field] = (string) ($settings[$field] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    } else {
        foreach ($accountTypes as $field => $config) {
            $values[$field] = trim((string) ($_POST[$field] ?? ''));
            $accountId = filter_var($values[$field], FILTER_VALIDATE_INT);
            $found = false;
            foreach ($accounts as $account) {
                if ((int) $account['id'] === (int) $accountId && $account['account_type'] === $config['type']) {
                    $found = true;
                    break;
                }
            }
            if (!$accountId || !$found) {
                $errors[] = 'Vali väljale „' . $config['label'] . '” aktiivne ' . ($config['type'] === 'asset' ? 'varakonto' : 'kulukonto') . '.';
            }
        }
        if (!$errors) {
            $saveStmt = $pdo->prepare(
                'INSERT INTO fixed_asset_settings
                    (company_id, asset_account_id, accumulated_depreciation_account_id, depreciation_expense_account_id, updated_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE asset_account_id = VALUES(asset_account_id),
                    accumulated_depreciation_account_id = VALUES(accumulated_depreciation_account_id),
                    depreciation_expense_account_id = VALUES(depreciation_expense_account_id),
                    updated_by = VALUES(updated_by)'
            );
            $saveStmt->execute([$companyId, (int) $values['asset_account_id'], (int) $values['accumulated_depreciation_account_id'], (int) $values['depreciation_expense_account_id'], (int) $_SESSION['user_id']]);
            header('Location: settings.php?saved=1');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Põhivara kontode seaded</title>
    <style>
        * { box-sizing: border-box; }
        body { max-width: 900px; margin: 34px auto; padding: 0 22px; background: #f4f7fb; color: #263746; font-family: Arial, sans-serif; }
        h1, h2 { color: #17324d; }
        .panel { padding: 22px; background: #fff; border: 1px solid #dce3ec; border-radius: 12px; }
        .field { margin: 18px 0; }
        label { display: block; margin-bottom: 7px; font-weight: 700; }
        select { width: 100%; padding: 11px 12px; border: 1px solid #b8c4d1; border-radius: 7px; background: #fff; font: inherit; }
        .muted { color: #667788; line-height: 1.55; }
        .notice { margin: 16px 0; padding: 12px 14px; border-radius: 8px; background: #fff6df; color: #694e00; }
        .success { background: #eaf7ef; color: #176b3a; }
        .error { background: #fff0f0; color: #9a2929; }
        .button { display: inline-block; padding: 11px 16px; border: 0; border-radius: 7px; background: #1769aa; color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .actions { display: flex; gap: 9px; margin-top: 22px; }
        @media (max-width: 600px) { body { margin: 18px auto; padding: 0 14px; } }
    </style>
</head>
<body>
    <h1>Põhivara kontode seaded</h1>
    <section class="panel">
        <p class="muted">Seo põhivara register oma ettevõtte kontoplaaniga. Akumuleeritud kulumi konto on varakonto, mille saldo kajastatakse põhivara soetusmaksumuse vastu.</p>
        <?php if ($success): ?><p class="notice success">Kontoseosed salvestati.</p><?php endif; ?>
        <?php foreach ($errors as $error): ?><p class="notice error"><?= h($error) ?></p><?php endforeach; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <?php foreach ($accountTypes as $field => $config): ?>
                <div class="field">
                    <label for="<?= h($field) ?>"><?= h($config['label']) ?></label>
                    <select id="<?= h($field) ?>" name="<?= h($field) ?>" required>
                        <option value="">Vali <?= $config['type'] === 'asset' ? 'varakonto' : 'kulukonto' ?></option>
                        <?php foreach ($accounts as $account): if ($account['account_type'] !== $config['type']) continue; ?>
                            <option value="<?= (int) $account['id'] ?>" <?= $values[$field] === (string) $account['id'] ? 'selected' : '' ?>><?= h($account['code'] . ' — ' . $account['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; ?>
            <p class="muted">Neid kontoseoseid kasutatakse põhivara registrisse kinnitamisel, amortisatsioonikannete loomisel ning vara müügi või mahakandmise kirjendamisel. Seadete muutmine ei muuda juba postitatud kandeid.</p>
            <div class="actions"><button class="button" type="submit">Salvesta kontoseosed</button><a class="button" href="index.php">Tagasi põhivara lehele</a></div>
        </form>
    </section>
</body>
</html>
