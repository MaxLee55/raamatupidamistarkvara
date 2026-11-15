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

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function validDate(string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}
function parseAmount(string $value): ?string {
    $value = str_replace(',', '.', trim($value));
    if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $value)) return null;
    return number_format((float) $value, 2, '.', '');
}

$companyId = (int) $_SESSION['company_id'];
$errors = [];
$success = isset($_GET['saved']);
$activated = isset($_GET['activated']);
$disposalEntryId = isset($_GET['disposal']) && ctype_digit((string) $_GET['disposal']) ? (int) $_GET['disposal'] : 0;
$disposalEntry = null;
if ($disposalEntryId > 0) {
    $disposalCheck = $pdo->prepare("SELECT id, entry_date, description FROM journal_entries WHERE id = ? AND company_id = ? AND status = 'posted'");
    $disposalCheck->execute([$disposalEntryId, $companyId]);
    $disposalEntry = $disposalCheck->fetch(PDO::FETCH_ASSOC) ?: null;
}
$editingId = 0;
$editingAsset = null;
if (isset($_GET['edit']) && ctype_digit((string) $_GET['edit']) && (int) $_GET['edit'] > 0) {
    $editingId = (int) $_GET['edit'];
    $editStmt = $pdo->prepare("SELECT * FROM fixed_assets WHERE id = ? AND company_id = ? AND status = 'draft'");
    $editStmt->execute([$editingId, $companyId]);
    $editingAsset = $editStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$editingAsset) {
        $errors[] = 'Seda põhivara mustandit ei leitud või see ei ole enam muudetav.';
        $editingId = 0;
    }
}
$form = [
    'asset_number' => trim((string) ($_POST['asset_number'] ?? $editingAsset['asset_number'] ?? '')),
    'name' => trim((string) ($_POST['name'] ?? $editingAsset['name'] ?? '')),
    'category' => trim((string) ($_POST['category'] ?? $editingAsset['category'] ?? '')),
    'acquisition_date' => trim((string) ($_POST['acquisition_date'] ?? $editingAsset['acquisition_date'] ?? date('Y-m-d'))),
    'available_date' => trim((string) ($_POST['available_date'] ?? $editingAsset['available_date'] ?? date('Y-m-d'))),
    'acquisition_cost' => trim((string) ($_POST['acquisition_cost'] ?? $editingAsset['acquisition_cost'] ?? '')),
    'residual_value' => trim((string) ($_POST['residual_value'] ?? $editingAsset['residual_value'] ?? '0.00')),
    'useful_life_months' => trim((string) ($_POST['useful_life_months'] ?? $editingAsset['useful_life_months'] ?? '')),
    'source_document' => trim((string) ($_POST['source_document'] ?? $editingAsset['source_document'] ?? '')),
    'notes' => trim((string) ($_POST['notes'] ?? $editingAsset['notes'] ?? '')),
    'legacy_opening_balance' => $_SERVER['REQUEST_METHOD'] === 'POST' ? (isset($_POST['legacy_opening_balance']) ? '1' : '0') : (string) ($editingAsset['legacy_opening_balance'] ?? '0'),
    'opening_accumulated_depreciation' => trim((string) ($_POST['opening_accumulated_depreciation'] ?? $editingAsset['opening_accumulated_depreciation'] ?? '0.00')),
    'opening_depreciation_months' => trim((string) ($_POST['opening_depreciation_months'] ?? $editingAsset['opening_depreciation_months'] ?? '0')),
];

