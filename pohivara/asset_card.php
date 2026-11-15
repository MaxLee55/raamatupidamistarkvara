<?php
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: ../login.php');
    exit;
}
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/feature_migration.php';
requireFixedAssetEnhancements($pdo);

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function money(float $value): string { return number_format($value, 2, ',', ' ') . ' €'; }
function journalLink(?int $id): string {
    return $id ? '<a href="../entry.php?id=' . $id . '">Kanne #' . $id . '</a>' : '—';
}

$companyId = (int) $_SESSION['company_id'];
$assetId = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : 0;
$assetStmt = $pdo->prepare(
    'SELECT fa.*, aa.code AS asset_code, aa.name AS asset_name,
            ca.code AS accumulated_code, ca.name AS accumulated_name,
            ea.code AS expense_code, ea.name AS expense_name
     FROM fixed_assets fa
     JOIN accounts aa ON aa.id = fa.asset_account_id AND aa.company_id = fa.company_id
     JOIN accounts ca ON ca.id = fa.accumulated_depreciation_account_id AND ca.company_id = fa.company_id
     JOIN accounts ea ON ea.id = fa.depreciation_expense_account_id AND ea.company_id = fa.company_id
     WHERE fa.id = ? AND fa.company_id = ?'
);
$assetStmt->execute([$assetId, $companyId]);
$asset = $assetStmt->fetch(PDO::FETCH_ASSOC);
if (!$asset) {
    http_response_code(404);
    exit('Põhivara kirjet ei leitud.');
}

