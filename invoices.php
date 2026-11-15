<?php
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice_fx.php';
require_once __DIR__ . '/tax_code_lib.php';
ensureTaxCodeSchema($pdo);
require_once __DIR__.'/credit_note_lib.php';
ensureCreditNoteSchema($pdo);
require_once __DIR__ . '/billberry_lib.php';
require_once __DIR__ . '/period_lock.php';
require_once __DIR__ . '/purchase_invoice_cancel_lib.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function toCents(string $amount): int
{
    return creditCents($amount);
}

function money(string $amount): string
{
    return number_format(toCents($amount) / 100, 2, ',', ' ') . ' €';
}

$companyId = (int) $_SESSION['company_id'];
$creditLinks=loadCreditLinks($pdo,$companyId,'sales');
$creditedTotals=creditAppliedTotals($pdo,$companyId,'sales');
$returnedTotals=creditRefundTotals($pdo,$companyId,'sales');
$invoiceEmailNotice = $_SESSION['invoice_email_notice'] ?? '';
$invoiceEmailError = $_SESSION['invoice_email_error'] ?? '';
$billberryNotice = $_SESSION['billberry_notice'] ?? '';
$billberryError = $_SESSION['billberry_error'] ?? '';
$invoiceDeleteNotice = $_SESSION['invoice_delete_notice'] ?? '';
$invoiceDeleteError = $_SESSION['invoice_delete_error'] ?? '';
unset($_SESSION['invoice_email_notice'], $_SESSION['invoice_email_error'], $_SESSION['billberry_notice'], $_SESSION['billberry_error'], $_SESSION['invoice_delete_notice'], $_SESSION['invoice_delete_error']);
$invoiceId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$invoice = null;
$lines = [];
$emailHistory = [];
$billberrySent = null;
$billberryTableAvailable = true;
$stockMovementId = null;
$stockMovementStatus = null;
$salesCancellation = null;

