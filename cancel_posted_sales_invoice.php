<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Kasuta müügiarve tühistamise vormi.');
}
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    exit('Vorm aegus. Ava müügiarve uuesti ja proovi siis.');
}

require_once __DIR__ . '/db.php';
require_once __DIR__.'/credit_note_lib.php';
ensureCreditNoteSchema($pdo);
require_once __DIR__ . '/period_lock.php';
require_once __DIR__ . '/purchase_invoice_cancel_lib.php';
ensureSalesInvoiceCancellationTable($pdo);

$invoiceId = filter_input(INPUT_POST, 'invoice_id', FILTER_VALIDATE_INT);
$reversalEntryId = filter_input(INPUT_POST, 'reversal_entry_id', FILTER_VALIDATE_INT);
$companyId = (int) $_SESSION['company_id'];
$reason = trim((string) ($_POST['reason'] ?? ''));
$reason = function_exists('mb_substr') ? mb_substr($reason, 0, 500) : substr($reason, 0, 500);
if (!$invoiceId || !$reversalEntryId || $invoiceId < 1 || $reversalEntryId < 1) {
    http_response_code(400);
    exit('Müügiarve või paranduskande valik on vigane.');
}

try {
    $pdo->beginTransaction();
    $creditLinks=loadCreditLinks($pdo,$companyId,'sales');
    foreach($creditLinks as $creditId=>$link) {
        if(($link['credit_status']??'posted')!=='posted') continue;
        if((int)$creditId===(int)$invoiceId || (int)$link['original_invoice_id']===(int)$invoiceId) throw new RuntimeException('Arvega on seotud kreeditarve. Kasuta eraldi kreeditarve parandamise töövoogu.');
    }

    $invoiceStmt = $pdo->prepare(
        'SELECT id, invoice_number, invoice_date, status, journal_entry_id
         FROM sales_invoices WHERE id = ? AND company_id = ? FOR UPDATE'
    );
    $invoiceStmt->execute([$invoiceId, $companyId]);
    $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice || $invoice['status'] !== 'posted' || empty($invoice['journal_entry_id'])) {
        throw new RuntimeException('Müügiarve ei ole enam kinnitatud või sellele puudub pearaamatukanne.');
    }

    $paymentStmt = $pdo->prepare('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?');
    $paymentStmt->execute([$invoiceId]);
    if ((int) $paymentStmt->fetchColumn() > 0) {
        throw new RuntimeException('Arvega on seotud laekumine. Paranda või tühista laekumine enne arve tühistamist.');
    }

    $emailStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM email_send_log
         WHERE company_id = ? AND document_type = 'sales_invoice' AND document_id = ? AND status = 'sent'"
    );
    $emailStmt->execute([$companyId, $invoiceId]);
    if ((int) $emailStmt->fetchColumn() > 0) {
        throw new RuntimeException('Arve on e-postiga kliendile saadetud. Koosta enne kliendile parandusdokument.');
    }
    $billberryTableStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'billberry_sent_invoices'"
    );
    $billberryTableStmt->execute();
    if ((int) $billberryTableStmt->fetchColumn() > 0) {
        $billberryStmt = $pdo->prepare('SELECT COUNT(*) FROM billberry_sent_invoices WHERE company_id = ? AND sales_invoice_id = ?');
        $billberryStmt->execute([$companyId, $invoiceId]);
        if ((int) $billberryStmt->fetchColumn() > 0) {
            throw new RuntimeException('Arve on Billberry kaudu saadetud. Koosta enne kliendile parandusdokument.');
        }
    }

    $movementStmt = $pdo->prepare(
        'SELECT id, movement_date, status, movement_type
         FROM inventory_movements WHERE company_id = ? AND source_sales_invoice_id = ? ORDER BY id FOR UPDATE'
    );
    $movementStmt->execute([$companyId, $invoiceId]);
    $movements = $movementStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($movements) > 1) {
        throw new RuntimeException('Müügiarvega on seotud mitu laoliikumist; neid tuleb enne käsitsi üle vaadata.');
    }
    $movement = $movements[0] ?? null;
    if ($movement && ($movement['status'] !== 'posted' || $movement['movement_type'] !== 'issue')) {
        throw new RuntimeException('Arvega seotud laost väljastamine ei ole kinnitatud olekus.');
    }
    if ($movement) {
        $productIdsStmt = $pdo->prepare(
            'SELECT DISTINCT product_id FROM inventory_movement_lines
             WHERE movement_id = ? AND quantity_out > 0 ORDER BY product_id'
        );
        $productIdsStmt->execute([(int) $movement['id']]);
        $productIds = array_map('intval', $productIdsStmt->fetchAll(PDO::FETCH_COLUMN));
        if (!$productIds) {
            throw new RuntimeException('Seotud laoväljastusel puuduvad kaubakogused.');
        }
        $productPlaceholders = implode(',', array_fill(0, count($productIds), '?'));
        $productLockStmt = $pdo->prepare(
            "SELECT id FROM inventory_products WHERE company_id = ? AND id IN ($productPlaceholders) ORDER BY id FOR UPDATE"
        );
        $productLockStmt->execute(array_merge([$companyId], $productIds));
        if (count($productLockStmt->fetchAll(PDO::FETCH_COLUMN)) !== count($productIds)) {
            throw new RuntimeException('Üks või mitu laotoodet ei leitud selle ettevõtte alt.');
        }
        if (salesInvoiceLaterStockOutflow($pdo, $companyId, (int) $movement['id'])) {
            throw new RuntimeException('Selle arve väljaminekule järgneb sama kauba hilisem laoväljaminek. FIFO ja laoväärtust ei saa ohutult tagasi võtta.');
        }
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
        throw new RuntimeException('Müügiarve algne pearaamatukanne ei ole kinnitatud.');
    }
    $reversalStmt = $pdo->prepare(
        'SELECT id, entry_date, status FROM journal_entries
         WHERE id = ? AND company_id = ? FOR UPDATE'
    );
    $reversalStmt->execute([$reversalEntryId, $companyId]);
    $reversal = $reversalStmt->fetch(PDO::FETCH_ASSOC);
    if (!$reversal || $reversal['status'] !== 'posted' || (int) $reversal['id'] <= $originalEntryId
        || $reversal['entry_date'] < $originalEntry['entry_date']) {
        throw new RuntimeException('Valitud paranduskannet ei saa selle müügiarve vastukandena kasutada.');
    }
    if (!isExactJournalEntryReversal($pdo, $originalEntryId, $reversalEntryId)) {
        throw new RuntimeException('Valitud kanne ei pööra müügiarve algset kannet täpselt vastupidiseks. Kontrolli kõiki kontosid ja summasid.');
    }
    if (journalEntryUsedForInvoiceCancellation($pdo, $companyId, $reversalEntryId)) {
        throw new RuntimeException('Seda paranduskannet on juba kasutatud teise arve tühistamise tõendina.');
    }

    $usedStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM sales_invoice_cancellations
         WHERE company_id = ? AND (sales_invoice_id = ? OR inventory_movement_id = ? OR reversal_journal_entry_id = ?)'
    );
    $usedStmt->execute([$companyId, $invoiceId, $movement ? (int) $movement['id'] : 0, $reversalEntryId]);
    if ((int) $usedStmt->fetchColumn() > 0) {
        throw new RuntimeException('Müügiarve, laoliikumine või paranduskande vastukanne on juba tühistamisega seotud.');
    }

    $auditStmt = $pdo->prepare(
        'INSERT INTO sales_invoice_cancellations
            (company_id, sales_invoice_id, inventory_movement_id,
             original_journal_entry_id, reversal_journal_entry_id, cancelled_by, reason)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $auditStmt->execute([
        $companyId,
        $invoiceId,
        $movement ? (int) $movement['id'] : null,
        $originalEntryId,
        $reversalEntryId,
        (int) $_SESSION['user_id'],
        $reason,
    ]);

    if ($movement) {
        $movementUpdate = $pdo->prepare(
            "UPDATE inventory_movements SET status = 'cancelled'
             WHERE id = ? AND company_id = ? AND status = 'posted' AND source_sales_invoice_id = ?"
        );
        $movementUpdate->execute([(int) $movement['id'], $companyId, $invoiceId]);
        if ($movementUpdate->rowCount() !== 1) {
            throw new RuntimeException('Laost väljastamise tühistamine ebaõnnestus.');
        }
    }

    $invoiceUpdate = $pdo->prepare(
        "UPDATE sales_invoices SET status = 'cancelled'
         WHERE id = ? AND company_id = ? AND status = 'posted' AND journal_entry_id = ?"
    );
    $invoiceUpdate->execute([$invoiceId, $companyId, $originalEntryId]);
    if ($invoiceUpdate->rowCount() !== 1) {
        throw new RuntimeException('Müügiarve tühistamine ebaõnnestus.');
    }

    $pdo->commit();
    $_SESSION['invoice_delete_notice'] = 'Müügiarve' . ($movement ? ' ja seotud laost väljastamine' : '') . ' märgiti tühistatuks. Paranduskande viide säilitati; uut pearaamatukannet ei loodud.';
    header('Location: invoices.php?id=' . $invoiceId);
    exit;
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['invoice_delete_error'] = $e->getMessage();
    header('Location: invoices.php?id=' . $invoiceId);
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log($e->getMessage());
    $_SESSION['invoice_delete_error'] = 'Tühistamisel tekkis ootamatu viga. Andmeid ei muudetud.';
    header('Location: invoices.php?id=' . $invoiceId);
    exit;
}
