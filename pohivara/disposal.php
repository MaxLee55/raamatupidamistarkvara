<?php
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: ../login.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/period_lock.php';

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function validDate(string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}
function euro(float $value): string { return number_format($value, 2, ',', ' ') . ' €'; }
function invoiceMatchesAsset(array $descriptions, string $assetNumber, string $assetName): bool {
    $normalize = static function (string $value): string {
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    };
    $number = $normalize($assetNumber);
    $numberCompact = preg_replace('/[^\p{L}\p{N}]+/u', '', $number) ?? $number;
    $nameTokens = preg_split('/[^\p{L}\p{N}]+/u', $normalize($assetName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($descriptions as $description) {
        $normalized = $normalize((string) $description);
        $compact = preg_replace('/[^\p{L}\p{N}]+/u', '', $normalized) ?? $normalized;
        if ($number !== '' && (strpos($normalized, $number) !== false || strpos($compact, $numberCompact) !== false)) return true;
        $descriptionTokens = preg_split('/[^\p{L}\p{N}]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($nameTokens as $token) {
            $prefix = function_exists('mb_substr') ? mb_substr($token, 0, 5, 'UTF-8') : substr($token, 0, 5);
            $length = function_exists('mb_strlen') ? mb_strlen($token, 'UTF-8') : strlen($token);
            if ($length < 5) continue;
            foreach ($descriptionTokens as $descriptionToken) {
                if (strpos($descriptionToken, $prefix) === 0) return true;
            }
        }
    }
    return false;
}

$companyId = (int) $_SESSION['company_id'];
$errors = [];
$saved = isset($_GET['saved']);
$assetId = (int) ($_POST['asset_id'] ?? $_GET['asset_id'] ?? 0);
$form = [
    'disposal_date' => (string) ($_POST['disposal_date'] ?? date('Y-m-d')),
    'disposal_type' => (string) ($_POST['disposal_type'] ?? 'writeoff'),
    'sales_invoice_id' => (string) ($_POST['sales_invoice_id'] ?? ''),
    'disposal_expense_account_id' => (string) ($_POST['disposal_expense_account_id'] ?? ''),
    'notes' => trim((string) ($_POST['notes'] ?? '')),
];

$assetStmt = $pdo->prepare(
    "SELECT fa.*, aa.code AS asset_code, aa.name AS asset_name,
            da.code AS accumulated_code, da.name AS accumulated_name
     FROM fixed_assets fa
     JOIN accounts aa ON aa.id = fa.asset_account_id AND aa.company_id = fa.company_id
     JOIN accounts da ON da.id = fa.accumulated_depreciation_account_id AND da.company_id = fa.company_id
     WHERE fa.id = ? AND fa.company_id = ?"
);
$assetStmt->execute([$assetId, $companyId]);
$asset = $assetStmt->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$asset) $errors[] = 'Põhivara kirjet ei leitud.';
elseif ($asset['status'] !== 'active') $errors[] = 'Müüa või maha kanda saab ainult aktiivset põhivara.';

$depreciationStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(r.amount), 0) AS accumulated, COUNT(r.id) AS months_count,
            MAX(je.entry_date) AS last_entry_date
     FROM fixed_asset_depreciation_runs r
     JOIN journal_entries je ON je.id = r.journal_entry_id AND je.status = 'posted'
     WHERE r.company_id = ? AND r.asset_id = ?"
);
$depreciationStmt->execute([$companyId, $assetId]);
$depreciation = $depreciationStmt->fetch(PDO::FETCH_ASSOC) ?: ['accumulated' => 0, 'months_count' => 0, 'last_entry_date' => null];
$cost = $asset ? (float) $asset['acquisition_cost'] : 0.0;
$accumulated = (float) $depreciation['accumulated'];
$carryingValue = max(0, $cost - $accumulated);

$expenseStmt = $pdo->prepare("SELECT id, code, name FROM accounts WHERE company_id = ? AND account_type = 'expense' AND is_active = 1 ORDER BY code");
$expenseStmt->execute([$companyId]);
$expenseAccounts = $expenseStmt->fetchAll(PDO::FETCH_ASSOC);
if ($form['disposal_expense_account_id'] === '') {
    foreach ($expenseAccounts as $expenseAccount) {
        if ($expenseAccount['code'] === '6200') { $form['disposal_expense_account_id'] = (string) $expenseAccount['id']; break; }
    }
}

