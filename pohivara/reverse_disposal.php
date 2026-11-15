<?php
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: ../login.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/period_lock.php';
require_once dirname(__DIR__) . '/purchase_invoice_cancel_lib.php';
ensureSalesInvoiceCancellationTable($pdo);

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function validDate(string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}
function reversalEuro(float $value): string { return number_format($value, 2, ',', ' ') . ' €'; }

$companyId = (int) $_SESSION['company_id'];
$disposalId = isset($_POST['disposal_id']) && ctype_digit((string) $_POST['disposal_id'])
    ? (int) $_POST['disposal_id'] : (isset($_GET['disposal_id']) && ctype_digit((string) $_GET['disposal_id']) ? (int) $_GET['disposal_id'] : 0);
$errors = [];
$disposalStmt = $pdo->prepare(
    "SELECT d.*, fa.asset_number, fa.name AS asset_name, fa.status AS asset_status,
            si.invoice_number, si.status AS sales_invoice_status,
            sc.cancelled_at, reversal.entry_date AS invoice_reversal_date
     FROM fixed_asset_disposals d
     JOIN fixed_assets fa ON fa.id = d.asset_id AND fa.company_id = d.company_id
     LEFT JOIN sales_invoices si ON si.id = d.sales_invoice_id AND si.company_id = d.company_id
     LEFT JOIN sales_invoice_cancellations sc ON sc.sales_invoice_id = si.id AND sc.company_id = d.company_id
     LEFT JOIN journal_entries reversal ON reversal.id = sc.reversal_journal_entry_id AND reversal.company_id = d.company_id
     WHERE d.id = ? AND d.company_id = ?"
);
$disposalStmt->execute([$disposalId, $companyId]);
$disposal = $disposalStmt->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$disposal) $errors[] = 'Kõrvaldamise kirjet ei leitud.';
elseif ($disposal['status'] !== 'posted' || $disposal['asset_status'] !== 'disposed') $errors[] = 'Seda kõrvaldamist on juba parandatud või vara pole kõrvaldatud olekus.';

