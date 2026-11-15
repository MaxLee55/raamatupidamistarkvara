<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email_lib.php';
require_once __DIR__ . '/email_log.php';

$invoiceId = filter_var($_POST['invoice_id'] ?? null, FILTER_VALIDATE_INT);
$companyId = (int) $_SESSION['company_id'];
$emailLogId = null;
$backUrl = 'invoices.php' . ($invoiceId ? '?id=' . $invoiceId : '');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('Vorm aegus. Värskenda arvet ja proovi uuesti.');
    }
    if (!$invoiceId || $invoiceId < 1) {
        throw new RuntimeException('Arve tunnus pole korrektne.');
    }

    $stmt = $pdo->prepare(
        'SELECT si.id, si.invoice_number, si.status, c.name AS contact_name, c.email AS contact_email,
                co.name AS company_name
         FROM sales_invoices si
         JOIN contacts c ON c.id = si.contact_id AND c.company_id = si.company_id
         JOIN companies co ON co.id = si.company_id
         WHERE si.id = ? AND si.company_id = ?'
    );
    $stmt->execute([$invoiceId, $companyId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        throw new RuntimeException('Arvet ei leitud.');
    }
    if ($invoice['status'] !== 'posted') {
        throw new RuntimeException('Saata saab ainult kinnitatud müügiarvet.');
    }
    if (!filter_var((string) $invoice['contact_email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Kliendi kontaktikaardil puudub korrektne e-posti aadress.');
    }

    $subject = 'Müügiarve ' . $invoice['invoice_number'] . ' · ' . $invoice['company_name'];
    $emailLogId = emailLogStart(
        $pdo,
        $companyId,
        'sales_invoice',
        (int) $invoice['id'],
        null,
        (string) $invoice['invoice_number'],
        (string) $invoice['contact_email'],
        $subject,
        (int) $_SESSION['user_id']
    );

    define('APP_RETURN_PDF', true);
    $_GET['id'] = (string) $invoiceId;
    $_GET['pdf'] = '1';
    $pdfBytes = require __DIR__ . '/print_invoice.php';
    if (!is_string($pdfBytes) || substr($pdfBytes, 0, 4) !== '%PDF') {
        throw new RuntimeException('Arve PDF-i loomine ebaõnnestus.');
    }

    $html = '<p>Tere ' . emailEscape((string) $invoice['contact_name']) . '!</p>'
        . '<p>Saadame Teile müügiarve <strong>' . emailEscape((string) $invoice['invoice_number']) . '</strong>. Arve PDF on kirjale lisatud.</p>'
        . '<p>Lugupidamisega<br>' . emailEscape((string) $invoice['company_name']) . '</p>';
    $filename = 'arve-' . preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $invoice['invoice_number']) . '.pdf';
    emailSend((string) $invoice['contact_email'], (string) $invoice['contact_name'], $subject, $html, $filename, $pdfBytes);
    emailLogFinish($pdo, (int) $emailLogId, 'sent');
    $_SESSION['invoice_email_notice'] = 'Arve ' . $invoice['invoice_number'] . ' saadeti aadressile ' . $invoice['contact_email'] . '.';
} catch (Throwable $e) {
    if ($emailLogId !== null) {
        try {
            emailLogFinish($pdo, (int) $emailLogId, 'failed', mb_substr($e->getMessage(), 0, 2000));
        } catch (Throwable $logError) {
            error_log('E-kirja saatmise vea logimine ebaõnnestus: ' . $logError->getMessage());
        }
    }
    $_SESSION['invoice_email_error'] = $e->getMessage();
}

header('Location: ' . $backUrl);
exit;