if ($invoiceId) {
    $invoiceStmt = $pdo->prepare(
        'SELECT si.*, c.name AS contact_name, c.registry_code, c.email AS contact_email,
                c.bank_einvoice_bic, c.bank_einvoice_account
         FROM sales_invoices si
         JOIN contacts c ON c.id = si.contact_id
         WHERE si.id = :invoice_id
           AND si.company_id = :company_id'
    );
    $invoiceStmt->execute([
        'invoice_id' => $invoiceId,
        'company_id' => $companyId,
    ]);
    $invoice = $invoiceStmt->fetch();

    if (!$invoice) {
        http_response_code(404);
        exit('Arvet ei leitud.');
    }

    try {
        $billberrySentStmt = $pdo->prepare('SELECT billberry_invoice_id, sent_at FROM billberry_sent_invoices WHERE company_id = ? AND sales_invoice_id = ?');
        $billberrySentStmt->execute([$companyId, (int) $invoice['id']]);
        $billberrySent = $billberrySentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        $billberryTableAvailable = false;
    }

    $emailHistoryStmt = $pdo->prepare(
        'SELECT document_label, recipient_email, subject, status, error_message,
                attempted_at, completed_at
         FROM email_send_log
         WHERE company_id = ? AND document_type = ? AND document_id = ?
         ORDER BY id DESC
         LIMIT 50'
    );
    $emailHistoryStmt->execute([$companyId, 'sales_invoice', (int) $invoice['id']]);
    $emailHistory = $emailHistoryStmt->fetchAll(PDO::FETCH_ASSOC);

    $linesStmt = $pdo->prepare(
        'SELECT sil.description, sil.quantity, sil.unit_price, sil.vat_rate, tc.label AS tax_code_label,
                sil.fx_unit_price,sil.fx_net,sil.fx_vat,sil.fx_gross,
                sil.net_amount, sil.vat_amount, sil.gross_amount,
                p.sku AS product_sku, p.name AS product_name,
                w.code AS warehouse_code, w.name AS warehouse_name
         FROM sales_invoice_lines sil
         LEFT JOIN tax_codes tc ON tc.id = sil.tax_code_id AND tc.company_id = :company_id
         LEFT JOIN inventory_products p ON p.id = sil.product_id
         LEFT JOIN inventory_warehouses w ON w.id = sil.warehouse_id
         WHERE sil.invoice_id = :invoice_id
         ORDER BY sil.id'
    );
    $linesStmt->execute(['invoice_id' => $invoiceId, 'company_id' => $companyId]);
    $lines = $linesStmt->fetchAll();
	
	$paymentsStmt = $pdo->prepare(
    'SELECT ip.*, a.code AS bank_code, a.name AS bank_name
     FROM invoice_payments ip
     JOIN accounts a ON a.id = ip.bank_account_id
     WHERE ip.invoice_id = :invoice_id
     ORDER BY ip.payment_date, ip.id'
);
$paymentsStmt->execute(['invoice_id' => $invoiceId]);
$payments = $paymentsStmt->fetchAll();
$fxCurrency = invoiceFxCurrency($invoice ?: []);
$foreignInvoice = $fxCurrency !== 'EUR';
$fxBalance = $foreignInvoice ? invoiceFxForeignBalance($lines,$payments) : '0.00';

$paidTotalCents = 0;
foreach ($payments as $payment) {
    $paidTotalCents += toCents((string) $payment['amount']);
}
$returnedTotals=creditRefundTotals($pdo,$companyId,'sales');
$paidTotalCents-=$returnedTotals[$invoice['id']]??0;

    if (in_array($invoice['status'], ['posted', 'cancelled'], true)) {
        $stockMovementStmt = $pdo->prepare(
            'SELECT id, status, movement_type FROM inventory_movements
             WHERE company_id = ? AND source_sales_invoice_id = ? ORDER BY id'
        );
        $stockMovementStmt->execute([$companyId, (int) $invoice['id']]);
        $stockMovements = $stockMovementStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($stockMovements) === 1) {
            $stockMovementId = (int) $stockMovements[0]['id'];
            $stockMovementStatus = (string) $stockMovements[0]['status'];
        } elseif (count($stockMovements) > 1) {
            $invoiceDeleteError = 'Selle müügiarvega on seotud mitu laoliikumist. Automaatset tühistamist ei pakuta.';
        }
    }
    if ($invoice['status'] === 'cancelled') {
        ensureSalesInvoiceCancellationTable($pdo);
        $salesCancellationStmt = $pdo->prepare(
            'SELECT sc.*, reversal.entry_date AS reversal_date
             FROM sales_invoice_cancellations sc
             LEFT JOIN journal_entries reversal ON reversal.id = sc.reversal_journal_entry_id
             WHERE sc.company_id = ? AND sc.sales_invoice_id = ?'
        );
        $salesCancellationStmt->execute([$companyId, (int) $invoice['id']]);
        $salesCancellation = $salesCancellationStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

$bankAccountsStmt = $pdo->prepare(
    "SELECT id, code, name
     FROM accounts
     WHERE company_id = :company_id
       AND is_active = 1
       AND account_type = 'asset'
     ORDER BY code"
);
$bankAccountsStmt->execute(['company_id' => $companyId]);
$bankAccounts = $bankAccountsStmt->fetchAll();
	
	$accountsStmt = $pdo->prepare(
    'SELECT id, code, name, account_type
     FROM accounts
     WHERE company_id = :company_id
       AND is_active = 1
     ORDER BY code'
);
$accountsStmt->execute(['company_id' => $companyId]);
$accountsByType = [
    'asset' => [],
    'income' => [],
    'liability' => [],
];

foreach ($accountsStmt->fetchAll() as $account) {
    if (isset($accountsByType[$account['account_type']])) {
        $accountsByType[$account['account_type']][] = $account;
    }
}
	
} else {
    $paymentFilter = $_GET['payment_filter'] ?? 'all';
if (!in_array($paymentFilter, ['all', 'unpaid', 'draft', 'overdue'], true)) {
    $paymentFilter = 'all';
}

$creditBalanceSql = "COALESCE((SELECT -SUM(ct.gross_amount) FROM credit_note_links cn JOIN sales_invoices ci ON ci.id=cn.credit_invoice_id AND ci.company_id=cn.company_id JOIN sales_invoice_lines ct ON ct.invoice_id=ci.id WHERE cn.company_id=si.company_id AND cn.invoice_kind='sales' AND cn.original_invoice_id=si.id AND ci.status='posted'),0)";
$creditBalanceSql.=" - COALESCE((SELECT SUM(r.amount_cents)/100 FROM credit_note_refunds r WHERE r.company_id=si.company_id AND r.invoice_kind='sales' AND r.original_invoice_id=si.id AND r.status='posted'),0)";
$creditBalanceSql='('.$creditBalanceSql.')';
$statusCondition = $paymentFilter === 'draft' ? "AND si.status = 'draft'" : '';
$unpaidCondition = $paymentFilter === 'unpaid'
    ? "AND si.status = 'posted'
       AND COALESCE(gross_summary.gross_total, 0)
           > (COALESCE(payment_summary.paid_total, 0) + $creditBalanceSql)"
    : '';
$overdueCondition = $paymentFilter === 'overdue'
    ? "AND si.status = 'posted'
       AND si.due_date IS NOT NULL
       AND si.due_date < :today
       AND COALESCE(gross_summary.gross_total, 0)
           > (COALESCE(payment_summary.paid_total, 0) + $creditBalanceSql)"
    : '';

$listStmt = $pdo->prepare(
    "SELECT
        si.id,
        si.invoice_number,
        si.invoice_date,
        si.due_date,
        si.status,
        c.name AS contact_name,
        COALESCE(gross_summary.gross_total, 0) AS gross_total,
        COALESCE(payment_summary.paid_total, 0) AS paid_total
     FROM sales_invoices si
     JOIN contacts c ON c.id = si.contact_id
     LEFT JOIN (
        SELECT invoice_id, SUM(gross_amount) AS gross_total
        FROM sales_invoice_lines
        GROUP BY invoice_id
     ) gross_summary ON gross_summary.invoice_id = si.id
     LEFT JOIN (
        SELECT invoice_id, SUM(amount) AS paid_total
        FROM invoice_payments
        GROUP BY invoice_id
     ) payment_summary ON payment_summary.invoice_id = si.id
     WHERE si.company_id = :company_id
       $unpaidCondition
       $statusCondition
       $overdueCondition
     ORDER BY si.invoice_date DESC, si.id DESC"
);
$listParams = ['company_id' => $companyId];
if ($paymentFilter === 'overdue') {
    $listParams['today'] = date('Y-m-d');
}
$listStmt->execute($listParams);
$invoices = $listStmt->fetchAll();
}

