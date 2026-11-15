<?php
function fixedAssetEnhancementsReady(PDO $pdo): bool {
    $stmt = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'fixed_assets' AND column_name IN ('legacy_opening_balance', 'opening_accumulated_depreciation', 'opening_depreciation_months')");
    $columnsReady = (int) $stmt->fetchColumn() === 3;
    $table = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'fixed_asset_estimate_changes'");
    return $columnsReady && (int) $table->fetchColumn() === 1;
}

function requireFixedAssetEnhancements(PDO $pdo): void {
    if (fixedAssetEnhancementsReady($pdo)) return;
    http_response_code(503);
    echo '<!doctype html><html lang="et"><meta charset="utf-8"><title>Põhivara uuendus vajalik</title><body style="font:16px Arial,sans-serif;max-width:760px;margin:40px auto;padding:20px;color:#263746"><h1>Põhivara uuendus vajalik</h1><p>Enne selle funktsiooni kasutamist impordi phpMyAdminis andmebaasi fail <strong>pohivara/migration_006_opening_balances_and_asset_changes.sql</strong>. See lisab algseisu väljad ja hinnangute ajalootabeli; olemasolevaid kandeid ei kustutata.</p><p><a href="index.php">Tagasi põhivara lehele</a></p></body></html>';
    exit;
}
