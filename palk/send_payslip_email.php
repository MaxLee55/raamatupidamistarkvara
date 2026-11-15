<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: ../login.php');
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Palgateatise saatmiseks on vaja administraatori õigust.');
}
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/email_lib.php';
require_once dirname(__DIR__) . '/email_log.php';

$runId = filter_var($_POST['run_id'] ?? null, FILTER_VALIDATE_INT);
$employeeId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
$companyId = (int) $_SESSION['company_id'];
$emailLogId = null;
$backUrl = 'tax-calculation.php?id=' . (int) $runId;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('Vorm aegus. Värskenda palgaarvestuse lehte ja proovi uuesti.');
    }
    if (!$runId || $runId < 1 || !$employeeId || $employeeId < 1) {
        throw new RuntimeException('Palgaperioodi või töötaja tunnus pole korrektne.');
    }

    $stmt = $pdo->prepare(
        'SELECT pr.work_month, e.id AS employee_id, e.first_name, e.last_name, e.email,
                payment.actual_payment_date, calculation.id AS calculation_id
         FROM payroll_runs pr
         JOIN payroll_run_items pri ON pri.run_id = pr.id AND pri.company_id = pr.company_id
         JOIN payroll_employments pe ON pe.id = pri.employment_id AND pe.company_id = pri.company_id
         JOIN payroll_employees e ON e.id = pe.employee_id AND e.company_id = pe.company_id
         JOIN payroll_run_item_payments payment
           ON payment.company_id = pri.company_id AND payment.run_id = pri.run_id AND payment.run_item_id = pri.id
         JOIN payroll_run_item_calculations calculation
           ON calculation.id = (
               SELECT latest.id FROM payroll_run_item_calculations latest
               WHERE latest.company_id = pri.company_id AND latest.run_id = pri.run_id
                 AND latest.run_item_id = pri.id ORDER BY latest.id DESC LIMIT 1
           )
         WHERE pr.company_id = ? AND pr.id = ? AND e.id = ?
         LIMIT 1'
    );
    $stmt->execute([$companyId, $runId, $employeeId]);
    $payslip = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$payslip) {
        throw new RuntimeException('Selle töötaja salvestatud palgateatist ei leitud.');
    }
    if (empty($payslip['actual_payment_date'])) {
        throw new RuntimeException('Palgateatise saab saata pärast seda, kui palk on palgaperioodis makstuks märgitud.');
    }
    if (!filter_var((string) $payslip['email'], FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Töötajakaardil puudub korrektne e-posti aadress.');
    }

    $name = trim($payslip['first_name'] . ' ' . $payslip['last_name']);
    $month = substr((string) $payslip['work_month'], 0, 7);
    $subject = 'Palgateatis · ' . $month;
    $emailLogId = emailLogStart(
        $pdo,
        $companyId,
        'payslip',
        (int) $runId,
        (int) $employeeId,
        $month . ' · ' . $name,
        (string) $payslip['email'],
        $subject,
        (int) $_SESSION['user_id']
    );

    define('APP_RETURN_PDF', true);
    $_GET['id'] = (string) $runId;
    $_GET['employee_id'] = (string) $employeeId;
    $_GET['pdf'] = '1';
    $pdfBytes = require __DIR__ . '/payslip.php';
    if (!is_string($pdfBytes) || substr($pdfBytes, 0, 4) !== '%PDF') {
        throw new RuntimeException('Palgateatise PDF-i loomine ebaõnnestus.');
    }

    $html = '<p>Tere ' . emailEscape($name) . '!</p>'
        . '<p>Saadame Sulle ' . emailEscape($month) . ' arvestuskuu palgateatise. PDF on kirjale lisatud.</p>'
        . '<p>Parimate soovidega</p>';
    emailSend((string) $payslip['email'], $name, $subject, $html, 'palgateatis-' . $month . '.pdf', $pdfBytes);
    emailLogFinish($pdo, (int) $emailLogId, 'sent');
    $_SESSION['payroll_calculation_notice'] = 'Palgateatis saadeti töötaja aadressile ' . $payslip['email'] . '.';
} catch (Throwable $e) {
    if ($emailLogId !== null) {
        try {
            emailLogFinish($pdo, (int) $emailLogId, 'failed', mb_substr($e->getMessage(), 0, 2000));
        } catch (Throwable $logError) {
            error_log('Palgateatise saatmise vea logimine ebaõnnestus: ' . $logError->getMessage());
        }
    }
    $_SESSION['payroll_calculation_notice'] = $e->getMessage();
}

header('Location: ' . $backUrl);
exit;
