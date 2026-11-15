<?php
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) { header('Location: ../login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/feature_migration.php';
requireFixedAssetEnhancements($pdo);
require_once dirname(__DIR__) . '/period_lock.php';
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function amount(string $v): ?float {
    $v = str_replace(',', '.', trim($v));
    return preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $v) ? (float) $v : null;
}
$companyId = (int) $_SESSION['company_id'];
$assetId = isset($_GET['asset_id']) && ctype_digit((string) $_GET['asset_id']) ? (int) $_GET['asset_id'] : (int) ($_POST['asset_id'] ?? 0);
$assetStmt = $pdo->prepare("SELECT * FROM fixed_assets WHERE id = ? AND company_id = ? AND status = 'active'");
$assetStmt->execute([$assetId, $companyId]);
$asset = $assetStmt->fetch(PDO::FETCH_ASSOC);
if (!$asset) { http_response_code(404); exit('Aktiivset põhivara ei leitud.'); }
$runsStmt = $pdo->prepare('SELECT period_start, amount FROM fixed_asset_depreciation_runs WHERE company_id = ? AND asset_id = ? ORDER BY period_start');
$runsStmt->execute([$companyId, $assetId]);
$runs = $runsStmt->fetchAll(PDO::FETCH_ASSOC);
$disposalStmt = $pdo->prepare("SELECT id FROM fixed_asset_disposals WHERE company_id = ? AND asset_id = ? AND status = 'posted' LIMIT 1");
$disposalStmt->execute([$companyId, $assetId]);
$alreadyDisposed = (bool) $disposalStmt->fetchColumn();
$availableMonth = substr($asset['available_date'], 0, 7);
$openingMonths = (int) $asset['opening_depreciation_months'];
$offset = $openingMonths + count($runs);
$defaultPeriod = (new DateTimeImmutable($availableMonth . '-01'))->modify('+' . $offset . ' months')->format('Y-m');
$form = [
    'effective_period' => (string) ($_POST['effective_period'] ?? max(date('Y-m'), $defaultPeriod)),
    'residual_value' => (string) ($_POST['residual_value'] ?? $asset['residual_value']),
    'remaining_life_months' => (string) ($_POST['remaining_life_months'] ?? max(1, (int) $asset['useful_life_months'] - $offset)),
    'reason' => trim((string) ($_POST['reason'] ?? '')),
];
$errors = [];
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $form['effective_period'])) $errors[] = 'Vali kehtiv arvestuskuu.';
    if ($alreadyDisposed) $errors[] = 'Kõrvaldatud vara arvestushinnangut enam muuta ei saa.';
    $effective = $form['effective_period'] . '-01';
    if (!$errors) {
        $minimumOffset = $openingMonths;
        foreach ($runs as $i => $run) {
            $expected = (new DateTimeImmutable($availableMonth . '-01'))->modify('+' . ($openingMonths + $i) . ' months')->format('Y-m-01');
            if ($run['period_start'] !== $expected) { $errors[] = 'Amortisatsiooni ajaloos on varasem kuu puudu või parandatud. Kontrolli kuude järjekord enne hinnangu muutmist.'; break; }
            $minimumOffset++;
        }
        $monthOffset = ((int) substr($form['effective_period'], 0, 4) - (int) substr($availableMonth, 0, 4)) * 12 + (int) substr($form['effective_period'], 5, 2) - (int) substr($availableMonth, 5, 2);
        if (!$errors && ($monthOffset < $minimumOffset || $monthOffset < $openingMonths)) $errors[] = 'Muudatus peab jõustuma esimesel arvestamata kuul (' . $defaultPeriod . ') või hiljem; varasemaid kandeid ei muudeta.';
        if (!$errors && $form['effective_period'] !== $defaultPeriod) $errors[] = 'Turvaliseks edasiulatuvaks muutmiseks arvesta enne puuduvad kuud järjest. Muudatus saab praegu jõustuda kuul ' . $defaultPeriod . '.';
        if (!$errors && $effective < date('Y-m-01')) $errors[] = 'Muudatus saab jõustuda käesolevast arvestuskuust või hiljem.';
        if (!$errors) {
            $periodMessage = closedAccountingPeriodMessage($pdo, $companyId, $effective);
            if ($periodMessage !== null) $errors[] = $periodMessage;
        }
    }
    $residual = amount($form['residual_value']);
    if ($residual === null) $errors[] = 'Lõppväärtus peab olema null või positiivne summa.';
    if (!ctype_digit($form['remaining_life_months']) || (int) $form['remaining_life_months'] < 1 || (int) $form['remaining_life_months'] > 1200) $errors[] = 'Järelejäänud kasulik eluiga peab olema 1 kuni 1200 kuud.';
    if (mb_strlen($form['reason']) < 3 || mb_strlen($form['reason']) > 500) $errors[] = 'Selgita muudatuse põhjust (3–500 märki).';

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare("SELECT id FROM fixed_assets WHERE id = ? AND company_id = ? AND status = 'active' FOR UPDATE");
            $lock->execute([$assetId, $companyId]);
            if (!$lock->fetchColumn()) throw new RuntimeException('Vara olek muutus. Värskenda lehte.');
            $sumRuns = array_sum(array_map(static fn($r) => (float) $r['amount'], $runs));
            $carrying = round((float) $asset['acquisition_cost'] - (float) $asset['opening_accumulated_depreciation'] - $sumRuns, 2);
            if ($residual > $carrying) throw new RuntimeException('Uus lõppväärtus ei saa ületada muudatuse hetke bilansilist jääki (' . number_format($carrying, 2, ',', ' ') . ' €).');
            $changeOffset = ((int) substr($form['effective_period'], 0, 4) - (int) substr($availableMonth, 0, 4)) * 12 + (int) substr($form['effective_period'], 5, 2) - (int) substr($availableMonth, 5, 2);
            $insert = $pdo->prepare('INSERT INTO fixed_asset_estimate_changes (company_id, asset_id, effective_period, months_before_change, carrying_value_at_change, residual_value, remaining_life_months, reason, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $insert->execute([$companyId, $assetId, $effective, $changeOffset, number_format($carrying, 2, '.', ''), number_format($residual, 2, '.', ''), (int) $form['remaining_life_months'], $form['reason'], (int) $_SESSION['user_id']]);
            $pdo->commit();
            header('Location: asset_card.php?id=' . $assetId . '&estimate_changed=1'); exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $e instanceof RuntimeException ? $e->getMessage() : 'Arvestushinnangu salvestamine ebaõnnestus.';
        }
    }
}
$accumulated = (float) $asset['opening_accumulated_depreciation'] + array_sum(array_map(static fn($r) => (float) $r['amount'], $runs));
$carrying = max(0, (float) $asset['acquisition_cost'] - $accumulated);
?>
<!doctype html><html lang="et"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Muuda põhivara arvestushinnangut</title>
<style>*{box-sizing:border-box}body{max-width:900px;margin:32px auto;padding:0 18px;background:#f4f7fb;color:#263746;font-family:Arial,sans-serif}h1,h2{color:#17324d}.panel{padding:22px;background:#fff;border:1px solid #dce3ec;border-radius:12px;margin-bottom:18px}.muted{color:#667788;line-height:1.5}.field{margin:14px 0}.field label{display:block;margin-bottom:6px;font-weight:700}.field input,.field textarea{width:100%;padding:10px 12px;border:1px solid #b8c4d1;border-radius:7px;font:inherit}.field textarea{min-height:90px}.button{display:inline-block;padding:10px 15px;border:0;border-radius:7px;background:#1769aa;color:#fff;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}.secondary{background:#657789}.notice{padding:12px 14px;border-radius:8px;background:#fff6df;color:#694e00}.error{background:#fff0f0;color:#9a2929}.actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:18px}</style></head><body>
<h1>Muuda põhivara arvestushinnangut</h1>
<section class="panel"><h2><?= h($asset['asset_number'] . ' — ' . $asset['name']) ?></h2><p>Praegune bilansiline jääk: <strong><?= number_format($carrying, 2, ',', ' ') ?> €</strong></p><p class="muted">Muudatus rakendub ainult valitud arvestuskuust edasi. Juba postitatud kanded ja varasemad kuud jäävad muutmata. Sisesta uus lõppväärtus ja selle vara uus järelejäänud kasulik eluiga.</p>
<?php foreach ($errors as $error): ?><p class="notice error"><?= h($error) ?></p><?php endforeach; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="asset_id" value="<?= $assetId ?>">
<div class="field"><label for="effective_period">Kehtib alates (kuu)</label><input id="effective_period" type="month" name="effective_period" min="<?= h(max(date('Y-m'), $defaultPeriod)) ?>" value="<?= h($form['effective_period']) ?>" required></div>
<div class="field"><label for="residual_value">Uus hinnanguline lõppväärtus (€)</label><input id="residual_value" name="residual_value" inputmode="decimal" value="<?= h($form['residual_value']) ?>" required></div>
<div class="field"><label for="remaining_life_months">Uus järelejäänud kasulik eluiga (kuudes)</label><input id="remaining_life_months" type="number" min="1" max="1200" name="remaining_life_months" value="<?= h($form['remaining_life_months']) ?>" required></div>
<div class="field"><label for="reason">Muudatuse põhjus</label><textarea id="reason" name="reason" maxlength="500" required><?= h($form['reason']) ?></textarea></div>
<div class="actions"><button class="button" type="submit">Salvesta muudatus</button><a class="button secondary" href="asset_card.php?id=<?= $assetId ?>">Katkesta</a></div></form></section></body></html>
