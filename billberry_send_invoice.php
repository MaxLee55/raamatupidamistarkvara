<?php
declare(strict_types=1);
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billberry_lib.php';
require_once __DIR__ . '/zero_sales_vat.php';
$companyId = (int) $_SESSION['company_id'];
$billberryEnvironment = 'production';
$invoiceId = filter_var($_POST['invoice_id'] ?? null, FILTER_VALIDATE_INT);
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['confirm_send'] ?? '') !== '1' || empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Enne saatmist vaata arve eelvaadet ja kinnita saatmine seal.');
    $billberryEnvironment = (string) (billberrySettings($companyId)['environment'] ?? 'production');
    if (!$invoiceId || $invoiceId < 1) throw new RuntimeException('Arve tunnus pole korrektne.');
    if ($billberryEnvironment === 'production') {
        $exists = $pdo->prepare('SELECT id FROM billberry_sent_invoices WHERE company_id = ? AND sales_invoice_id = ?');
        $exists->execute([$companyId, $invoiceId]);
        if ($exists->fetchColumn()) throw new RuntimeException('See arve on Billberry kaudu juba saadetud.');
    }
    $companyStmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
    $companyStmt->execute([$companyId]);
    $company = $companyStmt->fetch(PDO::FETCH_ASSOC);
    $invoiceStmt = $pdo->prepare('SELECT si.*, c.name AS contact_name, c.registry_code AS contact_registry_code, c.address AS contact_address, c.bank_einvoice_bic, c.bank_einvoice_account FROM sales_invoices si JOIN contacts c ON c.id = si.contact_id AND c.company_id = si.company_id WHERE si.id = ? AND si.company_id = ?');
    $invoiceStmt->execute([$invoiceId, $companyId]);
    $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
    if (!$company || !$invoice || $invoice['status'] !== 'posted') throw new RuntimeException('Billberry kaudu saab saata ainult selle ettevõtte kinnitatud müügiarvet.');
    $linesStmt = $pdo->prepare('SELECT description, quantity, unit_price, vat_rate, net_amount, vat_amount, gross_amount FROM sales_invoice_lines WHERE invoice_id = ? ORDER BY id');
    $linesStmt->execute([$invoiceId]);
    $invoice=zeroEinvoiceData($pdo,$companyId,(int)$invoiceId,$invoice);
    $xml = billberryBuildSalesInvoiceXml($company, $invoice, $linesStmt->fetchAll(PDO::FETCH_ASSOC));
    $response = billberryRequest($companyId, '/invoices', 'POST', $xml);
    $data = json_decode($response['body'], true);
    if (!is_array($data) || empty($data['id'])) throw new RuntimeException('Billberry ei tagastanud saadetud arve tunnust; kontrolli Billberry kontolt, kas arve jõudis kohale.');
    if ($billberryEnvironment === 'production') {
        $save = $pdo->prepare('INSERT INTO billberry_sent_invoices (company_id, sales_invoice_id, billberry_invoice_id, response_json) VALUES (?, ?, ?, ?)');
        $save->execute([$companyId, $invoiceId, (int) $data['id'], json_encode($data, JSON_UNESCAPED_UNICODE)]);
        $_SESSION['billberry_notice'] = 'E-arve ' . $invoice['invoice_number'] . ' saadeti Billberry päriskeskkonna kaudu.';
    } else {
        $_SESSION['billberry_notice'] = 'E-arve ' . $invoice['invoice_number'] . ' saadeti Billberry testkeskkonda. Seda ei märgitud päriselt saadetuks.';
    }
} catch (Throwable $e) {
    $_SESSION['billberry_error'] = $e->getMessage();
}
header('Location: invoices.php?id=' . (int) $invoiceId);
exit;
