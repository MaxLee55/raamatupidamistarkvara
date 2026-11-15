<?php
declare(strict_types=1);
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) { http_response_code(401); exit('Logi enne sisse.'); }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billberry_lib.php';
require_once __DIR__ . '/zero_sales_vat.php';

$companyId = (int) $_SESSION['company_id'];
$invoiceId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
try {
    if (!$invoiceId || $invoiceId < 1) throw new RuntimeException('Arve tunnus puudub või on vigane.');
    $companyStmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
    $companyStmt->execute([$companyId]);
    $company = $companyStmt->fetch(PDO::FETCH_ASSOC);

    $invoiceStmt = $pdo->prepare(
        'SELECT si.*, c.name AS contact_name, c.registry_code AS contact_registry_code,
                c.address AS contact_address,
                c.bank_einvoice_bic, c.bank_einvoice_account
         FROM sales_invoices si
         JOIN contacts c ON c.id = si.contact_id AND c.company_id = si.company_id
         WHERE si.id = ? AND si.company_id = ?'
    );
    $invoiceStmt->execute([$invoiceId, $companyId]);
    $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
    if (!$company || !$invoice || $invoice['status'] !== 'posted') throw new RuntimeException('XML-i saab luua ainult selle ettevõtte kinnitatud müügiarvele.');

    $linesStmt = $pdo->prepare('SELECT description, quantity, unit_price, vat_rate, net_amount, vat_amount, gross_amount FROM sales_invoice_lines WHERE invoice_id = ? ORDER BY id');
    $linesStmt->execute([$invoiceId]);
    $invoice=zeroEinvoiceData($pdo,$companyId,(int)$invoiceId,$invoice);
    $xml = billberryBuildSalesInvoiceXml($company, $invoice, $linesStmt->fetchAll(PDO::FETCH_ASSOC));

    $safeNumber = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $invoice['invoice_number']);
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="e-arve-test-' . $safeNumber . '.xml"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, private');
    echo $xml;
} catch (Throwable $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $e->getMessage();
}