$settingsStmt = $pdo->prepare('SELECT * FROM fixed_asset_settings WHERE company_id = ?');
$settingsStmt->execute([$companyId]);
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'activate') {
    $assetId = isset($_POST['asset_id']) && ctype_digit((string) $_POST['asset_id']) ? (int) $_POST['asset_id'] : 0;
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    } elseif ($assetId < 1) {
        $errors[] = 'Põhivara kirje puudub või on vigane.';
    } else {
        $assetCheck = $pdo->prepare("SELECT * FROM fixed_assets WHERE id = ? AND company_id = ? AND status = 'draft'");
        $assetCheck->execute([$assetId, $companyId]);
        $assetToActivate = $assetCheck->fetch(PDO::FETCH_ASSOC);
        if (!$assetToActivate) {
            $errors[] = 'Kinnitada saab ainult selle ettevõtte mustandit.';
        } elseif (empty($assetToActivate['source_document'])) {
            $errors[] = 'Enne kinnitamist lisa algdokumendi viide.';
        } elseif ((int) $assetToActivate['legacy_opening_balance'] === 1) {
            if ((float) $assetToActivate['opening_accumulated_depreciation'] > (float) $assetToActivate['acquisition_cost'] - (float) $assetToActivate['residual_value']) {
                $errors[] = 'Algseisu kulum ei saa viia vara bilansilist jääki alla hinnangulise lõppväärtuse.';
            } elseif ((int) $assetToActivate['opening_depreciation_months'] >= (int) $assetToActivate['useful_life_months']) {
                $errors[] = 'Algseisu arvestatud kuude arv peab olema kasulikust elueast väiksem.';
            } else {
                $activate = $pdo->prepare("UPDATE fixed_assets SET status = 'active' WHERE id = ? AND company_id = ? AND status = 'draft'");
                $activate->execute([$assetId, $companyId]);
                header('Location: assets.php?activated=1&legacy=1');
                exit;
            }
        } else {
            $invoiceCheck = $pdo->prepare(
                "SELECT pi.id, pi.journal_entry_id, COUNT(pil.id) AS asset_line_count, COALESCE(SUM(pil.net_amount), 0) AS asset_line_total
                 FROM purchase_invoices pi
                 JOIN purchase_invoice_lines pil ON pil.invoice_id = pi.id
                 WHERE pi.company_id = ? AND pi.supplier_invoice_number = ? AND pi.status = 'posted'
                   AND pi.journal_entry_id IS NOT NULL AND pil.account_id = ?
                 GROUP BY pi.id, pi.journal_entry_id"
            );
            $invoiceCheck->execute([$companyId, $assetToActivate['source_document'], $assetToActivate['asset_account_id']]);
            $matchingInvoice = $invoiceCheck->fetch(PDO::FETCH_ASSOC);
            if (!$matchingInvoice) {
                $errors[] = 'Algdokumendile vastavat kinnitatud ostuarvet ei leitud või ostuarve põhivara rida on teisel kontol.';
            } elseif ((int) $matchingInvoice['asset_line_count'] !== 1 || abs((float) $matchingInvoice['asset_line_total'] - (float) $assetToActivate['acquisition_cost']) > 0.005) {
                $errors[] = 'Ostuarve põhivara rea summa ei klapi mustandi soetusmaksumusega. Kontrolli arvet ja vara andmeid.';
            } else {
                $activate = $pdo->prepare("UPDATE fixed_assets SET status = 'active' WHERE id = ? AND company_id = ? AND status = 'draft'");
                $activate->execute([$assetId, $companyId]);
                header('Location: assets.php?activated=1');
                exit;
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? 'save') !== 'activate') {
    $postedAssetId = isset($_POST['asset_id']) && ctype_digit((string) $_POST['asset_id']) ? (int) $_POST['asset_id'] : 0;
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    }
    if (!$settings) {
        $errors[] = 'Enne põhivara lisamist määra kontode seosed lehel „Kontode seaded”.';
    }
    if ($form['asset_number'] === '' || mb_strlen($form['asset_number']) > 50) $errors[] = 'Vara number on kohustuslik (kuni 50 märki).';
    if ($form['name'] === '' || mb_strlen($form['name']) > 200) $errors[] = 'Vara nimetus on kohustuslik (kuni 200 märki).';
    if (mb_strlen($form['category']) > 100) $errors[] = 'Kategooria võib olla kuni 100 märki.';
    if (!validDate($form['acquisition_date']) || !validDate($form['available_date'])) $errors[] = 'Sisesta kehtivad soetamise ja kasutusvalmis kuupäevad.';
    elseif ($form['available_date'] < $form['acquisition_date']) $errors[] = 'Kasutusvalmis kuupäev ei saa olla varasem kui soetamise kuupäev.';
    $cost = parseAmount($form['acquisition_cost']);
    $residual = parseAmount($form['residual_value']);
    if ($cost === null || (float) $cost <= 0) $errors[] = 'Soetusmaksumus peab olema positiivne summa.';
    if ($residual === null || (float) $residual < 0) $errors[] = 'Lõppväärtus peab olema null või positiivne summa.';
    if ($cost !== null && $residual !== null && (float) $residual >= (float) $cost) $errors[] = 'Lõppväärtus peab olema soetusmaksumusest väiksem.';
    if (!ctype_digit($form['useful_life_months']) || (int) $form['useful_life_months'] < 1 || (int) $form['useful_life_months'] > 1200) $errors[] = 'Kasulik eluiga peab olema 1 kuni 1200 kuud.';
    if (mb_strlen($form['source_document']) > 100) $errors[] = 'Algdokumendi viide võib olla kuni 100 märki.';
    if (mb_strlen($form['notes']) > 5000) $errors[] = 'Märkus võib olla kuni 5000 märki.';
    $legacyOpening = $form['legacy_opening_balance'] === '1';
    $openingAccumulated = parseAmount($form['opening_accumulated_depreciation']);
    if ($openingAccumulated === null || (float) $openingAccumulated < 0) $errors[] = 'Algseisu akumuleeritud kulum peab olema null või positiivne summa.';
    if ($cost !== null && $residual !== null && $openingAccumulated !== null && (float) $openingAccumulated > (float) $cost - (float) $residual) $errors[] = 'Algseisu kulum ei saa viia vara bilansilist jääki alla hinnangulise lõppväärtuse.';
    if (!ctype_digit($form['opening_depreciation_months']) || (int) $form['opening_depreciation_months'] < 0 || (int) $form['opening_depreciation_months'] > 1200) $errors[] = 'Algseisu arvestatud kuude arv peab olema 0 kuni 1200.';
    elseif (ctype_digit($form['useful_life_months']) && (int) $form['opening_depreciation_months'] >= (int) $form['useful_life_months']) $errors[] = 'Algseisu arvestatud kuude arv peab olema kasulikust elueast väiksem.';
    if (!$legacyOpening && $openingAccumulated !== null && (float) $openingAccumulated > 0) $errors[] = 'Algseisu kulumi sisestamiseks märgi „Olemasoleva vara algseis”.';

    if ($postedAssetId > 0) {
        $editCheck = $pdo->prepare("SELECT id FROM fixed_assets WHERE id = ? AND company_id = ? AND status = 'draft'");
        $editCheck->execute([$postedAssetId, $companyId]);
        if (!$editCheck->fetchColumn()) $errors[] = 'Muuta saab ainult selle ettevõtte põhivara mustandit.';
        else $editingId = $postedAssetId;
    }

    if (!$errors) {
        try {
            if ($postedAssetId > 0) {
                $save = $pdo->prepare(
                    "UPDATE fixed_assets SET asset_number = ?, name = ?, category = ?, acquisition_date = ?, available_date = ?,
                        acquisition_cost = ?, residual_value = ?, useful_life_months = ?, source_document = ?, notes = ?,
                        legacy_opening_balance = ?, opening_accumulated_depreciation = ?, opening_depreciation_months = ?,
                        asset_account_id = ?, accumulated_depreciation_account_id = ?, depreciation_expense_account_id = ?
                     WHERE id = ? AND company_id = ? AND status = 'draft'"
                );
                $save->execute([
                    $form['asset_number'], $form['name'], $form['category'] !== '' ? $form['category'] : null,
                    $form['acquisition_date'], $form['available_date'], $cost, $residual, (int) $form['useful_life_months'],
                    $form['source_document'] !== '' ? $form['source_document'] : null,
                    $form['notes'] !== '' ? $form['notes'] : null, $legacyOpening ? 1 : 0,
                    $legacyOpening ? $openingAccumulated : '0.00', $legacyOpening ? (int) $form['opening_depreciation_months'] : 0,
                    (int) $settings['asset_account_id'], (int) $settings['accumulated_depreciation_account_id'],
                    (int) $settings['depreciation_expense_account_id'], $postedAssetId, $companyId,
                ]);
                header('Location: assets.php?saved=1&updated=1');
            } else {
                $insert = $pdo->prepare(
                    "INSERT INTO fixed_assets
                        (company_id, asset_number, name, category, acquisition_date, available_date,
                         acquisition_cost, residual_value, useful_life_months, depreciation_method,
                         asset_account_id, accumulated_depreciation_account_id, depreciation_expense_account_id,
                         source_document, notes, legacy_opening_balance, opening_accumulated_depreciation,
                         opening_depreciation_months, status, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'straight_line', ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)"
                );
                $insert->execute([
                    $companyId, $form['asset_number'], $form['name'], $form['category'] !== '' ? $form['category'] : null,
                    $form['acquisition_date'], $form['available_date'], $cost, $residual, (int) $form['useful_life_months'],
                    (int) $settings['asset_account_id'], (int) $settings['accumulated_depreciation_account_id'],
                    (int) $settings['depreciation_expense_account_id'],
                    $form['source_document'] !== '' ? $form['source_document'] : null,
                    $form['notes'] !== '' ? $form['notes'] : null, $legacyOpening ? 1 : 0,
                    $legacyOpening ? $openingAccumulated : '0.00', $legacyOpening ? (int) $form['opening_depreciation_months'] : 0,
                    (int) $_SESSION['user_id'],
                ]);
                header('Location: assets.php?saved=1');
            }
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') $errors[] = 'Selle ettevõtte vara number on juba kasutusel.';
            else { error_log($e->getMessage()); $errors[] = 'Põhivara mustandi salvestamine ebaõnnestus.'; }
        }
    }
}