$minimumDate = date('Y-m-d');
if ($disposal) {
    $minimumDate = max($minimumDate, $disposal['disposal_date']);
    if ($disposal['invoice_reversal_date']) $minimumDate = max($minimumDate, $disposal['invoice_reversal_date']);
}
$formDate = (string) ($_POST['reversal_date'] ?? $minimumDate);
$reason = trim((string) ($_POST['reason'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    } elseif (!$disposal || $disposal['status'] !== 'posted' || $disposal['asset_status'] !== 'disposed') {
        $errors[] = 'Seda kõrvaldamist ei saa enam parandada.';
    } elseif (!validDate($formDate) || $formDate < $disposal['disposal_date']) {
        $errors[] = 'Paranduskande kuupäev peab olema kehtiv ja kõrvaldamise kuupäevaga samal või hilisemal päeval.';
    } elseif (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
        $errors[] = 'Kirjelda parandamise põhjust (3–500 märki).';
    } elseif ($disposal['disposal_type'] === 'sale'
        && ($disposal['sales_invoice_status'] !== 'cancelled' || empty($disposal['invoice_reversal_date']))) {
        $errors[] = 'Müügi kõrvaldamise parandamiseks tühista kõigepealt seotud müügiarve koos vastukandega. See toiming müügiarvet ei tühista.';
    } elseif ($disposal['invoice_reversal_date'] && $formDate < $disposal['invoice_reversal_date']) {
        $errors[] = 'Põhivara taastamise kuupäev ei saa olla varasem kui müügiarve vastukande kuupäev (' . $disposal['invoice_reversal_date'] . ').';
    } else {
        try {
            $pdo->beginTransaction();
            $lockDisposal = $pdo->prepare(
                "SELECT * FROM fixed_asset_disposals WHERE id = ? AND company_id = ? AND status = 'posted' FOR UPDATE"
            );
            $lockDisposal->execute([$disposalId, $companyId]);
            $lockedDisposal = $lockDisposal->fetch(PDO::FETCH_ASSOC);
            if (!$lockedDisposal) throw new RuntimeException('Kõrvaldamist on juba parandatud. Värskenda lehte.');

            $lockAsset = $pdo->prepare("SELECT id, asset_number, status FROM fixed_assets WHERE id = ? AND company_id = ? FOR UPDATE");
            $lockAsset->execute([(int) $lockedDisposal['asset_id'], $companyId]);
            $asset = $lockAsset->fetch(PDO::FETCH_ASSOC);
            if (!$asset || $asset['status'] !== 'disposed') throw new RuntimeException('Vara ei ole enam kõrvaldatud olekus.');

            if ($lockedDisposal['disposal_type'] === 'sale') {
                $invoiceLock = $pdo->prepare('SELECT id, status FROM sales_invoices WHERE id = ? AND company_id = ? FOR UPDATE');
                $invoiceLock->execute([(int) $lockedDisposal['sales_invoice_id'], $companyId]);
                $saleInvoice = $invoiceLock->fetch(PDO::FETCH_ASSOC);
                if (!$saleInvoice || $saleInvoice['status'] !== 'cancelled') {
                    throw new RuntimeException('Müügi kõrvaldamise parandamiseks tühista kõigepealt seotud müügiarve koos vastukandega.');
                }
                $invoiceCancellation = $pdo->prepare(
                    'SELECT sc.reversal_journal_entry_id, je.entry_date
                     FROM sales_invoice_cancellations sc
                     JOIN journal_entries je ON je.id = sc.reversal_journal_entry_id AND je.company_id = sc.company_id AND je.status = \'posted\'
                     WHERE sc.company_id = ? AND sc.sales_invoice_id = ? FOR UPDATE'
                );
                $invoiceCancellation->execute([$companyId, (int) $saleInvoice['id']]);
                $invoiceReversal = $invoiceCancellation->fetch(PDO::FETCH_ASSOC);
                if (!$invoiceReversal) throw new RuntimeException('Müügiarve tühistamise vastukannet ei leitud.');
                if ($formDate < $invoiceReversal['entry_date']) throw new RuntimeException('Põhivara taastamise kuupäev ei saa olla varasem kui müügiarve vastukande kuupäev (' . $invoiceReversal['entry_date'] . ').');
            }

            $periodMessage = closedAccountingPeriodMessage($pdo, $companyId, $formDate);
            if ($periodMessage !== null) throw new RuntimeException($periodMessage);
            $originalStmt = $pdo->prepare(
                "SELECT je.id, je.entry_date FROM journal_entries je
                 WHERE je.id = ? AND je.company_id = ? AND je.status = 'posted' FOR UPDATE"
            );
            $originalStmt->execute([(int) $lockedDisposal['journal_entry_id'], $companyId]);
            $originalEntry = $originalStmt->fetch(PDO::FETCH_ASSOC);
            if (!$originalEntry) throw new RuntimeException('Algset põhivara kõrvaldamiskannet ei leitud.');
            if ($formDate < $originalEntry['entry_date']) throw new RuntimeException('Paranduskande kuupäev ei saa olla algsest kande kuupäevast varasem.');

            $linesStmt = $pdo->prepare(
                'SELECT jl.account_id, jl.description, jl.debit, jl.credit
                 FROM journal_lines jl JOIN accounts a ON a.id = jl.account_id AND a.company_id = ?
                 WHERE jl.entry_id = ? ORDER BY jl.account_id FOR UPDATE'
            );
            $linesStmt->execute([$companyId, (int) $originalEntry['id']]);
            $lines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$lines) throw new RuntimeException('Algsel kõrvaldamiskandel pole pearaamatu ridu.');
            $debitTotal = 0;
            $creditTotal = 0;
            foreach ($lines as $line) {
                $debitTotal += (int) round((float) $line['debit'] * 100);
                $creditTotal += (int) round((float) $line['credit'] * 100);
            }
            if ($debitTotal < 1 || $debitTotal !== $creditTotal) throw new RuntimeException('Algne kõrvaldamiskanne ei ole tasakaalus; automaatset tühistamist ei saa teha.');

            $baseDocumentNumber = 'PV-OUT-REV-' . (int) $lockedDisposal['id'];
            $usedNumbersStmt = $pdo->prepare('SELECT document_number FROM journal_entries WHERE company_id = ? AND (document_number = ? OR document_number LIKE ?) FOR UPDATE');
            $usedNumbersStmt->execute([$companyId, $baseDocumentNumber, $baseDocumentNumber . '-%']);
            $usedNumbers = array_fill_keys($usedNumbersStmt->fetchAll(PDO::FETCH_COLUMN), true);
            $documentNumber = $baseDocumentNumber;
            $suffix = 2;
            while (isset($usedNumbers[$documentNumber])) $documentNumber = $baseDocumentNumber . '-' . $suffix++;

            $description = 'Põhivara kõrvaldamise tühistamine ' . $asset['asset_number'];
            $entryStmt = $pdo->prepare(
                "INSERT INTO journal_entries (company_id, entry_date, description, document_number, status, created_by, posted_at)
                 VALUES (?, ?, ?, ?, 'posted', ?, CURRENT_TIMESTAMP)"
            );
            $entryStmt->execute([$companyId, $formDate, $description, $documentNumber, (int) $_SESSION['user_id']]);
            $reversalEntryId = (int) $pdo->lastInsertId();
            $lineInsert = $pdo->prepare('INSERT INTO journal_lines (entry_id, account_id, description, debit, credit) VALUES (?, ?, ?, ?, ?)');
            foreach ($lines as $line) {
                $lineDescription = 'Tühistus: ' . (string) $line['description'];
                if (function_exists('mb_substr')) $lineDescription = mb_substr($lineDescription, 0, 250);
                else $lineDescription = substr($lineDescription, 0, 250);
                $lineInsert->execute([
                    $reversalEntryId, (int) $line['account_id'], $lineDescription,
                    number_format((float) $line['credit'], 2, '.', ''),
                    number_format((float) $line['debit'], 2, '.', ''),
                ]);
            }

            $auditStmt = $pdo->prepare(
                'INSERT INTO fixed_asset_disposal_reversals
                    (company_id, disposal_id, reversal_journal_entry_id, reversal_date, reason, reversed_by)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $auditStmt->execute([$companyId, $disposalId, $reversalEntryId, $formDate, $reason, (int) $_SESSION['user_id']]);
            $markReversed = $pdo->prepare("UPDATE fixed_asset_disposals SET status = 'reversed' WHERE id = ? AND company_id = ? AND status = 'posted'");
            $markReversed->execute([$disposalId, $companyId]);
            if ($markReversed->rowCount() !== 1) throw new RuntimeException('Kõrvaldamise olekut ei õnnestunud muuta.');
            $restoreAsset = $pdo->prepare("UPDATE fixed_assets SET status = 'active' WHERE id = ? AND company_id = ? AND status = 'disposed'");
            $restoreAsset->execute([(int) $lockedDisposal['asset_id'], $companyId]);
            if ($restoreAsset->rowCount() !== 1) throw new RuntimeException('Põhivara aktiivseks taastamine ebaõnnestus.');
            $pdo->commit();
            header('Location: asset_card.php?id=' . (int) $lockedDisposal['asset_id'] . '&reversed=' . $reversalEntryId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof RuntimeException) $errors[] = $e->getMessage();
            else { error_log($e->getMessage()); $errors[] = 'Kõrvaldamise vastukande loomine ebaõnnestus. Andmeid ei muudetud.'; }
        }
    }
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Paranda põhivara kõrvaldamist</title>
    <style>
        * { box-sizing: border-box; }
        body { max-width: 900px; margin: 34px auto; padding: 0 22px; background: #f4f7fb; color: #263746; font-family: Arial, sans-serif; }
        h1, h2 { color: #17324d; }
        .panel { margin-bottom: 18px; padding: 22px; background: #fff; border: 1px solid #dce3ec; border-radius: 12px; }
        .notice { padding: 12px 14px; border-radius: 8px; line-height: 1.5; background: #fff6df; color: #694e00; }
        .error { background: #fff0f0; color: #9a2929; }
        .muted { color: #667788; line-height: 1.55; }
        label { display: block; margin: 14px 0 6px; font-weight: 700; }
        input, textarea { width: 100%; padding: 10px 12px; border: 1px solid #b8c4d1; border-radius: 7px; font: inherit; }
        textarea { min-height: 90px; resize: vertical; }
        .actions { display: flex; flex-wrap: wrap; gap: 9px; margin-top: 18px; }
        .button { display: inline-block; padding: 10px 15px; border: 0; border-radius: 7px; background: #a83232; color: white; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .secondary { background: #657789; }
    </style>
</head>
<body>
    <h1>Paranda põhivara kõrvaldamist</h1>
    <?php foreach ($errors as $error): ?><p class="notice error"><?= h($error) ?></p><?php endforeach; ?>
    <?php if ($disposal && $disposal['status'] === 'posted' && $disposal['asset_status'] === 'disposed'): ?>
        <section class="panel">
            <h2><?= h($disposal['asset_number'] . ' — ' . $disposal['asset_name']) ?></h2>
            <p><strong>Toiming:</strong> <?= $disposal['disposal_type'] === 'sale' ? 'Müük' : 'Mahakandmine' ?>, <?= h($disposal['disposal_date']) ?></p>
            <p><strong>Vastukandega taastatav summa:</strong> <?= reversalEuro((float) $disposal['acquisition_cost']) ?></p>
            <p class="notice">See loob algsele kõrvaldamiskandele täpse vastukande, säilitab mõlemad kanded ajaloos ja taastab vara aktiivseks. Müügiarvet see ei tühista. Müügi korral peab seotud arve olema enne eraldi tühistatud.</p>
            <form method="post" onsubmit="return confirm('Loo kõrvaldamise täpne vastukanne ja taasta põhivara aktiivseks? Algne kanne jääb alles.');">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="disposal_id" value="<?= (int) $disposalId ?>">
                <label for="reversal_date">Vastukande kuupäev</label><input type="date" id="reversal_date" name="reversal_date" min="<?= h(max($disposal['disposal_date'], $disposal['invoice_reversal_date'] ?: $disposal['disposal_date'])) ?>" value="<?= h($formDate) ?>" required>
                <label for="reason">Parandamise põhjus</label><textarea id="reason" name="reason" maxlength="500" required><?= h($reason) ?></textarea>
                <div class="actions"><button class="button" type="submit">Loo vastukanne ja taasta vara</button><a class="button secondary" href="asset_card.php?id=<?= (int) $disposal['asset_id'] ?>">Katkesta</a></div>
            </form>
        </section>
    <?php else: ?>
        <section class="panel"><p class="notice">Seda kõrvaldamist ei saa parandada. Kontrolli vara kaardilt, kas see on juba taastatud.</p><a class="button secondary" href="assets.php">Põhivara register</a></section>
    <?php endif; ?>
</body>
</html>