$purchaseInvoice = null;
if (!empty($asset['source_document'])) {
    $purchaseStmt = $pdo->prepare(
        "SELECT id, supplier_invoice_number, invoice_date, journal_entry_id, status
         FROM purchase_invoices
         WHERE company_id = ? AND supplier_invoice_number = ? AND status = 'posted'
         ORDER BY id DESC LIMIT 1"
    );
    $purchaseStmt->execute([$companyId, $asset['source_document']]);
    $purchaseInvoice = $purchaseStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$runStmt = $pdo->prepare(
    "SELECT r.period_start, r.amount, r.journal_entry_id, je.entry_date, je.description, je.document_number
     FROM fixed_asset_depreciation_runs r
     JOIN journal_entries je ON je.id = r.journal_entry_id AND je.company_id = r.company_id AND je.status = 'posted'
     WHERE r.company_id = ? AND r.asset_id = ?
     ORDER BY r.period_start, r.id"
);
$runStmt->execute([$companyId, $assetId]);
$runs = $runStmt->fetchAll(PDO::FETCH_ASSOC);
$estimateStmt = $pdo->prepare('SELECT effective_period, months_before_change, carrying_value_at_change, residual_value, remaining_life_months, reason FROM fixed_asset_estimate_changes WHERE company_id = ? AND asset_id = ? ORDER BY effective_period, id');
$estimateStmt->execute([$companyId, $assetId]);
$estimateChanges = $estimateStmt->fetchAll(PDO::FETCH_ASSOC);
$currentEstimate = null;
foreach ($estimateChanges as $estimateRow) {
    if ($estimateRow['effective_period'] <= date('Y-m-01')) $currentEstimate = $estimateRow;
}
$currentResidual = $currentEstimate ? (float) $currentEstimate['residual_value'] : (float) $asset['residual_value'];
$currentLife = $currentEstimate
    ? max(0, (int) $currentEstimate['remaining_life_months'] - count(array_filter($runs, static fn(array $run): bool => $run['period_start'] >= $currentEstimate['effective_period'])))
    : max(0, (int) $asset['useful_life_months'] - (int) $asset['opening_depreciation_months'] - count($runs));

$correctionTableCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'fixed_asset_depreciation_corrections'");
$correctionTableCheck->execute();
$correctionTableReady = (int) $correctionTableCheck->fetchColumn() === 1;
$depreciationCorrections = [];
if ($correctionTableReady) {
    $depreciationCorrectionsStmt = $pdo->prepare(
        "SELECT c.period_start, c.amount, c.original_journal_entry_id, c.reversal_journal_entry_id,
                c.reversal_date, c.reason,
                original.document_number AS original_document_number,
                reversal.document_number AS reversal_document_number
         FROM fixed_asset_depreciation_corrections c
         JOIN journal_entries original ON original.id = c.original_journal_entry_id
             AND original.company_id = c.company_id
         JOIN journal_entries reversal ON reversal.id = c.reversal_journal_entry_id
             AND reversal.company_id = c.company_id AND reversal.status = 'posted'
         WHERE c.company_id = ? AND c.asset_id = ?
         ORDER BY c.period_start, c.id"
    );
    $depreciationCorrectionsStmt->execute([$companyId, $assetId]);
    $depreciationCorrections = $depreciationCorrectionsStmt->fetchAll(PDO::FETCH_ASSOC);
}

$disposalStmt = $pdo->prepare(
    "SELECT d.*, je.entry_date AS journal_date, je.document_number,
            si.invoice_number AS sales_invoice_number, si.id AS sales_invoice_id,
            si.status AS sales_invoice_status,
            dr.reversal_date, dr.reason AS reversal_reason,
            rje.id AS reversal_journal_entry_id, rje.document_number AS reversal_document_number
     FROM fixed_asset_disposals d
     JOIN journal_entries je ON je.id = d.journal_entry_id AND je.company_id = d.company_id AND je.status = 'posted'
     LEFT JOIN sales_invoices si ON si.id = d.sales_invoice_id AND si.company_id = d.company_id
     LEFT JOIN fixed_asset_disposal_reversals dr ON dr.disposal_id = d.id AND dr.company_id = d.company_id
     LEFT JOIN journal_entries rje ON rje.id = dr.reversal_journal_entry_id AND rje.company_id = d.company_id AND rje.status = 'posted'
     WHERE d.company_id = ? AND d.asset_id = ?
     ORDER BY d.disposal_date, d.id"
);
$disposalStmt->execute([$companyId, $assetId]);
$disposals = $disposalStmt->fetchAll(PDO::FETCH_ASSOC);
$activeDisposal = null;
foreach ($disposals as $disposalRow) {
    if ($disposalRow['status'] === 'posted') $activeDisposal = $disposalRow;
}
$reversedEntryId = isset($_GET['reversed']) && ctype_digit((string) $_GET['reversed']) ? (int) $_GET['reversed'] : 0;
$reversedEntry = null;
if ($reversedEntryId > 0) {
    $reversedStmt = $pdo->prepare("SELECT id, entry_date FROM journal_entries WHERE id = ? AND company_id = ? AND status = 'posted'");
    $reversedStmt->execute([$reversedEntryId, $companyId]);
    $reversedEntry = $reversedStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$events = [];
require_once dirname(__DIR__).'/credit_note_lib.php';
ensureCreditNoteSchema($pdo);
$creditHistory=$pdo->prepare('SELECT l.*,i.invoice_date,i.supplier_invoice_number,i.journal_entry_id,i.status AS credit_status FROM credit_note_asset_links l JOIN purchase_invoices i ON i.id=l.credit_invoice_id AND i.company_id=l.company_id WHERE l.company_id=? AND l.asset_id=? ORDER BY l.credit_invoice_id');
$creditHistory->execute([$companyId,$assetId]); $assetCredits=$creditHistory->fetchAll(PDO::FETCH_ASSOC);
foreach($assetCredits as $assetCredit) {
    $before=json_decode($assetCredit['before_json'],true); $after=json_decode($assetCredit['after_json'],true);
    $events[]=['date'=>$assetCredit['invoice_date'],'sort'=>2,'title'=>'Ostu kreeditarve'.($assetCredit['credit_status']==='cancelled'?' (tühistatud)':''),
        'details'=>'Soetusmaksumus '.money((float)$before['acquisition_cost']).' → '.money((float)$after['acquisition_cost']),
        'document'=>'<a href="../purchase_invoices.php?id='.(int)$assetCredit['credit_invoice_id'].'">'.h($assetCredit['supplier_invoice_number']).'</a>',
        'journal'=>journalLink((int)$assetCredit['journal_entry_id']),'amount'=>$assetCredit['credit_status']==='cancelled'?'Tühistatud':money((float)$after['acquisition_cost']-(float)$before['acquisition_cost'])];
}
$events[] = [
    'date' => $asset['acquisition_date'], 'sort' => 0, 'title' => 'Soetmine',
    'details' => 'Soetusmaksumus ' . money((float) $asset['acquisition_cost'])
        . ($purchaseInvoice ? ' · Ostuarve ' . $purchaseInvoice['supplier_invoice_number'] : ' · Algdokument ' . ($asset['source_document'] ?: 'lisamata')),
    'document' => $purchaseInvoice ? '<a href="../purchase_invoices.php?id=' . (int) $purchaseInvoice['id'] . '">Ostuarve ' . h($purchaseInvoice['supplier_invoice_number']) . '</a>' : h($asset['source_document'] ?: '—'),
    'journal' => $purchaseInvoice ? journalLink($purchaseInvoice['journal_entry_id'] !== null ? (int) $purchaseInvoice['journal_entry_id'] : null) : '—',
    'amount' => money((float) $asset['acquisition_cost']),
];
if ((float) $asset['opening_accumulated_depreciation'] > 0 || (int) $asset['opening_depreciation_months'] > 0) {
    $events[] = [
        'date' => $asset['available_date'], 'sort' => 1, 'title' => 'Sisestatud põhivara algseis',
        'details' => 'Enne mooduli kasutuselevõttu arvestatud kulum ' . money((float) $asset['opening_accumulated_depreciation'])
            . ' · arvestatud ' . (int) $asset['opening_depreciation_months'] . ' kuud',
        'document' => h($asset['source_document'] ?: 'Algseisu dokument'), 'journal' => '—',
        'amount' => 'Algseis',
    ];
}
foreach ($runs as $run) {
    $events[] = [
        'date' => $run['entry_date'], 'sort' => 1, 'title' => 'Amortisatsioon ' . substr($run['period_start'], 0, 7),
        'details' => 'Kulum ' . money((float) $run['amount']),
        'document' => h($run['document_number'] ?: $run['description']),
        'journal' => journalLink((int) $run['journal_entry_id']),
        'amount' => '−' . money((float) $run['amount']),
    ];
}
foreach ($estimateChanges as $change) {
    $events[] = [
        'date' => $change['effective_period'], 'sort' => 1, 'title' => 'Amortisatsiooni arvestushinnang muutus',
        'details' => 'Lõppväärtus ' . money((float) $change['residual_value']) . ' · järelejäänud eluiga ' . (int) $change['remaining_life_months'] . ' kuud · ' . $change['reason'],
        'document' => 'Arvestushinnangu muudatus', 'journal' => '—', 'amount' => 'Edaspidine mõju',
    ];
}
foreach ($depreciationCorrections as $correction) {
    $month = substr($correction['period_start'], 0, 7);
    $events[] = [
        'date' => $correction['reversal_date'], 'sort' => 2,
        'title' => 'Amortisatsiooni parandus ' . $month,
        'details' => 'Varasem kulum ' . money((float) $correction['amount'])
            . ' pöörati ümber · ' . $correction['reason'],
        'document' => h($correction['original_document_number'] ?: 'Kanne #' . $correction['original_journal_entry_id'])
            . ' → ' . h($correction['reversal_document_number'] ?: 'Vastukanne #' . $correction['reversal_journal_entry_id']),
        'journal' => journalLink((int) $correction['reversal_journal_entry_id']),
        'amount' => '+' . money((float) $correction['amount']),
    ];
}
foreach ($disposals as $disposal) {
    $events[] = [
        'date' => $disposal['disposal_date'], 'sort' => 2,
        'title' => ($disposal['disposal_type'] === 'sale' ? 'Müük' : 'Mahakandmine') . ($disposal['status'] === 'reversed' ? ' (tühistatud)' : ''),
        'details' => 'Kõrvaldamisel suletud soetusmaksumus ' . money((float) $disposal['acquisition_cost'])
            . '; akumuleeritud kulum ' . money((float) $disposal['accumulated_depreciation'])
            . '; jääkmaksumus ' . money((float) $disposal['carrying_value'])
            . ($disposal['notes'] ? ' · ' . $disposal['notes'] : ''),
        'document' => $disposal['sales_invoice_id']
            ? '<a href="../invoices.php?id=' . (int) $disposal['sales_invoice_id'] . '">Müügiarve ' . h($disposal['sales_invoice_number']) . '</a>'
            : h($disposal['document_number']),
        'journal' => journalLink((int) $disposal['journal_entry_id']),
        'amount' => $disposal['status'] === 'reversed' ? 'Tühistatud' : 'Kõrvaldatud',
    ];
    if (!empty($disposal['reversal_journal_entry_id'])) {
        $events[] = [
            'date' => $disposal['reversal_date'], 'sort' => 3, 'title' => 'Kõrvaldamise vastukanne',
            'details' => 'Põhivara taastati aktiivseks · ' . $disposal['reversal_reason'],
            'document' => h($disposal['reversal_document_number'] ?: '—'),
            'journal' => journalLink((int) $disposal['reversal_journal_entry_id']),
            'amount' => 'Taastatud',
        ];
    }
}
usort($events, static function (array $a, array $b): int {
    $dateOrder = strcmp($a['date'], $b['date']);
    return $dateOrder !== 0 ? $dateOrder : ($a['sort'] <=> $b['sort']);
});

$totalDepreciation = (float) $asset['opening_accumulated_depreciation'] + array_sum(array_map(static fn(array $run): float => (float) $run['amount'], $runs));
$carryingValue = max(0, (float) $asset['acquisition_cost'] - $totalDepreciation);
$statusLabels = ['draft' => 'Mustand', 'active' => 'Aktiivne', 'disposed' => 'Kõrvaldatud','returned'=>'Tarnijale tagastatud'];
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Põhivara kaart · <?= h($asset['asset_number']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { width: 100%; margin: 0; padding: 34px clamp(18px, 3vw, 48px); background: #f4f7fb; color: #263746; font-family: Arial, sans-serif; }
        h1, h2 { color: #17324d; }
        .intro, .muted { color: #667788; line-height: 1.55; }
        .notice { padding: 12px 14px; border-radius: 8px; line-height: 1.5; background: #eaf7ef; color: #176b3a; }
        .panel { margin-bottom: 18px; padding: 22px; background: #fff; border: 1px solid #dce3ec; border-radius: 12px; }
        .summary { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
        .metric { padding: 14px; background: #f7f9fc; border: 1px solid #e1e7ef; border-radius: 9px; }
        .metric span { display: block; margin-bottom: 5px; color: #667788; }
        .metric strong { color: #17324d; font-size: 18px; }
        .details { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px 24px; }
        .details dt { color: #667788; font-size: 14px; }
        .details dd { margin: 3px 0 0; font-weight: 700; }
        .details dd small { display: block; margin-top: 4px; font-weight: 400; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 11px 13px; border-bottom: 1px solid #e1e7ef; text-align: left; vertical-align: top; }
        th { background: #f3f6fa; color: #33475b; }
        td small { display: block; margin-top: 4px; color: #667788; }
        .amount { white-space: nowrap; text-align: right; }
        .status { display: inline-block; padding: 4px 9px; border-radius: 999px; background: #fff3d7; color: #735400; font-weight: 700; font-size: 13px; }
        .actions { display: flex; flex-wrap: wrap; gap: 9px; margin: 18px 0; }
        .button { display: inline-block; padding: 10px 15px; border-radius: 7px; background: #657789; color: #fff; font-weight: 700; text-decoration: none; }
        @media (max-width: 750px) { body { padding: 20px 14px; } .summary { grid-template-columns: repeat(2, minmax(0, 1fr)); } .details { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 450px) { .summary, .details { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <h1>Põhivara kaart</h1>
    <p class="intro">Vara andmed ja pearaamatusse jõudnud liikumiste ajalugu ühel lehel.</p>
    <?php if (!$correctionTableReady): ?><p class="notice">Amortisatsiooni paranduste ajalugu kuvatakse pärast lisatabeli paigaldamist. Vaata kasutusjuhendit ja faili pohivara/migration_005_depreciation_corrections.sql.</p><?php endif; ?>
    <?php if ($reversedEntry): ?><p class="notice">Kõrvaldamine parandati. Vara taastati aktiivseks; vastukanne <a href="../entry.php?id=<?= (int) $reversedEntry['id'] ?>">#<?= (int) $reversedEntry['id'] ?></a> postitati <?= h($reversedEntry['entry_date']) ?>.</p><?php endif; ?>
    <?php if (isset($_GET['estimate_changed'])): ?><p class="notice">Arvestushinnangu muudatus salvestati ja rakendub valitud kuust edasi.</p><?php endif; ?>
    <div class="actions"><a class="button" href="assets.php">Tagasi põhivara registrisse</a><a class="button" href="depreciation.php">Põhivara amortisatsioon</a><?php if ($asset['status'] === 'active'): ?><a class="button" href="change_estimate.php?asset_id=<?= (int) $asset['id'] ?>">Muuda arvestushinnangut</a><?php endif; ?></div>
    <p><a href="../kmd_adjustment_register.php?asset_id=<?= (int)$asset['id'] ?>">Sisendkäibemaksu ümberarvestus</a></p>
    <section class="panel">
        <h2><?= h($asset['asset_number'] . ' — ' . $asset['name']) ?> <span class="status"><?= h($statusLabels[$asset['status']] ?? $asset['status']) ?></span></h2>
        <div class="summary">
            <div class="metric"><span>Soetusmaksumus</span><strong><?= money((float) $asset['acquisition_cost']) ?></strong></div>
            <div class="metric"><span>Arvestatud kulum</span><strong><?= money($totalDepreciation) ?></strong></div>
            <div class="metric"><span>Bilansiline jääk</span><strong><?= money($asset['status'] === 'disposed' ? 0 : $carryingValue) ?></strong></div>
            <div class="metric"><span><?= $currentEstimate ? 'Järelejäänud eluiga' : 'Kasulik eluiga' ?></span><strong><?= $currentLife ?> kuud</strong></div>
        </div>
    </section>
    <?php if ($activeDisposal && $asset['status'] === 'disposed'): ?>
        <section class="panel">
            <h2>Parandamise võimalus</h2>
            <p class="muted">Kui kõrvaldamine oli ekslik, saad algsele kandele luua täpse vastukande ja vara aktiivseks taastada.</p>
            <?php if ($activeDisposal['disposal_type'] === 'sale' && $activeDisposal['sales_invoice_status'] !== 'cancelled'): ?>
                <p class="notice">Müügi korral tühista kõigepealt seotud müügiarve koos vastukandega. <a href="../invoices.php?id=<?= (int) $activeDisposal['sales_invoice_id'] ?>">Ava müügiarve <?= h($activeDisposal['sales_invoice_number']) ?></a>.</p>
            <?php else: ?>
                <a class="button" style="background:#a83232" href="reverse_disposal.php?disposal_id=<?= (int) $activeDisposal['id'] ?>">Paranda kõrvaldamist</a>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <section class="panel">
        <h2>Vara andmed</h2>
        <dl class="details">
            <div><dt>Kategooria</dt><dd><?= h($asset['category'] ?: '—') ?></dd></div>
            <div><dt>Algdokument</dt><dd><?= h($asset['source_document'] ?: '—') ?></dd></div>
            <div><dt>Soetamise kuupäev</dt><dd><?= h($asset['acquisition_date']) ?></dd></div>
            <div><dt>Kasutusvalmis</dt><dd><?= h($asset['available_date']) ?></dd></div>
            <div><dt><?= $currentEstimate ? 'Kehtiv lõppväärtus' : 'Hinnanguline lõppväärtus' ?></dt><dd><?= money($currentResidual) ?><?php if ($currentEstimate): ?><small class="muted">Algne: <?= money((float) $asset['residual_value']) ?></small><?php endif; ?></dd></div>
            <?php if ((float) $asset['opening_accumulated_depreciation'] > 0 || (int) $asset['opening_depreciation_months'] > 0): ?><div><dt>Algseisu kulum / varasemad kuud</dt><dd><?= money((float) $asset['opening_accumulated_depreciation']) ?> / <?= (int) $asset['opening_depreciation_months'] ?> kuud</dd></div><?php endif; ?>
            <div><dt>Kontod</dt><dd><?= h($asset['asset_code'] . ' — ' . $asset['asset_name']) ?><small class="muted"><?= h($asset['accumulated_code'] . ' — ' . $asset['accumulated_name']) ?><br><?= h($asset['expense_code'] . ' — ' . $asset['expense_name']) ?></small></dd></div>
            <div><dt>Märkus</dt><dd><?= nl2br(h($asset['notes'] ?: '—')) ?></dd></div>
        </dl>
    </section>
    <section class="panel">
        <h2>Liikumiste ajalugu</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>Kuupäev</th><th>Toiming</th><th>Dokument</th><th class="amount">Summa</th><th>Pea­raamatu kanne</th></tr></thead>
            <tbody><?php foreach ($events as $event): ?>
                <tr><td><?= h($event['date']) ?></td><td><strong><?= h($event['title']) ?></strong><small><?= h($event['details']) ?></small></td><td><?= $event['document'] ?></td><td class="amount"><?= h($event['amount']) ?></td><td><?= $event['journal'] ?></td></tr>
            <?php endforeach; ?></tbody>
        </table></div>
        <?php if (!$runs && !$depreciationCorrections && !$disposals): ?><p class="muted">Amortisatsiooni ega kõrvaldamise kandeid pole veel registreeritud.</p><?php endif; ?>
    </section>
</body>
</html>