$statusNames = [
    'draft' => 'Mustand',
    'posted' => 'Kinnitatud',
    'cancelled' => 'Tühistatud',
];
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $invoice ? 'Arve ' . h($invoice['invoice_number']) : 'Müügiarved' ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 32px; }
        table { border-collapse: collapse; margin-top: 16px; width: 100%; }
        th, td { padding: 8px 12px; border: 1px solid #ccc; text-align: left; }
        .number { text-align: right; white-space: nowrap; }
        .button { display: inline-block; padding: 9px 14px; background: #1769aa; color: white; text-decoration: none; border-radius: 6px; }
		.delete-button { display: inline-block; padding: 9px 14px; border: 0; border-radius: 6px; background: #ad3434; color: white; font: inherit; cursor: pointer; }
		
		.posting-card {
    margin-top: 28px;
    padding: 24px;
    background: #f7f9fc;
    border: 1px solid #dce3ec;
    border-radius: 12px;
    max-width: 760px;
}

.posting-card h2 {
    margin-top: 0;
    color: #17324d;
}

.posting-intro {
    margin: 12px 0 18px;
    color: #526273;
    line-height: 1.5;
}

.sales-cancellation-card {
    margin: 28px 0 32px;
}

.sales-cancellation-card form {
    display: grid;
    gap: 10px;
    margin-top: 18px;
}

.sales-cancellation-card label {
    display: block;
    margin-top: 4px;
    font-weight: 600;
}

.sales-cancellation-card select,
.sales-cancellation-card input {
    box-sizing: border-box;
    width: 100%;
    max-width: 620px;
    padding: 10px 12px;
    border: 1px solid #b8c4d1;
    border-radius: 7px;
    background: #fff;
    font: inherit;
}

.sales-cancellation-card .delete-button {
    justify-self: start;
    margin-top: 6px;
}

.invoice-journal-link {
    margin: 20px 0 26px;
}

.invoice-payment-summary {
    margin: 18px 0 28px;
}

.invoice-payment-summary h2 {
    margin: 0 0 16px;
}

.invoice-payment-summary p {
    margin: 9px 0;
}

.posting-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(220px, 1fr));
    gap: 16px;
}

.posting-field label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
}

.posting-field select {
    box-sizing: border-box;
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #b8c4d1;
    border-radius: 7px;
    background: white;
    font-size: 15px;
}

.posting-actions {
    margin-top: 22px;
}

.posting-button {
    padding: 11px 18px;
    border: 0;
    border-radius: 7px;
    background: #1769aa;
    color: white;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
}

.posting-button:hover {
    background: #0f4f83;
}

@media (max-width: 600px) {
    .posting-grid {
        grid-template-columns: 1fr;
    }
	
	.payment-card {
    margin-top: 28px;
    padding: 24px;
    background: #f3faf5;
    border: 1px solid #cfe4d4;
    border-radius: 12px;
    max-width: 760px;
}
}
.payment-card h2 {
    margin-top: 0;
    color: #245b36;
}

.payment-intro {
    margin-bottom: 20px;
    color: #526b59;
}

.payment-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(220px, 1fr));
    gap: 16px;
}

.payment-field label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
}

.payment-field input,
.payment-field select {
    box-sizing: border-box;
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #b9cfbf;
    border-radius: 7px;
    background: white;
    font-size: 15px;
}

.payment-actions {
    margin-top: 22px;
}

.payment-button {
    padding: 11px 18px;
    border: 0;
    border-radius: 7px;
    background: #26834a;
    color: white;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
}

.payment-button:hover {
    background: #1d683a;
}

@media (max-width: 600px) {
    .payment-grid {
        grid-template-columns: 1fr;
    }
}
.invoice-filter-card {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    gap: 16px;
    max-width: 680px;
    margin: 20px 0 24px;
    padding: 18px 20px;
    background: #f7f9fc;
    border: 1px solid #dce3ec;
    border-radius: 12px;
}

.invoice-filter-field {
    flex: 1 1 300px;
}

.invoice-filter-field label {
    display: block;
    margin-bottom: 7px;
    color: #33475b;
    font-weight: 600;
}

.invoice-filter-field select {
    box-sizing: border-box;
    width: 100%;
    padding: 11px 12px;
    border: 1px solid #b8c4d1;
    border-radius: 7px;
    background: #fff;
    font: inherit;
}

.invoice-filter-button {
    padding: 11px 20px;
    border: 0;
    border-radius: 7px;
    background: #1769aa;
    color: #fff;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
}

.invoice-filter-button:hover {
    background: #0f4f83;
}

@media (max-width: 600px) {
    .invoice-filter-card {
        align-items: stretch;
        flex-direction: column;
    }

    .invoice-filter-field {
        flex-basis: auto;
    }
}

    </style>
</head>
<body>

