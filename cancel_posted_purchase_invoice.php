<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Kasuta ostuarve tühistamise vormi.');
}
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    exit('Vorm aegus. Ava ostuarve uuesti ja proovi siis.');
}

require_once __DIR__ . '/db.php';
require_once __DIR__.'/credit_note_lib.php';
ensureCreditNoteSchema($pdo);
require_once __DIR__ . '/period_lock.php';
require_once __DIR__ . '/purchase_invoice_cancel_lib.php';
ensurePurchaseInvoiceCancellationTable($pdo);

$invoiceId = filter_input(INPUT_POST, 'invoice_id', FILTER_VALIDATE_INT);
$reversalEntryId = filter_input(INPUT_POST, 'reversal_entry_id', FILTER_VALIDATE_INT);
$companyId = (int) $_SESSION['company_id'];
$reason = trim((string) ($_POST['reason'] ?? ''));
if (!$invoiceId || !$reversalEntryId || $invoiceId < 1 || $reversalEntryId < 1) {
    http_response_code(400);
    exit('Ostuarve või paranduskande valik on vigane.');
}
$reason = function_exists('mb_substr') ? mb_substr($reason, 0, 500) : substr($reason, 0, 500);

try {
    require_once __DIR__.'/import_vat.php'; ensureImportVatSchema($pdo);
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();
    $creditLinks=loadCreditLinks($pdo,$companyId,'purchase');
    foreach($creditLinks as $creditId=>$link) {
        if(($link['credit_status']??'posted')!=='posted') continue;
        if((int)$creditId===(int)$invoiceId || (int)$link['original_invoice_id']===(int)$invoiceId) throw new RuntimeException('Arvega on seotud kreeditarve. Kasuta eraldi kreeditarve parandamise töövoogu.');
    }

    $invoiceStmt = $pdo->prepare(
        'SELECT id, invoice_date, supplier_invoice_number, status, journal_entry_id
         FROM purchase_invoices WHERE id = ? AND company_id = ? FOR UPDATE'
    );
    $invoiceStmt->execute([$invoiceId, $companyId]);
    if (purchaseVatPeriodPlan($pdo, $companyId, $invoiceId)) {
        throw new RuntimeException('Enne tühistamist lahenda arve mahaarvamise kuude jaotus.');
    }
    $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
    $s=$pdo->prepare("SELECT id FROM import_vat_documents WHERE company_id=? AND status='posted' AND (invoice_id=? OR cost_invoice_id=?) FOR UPDATE"); $s->execute([$companyId,$invoiceId,$invoiceId]);
    if($s->fetchColumn()) throw new RuntimeException('Ostuarve on seotud kinnitatud tollidokumendiga. Tühista või paranda esmalt impordi KM registri seos.');
    if (!$invoice || $invoice['status'] !== 'posted' || empty($invoice['journal_entry_id'])) {
        throw new RuntimeException('Ostuarve ei ole enam kinnitatud või sellele puudub pearaamatukanne.');
    }

    $movementStmt = $pdo->prepare(
        "SELECT id, movement_date, status, movement_type
         FROM inventory_movements
         WHERE company_id = ? AND source_purchase_invoice_id = ?
         FOR UPDATE"
    );
    $movementStmt->execute([$companyId, $invoiceId]);
    $movements = $movementStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($movements) !== 1) {
        throw new RuntimeException('Ostuarvega peab olema seotud täpselt üks lao vastuvõtt.');
    }
    $movement = $movements[0];
    if ($movement['status'] !== 'posted' || $movement['movement_type'] !== 'receipt') {
        throw new RuntimeException('Ostuarvega seotud kinnitatud laovastuvõttu ei leitud.');
    }

    $productIdsStmt = $pdo->prepare(
        'SELECT DISTINCT product_id FROM inventory_movement_lines
         WHERE movement_id = ? AND quantity_in > 0 ORDER BY product_id'
    );
    $productIdsStmt->execute([(int) $movement['id']]);
    $productIds = array_map('intval', $productIdsStmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$productIds) {
        throw new RuntimeException('Laovastuvõtul pole tühistamiseks ühtegi kaubakogust.');
    }
    $productPlaceholders = implode(',', array_fill(0, count($productIds), '?'));
    $productLockStmt = $pdo->prepare(
        "SELECT id FROM inventory_products WHERE company_id = ? AND id IN ($productPlaceholders) ORDER BY id FOR UPDATE"
    );
    $productLockStmt->execute(array_merge([$companyId], $productIds));
    if (count($productLockStmt->fetchAll(PDO::FETCH_COLUMN)) !== count($productIds)) {
        throw new RuntimeException('Üks või mitu laotoodet ei leitud selle ettevõtte alt.');
    }

    $paymentStmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_invoice_payments WHERE invoice_id = ?');
    $paymentStmt->execute([$invoiceId]);
    if ((int) $paymentStmt->fetchColumn() > 0) {
        throw new RuntimeException('Arvega on seotud makse. Paranda või tühista makse enne ostuarve tühistamist.');
    }
    if (purchaseInvoiceLaterStockOutflow($pdo, $companyId, (int) $movement['id'])) {
        throw new RuntimeException('Sama kauba laovastuvõtule järgneb kinnitatud väljaminek. Lao ajalugu ei saa ohutult tagasi võtta.');
    }
    $periodMessage = closedAccountingPeriodMessage($pdo, $companyId, $invoice['invoice_date']);
    if ($periodMessage !== null) {
        throw new RuntimeException($periodMessage);
    }

    $originalEntryId = (int) $invoice['journal_entry_id'];
    $entryStmt = $pdo->prepare(
        'SELECT id, entry_date, status FROM journal_entries
         WHERE id = ? AND company_id = ? FOR UPDATE'
    );
    $entryStmt->execute([$originalEntryId, $companyId]);
    $originalEntry = $entryStmt->fetch(PDO::FETCH_ASSOC);
    if (!$originalEntry || $originalEntry['status'] !== 'posted') {
        throw new RuntimeException('Ostuarve algne pearaamatukanne ei ole kinnitatud.');
    }

    $reversalStmt = $pdo->prepare(
        'SELECT id, entry_date, description, status FROM journal_entries
         WHERE id = ? AND company_id = ? FOR UPDATE'
    );
    $reversalStmt->execute([$reversalEntryId, $companyId]);
    $reversal = $reversalStmt->fetch(PDO::FETCH_ASSOC);
    if (!$reversal || $reversal['status'] !== 'posted' || (int) $reversal['id'] === $originalEntryId
        || (int) $reversal['id'] <= $originalEntryId || $reversal['entry_date'] < $originalEntry['entry_date']) {
        throw new RuntimeException('Valitud paranduskannet ei saa selle ostuarve vastukandena kasutada.');
    }
    if (!isExactPurchaseInvoiceReversal($pdo, $originalEntryId, $reversalEntryId)) {
        throw new RuntimeException('Valitud kanne ei pööra ostuarve algset kannet täpselt vastupidiseks. Kontrolli kõiki kontosid ja summasid.');
    }
    if (journalEntryUsedForInvoiceCancellation($pdo, $companyId, $reversalEntryId)) {
        throw new RuntimeException('Seda paranduskannet on juba kasutatud teise arve tühistamise tõendina.');
    }

    $usedStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM purchase_invoice_cancellations
         WHERE company_id = ? AND (purchase_invoice_id = ? OR inventory_movement_id = ? OR reversal_journal_entry_id = ?)'
    );
    $usedStmt->execute([$companyId, $invoiceId, (int) $movement['id'], $reversalEntryId]);
    if ((int) $usedStmt->fetchColumn() > 0) {
        throw new RuntimeException('Arve, laovastuvõtt või paranduskande vastukanne on juba tühistamisega seotud.');
    }

    $auditStmt = $pdo->prepare(
        'INSERT INTO purchase_invoice_cancellations
            (company_id, purchase_invoice_id, inventory_movement_id,
             original_journal_entry_id, reversal_journal_entry_id, cancelled_by, reason)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $auditStmt->execute([
        $companyId,
        $invoiceId,
        (int) $movement['id'],
        $originalEntryId,
        $reversalEntryId,
        (int) $_SESSION['user_id'],
        $reason,
    ]);

    voidPurchaseVatAdjustment($pdo,$companyId,(int)$_SESSION['user_id'],$invoiceId,$invoice['invoice_date'],true);
    $movementUpdate = $pdo->prepare(
        "UPDATE inventory_movements SET status = 'cancelled'
         WHERE id = ? AND company_id = ? AND status = 'posted' AND source_purchase_invoice_id = ?"
    );
    $movementUpdate->execute([(int) $movement['id'], $companyId, $invoiceId]);
    if ($movementUpdate->rowCount() !== 1) {
        throw new RuntimeException('Laovastuvõtu tühistamine ebaõnnestus.');
    }

    $invoiceUpdate = $pdo->prepare(
        "UPDATE purchase_invoices SET status = 'cancelled'
         WHERE id = ? AND company_id = ? AND status = 'posted' AND journal_entry_id = ?"
    );
    $invoiceUpdate->execute([$invoiceId, $companyId, $originalEntryId]);
    if ($invoiceUpdate->rowCount() !== 1) {
        throw new RuntimeException('Ostuarve tühistamine ebaõnnestus.');
    }

    $pdo->commit();
    $_SESSION['purchase_delete_notice'] = 'Ostuarve ja laovastuvõtt tühistati. Mahaarvamata käibemaksu automaatne parandus pöörati vajadusel tagasi; kannete ajalugu säilis.';
    header('Location: purchase_invoices.php?id=' . $invoiceId);
    exit;
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['purchase_delete_error'] = $e->getMessage();
    header('Location: purchase_invoices.php?id=' . $invoiceId);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($e->getMessage());
    $_SESSION['purchase_delete_error'] = 'Tühistamisel tekkis ootamatu viga. Andmeid ei muudetud.';
    header('Location: purchase_invoices.php?id=' . $invoiceId);
    exit;
}
