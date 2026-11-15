<?php
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: ../login.php');
    exit;
}
require_once dirname(__DIR__) . '/db.php';

$companyId = (int) $_SESSION['company_id'];
$tablesReady = true;
$correctionHistoryReady = false;
$assetFeaturesReady = false;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('fixed_asset_settings', 'fixed_assets', 'fixed_asset_depreciation_runs', 'fixed_asset_disposals')");
    $stmt->execute();
    $tablesReady = (int) $stmt->fetchColumn() === 4;
    $correctionStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'fixed_asset_depreciation_corrections'");
    $correctionStmt->execute();
    $correctionHistoryReady = (int) $correctionStmt->fetchColumn() === 1;
    $featuresStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'fixed_asset_estimate_changes'");
    $featuresStmt->execute();
    $assetFeaturesReady = (int) $featuresStmt->fetchColumn() === 1;
} catch (Throwable $e) {
    $tablesReady = false;
}

$draftCount = 0;
$activeCount = 0;
$disposedCount = 0;
if ($tablesReady) {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) AS row_count FROM fixed_assets WHERE company_id = ? GROUP BY status");
    $stmt->execute([$companyId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $countRow) {
        if ($countRow['status'] === 'draft') $draftCount = (int) $countRow['row_count'];
        elseif ($countRow['status'] === 'active') $activeCount = (int) $countRow['row_count'];
        elseif ($countRow['status'] === 'disposed') $disposedCount = (int) $countRow['row_count'];
    }
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Põhivara</title>
    <style>
        * { box-sizing: border-box; }
        body { max-width: 1120px; margin: 34px auto; padding: 0 22px; background: #f4f7fb; color: #263746; font-family: Arial, sans-serif; }
        h1, h2, h3 { color: #17324d; }
        .panel { margin-bottom: 18px; padding: 22px; background: #fff; border: 1px solid #dce3ec; border-radius: 12px; }
        .topbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .topbar h1 { margin: 0; }
        .muted { color: #667788; line-height: 1.55; }
        .actions { display: flex; flex-wrap: wrap; gap: 9px; }
        .button { display: inline-block; min-height: 42px; padding: 11px 15px; border-radius: 7px; background: #1769aa; color: #fff; font-weight: 600; text-decoration: none; }
        .button.secondary { background: #657789; }
        .cards { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
        .card { padding: 18px; background: #f7f9fc; border: 1px solid #e1e7ef; border-radius: 10px; }
        .card h3 { margin: 0 0 8px; font-size: 16px; }
        .count { margin: 0; font-size: 27px; font-weight: 700; }
        .notice { padding: 14px 16px; border-radius: 8px; background: #fff6df; border: 1px solid #f0d68c; color: #694e00; line-height: 1.5; }
        @media (max-width: 700px) { body { margin: 18px auto; padding: 0 14px; } .topbar { align-items: flex-start; flex-direction: column; } .cards { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <header class="panel topbar">
        <div>
            <h1>Põhivara</h1>
    <p class="muted">Põhivara register, kontoseosed ja amortisatsiooni ettevalmistus.</p>
        </div>
        <nav class="actions" aria-label="Põhivara toimingud">
            <?php if ($tablesReady): ?>
                <a class="button" href="assets.php">Põhivara register</a>
                <a class="button" href="depreciation.php">Amortisatsiooni eelvaade</a>
                <a class="button" href="settings.php">Kontode seaded</a>
            <?php endif; ?>
            <a class="button secondary" href="../index.php">Avalehele</a>
            <a class="button secondary" href="../manual.php#fixed-assets">Põhivara kasutusjuhend</a>
        </nav>
    </header>

    <?php if (!$tablesReady): ?>
        <section class="panel">
            <h2>Alusta põhivara moodulit</h2>
            <p class="notice">Põhivara tabelid pole andmebaasi veel lisatud. Impordi enne kasutamist fail <strong>pohivara/schema.sql</strong> phpMyAdminis andmebaasi <strong>raamatupidamine</strong>. See lisab kaks uut põhivara tabelit ega muuda olemasolevaid andmeid ega seadeid.</p>
        </section>
    <?php else: ?>
        <section class="panel cards" aria-label="Põhivara kokkuvõte">
            <article class="card"><h3>Aktiivseid varasid</h3><p class="count"><?= $activeCount ?></p></article>
            <article class="card"><h3>Mustandeid</h3><p class="count"><?= $draftCount ?></p></article>
            <article class="card"><h3>Kõrvaldatud varasid</h3><p class="count"><?= $disposedCount ?></p></article>
        </section>
        <?php if (!$correctionHistoryReady): ?><section class="panel"><p class="notice">Amortisatsioonikannete parandamise ajaloo jaoks impordi andmebaasi fail <strong>pohivara/migration_005_depreciation_corrections.sql</strong>.</p></section><?php endif; ?>
        <?php if (!$assetFeaturesReady): ?><section class="panel"><p class="notice">Olemasoleva põhivara algseisu, arvestushinnangute ajaloo ja aruannete jaoks impordi andmebaasi fail <strong>pohivara/migration_006_opening_balances_and_asset_changes.sql</strong>. See lisab väljad ja ajalootabeli olemasolevaid kandeid kustutamata.</p></section><?php endif; ?>
        <section class="panel">
            <h2>Alustamine</h2>
            <ol class="muted">
                <li>Vali põhivara, akumuleeritud kulumi ja amortisatsioonikulu kontod.</li>
                <li>Lisa vara mustandina, seo see kinnitatud ostuarvega ning kinnita registrisse.</li>
                <li>Vaata perioodi amortisatsiooni eelvaade üle ja postita kuu kanne. Iga vara amortisatsiooni tuleb arvestada kuude kaupa järjest.</li>
                <li>Ava vara kaart, et vaadata ostu-, amortisatsiooni- ja kõrvaldamiskannete ajalugu.</li>
            </ol>
            <p class="notice">Kui kuu amortisatsioonikanne on juba tehtud, saab hiljem lisatud vara selle kuu eest postitada eraldi kandega. Juba arvestatud varade kulumit programm ei korda.</p>
        </section>
    <?php endif; ?>
</body>
</html>