$invoiceStmt = $pdo->prepare(
    "SELECT si.id, si.invoice_number, si.invoice_date,
            COALESCE(SUM(sil.net_amount), 0) AS net_amount,
            COALESCE(SUM(sil.vat_amount), 0) AS vat_amount,
            COALESCE(SUM(sil.gross_amount), 0) AS gross_amount
     FROM sales_invoices si
     JOIN sales_invoice_lines sil ON sil.invoice_id = si.id
     WHERE si.company_id = ? AND si.status = 'posted'
       AND NOT EXISTS (SELECT 1 FROM fixed_asset_disposals d WHERE d.company_id = si.company_id AND d.sales_invoice_id = si.id AND d.status = 'posted')
     GROUP BY si.id, si.invoice_number, si.invoice_date
     ORDER BY si.invoice_date DESC, si.id DESC"
);
$invoiceStmt->execute([$companyId]);
$invoiceRows = $invoiceStmt->fetchAll(PDO::FETCH_ASSOC);
$invoiceLinesStmt = $pdo->prepare('SELECT description FROM sales_invoice_lines WHERE invoice_id = ? ORDER BY id');
$salesInvoices = [];
if ($asset) {
    foreach ($invoiceRows as $invoiceRow) {
        $invoiceLinesStmt->execute([(int) $invoiceRow['id']]);
        if (invoiceMatchesAsset($invoiceLinesStmt->fetchAll(PDO::FETCH_COLUMN), (string) $asset['asset_number'], (string) $asset['name'])) {
            $salesInvoices[] = $invoiceRow;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dispose') {
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    } elseif (!$asset || $asset['status'] !== 'active') {
        $errors[] = 'Seda vara ei saa enam kõrvaldada.';
    } elseif (!validDate($form['disposal_date'])) {
        $errors[] = 'Sisesta kehtiv müügi või mahakandmise kuupäev.';
    } elseif (!in_array($form['disposal_type'], ['sale', 'writeoff'], true)) {
        $errors[] = 'Vali müük või mahakandmine.';
    } elseif (!ctype_digit($form['disposal_expense_account_id']) || (int) $form['disposal_expense_account_id'] < 1) {
        $errors[] = 'Vali kahjumi või mahakandmise kulukonto.';
    } elseif (mb_strlen($form['notes']) > 500) {
        $errors[] = 'Märkus võib olla kuni 500 märki.';
    } elseif ($form['disposal_type'] === 'sale' && (!ctype_digit($form['sales_invoice_id']) || (int) $form['sales_invoice_id'] < 1)) {
        $errors[] = 'Müügi korral vali juba kinnitatud müügiarve.';
    } else {
        try {
            $pdo->beginTransaction();
            $lockAssetStmt = $pdo->prepare("SELECT * FROM fixed_assets WHERE id = ? AND company_id = ? AND status = 'active' FOR UPDATE");
            $lockAssetStmt->execute([$assetId, $companyId]);
            $lockedAsset = $lockAssetStmt->fetch(PDO::FETCH_ASSOC);
            if (!$lockedAsset) throw new RuntimeException('Vara pole enam aktiivne või on juba müüdud/kõrvaldatud.');

            $duplicateStmt = $pdo->prepare("SELECT id FROM fixed_asset_disposals WHERE company_id = ? AND asset_id = ? AND status = 'posted'");
            $duplicateStmt->execute([$companyId, $assetId]);
            if ($duplicateStmt->fetchColumn()) throw new RuntimeException('Selle vara kõrvaldamine on juba registreeritud.');

            $date = $form['disposal_date'];
            if ($date < $lockedAsset['available_date']) throw new RuntimeException('Kõrvaldamise kuupäev ei saa olla varasem kui vara kasutusvalmis kuupäev.');
            $periodMessage = closedAccountingPeriodMessage($pdo, $companyId, $date);
            if ($periodMessage !== null) throw new RuntimeException($periodMessage);

            $accStmt = $pdo->prepare(
                "SELECT COALESCE(SUM(r.amount), 0), MAX(je.entry_date)
                 FROM fixed_asset_depreciation_runs r
                 JOIN journal_entries je ON je.id = r.journal_entry_id AND je.status = 'posted'
                 WHERE r.company_id = ? AND r.asset_id = ?"
            );
            $accStmt->execute([$companyId, $assetId]);
            [$accumText, $lastDepDate] = $accStmt->fetch(PDO::FETCH_NUM);
            $accumulatedAmount = (float) $accumText;
            $assetCost = (float) $lockedAsset['acquisition_cost'];
            if ($accumulatedAmount > $assetCost + 0.005) throw new RuntimeException('Akumuleeritud kulum ületab vara soetusmaksumust; kontrolli amortisatsioonikandeid.');
            if ($lastDepDate && $date < $lastDepDate) throw new RuntimeException('Kõrvaldamise kuupäev ei saa olla varasem kui juba postitatud amortisatsioonikanne (' . $lastDepDate . ').');
            $bookValue = max(0, round($assetCost - $accumulatedAmount, 2));

            $expenseAccountStmt = $pdo->prepare("SELECT id, code, name FROM accounts WHERE id = ? AND company_id = ? AND account_type = 'expense' AND is_active = 1 FOR UPDATE");
            $expenseAccountStmt->execute([(int) $form['disposal_expense_account_id'], $companyId]);
            $expenseAccount = $expenseAccountStmt->fetch(PDO::FETCH_ASSOC);
            if (!$expenseAccount) throw new RuntimeException('Valitud kahjumikonto pole aktiivne selle ettevõtte kulukonto.');

            $salesInvoiceId = null;
            if ($form['disposal_type'] === 'sale') {
                $saleStmt = $pdo->prepare(
                    "SELECT id, invoice_number FROM sales_invoices
                     WHERE id = ? AND company_id = ? AND status = 'posted' FOR UPDATE"
                );
                $saleStmt->execute([(int) $form['sales_invoice_id'], $companyId]);
                $saleInvoice = $saleStmt->fetch(PDO::FETCH_ASSOC);
                if (!$saleInvoice) throw new RuntimeException('Valitud müügiarve ei ole selle ettevõtte kinnitatud arve.');
                $saleLinesStmt = $pdo->prepare('SELECT description FROM sales_invoice_lines WHERE invoice_id = ? ORDER BY id FOR UPDATE');
                $saleLinesStmt->execute([(int) $saleInvoice['id']]);
                if (!invoiceMatchesAsset($saleLinesStmt->fetchAll(PDO::FETCH_COLUMN), (string) $lockedAsset['asset_number'], (string) $lockedAsset['name'])) {
                    throw new RuntimeException('Valitud arve rida ei tuvasta seda vara nime või numbri järgi. Kasuta arvel vara nime või numbrit; vajadusel tühista arve ja koosta uus.');
                }
                $usedInvoiceStmt = $pdo->prepare("SELECT id FROM fixed_asset_disposals WHERE company_id = ? AND sales_invoice_id = ? AND status = 'posted'");
                $usedInvoiceStmt->execute([$companyId, (int) $saleInvoice['id']]);
                if ($usedInvoiceStmt->fetchColumn()) throw new RuntimeException('See müügiarve on juba seotud teise põhivara müügiga.');
                $salesInvoiceId = (int) $saleInvoice['id'];
            }

            $entryDescription = ($form['disposal_type'] === 'sale' ? 'Põhivara müük ' : 'Põhivara mahakandmine ')
                . $lockedAsset['asset_number'];
            $documentNumber = 'PV-OUT-' . (int) $lockedAsset['id'];
            $entryStmt = $pdo->prepare(
                "INSERT INTO journal_entries (company_id, entry_date, description, document_number, status, created_by, posted_at)
                 VALUES (?, ?, ?, ?, 'posted', ?, CURRENT_TIMESTAMP)"
            );
            $entryStmt->execute([$companyId, $date, $entryDescription, $documentNumber, (int) $_SESSION['user_id']]);
            $entryId = (int) $pdo->lastInsertId();
            $lineStmt = $pdo->prepare('INSERT INTO journal_lines (entry_id, account_id, description, debit, credit) VALUES (?, ?, ?, ?, ?)');
            if ($accumulatedAmount > 0) {
                $lineStmt->execute([$entryId, (int) $lockedAsset['accumulated_depreciation_account_id'], 'Vara akumuleeritud kulumi sulgemine', number_format($accumulatedAmount, 2, '.', ''), '0.00']);
            }
            if ($bookValue > 0) {
                $lossDescription = $form['disposal_type'] === 'sale' ? 'Müüdud vara bilansilise jääkmaksumuse mahakandmine' : 'Mahakantud vara bilansiline jääkmaksumus';
                $lineStmt->execute([$entryId, (int) $expenseAccount['id'], $lossDescription, number_format($bookValue, 2, '.', ''), '0.00']);
            }
            $lineStmt->execute([$entryId, (int) $lockedAsset['asset_account_id'], 'Vara soetusmaksumuse sulgemine', '0.00', number_format($assetCost, 2, '.', '')]);

            $disposalStmt = $pdo->prepare(
                'INSERT INTO fixed_asset_disposals
                    (company_id, asset_id, disposal_date, disposal_type, sales_invoice_id, acquisition_cost,
                     accumulated_depreciation, carrying_value, disposal_expense_account_id, journal_entry_id, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $disposalStmt->execute([
                $companyId, $assetId, $date, $form['disposal_type'], $salesInvoiceId,
                number_format($assetCost, 2, '.', ''), number_format($accumulatedAmount, 2, '.', ''),
                number_format($bookValue, 2, '.', ''), (int) $expenseAccount['id'], $entryId,
                $form['notes'] !== '' ? $form['notes'] : null, (int) $_SESSION['user_id'],
            ]);
            $updateAssetStmt = $pdo->prepare("UPDATE fixed_assets SET status = 'disposed' WHERE id = ? AND company_id = ? AND status = 'active'");
            $updateAssetStmt->execute([$assetId, $companyId]);
            if ($updateAssetStmt->rowCount() !== 1) throw new RuntimeException('Vara oleku uuendamine ebaõnnestus.');
            $pdo->commit();
            header('Location: assets.php?disposal=' . $entryId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof RuntimeException) $errors[] = $e->getMessage();
            else { error_log($e->getMessage()); $errors[] = 'Põhivara müügi või mahakandmise kanne ebaõnnestus.'; }
        }
    }
}

if ($asset) {
    $cost = (float) $asset['acquisition_cost'];
    $accumulated = (float) $depreciation['accumulated'];
    $carryingValue = max(0, round($cost - $accumulated, 2));
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Põhivara müük või mahakandmine</title>
    <style>
        * { box-sizing: border-box; }
        body { max-width: 1050px; margin: 34px auto; padding: 0 22px; background: #f4f7fb; color: #263746; font-family: Arial, sans-serif; }
        h1, h2 { color: #17324d; }
        .panel { margin-bottom: 18px; padding: 22px; background: #fff; border: 1px solid #dce3ec; border-radius: 12px; }
        .muted { color: #667788; line-height: 1.55; }
        .notice { padding: 12px 14px; border-radius: 8px; line-height: 1.5; background: #fff6df; color: #694e00; }
        .error { background: #fff0f0; color: #9a2929; }
        .summary { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
        .metric { padding: 14px; background: #f7f9fc; border: 1px solid #e1e7ef; border-radius: 9px; }
        .metric span { display: block; color: #667788; margin-bottom: 5px; }
        .metric strong { color: #17324d; font-size: 18px; }
        .field { margin: 14px 0; }
        label { display: block; margin-bottom: 6px; font-weight: 700; }
        input, select, textarea { width: 100%; padding: 10px 12px; border: 1px solid #b8c4d1; border-radius: 7px; background: #fff; font: inherit; }
        textarea { min-height: 78px; resize: vertical; }
        .button { display: inline-block; padding: 11px 16px; border: 0; border-radius: 7px; background: #a83232; color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .secondary { background: #657789; }
        .actions { display: flex; flex-wrap: wrap; gap: 9px; margin-top: 18px; }
        .hidden { display: none; }
        @media (max-width: 650px) { body { margin: 18px auto; padding: 0 14px; } .summary { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <h1>Põhivara müük või mahakandmine</h1>
    <?php foreach ($errors as $error): ?><p class="notice error"><?= h($error) ?></p><?php endforeach; ?>
    <?php if ($asset && $asset['status'] === 'active'): ?>
        <section class="panel">
            <h2><?= h($asset['asset_number'] . ' — ' . $asset['name']) ?></h2>
            <div class="summary">
                <div class="metric"><span>Soetusmaksumus</span><strong><?= euro($cost) ?></strong></div>
                <div class="metric"><span>Arvestatud kulum</span><strong><?= euro($accumulated) ?></strong></div>
                <div class="metric"><span>Bilansiline jääkmaksumus</span><strong><?= euro($carryingValue) ?></strong></div>
            </div>
        </section>
        <section class="panel">
            <h2>Toimingu andmed</h2>
            <p class="notice">Toiming sulgeb põhivara maksumuse ja akumuleeritud kulumi ning postitab kande valitud kuupäevaga. Müügi korral peab juba kinnitatud arve real olema vara nimi või number; tulu ja käibemaksu siin uuesti ei kanta.</p>
            <form method="post" onsubmit="return confirm('Kinnita põhivara müük või mahakandmine? Toiming postitab pearaamatu kande ja kõrvaldab vara registrist.');">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="dispose">
                <input type="hidden" name="asset_id" value="<?= (int) $asset['id'] ?>">
                <div class="field"><label for="disposal_type">Toimingu liik</label><select id="disposal_type" name="disposal_type"><option value="writeoff" <?= $form['disposal_type'] === 'writeoff' ? 'selected' : '' ?>>Mahakandmine / tasuta kõrvaldamine</option><option value="sale" <?= $form['disposal_type'] === 'sale' ? 'selected' : '' ?>>Müük (müügiarve on juba kinnitatud)</option></select></div>
                <div class="field"><label for="disposal_date">Müügi või mahakandmise kuupäev</label><input type="date" id="disposal_date" name="disposal_date" value="<?= h($form['disposal_date']) ?>" required></div>
                <div class="field<?= $form['disposal_type'] === 'sale' ? '' : ' hidden' ?>" id="sales_invoice_field"><label for="sales_invoice_id">Kinnitatud müügiarve</label><select id="sales_invoice_id" name="sales_invoice_id"><option value="">Vali müügiarve</option><?php foreach ($salesInvoices as $invoice): ?><option value="<?= (int) $invoice['id'] ?>" <?= $form['sales_invoice_id'] === (string) $invoice['id'] ? 'selected' : '' ?>><?= h($invoice['invoice_number'] . ' · ' . $invoice['invoice_date'] . ' · neto ' . euro((float) $invoice['net_amount']) . ' · kokku ' . euro((float) $invoice['gross_amount'])) ?></option><?php endforeach; ?></select><small class="muted">Valikus kuvatakse arved, mille vähemalt üks rida sisaldab vara numbrit või nimetusega sobivat kirjeldust.</small></div>
                <div class="field"><label for="disposal_expense_account_id">Jääkmaksumuse kulukonto</label><select id="disposal_expense_account_id" name="disposal_expense_account_id" required><option value="">Vali kulukonto</option><?php foreach ($expenseAccounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= $form['disposal_expense_account_id'] === (string) $account['id'] ? 'selected' : '' ?>><?= h($account['code'] . ' — ' . $account['name']) ?></option><?php endforeach; ?></select><small class="muted">Mahakandmisel on see mahakandmiskulu; müügi puhul kantakse siia vara bilansiline jääkmaksumus.</small></div>
                <div class="field"><label for="notes">Märkus</label><textarea id="notes" name="notes" maxlength="500"><?= h($form['notes']) ?></textarea></div>
                <div class="actions"><button class="button" type="submit">Kinnita müük või mahakandmine</button><a class="button secondary" href="assets.php">Katkesta</a></div>
            </form>
        </section>
    <?php else: ?>
        <section class="panel"><p class="notice">Selle varaga ei saa praegu toimingut teha. Ava põhivara register ja kontrolli vara olekut.</p><a class="button secondary" href="assets.php">Põhivara register</a></section>
    <?php endif; ?>
    <script>
        const typeField = document.getElementById('disposal_type');
        const invoiceField = document.getElementById('sales_invoice_field');
        if (typeField && invoiceField) typeField.addEventListener('change', () => invoiceField.classList.toggle('hidden', typeField.value !== 'sale'));
    </script>
</body>
</html>