<?php if ($invoiceEmailNotice !== ''): ?><p style="padding:12px 15px;border:1px solid #b6dfc2;border-radius:8px;background:#eaf7ef;color:#176b3a"><?= h((string) $invoiceEmailNotice) ?></p><?php endif; ?>
<?php if ($invoiceEmailError !== ''): ?><p style="padding:12px 15px;border:1px solid #efb4b4;border-radius:8px;background:#fff0f0;color:#a12626"><?= h((string) $invoiceEmailError) ?></p><?php endif; ?>
<?php if ($billberryNotice !== ''): ?><p style="padding:12px 15px;border:1px solid #b6dfc2;border-radius:8px;background:#eaf7ef;color:#176b3a"><?= h((string) $billberryNotice) ?></p><?php endif; ?>
<?php if ($billberryError !== ''): ?><p style="padding:12px 15px;border:1px solid #efb4b4;border-radius:8px;background:#fff0f0;color:#a12626"><?= h((string) $billberryError) ?></p><?php endif; ?>
<?php if ($invoiceDeleteNotice !== ''): ?><p style="padding:12px 15px;border:1px solid #b6dfc2;border-radius:8px;background:#eaf7ef;color:#176b3a"><?= h((string) $invoiceDeleteNotice) ?></p><?php endif; ?>
<?php if ($invoiceDeleteError !== ''): ?><p style="padding:12px 15px;border:1px solid #efb4b4;border-radius:8px;background:#fff0f0;color:#a12626"><?= h((string) $invoiceDeleteError) ?></p><?php endif; ?>
<?php if ($invoice): ?>
    <h1>Arve <?= h($invoice['invoice_number']) ?></h1>
    <?php $invoice=zeroOutputInvoice($pdo,$companyId,(int)$invoice['id'],$invoice); ?>
    <?php if(zeroHasLines(zeroSource($pdo,$companyId,(int)$invoice['id']))): ?><p><a class="button" href="zero_sales_review.php?id=<?= (int)$invoice['id'] ?>">0% / maksuvaba käibe käsitlus</a></p><?php endif ?>
    <?php if(!empty($invoice['zero_vat_notice'])): ?><p class="notice"><?= nl2br(h($invoice['zero_vat_notice'])) ?></p><?php endif ?>
    <?php if(isset($creditLinks[$invoice['id']])): ?>
    <p><strong>Kreeditarve algsele arvele <a href="?id=<?= (int)$creditLinks[$invoice['id']]['original_invoice_id'] ?>"><?= h($creditLinks[$invoice['id']]['original_number']) ?></a>.</strong>
    Kreedit vähendab arvestust; võimalik tagasimakse kirjenda eraldi.</p>
    <?php if($invoice['status']==='posted'): ?><form method="post" action="void_credit_note.php" onsubmit="return confirm('Kas tühistad kreeditarve ja lood selle kande täpse vastukande?')">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="kind" value="sales"><input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>">
        <label>Tühistamise põhjus <input name="reason" required maxlength="500"></label><button type="submit">Tühista kreeditarve paranduskandega</button>
    </form><?php elseif(!empty($creditLinks[$invoice['id']]['void_entry_id'])): ?><p>Kreeditarve tühistati paranduskandega nr <?= (int)$creditLinks[$invoice['id']]['void_entry_id'] ?>.</p><?php endif; ?>

    <?php elseif($invoice['status']==='posted'): ?>
    <?php if (!$foreignInvoice): ?>
    <p><a class="button" href="credit_note.php?kind=sales&amp;invoice_id=<?= (int)$invoice['id'] ?>">Koosta kreeditarve</a></p>
    <?php endif; ?>
    <?php if(!empty($creditedTotals[$invoice['id']])): ?><p>Krediteeritud: <?= h(creditDecimal($creditedTotals[$invoice['id']])) ?> €.
    Laekumisi ja makseid see ei muuda; vajadusel kirjenda tagasimakse eraldi.</p><?php endif; ?>
    <?php endif; ?>

	<?php if ($invoice['status'] === 'draft' && empty($invoice['journal_entry_id'])): ?>
	<form method="post" action="delete_sales_invoice.php" onsubmit="return confirm('Kas kustutad selle müügiarve mustandi ja kõik selle arveread? Seda toimingut ei saa tagasi võtta.')" style="margin:12px 0">
		<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
		<input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
		<input type="hidden" name="return_filter" value="all">
		<button class="delete-button" type="submit">Kustuta müügiarve</button>
	</form>
	<?php endif; ?>
	<p class="no-print">
    <a class="button" href="print_invoice.php?id=<?= (int) $invoice['id'] ?>">
        Prindi / Salvesta PDF-ina
    </a>
