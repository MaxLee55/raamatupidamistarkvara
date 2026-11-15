<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Kasuta müügiarvete lehel olevat kustutamisnuppu.');
}

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
) {
    http_response_code(400);
    exit('Vorm aegus. Ava müügiarve uuesti ja proovi siis.');
}

require_once __DIR__ . '/db.php';

$companyId = (int) $_SESSION['company_id'];
$invoiceId = filter_var($_POST['invoice_id'] ?? null, FILTER_VALIDATE_INT);
$returnFilter = (string) ($_POST['return_filter'] ?? 'all');
if (!in_array($returnFilter, ['all', 'unpaid', 'draft', 'overdue'], true)) {
    $returnFilter = 'all';
}

try {
    if (!$invoiceId || $invoiceId < 1) {
        throw new RuntimeException('Müügiarve ID puudub või on vigane.');
    }

    $pdo->beginTransaction();
    $invoiceStmt = $pdo->prepare(
        'SELECT id, invoice_number, status, journal_entry_id
         FROM sales_invoices
         WHERE id = ? AND company_id = ?
         FOR UPDATE'
    );
    $invoiceStmt->execute([$invoiceId, $companyId]);
    $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        throw new RuntimeException('Müügiarvet ei leitud.');
    }
    if ($invoice['status'] !== 'draft' || $invoice['journal_entry_id'] !== null) {
        throw new RuntimeException('Kustutada saab ainult kinnitamata mustandit. Kinnitatud arve kustutamise asemel tuleb kasutada paranduskannet või kreeditarvet.');
    }

    $paymentStmt = $pdo->prepare('SELECT COUNT(*) FROM invoice_payments WHERE invoice_id = ?');
    $paymentStmt->execute([$invoiceId]);
    if ((int) $paymentStmt->fetchColumn() > 0) {
        throw new RuntimeException('Arvel on registreeritud laekumisi, seega seda kustutada ei saa.');
    }

    $movementTableStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'inventory_movements'"
    );
    $movementTableStmt->execute();
    if ((int) $movementTableStmt->fetchColumn() > 0) {
        $movementStmt = $pdo->prepare('SELECT COUNT(*) FROM inventory_movements WHERE company_id = ? AND source_sales_invoice_id = ?');
        $movementStmt->execute([$companyId, $invoiceId]);
        if ((int) $movementStmt->fetchColumn() > 0) {
            throw new RuntimeException('Arvega on seotud laoliikumine, seega seda kustutada ei saa.');
        }
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
            throw new RuntimeException('Arve on Billberry kaudu saadetud, seega seda kustutada ei saa.');
        }
    }

    $deleteLines = $pdo->prepare('DELETE FROM sales_invoice_lines WHERE invoice_id = ?');
    $deleteLines->execute([$invoiceId]);

    $deleteInvoice = $pdo->prepare(
        "DELETE FROM sales_invoices
         WHERE id = ? AND company_id = ? AND status = 'draft' AND journal_entry_id IS NULL"
    );
    $deleteInvoice->execute([$invoiceId, $companyId]);
    if ($deleteInvoice->rowCount() !== 1) {
        throw new RuntimeException('Arve olek muutus ja seda ei kustutatud. Värskenda nimekirja.');
    }

    $pdo->commit();
    $_SESSION['invoice_delete_notice'] = 'Müügiarve mustand ' . $invoice['invoice_number'] . ' ja selle read kustutati.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['invoice_delete_error'] = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'Müügiarve kustutamine ebaõnnestus. Midagi ei kustutatud.';
    if (!($e instanceof RuntimeException)) {
        error_log($e->getMessage());
    }
}

header('Location: invoices.php?payment_filter=' . rawurlencode($returnFilter));
exit;