$list = $pdo->prepare(
    'SELECT fa.*, aa.code AS asset_code, aa.name AS asset_name,
            da.code AS depreciation_code, da.name AS depreciation_name,
            fa.opening_accumulated_depreciation + COALESCE(dep.total_depreciation, 0) AS total_depreciation,
            fa.opening_depreciation_months + COALESCE(dep.depreciation_count, 0) AS depreciation_count,
            dep.last_depreciation_period
     FROM fixed_assets fa
     JOIN accounts aa ON aa.id = fa.asset_account_id AND aa.company_id = fa.company_id
     JOIN accounts da ON da.id = fa.depreciation_expense_account_id AND da.company_id = fa.company_id
     LEFT JOIN (
         SELECT company_id, asset_id, SUM(amount) AS total_depreciation,
                COUNT(*) AS depreciation_count, MAX(period_start) AS last_depreciation_period
         FROM fixed_asset_depreciation_runs
         GROUP BY company_id, asset_id
     ) dep ON dep.company_id = fa.company_id AND dep.asset_id = fa.id
     WHERE fa.company_id = ?
     ORDER BY CASE WHEN fa.status = \'draft\' THEN 0 ELSE 1 END, fa.asset_number'
);
$list->execute([$companyId]);
$assets = $list->fetchAll(PDO::FETCH_ASSOC);