</p>
    <p><strong>Klient:</strong> <?= h($invoice['contact_name']) ?></p>
    <?php if (!empty($invoice['registry_code'])): ?>
        <p><strong>Registrikood:</strong> <?= h($invoice['registry_code']) ?></p>
    <?php endif; ?>
    <p><strong>Arve kuupäev:</strong> <?= h($invoice['invoice_date']) ?></p>
    <p><strong>Maksetähtaeg:</strong> <?= h($invoice['due_date'] ?? '') ?></p>
    <p><strong>Olek:</strong> <?= h($statusNames[$invoice['status']] ?? $invoice['status']) ?></p>
    <?php if ($salesCancellation): ?>
        <p class="posting-intro">Müügiarve<?= $salesCancellation['inventory_movement_id'] ? ' ja seotud laost väljastamine' : '' ?> tühistati paranduskandega
            <?php if ($salesCancellation['reversal_date'] !== null): ?><a href="entry.php?id=<?= (int) $salesCancellation['reversal_journal_entry_id'] ?>">#<?= (int) $salesCancellation['reversal_journal_entry_id'] ?></a> (<?= h($salesCancellation['reversal_date']) ?>).<?php else: ?>#<?= (int) $salesCancellation['reversal_journal_entry_id'] ?> (kanne on praeguseks eemaldatud).<?php endif; ?>
            <?= h($salesCancellation['reason']) ?>
        </p>
    <?php endif; ?>
    <?php if ($invoice['status'] === 'posted'): ?>
        <?php if (!$foreignInvoice): ?>
        <section class="posting-card" style="max-width:760px">
            <h2>Saada arve e-arvena</h2>
            <?php if (!$billberryTableAvailable): ?>
                <p class="posting-intro">Enne kasutamist impordi üks kord fail <code>billberry_invoice_tables.sql</code> phpMyAdmini kaudu.</p>
            <?php elseif ($billberrySent): ?>
                <p class="posting-intro">E-arve saadeti Billberry kaudu <?= h((string) $billberrySent['sent_at']) ?>. Billberry tunnus: <?= h((string) $billberrySent['billberry_invoice_id']) ?>.</p>
            <?php elseif (empty($invoice['registry_code'])): ?>
                <p class="posting-intro">E-arve saatmiseks peab kliendi kontaktikaardil olema ettevõtte registrikood.</p>
            <?php else: ?>
                <?php if (!empty($invoice['bank_einvoice_bic'])): ?>
                    <p class="posting-intro">Saadab kinnitatud arve panga e-arvena <?= h(billberryBankBics()[strtoupper((string) $invoice['bank_einvoice_bic'])] ?? 'valitud panka') ?>. Enne saatmist kontrolli pangaandmeid eelvaates; pangakanali saatmine on tasuline.</p>
                <?php else: ?>
                    <p class="posting-intro">Saadab kinnitatud arve tavalise struktureeritud Eesti e-arvena Billberry kaudu kliendi registrikoodile või isikukoodile <?= h((string) $invoice['registry_code']) ?>.</p>
                <?php endif; ?>
                <form method="get" action="billberry_invoice_preview.php">
                    <input type="hidden" name="id" value="<?= (int) $invoice['id'] ?>">
                    <button class="posting-button" type="submit">Vaata e-arve eelvaadet</button>
                </form>
            <?php endif; ?>
        </section>
        <?php endif; ?>
        <section class="posting-card" style="max-width:760px">
            <h2>Saada arve e-postiga</h2>
            <?php if (filter_var((string) ($invoice['contact_email'] ?? ''), FILTER_VALIDATE_EMAIL)): ?>
                <p class="posting-intro">Arve saadetakse PDF-manusena kliendi kontaktikaardil olevale aadressile <strong><?= h($invoice['contact_email']) ?></strong>.</p>
                <form method="post" action="send_invoice_email.php" onsubmit="return confirm('Saadan kinnitatud arve PDF-i kliendi kontaktikaardil olevale aadressile. Kas jätkan?')">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                    <button class="posting-button" type="submit">Saada arve kliendile</button>
                </form>
            <?php else: ?>
                <p class="posting-intro">Kontakti kaardil puudub korrektne e-posti aadress. Lisa see enne saatmist lehel <a href="contacts.php">Kliendid ja tarnijad</a>.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($emailHistory): ?>
        <details class="posting-card" style="max-width:760px">
            <summary><strong>E-kirjade ajalugu (<?= count($emailHistory) ?>)</strong></summary>
            <div style="overflow-x:auto;margin-top:12px">
                <table>
                    <thead><tr><th>Aeg</th><th>Saaja</th><th>Olek</th><th>Teema / veateade</th></tr></thead>
                    <tbody>
                    <?php foreach ($emailHistory as $emailLog): ?>
                        <tr>
                            <td><?= h($emailLog['completed_at'] ?: $emailLog['attempted_at']) ?></td>
                            <td><?= h($emailLog['recipient_email']) ?></td>
                            <td><?= h(['sent' => 'Saadetud', 'failed' => 'Ebaõnnestus', 'pending' => 'Pooleli'][$emailLog['status']] ?? $emailLog['status']) ?></td>
                            <td><?= h($emailLog['status'] === 'failed' && $emailLog['error_message'] ? $emailLog['error_message'] : $emailLog['subject']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Kirjeldus</th>
                <th>Kogus</th>
                <th>Ühikuhind</th>
                <th>Maksukood</th>
                <th class="number">Netosumma</th>
                <th class="number">Käibemaks</th>
                <th class="number">Kokku</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $netTotal = 0;
            $vatTotal = 0;
            $grossTotal = 0;
            ?>
            <?php foreach ($lines as $line): ?>
                <?php
                $netTotal += toCents((string) $line['net_amount']);
                $vatTotal += toCents((string) $line['vat_amount']);
                $grossTotal += toCents((string) $line['gross_amount']);
                ?>
                <tr>
                    <td><?= h($line['description']) ?><?php if ($line['product_sku']): ?><br><small>Laost: <?= h($line['product_sku'] . ' — ' . $line['product_name']) ?> · <?= h($line['warehouse_code'] . ' — ' . $line['warehouse_name']) ?></small><?php endif; ?></td>
                    <td><?= h((string) $line['quantity']) ?></td>
                    <td><?= $foreignInvoice ? h(invoiceFxFormat($line['fx_unit_price'],$fxCurrency,true)) : h((string) $line['unit_price']) ?></td>
                    <td><?= $line['tax_code_label'] ? h($line['tax_code_label']) : h((string) $line['vat_rate']) . '%' ?></td>
                    <td class="number"><?php if ($foreignInvoice): ?><?= h(invoiceFxFormat($line['fx_net'],$fxCurrency)) ?><br><?php endif; ?><?= money((string) $line['net_amount']) ?></td>
                    <td class="number"><?php if ($foreignInvoice): ?><?= h(invoiceFxFormat($line['fx_vat'],$fxCurrency)) ?><br><?php endif; ?><?= money((string) $line['vat_amount']) ?></td>
                    <td class="number"><?php if ($foreignInvoice): ?><?= h(invoiceFxFormat($line['fx_gross'],$fxCurrency)) ?><br><?php endif; ?><?= money((string) $line['gross_amount']) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <th colspan="4">Kokku</th>
                <th class="number"><?= money((string) ($netTotal / 100)) ?></th>
                <th class="number"><?= money((string) ($vatTotal / 100)) ?></th>
                <th class="number"><?= money((string) ($grossTotal / 100)) ?></th>
            </tr>
        </tbody>
	</table>
    <?php if ($foreignInvoice): ?><p>Arve kokku <strong><?= h(invoiceFxFormat(invoiceFxSum($lines,'fx_gross'),$fxCurrency)) ?></strong>.
        Kurss 1 EUR = <?= h(rtrim(rtrim($invoice['fx_rate'],'0'),'.').' '.$fxCurrency) ?>, kuupäev <?= h($invoice['fx_rate_date']) ?>.</p><?php endif; ?>
		<?php if ($invoice['status'] === 'draft'): ?>
    <section class="posting-card">
    <h2>Kinnita arve</h2>
    <p class="posting-intro">
        Vali arvele sobivad kontod. Kinnitamisel luuakse pearaamatu kanne.
    </p>

    <form method="post" action="post_invoice.php">
        <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

        <div class="posting-grid">
            <div class="posting-field">
                <label for="receivable-account">Ostjate nõuete konto</label>
                <select id="receivable-account" name="receivable_account_id" required>
                    <option value="">Vali varakonto</option>
                    <?php foreach ($accountsByType['asset'] as $account): ?>
                        <option value="<?= (int) $account['id'] ?>">
                            <?= h($account['code'] . ' — ' . $account['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="posting-field">
                <label for="revenue-account">Müügitulu konto</label>
                <select id="revenue-account" name="revenue_account_id" required>
                    <option value="">Vali tulukonto</option>
                    <?php foreach ($accountsByType['income'] as $account): ?>
                        <option value="<?= (int) $account['id'] ?>">
                            <?= h($account['code'] . ' — ' . $account['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="posting-field">
                <label for="vat-account">Käibemaksukohustuse konto</label>
                <select
                    id="vat-account"
                    name="vat_account_id"
                    <?= $vatTotal > 0 ? 'required' : '' ?>
                >
                    <option value="">Vali kohustuste konto</option>
                    <?php foreach ($accountsByType['liability'] as $account): ?>
                        <option value="<?= (int) $account['id'] ?>">
                            <?= h($account['code'] . ' — ' . $account['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="posting-actions">
            <button
                class="posting-button"
                type="submit"
                onclick="return confirm('Kas kinnitad arve ja lood pearaamatu kande?')"
            >
                Kinnita arve
            </button>
        </div>
    </form>
</section>
<?php elseif ($invoice['journal_entry_id']): ?>
    <p class="invoice-journal-link">
        Raamatupidamiskanne:
        <a href="entry.php?id=<?= (int) $invoice['journal_entry_id'] ?>">
            Ava kanne
        </a>
    </p>
<?php endif; ?>

<?php if ($invoice['status'] === 'posted'): ?>
    <?php
    $salesCancellationBlockers = [];
    if ($payments) {
        $salesCancellationBlockers[] = 'Arvega on seotud laekumine. Tühistamine on lubatud alles pärast laekumise eraldi parandamist.';
    }
    if (count($stockMovements ?? []) > 1) {
        $salesCancellationBlockers[] = 'Arvega on seotud mitu laoliikumist; kontrolli need käsitsi üle.';
    } elseif ($stockMovementId && ($stockMovementStatus !== 'posted')) {
        $salesCancellationBlockers[] = 'Arvega seotud laost väljastamine ei ole kinnitatud olekus.';
    }
    if ($stockMovementId && $stockMovementStatus === 'posted' && salesInvoiceLaterStockOutflow($pdo, $companyId, $stockMovementId)) {
        $salesCancellationBlockers[] = 'Selle arvega seotud väljaminekule järgneb sama kauba hilisem laoväljaminek; FIFO arvestus vajab esmalt kontrolli.';
    }
    $salesInvoicePeriodMessage = closedAccountingPeriodMessage($pdo, $companyId, $invoice['invoice_date']);
    if ($salesInvoicePeriodMessage !== null) {
        $salesCancellationBlockers[] = $salesInvoicePeriodMessage;
    }
    $sentEmailStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM email_send_log
         WHERE company_id = ? AND document_type = 'sales_invoice' AND document_id = ? AND status = 'sent'"
    );
    $sentEmailStmt->execute([$companyId, (int) $invoice['id']]);
    if ((int) $sentEmailStmt->fetchColumn() > 0) {
        $salesCancellationBlockers[] = 'Arve on e-postiga kliendile saadetud. Enne sisemist tühistamist tuleb kliendile vormistada parandusdokument.';
    }
    if ($billberrySent) {
        $salesCancellationBlockers[] = 'Arve on Billberry kaudu saadetud. Enne sisemist tühistamist tuleb kliendile vormistada parandusdokument.';
    }
    $salesCancellationCandidates = [];
    if (!$salesCancellationBlockers && !empty($invoice['journal_entry_id'])) {
        $salesCancellationCandidates = salesInvoiceReversalCandidates($pdo, $companyId, (int) $invoice['journal_entry_id']);
        if (!$salesCancellationCandidates) {
            $salesCancellationBlockers[] = 'Ei leitud kinnitatud pearaamatukannet, mis pööraks algse kande kõigi kontode ja summade lõikes täpselt vastupidiseks.';
        }
    }
    ?>
    <section class="posting-card sales-cancellation-card">
        <h2>Tühista müügiarve<?= $stockMovementId ? ' ja laost väljastamine' : '' ?></h2>
        <p class="posting-intro">Kasuta seda ainult siis, kui arve on tegelikult tühistatud ja paranduskande kanne on juba kinnitatud. <?= $stockMovementId ? 'Toiming märgib arve ja seotud laost väljastamise tühistatuks.' : 'Toiming märgib arve tühistatuks.' ?> Uut pearaamatukannet ei looda.</p>
        <?php if ($salesCancellationBlockers): ?>
            <p class="posting-intro"><?= h(implode(' ', $salesCancellationBlockers)) ?></p>
        <?php else: ?>
            <form method="post" action="cancel_posted_sales_invoice.php" onsubmit="return confirm('Kas oled kontrollinud, et arve tuleb tühistada ja valitud kanne pöörab algse kande täielikult vastupidiseks?');">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                <div class="sales-cancellation-field">
                    <label for="sales-reversal-entry">Algse arve täpne paranduskande vastukanne</label>
                    <select id="sales-reversal-entry" name="reversal_entry_id" required>
                        <option value="">Vali paranduskande vastukanne</option>
                        <?php foreach ($salesCancellationCandidates as $candidate): ?>
                            <option value="<?= (int) $candidate['id'] ?>"><?= h($candidate['entry_date'] . ' · #' . $candidate['id'] . ' · ' . $candidate['description'] . ($candidate['document_number'] ? ' · ' . $candidate['document_number'] : '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="sales-cancellation-field">
                    <label for="sales-cancellation-reason">Märkus (soovituslik)</label>
                    <input id="sales-cancellation-reason" type="text" name="reason" maxlength="500" placeholder="Näiteks arve tühistati">
                </div>
                <div class="sales-cancellation-actions">
                    <button class="delete-button" type="submit">Tühista müügiarve</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($invoice['status'] !== 'cancelled'): ?>
<?php
$remainingCents = $grossTotal - ($creditedTotals[$invoice['id']]??0) - $paidTotalCents;
?>

<div class="invoice-payment-summary">
<h2>Laekumised</h2>
<p>Arve summa: <?= money((string) ($grossTotal / 100)) ?></p>
<p>Laekunud: <?= money((string) ($paidTotalCents / 100)) ?></p>
<?php if(!empty($returnedTotals[$invoice['id']])): ?><p>Laekumiste summast on maha arvatud tagasimaksed: <?= money((string)($returnedTotals[$invoice['id']]/100)) ?>.</p><?php endif; ?>
<?php if(isset($creditLinks[$invoice['id']])): ?><p><a class="button" href="credit_refund.php?kind=sales&amp;invoice_id=<?= (int)$invoice['id'] ?>">Kreeditarve tagasimaksed</a></p><?php else: ?><?php foreach($creditLinks as $creditId=>$creditLink): ?><?php if((int)$creditLink['original_invoice_id']===(int)$invoice['id'] && $creditLink['credit_status']==='posted'): ?><p><a href="credit_refund.php?kind=sales&amp;invoice_id=<?= (int)$creditId ?>">Kreeditarve nr <?= (int)$creditId ?> tagasimaksed</a></p><?php endif; ?><?php endforeach; ?><?php endif; ?>
<p><?= isset($creditLinks[$invoice['id']])?'Kreediti summa':($remainingCents<0?'Tagastatav':'Tasumata') ?>: <?= money((string) (abs($remainingCents) / 100)) ?></p>
</div>

<?php if ($payments): ?>
    <table>
        <thead>
            <tr>
                <th>Kuupäev</th>
                <th>Konto</th>
                <th>Summa</th>
                <th>Märkus</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($payments as $payment): ?>
                <tr>
                    <td><?= h($payment['payment_date']) ?></td>
                    <td><?= h($payment['bank_code'] . ' — ' . $payment['bank_name']) ?></td>
                    <td><?php if ($foreignInvoice): ?><?= h(invoiceFxFormat($payment['fx_amount'],$fxCurrency)) ?><br><?= money((string)$payment['fx_cash_eur']) ?><?php else: ?><?= money((string) $payment['amount']) ?><?php endif; ?></td>
                    <td><?= h($payment['notes'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if ($invoice['status'] === 'posted' && $remainingCents > 0): ?>
   <section class="payment-card">
    <h2>Registreeri laekumine</h2>
    <p class="payment-intro">
        Tasumata summa: <strong><?= $foreignInvoice ? h(invoiceFxFormat($fxBalance,$fxCurrency)).' · ' : '' ?><?= money((string) ($remainingCents / 100)) ?></strong>
    </p>

    <form method="post" action="post_payment.php">
        <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

        <div class="payment-grid">
            <div class="payment-field">
                <label for="payment-date">Laekumise kuupäev</label>
                <input
                    id="payment-date"
                    type="date"
                    name="payment_date"
                    value="<?= h(date('Y-m-d')) ?>"
                    required
                >
            </div>

            <div class="payment-field">
                <label for="bank-account">Panga- või kassakonto</label>
                <select id="bank-account" name="bank_account_id" required>
                    <option value="">Vali konto</option>
                    <?php foreach ($bankAccounts as $account): ?>
                        <option value="<?= (int) $account['id'] ?>">
                            <?= h($account['code'] . ' — ' . $account['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($foreignInvoice) { require __DIR__.'/invoice_fx_payment_fields.php'; } else { ?>
            <div class="payment-field">
                <label for="payment-amount">Laekunud summa (€)</label>
                <input
                    id="payment-amount"
                    type="number"
                    name="amount"
                    min="0.01"
                    max="<?= number_format($remainingCents / 100, 2, '.', '') ?>"
                    step="0.01"
                    required
                >
            </div>
            <?php } ?>

            <div class="payment-field">
                <label for="payment-notes">Märkus</label>
                <input
                    id="payment-notes"
                    type="text"
                    name="notes"
                    maxlength="500"
                    placeholder="Soovi korral makse selgitus"
                >
            </div>
        </div>

        <div class="payment-actions">
            <button class="payment-button" type="submit">
                Salvesta laekumine
            </button>
        </div>
    </form>
</section>
<?php elseif ($invoice['status'] === 'posted' && !isset($creditLinks[$invoice['id']])): ?>
    <p>Arve on täielikult tasutud.</p>
<?php endif; ?>
<?php endif; ?>





    <?php if (!empty($invoice['notes'])): ?>
        <h2>Märkused</h2>
        <p><?= nl2br(h($invoice['notes'])) ?></p>
    <?php endif; ?>

    <p><a class="button" href="invoices.php">Tagasi arvete nimekirja</a></p>

<?php else: ?>
   <h1>Müügiarved</h1>

<p><a class="button" href="create_invoice.php">Uus müügiarve</a></p>

<form method="get" class="invoice-filter-card">
    <div class="invoice-filter-field">
        <label for="payment-filter">Näita arveid</label>
        <select id="payment-filter" name="payment_filter">
            <option value="all" <?= $paymentFilter === 'all' ? 'selected' : '' ?>>
                Kõiki arveid
            </option>
            <option value="unpaid" <?= $paymentFilter === 'unpaid' ? 'selected' : '' ?>>
                Laekumata ja osaliselt tasutud
            </option>            <option value="draft" <?= $paymentFilter === 'draft' ? 'selected' : '' ?>>
                Kinnitamata mustandid
            </option>            <option value="overdue" <?= $paymentFilter === 'overdue' ? 'selected' : '' ?>>
                Tähtaja ületanud
            </option>
        </select>
    </div>

    <button class="invoice-filter-button" type="submit">
        Filtreeri
    </button>
</form>

<?php if (!$invoices): ?>
    <p>Selle valiku jaoks arveid pole.</p>
<?php else: ?>
    <table>
        <thead>
            <tr>
                <th>Arve nr</th>
                <th>Klient</th>
                <th>Kuupäev</th>
                <th>Maksetähtaeg</th>
                <th>Olek</th>
                <th class="number">Arve summa</th>
                <th class="number">Laekunud</th>
                <th class="number">Tasumata</th>
                <th>Tegevused</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($invoices as $item): ?>
                <?php
                $grossCents = toCents((string) $item['gross_total']);
                $paidCents = toCents((string) $item['paid_total'])-($returnedTotals[$item['id']]??0);
                $remainingCents = max(0, $grossCents - ($creditedTotals[$item['id']]??0) - $paidCents);

                if ($item['status'] === 'draft') {
                    $displayStatus = 'Mustand';
                } elseif ($item['status'] === 'cancelled') {
                    $displayStatus = 'Tühistatud';
                } elseif ($grossCents<0) {
                    $displayStatus='Kreeditarve';
                } elseif ($remainingCents === 0) {
                    $displayStatus = 'Tasutud';
                } elseif ($paidCents > 0) {
                    $displayStatus = 'Osaliselt tasutud';
                } else {
                    $displayStatus = 'Laekumata';
                }
                ?>
                <tr>
                    <td>
                        <a href="invoices.php?id=<?= (int) $item['id'] ?>">
                            <?= h($item['invoice_number']) ?>
                        </a>
                    </td>
                    <td><?= h($item['contact_name']) ?></td>
                    <td><?= h($item['invoice_date']) ?></td>
                    <td><?= h($item['due_date'] ?? '') ?></td>
                    <td><?= h($displayStatus) ?></td>
                    <td class="number"><?= money((string) $item['gross_total']) ?></td>
                    <td class="number"><?= money(creditDecimal($paidCents)) ?></td>
                    <td class="number"><?= money((string) ($remainingCents / 100)) ?></td>
                    <td>
                        <?php if ($item['status'] === 'draft'): ?>
                            <form method="post" action="delete_sales_invoice.php" onsubmit="return confirm('Kas kustutad müügiarve mustandi <?= h($item['invoice_number']) ?>? Seda toimingut ei saa tagasi võtta.')" style="margin:0">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="invoice_id" value="<?= (int) $item['id'] ?>">
                                <input type="hidden" name="return_filter" value="<?= h($paymentFilter) ?>">
                                <button class="delete-button" type="submit">Kustuta</button>
                            </form>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>



    <p><a class="button" href="index.php">Tagasi avalehele</a></p>
<?php endif; ?>

</body>
</html>
