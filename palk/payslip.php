<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: ../login.php');
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Palgamoodulile on ligipääs ainult administraatoril.');
}
require_once dirname(__DIR__) . '/db.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
function formatMoney(string $amount): string
{
    return number_format((float) $amount, 2, ',', ' ') . ' €';
}

$companyId = (int) $_SESSION['company_id'];
$runId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$employeeId = filter_var($_GET['employee_id'] ?? null, FILTER_VALIDATE_INT);
if (!$runId || $runId < 1 || !$employeeId || $employeeId < 1) {
    http_response_code(404);
    exit('Palgalehte ei leitud.');
}
$stmt = $pdo->prepare(
    'SELECT pr.work_month, pr.status AS run_status,
            e.id AS employee_id, e.first_name, e.last_name, e.email,
            c.calculation_date, c.setting_valid_from, c.gross_amount,
            c.pension_rate, c.pension_amount, c.employee_unemployment_rate,
            c.employee_unemployment_amount, c.tax_free_amount,
            c.income_tax_rate, c.income_tax_amount, c.net_amount,
            c.created_at AS calculated_at
     FROM payroll_run_items pri
     JOIN payroll_employments pe ON pe.id = pri.employment_id AND pe.company_id = pri.company_id
     JOIN payroll_employees e ON e.id = pe.employee_id AND e.company_id = pe.company_id
     JOIN payroll_runs pr ON pr.id = pri.run_id AND pr.company_id = pri.company_id
     JOIN payroll_run_item_calculations c
       ON c.id = (
           SELECT latest.id FROM payroll_run_item_calculations latest
           WHERE latest.company_id = pri.company_id AND latest.run_id = pri.run_id
             AND latest.run_item_id = pri.id
           ORDER BY latest.id DESC LIMIT 1
       )
     WHERE pri.company_id = :company_id AND pri.run_id = :run_id
       AND e.id = :employee_id
     LIMIT 1'
);
$stmt->execute(['company_id' => $companyId, 'run_id' => $runId, 'employee_id' => $employeeId]);
$payslip = $stmt->fetch();
if (!$payslip) {
    http_response_code(404);
    exit('Selle töötaja arvutust pole salvestatud.');
}
$pdfMode = isset($_GET['pdf']) && $_GET['pdf'] === '1';
if ($pdfMode) {
    ob_start();
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Palgaleht · <?= h($payslip['first_name'] . ' ' . $payslip['last_name']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px; background: #f4f7fb; color: #243447; font-family: Arial, sans-serif; }
        main { max-width: 760px; margin: 0 auto; }
        header, .panel { margin-bottom: 20px; padding: 24px; background: #fff; border: 1px solid #dce3ec; border-radius: 12px; }
        header { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; }
        h1, h2 { margin-top: 0; color: #17324d; }
        .muted { color: #68798b; line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 8px; border-bottom: 1px solid #e2e8ef; text-align: left; }
        td:last-child { text-align: right; white-space: nowrap; }
        .net { font-size: 20px; font-weight: 700; color: #17324d; }
        .button { display: inline-block; min-height: 40px; padding: 10px 14px; border: 0; border-radius: 7px; background: #1769aa; color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .toolbar { margin-bottom: 18px; }
        @media print { body { padding: 0; background: #fff; } header, .panel { border: 0; border-radius: 0; } .toolbar { display: none; } }
        .pdf-mode { font-family: "DejaVu Sans", sans-serif; }
        .pdf-mode .toolbar { display: none !important; }
    </style>
</head>
<body class="<?= $pdfMode ? 'pdf-mode' : '' ?>">
<main>
    <div class="toolbar"><a class="button" href="tax-calculation.php?id=<?= (int) $runId ?>">Tagasi arvestuse juurde</a> <button class="button" onclick="window.print()">Prindi / salvesta PDF</button></div>
    <header><div><h1>Palgaleht</h1><div class="muted">Arvestuskuu <?= h(substr((string) $payslip['work_month'], 0, 7)) ?> · väljamakse <?= h($payslip['calculation_date']) ?></div></div><div><strong><?= h($payslip['first_name'] . ' ' . $payslip['last_name']) ?></strong></div></header>
    <section class="panel">
        <h2>Tasu ja kinnipidamised</h2>
        <table>
            <tbody>
                <tr><th>Brutotasu</th><td><?= formatMoney($payslip['gross_amount']) ?></td></tr>
                <tr><th>II samba makse (<?= h(number_format((float) $payslip['pension_rate'], 0, ',', '')) ?>%)</th><td>− <?= formatMoney($payslip['pension_amount']) ?></td></tr>
                <tr><th>Töötaja töötuskindlustus (<?= h(number_format((float) $payslip['employee_unemployment_rate'], 1, ',', '')) ?>%)</th><td>− <?= formatMoney($payslip['employee_unemployment_amount']) ?></td></tr>
                <tr><th>Arvestatud maksuvaba tulu</th><td><?= formatMoney($payslip['tax_free_amount']) ?></td></tr>
                <tr><th>Tulumaks (<?= h(number_format((float) $payslip['income_tax_rate'], 0, ',', '')) ?>%)</th><td>− <?= formatMoney($payslip['income_tax_amount']) ?></td></tr>
                <tr><th class="net">Töötajale väljamakstav netosumma</th><td class="net"><?= formatMoney($payslip['net_amount']) ?></td></tr>
            </tbody>
        </table>
        <p class="muted">Maksuseaded kehtisid alates <?= h($payslip['setting_valid_from']) ?>. Arvutus salvestati <?= h($payslip['calculated_at']) ?>.</p>
    </section>
</main>
</body>
</html>
<?php
if ($pdfMode) {
    $html = ob_get_clean();
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $pdfOptions = new \Dompdf\Options();
    $pdfOptions->set('defaultFont', 'DejaVu Sans');
    $pdfOptions->set('isRemoteEnabled', false);
    $dompdf = new \Dompdf\Dompdf($pdfOptions);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $pdfBytes = $dompdf->output();

    if (defined('APP_RETURN_PDF')) {
        return $pdfBytes;
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="palgaleht-' . (int) $runId . '-' . (int) $employeeId . '.pdf"');
    echo $pdfBytes;
    exit;
}
?>