foreach ($assets as &$asset) {
    $depreciableBase = max(0, (float) $asset['acquisition_cost'] - (float) $asset['residual_value']);
    $months = max(1, (int) $asset['useful_life_months'] - (int) $asset['opening_depreciation_months']);
    $asset['monthly_depreciation_preview'] = max(0, $depreciableBase - (float) $asset['opening_accumulated_depreciation']) / $months;
    $asset['depreciable_base_preview'] = $depreciableBase;
    $asset['carrying_value'] = $asset['status'] === 'disposed' ? 0 : max(0, (float) $asset['acquisition_cost'] - (float) $asset['total_depreciation']);
    $asset['remaining_depreciable_amount'] = $asset['status'] === 'disposed' ? 0 : max(0, $depreciableBase - (float) $asset['total_depreciation']);
    $asset['remaining_life_months'] = $asset['status'] === 'disposed' ? 0 : max(0, $months - (int) $asset['depreciation_count']);
}
unset($asset);
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Põhivara register</title>
    <style>
        * { box-sizing: border-box; }
        body { width: 100%; margin: 0; padding: 34px clamp(18px, 3vw, 48px); background: #f4f7fb; color: #263746; font-family: Arial, sans-serif; }
        h1, h2 { color: #17324d; }
        .panel { margin-bottom: 18px; padding: 22px; background: #fff; border: 1px solid #dce3ec; border-radius: 12px; }
        .intro, .muted { color: #667788; line-height: 1.55; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 15px; }
        .field label { display: block; margin: 0 0 7px; font-weight: 700; }
        .field input, .field textarea { width: 100%; padding: 10px 12px; border: 1px solid #b8c4d1; border-radius: 7px; background: #fff; font: inherit; }
        .field input[type="checkbox"] { width: auto; }
        .field textarea { min-height: 90px; resize: vertical; }
        .wide { grid-column: 1 / -1; }
        .button { display: inline-block; padding: 11px 16px; border: 0; border-radius: 7px; background: #1769aa; color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .secondary { background: #657789; }
        .actions { display: flex; flex-wrap: wrap; gap: 9px; margin-top: 18px; }
        .report-tools { display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin:12px 0 18px; }
        .report-tools input { padding:9px 10px; border:1px solid #b8c4d1; border-radius:7px; font:inherit; }
        .notice { padding: 12px 14px; border-radius: 8px; line-height: 1.5; background: #fff6df; color: #694e00; }
        .success { background: #eaf7ef; color: #176b3a; }
        .error { background: #fff0f0; color: #9a2929; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #e1e7ef; text-align: left; vertical-align: top; }
        th { background: #f3f6fa; color: #33475b; }
        td small { display: block; margin-top: 4px; color: #667788; white-space: normal; }
        .status { display: inline-block; padding: 4px 9px; border-radius: 999px; background: #fff3d7; color: #735400; font-weight: 700; font-size: 13px; }
        .row-actions { display: flex; flex-direction: column; align-items: flex-start; gap: 7px; min-width: 155px; }
        .small-action { display: inline-block; padding: 6px 9px; border: 1px solid #cbd9e7; border-radius: 6px; background: #edf5fc; color: #145c91; font-size: 13px; font-weight: 600; line-height: 1.25; text-decoration: none; white-space: nowrap; }
        .small-action:hover { background: #dcecf9; }
        .small-action-danger { border-color: #e2b8b8; background: #fff1f1; color: #9a2929; }
        .small-action-danger:hover { background: #ffe4e4; }
        @media (max-width: 700px) { body { margin: 0; padding: 18px 14px; } .grid { grid-template-columns: 1fr; } .wide { grid-column: auto; } }
    </style>
</head>
<body>
    <h1>Põhivara register</h1>
    <p class="intro">Lisa vara esmalt mustandina. Olemasoleva vara algseisu saab sisestada ainult siis, kui algsaldod on pearaamatus juba kajastatud.</p>
    <?php if ($success): ?><p class="notice success"><?= isset($_GET['updated']) ? 'Põhivara mustandi muudatused salvestati.' : 'Põhivara mustand salvestati registrisse.' ?></p><?php endif; ?>
    <?php if ($activated): ?><p class="notice success"><?= isset($_GET['legacy']) ? 'Olemasolev põhivara kinnitati algseisuna. Programm ei loonud algsaldode kannet; kontrolli, et need on pearaamatus juba olemas.' : 'Põhivara kinnitati registrisse ja seoti kontrollitud ostuarvega. Soetuskannet uuesti ei loodud.' ?></p><?php endif; ?>
    <?php if ($disposalEntry): ?><p class="notice success">Põhivara müügi või mahakandmise kanne salvestati: <a href="../entry.php?id=<?= (int) $disposalEntry['id'] ?>">Kanne #<?= (int) $disposalEntry['id'] ?></a> (<?= h($disposalEntry['entry_date']) ?>).</p><?php endif; ?>
    <?php foreach ($errors as $error): ?><p class="notice error"><?= h($error) ?></p><?php endforeach; ?>

    <?php if (!$settings): ?>
        <section class="panel"><p class="notice">Enne vara lisamist määra põhivara, akumuleeritud kulumi ja amortisatsioonikulu kontod.</p><p><a class="button" href="settings.php">Ava kontode seaded</a></p></section>
    <?php else: ?>
        <section class="panel">
            <h2><?= $editingId > 0 ? 'Muuda vara mustandit' : 'Lisa vara mustand' ?></h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <?php if ($editingId > 0): ?><input type="hidden" name="asset_id" value="<?= $editingId ?>"><?php endif; ?>
                <div class="grid">
                    <div class="field"><label for="asset_number">Vara number</label><input id="asset_number" name="asset_number" maxlength="50" value="<?= h($form['asset_number']) ?>" placeholder="Näiteks PV-001" required></div>
                    <div class="field"><label for="name">Vara nimetus</label><input id="name" name="name" maxlength="200" value="<?= h($form['name']) ?>" required></div>
                    <div class="field"><label for="category">Kategooria</label><input id="category" name="category" maxlength="100" value="<?= h($form['category']) ?>" placeholder="Näiteks arvutid ja seadmed"></div>
                    <div class="field"><label for="source_document">Algdokumendi viide</label><input id="source_document" name="source_document" maxlength="100" value="<?= h($form['source_document']) ?>" placeholder="Arve number või muu viide"></div>
                    <div class="field"><label for="acquisition_date">Soetamise kuupäev</label><input id="acquisition_date" type="date" name="acquisition_date" value="<?= h($form['acquisition_date']) ?>" required></div>
                    <div class="field"><label for="available_date">Kasutusvalmis kuupäev</label><input id="available_date" type="date" name="available_date" value="<?= h($form['available_date']) ?>" required></div>
                    <div class="field"><label for="acquisition_cost">Soetusmaksumus (€)</label><input id="acquisition_cost" name="acquisition_cost" inputmode="decimal" value="<?= h($form['acquisition_cost']) ?>" placeholder="0,00" required></div>
                    <div class="field"><label for="residual_value">Hinnanguline lõppväärtus (€)</label><input id="residual_value" name="residual_value" inputmode="decimal" value="<?= h($form['residual_value']) ?>" required></div>
                    <div class="field"><label for="useful_life_months">Kasulik eluiga (kuudes)</label><input id="useful_life_months" type="number" min="1" max="1200" name="useful_life_months" value="<?= h($form['useful_life_months']) ?>" placeholder="Näiteks 60" required></div>
                    <div class="field wide"><label><input type="checkbox" name="legacy_opening_balance" value="1" <?= $form['legacy_opening_balance'] === '1' ? 'checked' : '' ?>> Olemasoleva vara algseis</label><p class="muted">Märgi ainult varale, mis on juba kasutuses ja mille soetusmaksumus on pearaamatus kajastatud. Algseisu lisamine põhivara registrisse ise pearaamatu kannet ei loo.</p></div>
                    <div class="field"><label for="opening_accumulated_depreciation">Varem arvestatud kulum (€)</label><input id="opening_accumulated_depreciation" name="opening_accumulated_depreciation" inputmode="decimal" value="<?= h($form['opening_accumulated_depreciation']) ?>" required></div>
                    <div class="field"><label for="opening_depreciation_months">Varem arvestatud kuud</label><input id="opening_depreciation_months" type="number" min="0" max="1200" name="opening_depreciation_months" value="<?= h($form['opening_depreciation_months']) ?>" required></div>
                    <div class="field wide"><label for="notes">Märkus</label><textarea id="notes" name="notes" maxlength="5000"><?= h($form['notes']) ?></textarea></div>
                </div>
                <p class="muted">Mustand kasutab seadetes valitud kontoseoseid. Tavavara puhul kontrollitakse kinnitamisel ostuarvet; algseisuna lisatud vara puhul veendu ise, et soetusmaksumus ja varasem kulum on pearaamatus juba õigesti kajastatud.</p>
                <div class="actions"><button class="button" type="submit"><?= $editingId > 0 ? 'Salvesta muudatused' : 'Salvesta mustand' ?></button><?php if ($editingId > 0): ?><a class="button secondary" href="assets.php">Katkesta muutmine</a><?php endif; ?><a class="button secondary" href="index.php">Põhivara avalehele</a></div>
            </form>
        </section>
    <?php endif; ?>

    <section class="panel">
        <h2>Registri kirjed</h2>
        <div class="report-tools"><a class="button" href="reports.php?type=register">Laadi register CSV-na alla</a><form method="get" action="reports.php" class="report-tools"><input type="hidden" name="type" value="schedule"><label for="report_month">Amortisatsiooni kuu</label><input id="report_month" type="month" name="month" value="<?= h(date('Y-m')) ?>" required><button class="button" type="submit">Laadi graafik CSV-na alla</button></form></div>
        <?php if (!$assets): ?>
            <p class="muted">Põhivara kirjeid veel pole.</p>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Vara nr ja algdokument</th><th>Nimetus</th><th>Kuupäevad</th><th>Soetusmaksumus ja lõppväärtus</th><th>Arvestatud kulum</th><th>Bilansiline jääkmaksumus</th><th>Amortiseeritav jääk ja eluiga</th><th>Kontod</th><th>Olek</th><th>Tegevus</th></tr></thead>
                <tbody><?php foreach ($assets as $asset): ?>
                    <tr>
                        <td><?= h($asset['asset_number']) ?><small>Algdokument: <?= $asset['source_document'] !== null && $asset['source_document'] !== '' ? h($asset['source_document']) : 'lisamata' ?></small></td>
                        <td><?= h($asset['name']) ?><?= $asset['category'] ? '<br><small>' . h($asset['category']) . '</small>' : '' ?></td>
                        <td>Soetatud: <?= h($asset['acquisition_date']) ?><small>Kasutusvalmis: <?= h($asset['available_date']) ?></small></td>
                        <td><?= number_format((float) $asset['acquisition_cost'], 2, ',', ' ') ?> €<small>Lõppväärtus: <?= number_format((float) $asset['residual_value'], 2, ',', ' ') ?> €</small></td>
                        <td><?= number_format((float) $asset['total_depreciation'], 2, ',', ' ') ?> €<small><?= (int) $asset['depreciation_count'] ?> kuud arvestatud<?= $asset['last_depreciation_period'] ? '; viimati ' . h(substr($asset['last_depreciation_period'], 0, 7)) : '' ?></small></td>
                        <td><strong><?= number_format($asset['carrying_value'], 2, ',', ' ') ?> €</strong><small><?= $asset['status'] === 'disposed' ? 'Jääk suleti kõrvaldamiskandega' : 'Soetusmaksumus miinus arvestatud kulum' ?></small></td>
                        <td><?= number_format($asset['remaining_depreciable_amount'], 2, ',', ' ') ?> €<small><?= (int) $asset['remaining_life_months'] ?> kuud kasutuseast järel</small><small><?= $asset['status'] === 'disposed' ? 'Vara on kõrvaldatud' : 'Algne kuusumma: ' . number_format($asset['monthly_depreciation_preview'], 2, ',', ' ') . ' €' ?></small></td>
                        <td><?= h($asset['asset_code'] . ' — ' . $asset['asset_name']) ?><small><?= h($asset['depreciation_code'] . ' — ' . $asset['depreciation_name']) ?></small></td>
                        <td><span class="status"><?= $asset['status'] === 'draft' ? 'Mustand' : ($asset['status'] === 'active' ? 'Aktiivne' : ($asset['status'] === 'disposed' ? 'Kõrvaldatud' : ($asset['status']==='returned'?'Tarnijale tagastatud':h($asset['status'])))) ?></span></td>
                        <td><div class="row-actions">
                            <a class="small-action" href="asset_card.php?id=<?= (int) $asset['id'] ?>">Ava vara kaart</a>
                            <?php if ($asset['status'] === 'draft'): ?>
                                <a class="small-action" href="assets.php?edit=<?= (int) $asset['id'] ?>">Muuda mustandit</a>
                                <form method="post" onsubmit="return confirm('Kinnita see põhivara registrisse? Uut soetuskannet ei looda.');">
                                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="activate">
                                    <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">
                                    <button class="button" type="submit" style="padding:7px 10px;font-size:13px">Kinnita varaks</button>
                                </form>
                            <?php elseif ($asset['status'] === 'active'): ?>
                                <a class="small-action small-action-danger" href="disposal.php?asset_id=<?= (int) $asset['id'] ?>">Müü või kanna maha</a>
                            <?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>
</body>
</html>
