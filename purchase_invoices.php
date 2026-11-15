<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice_fx.php';
require_once __DIR__ . '/tax_code_lib.php';
ensureTaxCodeSchema($pdo);
require_once __DIR__.'/credit_note_lib.php';
ensureCreditNoteSchema($pdo);
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

function money(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

$companyId = (int) $_SESSION['company_id'];
$creditLinks=loadCreditLinks($pdo,$companyId,'purchase');
$creditedTotals=creditAppliedTotals($pdo,$companyId,'purchase');
$returnedTotals=creditRefundTotals($pdo,$companyId,'purchase');
$creditReverse=creditReverseAmounts($pdo,$companyId,'purchase');
$purchaseDeleteNotice = $_SESSION['purchase_delete_notice'] ?? '';
$purchaseDeleteError = $_SESSION['purchase_delete_error'] ?? '';
unset($_SESSION['purchase_delete_notice'], $_SESSION['purchase_delete_error']);
$invoiceId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$invoice = null;
$lines = [];
$cancellation = null;

if ($invoiceId) {
    $invoiceStmt = $pdo->prepare(
        'SELECT pi.*, c.name AS supplier_name
         FROM purchase_invoices pi
         JOIN contacts c ON c.id = pi.contact_id
         WHERE pi.id = :invoice_id
           AND pi.company_id = :company_id'
    );
    $invoiceStmt->execute([
        'invoice_id' => $invoiceId,
        'company_id' => $companyId,
    ]);
    $invoice = $invoiceStmt->fetch();

    if (!$invoice) {
        http_response_code(404);
        exit('Ostuarvet ei leitud.');
    }

    $linesStmt = $pdo->prepare(
        'SELECT pil.*, tc.label AS tax_code_label, tc.code AS tax_code, a.code AS account_code, a.name AS account_name,
                p.sku AS product_sku, p.name AS product_name,
                w.code AS warehouse_code, w.name AS warehouse_name
         FROM purchase_invoice_lines pil
         LEFT JOIN tax_codes tc ON tc.id = pil.tax_code_id AND tc.company_id = :company_id
         JOIN accounts a ON a.id = pil.account_id
         LEFT JOIN inventory_products p ON p.id = pil.product_id
         LEFT JOIN inventory_warehouses w ON w.id = pil.warehouse_id
         WHERE pil.invoice_id = :invoice_id
         ORDER BY pil.id'
    );
    $linesStmt->execute(['invoice_id' => $invoiceId, 'company_id' => $companyId]);
    $lines = $linesStmt->fetchAll();
	
	$paymentsStmt = $pdo->prepare(
    'SELECT pip.*, a.code AS bank_code, a.name AS bank_name
     FROM purchase_invoice_payments pip
     JOIN accounts a ON a.id = pip.bank_account_id
     WHERE pip.invoice_id = :invoice_id
     ORDER BY pip.payment_date, pip.id'
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
$returnedTotals=creditRefundTotals($pdo,$companyId,'purchase');
$paidTotalCents-=$returnedTotals[$invoice['id']]??0;
$stockLineCount = count(array_filter($lines, static function (array $line): bool {
    return $line['product_id'] !== null;
}));
$stockMovementId = null;
$stockMovementStatus = null;
if (in_array($invoice['status'], ['posted', 'cancelled'], true)) {
    $stockMovementStmt = $pdo->prepare('SELECT id, status FROM inventory_movements WHERE company_id = ? AND source_purchase_invoice_id = ?');
    $stockMovementStmt->execute([$companyId, (int) $invoice['id']]);
    $stockMovements = $stockMovementStmt->fetchAll(PDO::FETCH_ASSOC);
    $stockMovement = count($stockMovements) === 1 ? $stockMovements[0] : null;
    if ($stockMovement) {
        $stockMovementId = (int) $stockMovement['id'];
        $stockMovementStatus = (string) $stockMovement['status'];
    } elseif (count($stockMovements) > 1) {
        $purchaseDeleteError = 'Selle ostuarvega on seotud mitu lao vastuvõttu. Automaatset tühistamist ei pakuta; vaata lao liikumised käsitsi üle.';
    }
}
if ($invoice['status'] === 'cancelled') {
    ensurePurchaseInvoiceCancellationTable($pdo);
    $cancellationStmt = $pdo->prepare(
        'SELECT pc.*, reversal.entry_date AS reversal_date, reversal.description AS reversal_description
         FROM purchase_invoice_cancellations pc
         LEFT JOIN journal_entries reversal ON reversal.id = pc.reversal_journal_entry_id
         WHERE pc.company_id = ? AND pc.purchase_invoice_id = ?'
    );
    $cancellationStmt->execute([$companyId, (int) $invoice['id']]);
    $cancellation = $cancellationStmt->fetch(PDO::FETCH_ASSOC) ?: null;
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
        'liability' => [],
    ];

    foreach ($accountsStmt->fetchAll() as $account) {
        if (isset($accountsByType[$account['account_type']])) {
            $accountsByType[$account['account_type']][] = $account;
        }
    }
} else {
    $creditBalanceSql = "COALESCE((SELECT -SUM(ct.gross_amount) FROM credit_note_links cn JOIN purchase_invoices ci ON ci.id=cn.credit_invoice_id AND ci.company_id=cn.company_id JOIN purchase_invoice_lines ct ON ct.invoice_id=ci.id WHERE cn.company_id=pi.company_id AND cn.invoice_kind='purchase' AND cn.original_invoice_id=pi.id AND ci.status='posted'),0)";
    $creditBalanceSql.=" - COALESCE((SELECT SUM(r.amount_cents)/100 FROM credit_note_refunds r WHERE r.company_id=pi.company_id AND r.invoice_kind='purchase' AND r.original_invoice_id=pi.id AND r.status='posted'),0)";
    $creditBalanceSql='('.$creditBalanceSql.')';
    $filterConditions = [
        'all' => '1=1',
        'draft' => "pi.status = 'draft'",
        'unpaid' => "pi.status = 'posted'
                     AND COALESCE(payments.paid_total, 0) = 0
                     AND (COALESCE(totals.gross_total, 0) - $creditBalanceSql) > 0",
        'partial' => "pi.status = 'posted'
                      AND COALESCE(payments.paid_total, 0) > 0
                      AND COALESCE(payments.paid_total, 0)
                          < (COALESCE(totals.gross_total, 0) - $creditBalanceSql)",
        'overdue' => "pi.status = 'posted'
                      AND pi.due_date IS NOT NULL
                      AND pi.due_date < :today
                      AND COALESCE(payments.paid_total, 0)
                          < (COALESCE(totals.gross_total, 0) - $creditBalanceSql)",
    ];

    $paymentFilter = $_GET['payment_filter'] ?? 'all';

    if (!is_string($paymentFilter) || !isset($filterConditions[$paymentFilter])) {
        $paymentFilter = 'all';
    }

    $listStmt = $pdo->prepare(
        'SELECT
            pi.id,
            pi.supplier_invoice_number,
            pi.invoice_date,
            pi.due_date,
            pi.status,
            c.name AS supplier_name,
            COALESCE(totals.gross_total, 0) AS gross_total,
            COALESCE(payments.paid_total, 0) AS paid_total
         FROM purchase_invoices pi
         JOIN contacts c ON c.id = pi.contact_id
         LEFT JOIN (
            SELECT invoice_id, SUM(gross_amount) AS gross_total
            FROM purchase_invoice_lines
            GROUP BY invoice_id
         ) totals ON totals.invoice_id = pi.id
         LEFT JOIN (
            SELECT invoice_id, SUM(amount) AS paid_total
            FROM purchase_invoice_payments
            GROUP BY invoice_id
         ) payments ON payments.invoice_id = pi.id
         WHERE pi.company_id = :company_id
           AND ' . $filterConditions[$paymentFilter] . '
         ORDER BY pi.invoice_date DESC, pi.id DESC'
    );

    $params = ['company_id' => $companyId];

    if ($paymentFilter === 'overdue') {
        $params['today'] = date('Y-m-d');
    }

    $listStmt->execute($params);
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
    <title>Ostuarved</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 32px; }
        table { border-collapse: collapse; margin-top: 16px; width: 100%; }
        th, td { padding: 8px 12px; border: 1px solid #ccc; text-align: left; }
        .number { text-align: right; white-space: nowrap; }
        label { display: block; margin: 12px 0; }
        input, select { padding: 7px; }
        .button { display: inline-block; padding: 9px 14px; background: #1769aa; color: white; text-decoration: none; border-radius: 6px; }
		.delete-button { display: inline-block; padding: 8px 12px; border: 0; border-radius: 6px; background: #ad3434; color: white; font: inherit; cursor: pointer; }
		
		.purchase-posting-card {
    margin-top: 28px;
    padding: 24px;
    background: #f7f9fc;
    border: 1px solid #dce3ec;
    border-radius: 12px;
    max-width: 760px;
}

.purchase-posting-card h2 {
    margin-top: 0;
    color: #17324d;
}

.purchase-posting-intro {
    margin: 12px 0 18px;
    color: #526273;
    line-height: 1.5;
}

.purchase-cancellation-card {
    margin: 28px 0 32px;
}

.purchase-cancellation-card form {
    display: grid;
    gap: 10px;
    margin-top: 18px;
}

.purchase-cancellation-card label {
    display: block;
    margin: 4px 0 0;
    font-weight: 600;
}

.purchase-cancellation-card select,
.purchase-cancellation-card input {
    box-sizing: border-box;
    width: 100%;
    max-width: 620px;
    padding: 10px 12px;
    border: 1px solid #b8c4d1;
    border-radius: 7px;
    background: #fff;
    font: inherit;
}

.purchase-cancellation-card .delete-button {
    justify-self: start;
    margin-top: 6px;
}

.purchase-journal-link {
    margin: 20px 0 26px;
}

.purchase-posting-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(220px, 1fr));
    gap: 16px;
}

.purchase-posting-field label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
}

.purchase-posting-field select {
    box-sizing: border-box;
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #b8c4d1;
    border-radius: 7px;
    background: white;
    font-size: 15px;
}

.purchase-posting-actions {
    margin-top: 22px;
}

.purchase-posting-button {
    padding: 11px 18px;
    border: 0;
    border-radius: 7px;
    background: #1769aa;
    color: white;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
}

.purchase-posting-button:hover {
    background: #0f4f83;
}

@media (max-width: 600px) {
    .purchase-posting-grid {
        grid-template-columns: 1fr;
    }
}
.payment-card {
    margin-top: 28px;
    padding: 24px;
    background: #f7f9fc;
    border: 1px solid #dce3ec;
    border-radius: 12px;
}

.payment-card h2,
.payment-card h3 {
    color: #17324d;
}

.payment-summary {
    display: grid;
    grid-template-columns: repeat(3, minmax(150px, 1fr));
    gap: 12px;
    margin: 18px 0 24px;
}

.payment-summary-item {
    padding: 15px;
    background: #fff;
    border: 1px solid #e1e7ef;
    border-radius: 9px;
}

.payment-summary-item span {
    display: block;
    margin-bottom: 6px;
    color: #526273;
    font-size: 13px;
}

.payment-summary-item strong {
    color: #17324d;
    font-size: 19px;
}

.payment-history {
    overflow-x: auto;
}

.payment-history table {
    margin-top: 10px;
    background: #fff;
}

.payment-form {
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid #dce3ec;
}

.payment-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(220px, 1fr));
    gap: 14px;
}

.payment-field label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    color: #263746;
}

.payment-field input,
.payment-field select {
    box-sizing: border-box;
    width: 100%;
    padding: 10px 12px;
    border: 1px solid #b8c4d1;
    border-radius: 7px;
    background: #fff;
    font-size: 15px;
}

.payment-submit {
    margin-top: 18px;
    padding: 11px 18px;
    border: 0;
    border-radius: 7px;
    background: #1769aa;
    color: #fff;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
}

.payment-submit:hover {
    background: #0f4f83;
}

@media (max-width: 650px) {
    .payment-summary,
    .payment-grid {
        grid-template-columns: 1fr;
    }
}
	.purchase-filter-card {
    display: flex;
    flex-wrap: wrap;
    align-items: end;
    gap: 12px;
    max-width: 680px;
    margin: 20px 0 24px;
    padding: 18px 20px;
    background: #f7f9fc;
    border: 1px solid #dce3ec;
    border-radius: 12px;
}

.purchase-filter-card label {
    flex-basis: 100%;
    margin: 0;
    color: #33475b;
    font-weight: 600;
}

.purchase-filter-card select {
    flex: 1 1 280px;
    padding: 11px 12px;
    border: 1px solid #b8c4d1;
    border-radius: 7px;
    background: #fff;
    font: inherit;
}

.purchase-filter-card button {
    padding: 11px 20px;
    border: 0;
    border-radius: 7px;
    background: #1769aa;
    color: #fff;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
}

.purchase-filter-card button:hover {
    background: #0f4f83;
}

@media (max-width: 600px) {
    .purchase-filter-card {
        align-items: stretch;
        flex-direction: column;
    }
}	
    </style>
</head>
<body>

<?php if ($purchaseDeleteNotice !== ''): ?><p style="padding:12px 15px;border:1px solid #b6dfc2;border-radius:8px;background:#eaf7ef;color:#176b3a"><?= h((string) $purchaseDeleteNotice) ?></p><?php endif; ?>
<?php if ($purchaseDeleteError !== ''): ?><p style="padding:12px 15px;border:1px solid #efb4b4;border-radius:8px;background:#fff0f0;color:#a12626"><?= h((string) $purchaseDeleteError) ?></p><?php endif; ?>

<?php if ($invoice): ?>
    <?php
    $netTotal = 0;
    $vatTotal = 0;
    $grossTotal = 0;
    $reverseVatTotal = 0;
    foreach ($lines as $line) {
        $spec = reverseVatSpec((string) ($line['tax_code'] ?? ''));
        if ($spec) $reverseVatTotal += isset($creditReverse[$line['id']])?-(int)$creditReverse[$line['id']]:creditDisplayReverseVat(toCents((string) $line['net_amount']), $spec['rate']);
        $netTotal += toCents((string) $line['net_amount']);
        $vatTotal += toCents((string) $line['vat_amount']);
        $grossTotal += toCents((string) $line['gross_amount']);
    }
    ?>

    <h1>Ostuarve <?= h($invoice['supplier_invoice_number']) ?></h1>
    <?php if ($invoice['status'] === 'posted' && !isset($creditLinks[$invoice['id']])): ?>
        <p><a class="button" href="purchase_vat_period_edit.php?id=<?= (int)$invoice['id'] ?>">Sisendkäibemaksu jaotus kuude vahel</a></p>
    <?php endif; ?>
    <?php if(isset($creditLinks[$invoice['id']])): ?>
    <p><strong>Kreeditarve algsele arvele <a href="?id=<?= (int)$creditLinks[$invoice['id']]['original_invoice_id'] ?>"><?= h($creditLinks[$invoice['id']]['original_number']) ?></a>.</strong>
    Kreedit vähendab arvestust; võimalik tagasimakse kirjenda eraldi.</p>
    <?php if($invoice['status']==='posted'): ?><form method="post" action="void_credit_note.php" onsubmit="return confirm('Kas tühistad kreeditarve ja lood selle kande täpse vastukande?')">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="kind" value="purchase"><input type="hidden" name="invoice_id" value="<?= (int)$invoice['id'] ?>">
        <label>Tühistamise põhjus <input name="reason" required maxlength="500"></label><button type="submit">Tühista kreeditarve paranduskandega</button>
    </form><?php elseif(!empty($creditLinks[$invoice['id']]['void_entry_id'])): ?><p>Kreeditarve tühistati paranduskandega nr <?= (int)$creditLinks[$invoice['id']]['void_entry_id'] ?>.</p><?php endif; ?>

    <?php elseif($invoice['status']==='posted'): ?>
    <?php if (!$foreignInvoice): ?>
    <p><a class="button" href="credit_note.php?kind=purchase&amp;invoice_id=<?= (int)$invoice['id'] ?>">Koosta kreeditarve</a></p>
    <?php endif; ?>
    <?php if(!empty($creditedTotals[$invoice['id']])): ?><p>Krediteeritud: <?= h(creditDecimal($creditedTotals[$invoice['id']])) ?> €.
    Laekumisi ja makseid see ei muuda; vajadusel kirjenda tagasimakse eraldi.</p><?php endif; ?>
    <?php endif; ?>

    <?php if ($invoice['status'] === 'draft' && empty($invoice['journal_entry_id'])): ?>
    <form method="post" action="delete_purchase_invoice.php" onsubmit="return confirm('Kas kustutad ostuarve mustandi <?= h($invoice['supplier_invoice_number']) ?> ja selle read? Seda ei saa tagasi võtta.');" style="margin:12px 0">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
        <input type="hidden" name="return_filter" value="all">
        <button class="delete-button" type="submit">Kustuta ostuarve mustand</button>
    </form>
    <?php endif; ?>
    <p><strong>Tarnija:</strong> <?= h($invoice['supplier_name']) ?></p>
    <p><strong>Arve kuupäev:</strong> <?= h($invoice['invoice_date']) ?></p>
    <p><strong>Maksetähtaeg:</strong> <?= h($invoice['due_date'] ?? '') ?></p>
    <p><strong>Olek:</strong> <?= h($statusNames[$invoice['status']] ?? $invoice['status']) ?></p>
    <?php if ($cancellation): ?>
        <p class="purchase-posting-intro">Ostuarve ja sellega seotud laovastuvõtt tühistati paranduskandega
            <?php if ($cancellation['reversal_date'] !== null): ?><a href="entry.php?id=<?= (int) $cancellation['reversal_journal_entry_id'] ?>">#<?= (int) $cancellation['reversal_journal_entry_id'] ?></a> (<?= h($cancellation['reversal_date']) ?>).<?php else: ?>#<?= (int) $cancellation['reversal_journal_entry_id'] ?> (kanne on praeguseks eemaldatud).<?php endif; ?>
            <?= h($cancellation['reason']) ?>
        </p>
    <?php endif; ?>

    <table>
        <thead>
            <tr>
                <th>Konto</th>
                <th>Laotoode ja ladu</th>
                <th>Kirjeldus</th>
                <th>Kogus</th>
                <th>Ühikuhind</th>
                <th>KM (%)</th>
                <th class="number">Netosumma</th>
                <th class="number">KM</th>
                <th class="number">Kokku</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $line): ?>
                <tr>
                    <td><?= h($line['account_code'] . ' — ' . $line['account_name']) ?></td>
                    <td><?= $line['product_id'] ? h($line['product_sku'] . ' — ' . $line['product_name'] . ' · ' . $line['warehouse_code'] . ' — ' . $line['warehouse_name']) : '—' ?></td>
                    <td><?= h($line['description']) ?></td>
                    <td><?= h((string) $line['quantity']) ?></td>
                    <td><?= $foreignInvoice ? h(invoiceFxFormat($line['fx_unit_price'],$fxCurrency,true)) : h((string) $line['unit_price']) ?></td>
                    <td><?= $line['tax_code_label'] ? h($line['tax_code_label']) : h((string) $line['vat_rate']) . '%' ?></td>
                    <td class="number"><?php if ($foreignInvoice): ?><?= h(invoiceFxFormat($line['fx_net'],$fxCurrency)) ?><br><?php endif; ?><?= money(toCents((string) $line['net_amount'])) ?></td>
                    <td class="number"><?php if ($foreignInvoice): ?><?= h(invoiceFxFormat($line['fx_vat'],$fxCurrency)) ?><br><?php endif; ?><?= money(toCents((string) $line['vat_amount'])) ?></td>
                    <td class="number"><?php if ($foreignInvoice): ?><?= h(invoiceFxFormat($line['fx_gross'],$fxCurrency)) ?><br><?php endif; ?><?= money(toCents((string) $line['gross_amount'])) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <th colspan="6">Kokku</th>
                <th class="number"><?= money($netTotal) ?></th>
                <th class="number"><?= money($vatTotal) ?></th>
                <th class="number"><?= money($grossTotal) ?></th>
            </tr>
        </tbody>
    </table>

    <?php if ($foreignInvoice): ?><p>Arve kokku <strong><?= h(invoiceFxFormat(invoiceFxSum($lines,'fx_gross'),$fxCurrency)) ?></strong>.
        Kurss 1 EUR = <?= h(rtrim(rtrim($invoice['fx_rate'],'0'),'.').' '.$fxCurrency) ?>, kuupäev <?= h($invoice['fx_rate_date']) ?>.</p><?php endif; ?>
    <?php if ($reverseVatTotal > 0): ?>
    <p class="purchase-posting-intro">Pöördmaksu arvestatud KM: <strong><?= money($reverseVatTotal) ?></strong>.
    See summa lisandub käibemaksuarvestusse, tarnijale tasutav summa jääb <?= money($grossTotal) ?>.
    Kontrolli tehingule valitud pöördmaksustamise liigi sobivust ja vajadusel partneri KMKR numbri kehtivust.</p>
    <?php endif; ?>

    <?php if ($stockMovementId && $stockMovementStatus === 'posted'): ?><p class="purchase-posting-intro">Selle ostuarve kaubad võeti lattu vastu. <a href="ladu/movements.php">Vaata lao liikumisi</a>.</p><?php elseif ($stockMovementId && $stockMovementStatus === 'cancelled'): ?><p class="purchase-posting-intro">Selle arvega seotud laovastuvõtt on tühistatud.</p><?php endif; ?>

    <?php if ($invoice['status'] === 'draft'): ?>
        <section class="purchase-posting-card">
    <h2>Kinnita ostuarve</h2>
    <p class="purchase-posting-intro">
        Kontrolli arveridu ja vali kontod, kuhu kanne postitada.
    </p>
    <?php if ($stockLineCount > 0): ?><p class="purchase-posting-intro">Valitud <?= (int) $stockLineCount ?> laotoote rida võetakse ostuarve kinnitamisel valitud lattu vastu. Ära sisesta neile ridadele eraldi laovastuvõttu.</p><?php endif; ?>

    <form method="post" action="post_purchase_invoice.php">
        <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

        <div class="purchase-posting-grid">
            <div class="purchase-posting-field">
                <label for="payable-account">Tarnijavõla konto</label>
                <select id="payable-account" name="payable_account_id" required>
                    <option value="">Vali kohustuste konto</option>
                    <?php foreach ($accountsByType['liability'] as $account): ?>
                        <option value="<?= (int) $account['id'] ?>">
                            <?= h($account['code'] . ' — ' . $account['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($vatTotal > 0 || $reverseVatTotal > 0): ?>
                <div class="purchase-posting-field">
                    <label for="input-vat-account">Sisendkäibemaksu konto</label>
                    <select id="input-vat-account" name="vat_account_id" required>
                        <option value="">Vali varakonto</option>
                        <?php foreach ($accountsByType['asset'] as $account): ?>
                            <option value="<?= (int) $account['id'] ?>">
                                <?= h($account['code'] . ' — ' . $account['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($reverseVatTotal > 0): ?>
        <p>Pöördmaksu käibemaks: <strong><?= money($reverseVatTotal) ?></strong>. Tarnijale tasutav summa ei suurene. Sisendkäibemaks kantakse kinnitamisel täies ulatuses varakontole; mahaarvamise piirangu korral tee eraldi parandav kanne.</p>
        <div class="purchase-posting-field">
            <label for="reverse-vat-account">Pöördmaksu käibemaksukohustuse konto</label>
            <select id="reverse-vat-account" name="reverse_vat_account_id" required>
                <option value="">Vali käibemaksukohustuse konto</option>
                <?php foreach ($accountsByType['liability'] as $account): ?>
                <option value="<?= (int) $account['id'] ?>"><?= h($account['code'] . ' — ' . $account['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="purchase-posting-actions">
            <button
                class="purchase-posting-button"
                type="submit"
                onclick="return confirm('Kas kinnitad ostuarve ja lood pearaamatu kande?')"
            >
                Kinnita ostuarve
            </button>
        </div>
    </form>
</section>






    <?php elseif ($invoice['journal_entry_id']): ?>
        <p class="purchase-journal-link">
            Raamatupidamiskanne:
            <a href="entry.php?id=<?= (int) $invoice['journal_entry_id'] ?>">Ava kanne</a>
        </p>
    <?php endif; ?>

    <?php if ($invoice['status'] === 'posted' && $stockMovementId && $stockMovementStatus === 'posted'): ?>
        <?php
        $cancellationCandidates = [];
        $cancellationBlockers = [];
        if ($payments) {
            $cancellationBlockers[] = 'Arvega on seotud makse; esmalt tuleb makse tavapäraselt parandada.';
        }
        if (purchaseInvoiceLaterStockOutflow($pdo, $companyId, $stockMovementId)) {
            $cancellationBlockers[] = 'Sama kauba laovastuvõtule järgneb juba kinnitatud väljaminek.';
        }
        $invoicePeriodMessage = closedAccountingPeriodMessage($pdo, $companyId, $invoice['invoice_date']);
        if ($invoicePeriodMessage !== null) {
            $cancellationBlockers[] = $invoicePeriodMessage;
        }
        if (!$cancellationBlockers) {
            $cancellationCandidates = purchaseInvoiceReversalCandidates($pdo, $companyId, (int) $invoice['journal_entry_id']);
            if (!$cancellationCandidates) {
                $cancellationBlockers[] = 'Ei leitud kinnitatud pearaamatukannet, mis pööraks algse kande kõigi kontode ja summade lõikes täpselt vastupidiseks.';
            }
        }
        ?>
        <section class="purchase-posting-card purchase-cancellation-card">
            <h2>Tühista arve ja laovastuvõtt</h2>
            <p class="purchase-posting-intro">Kasuta seda ainult siis, kui kaup tegelikult lattu ei jõudnud ja algne ostuarve on juba täielikult paranduskandega tühistatud. Toiming märgib ostuarve ja seotud laoliikumise tühistatuks; uut pearaamatukannet ei looda.</p>
            <?php if ($cancellationBlockers): ?>
                <p><?= h(implode(' ', $cancellationBlockers)) ?></p>
            <?php else: ?>
                <form method="post" action="cancel_posted_purchase_invoice.php" onsubmit="return confirm('Kas oled kontrollinud, et kaup ei jõudnud lattu ning valitud kanne tühistab algse ostuarve täielikult? Arve ja laovastuvõtt märgitakse tühistatuks.');">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                    <div class="purchase-cancellation-field">
                        <label for="reversal-entry">Algse ostuarve täpne paranduskande vastukanne</label>
                        <select id="reversal-entry" name="reversal_entry_id" required>
                            <option value="">Vali paranduskande vastukanne</option>
                            <?php foreach ($cancellationCandidates as $candidate): ?>
                                <option value="<?= (int) $candidate['id'] ?>">
                                    <?= h($candidate['entry_date'] . ' · #' . $candidate['id'] . ' · ' . $candidate['description'] . ($candidate['document_number'] ? ' · ' . $candidate['document_number'] : '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="purchase-cancellation-field">
                        <label for="cancellation-reason">Märkus (soovituslik)</label>
                        <input id="cancellation-reason" type="text" name="reason" maxlength="500" placeholder="Näiteks kaup ei jõudnud lattu">
                    </div>
                    <div class="purchase-cancellation-actions">
                        <button class="delete-button" type="submit">Tühista ostuarve ja laovastuvõtt</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
	
	
	<?php if ($invoice['status'] === 'posted'): ?>
    <?php $remainingCents = max(0, $grossTotal - ($creditedTotals[$invoice['id']]??0) - $paidTotalCents); $refundDue=max(0,$paidTotalCents+($creditedTotals[$invoice['id']]??0)-$grossTotal); ?>
    <?php if($refundDue>0 && !isset($creditLinks[$invoice['id']])): ?><p>Tarnijalt tagastatav summa: <?= money($refundDue) ?>. Kirjenda toimunud tagasimakse seotud kreeditarve kaudu.</p><?php endif; ?>
    <?php if(!empty($returnedTotals[$invoice['id']])): ?><p>Tasumiste summast on maha arvatud tagasimaksed: <?= money($returnedTotals[$invoice['id']]) ?>.</p><?php endif; ?>
    <?php if(isset($creditLinks[$invoice['id']])): ?><p><a class="button" href="credit_refund.php?kind=purchase&amp;invoice_id=<?= (int)$invoice['id'] ?>">Kreeditarve tagasimaksed</a></p><?php else: ?><?php foreach($creditLinks as $creditId=>$creditLink): ?><?php if((int)$creditLink['original_invoice_id']===(int)$invoice['id'] && $creditLink['credit_status']==='posted'): ?><p><a href="credit_refund.php?kind=purchase&amp;invoice_id=<?= (int)$creditId ?>">Kreeditarve nr <?= (int)$creditId ?> tagasimaksed</a></p><?php endif; ?><?php endforeach; ?><?php endif; ?>

    <section class="payment-card">
        <h2>Maksed</h2>

        <div class="payment-summary">
            <div class="payment-summary-item">
                <span>Arve summa</span>
                <strong><?= money($grossTotal) ?></strong>
            </div>
            <div class="payment-summary-item">
                <span>Makstud</span>
                <strong><?= money($paidTotalCents) ?></strong>
            </div>
            <div class="payment-summary-item">
                <span>Tasumata</span>
                <strong><?= $foreignInvoice ? h(invoiceFxFormat($fxBalance,$fxCurrency)).' · ' : '' ?><?= money($remainingCents) ?></strong>
            </div>
        </div>

        <?php if ($payments): ?>
            <h3>Maksete ajalugu</h3>
            <div class="payment-history">
                <table>
                    <thead>
                        <tr>
                            <th>Kuupäev</th>
                            <th>Panga- või kassakonto</th>
                            <th class="number">Summa</th>
                            <th>Märkus</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?= h($payment['payment_date']) ?></td>
                                <td><?= h($payment['bank_code'] . ' — ' . $payment['bank_name']) ?></td>
                                <td class="number"><?php if ($foreignInvoice): ?><?= h(invoiceFxFormat($payment['fx_amount'],$fxCurrency)) ?><br><?= money(toCents($payment['fx_cash_eur'])) ?><?php else: ?><?= money(toCents((string) $payment['amount'])) ?><?php endif; ?></td>
                                <td><?= h($payment['notes'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($remainingCents > 0): ?>
            <div class="payment-form">
                <h3>Registreeri tarnijale makse</h3>

                <form method="post" action="post_purchase_payment.php">
                    <input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

                    <div class="payment-grid">
                        <div class="payment-field">
                            <label for="payment-date">Makse kuupäev</label>
                            <input id="payment-date" type="date" name="payment_date"
                                   value="<?= h(date('Y-m-d')) ?>" required>
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
                            <label for="payment-amount">Makse summa (€)</label>
                            <input id="payment-amount" type="number" name="amount"
                                   min="0.01"
                                   max="<?= number_format($remainingCents / 100, 2, '.', '') ?>"
                                   step="0.01" required>
                        </div>
                        <?php } ?>

                        <div class="payment-field">
                            <label for="payment-notes">Märkus</label>
                            <input id="payment-notes" type="text" name="notes"
                                   maxlength="500"
                                   placeholder="Soovi korral makse selgitus">
                        </div>
                    </div>

                    <button class="payment-submit" type="submit">
                        Salvesta makse
                    </button>
                </form>
            </div>
        <?php else: ?>
            <?php if(!isset($creditLinks[$invoice['id']])): ?><p>Ostuarve tasumata jääk on null.</p><?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>
    

    
	
	
	
	

    <p><a class="button" href="purchase_invoices.php">Ostuarvete nimekiri</a></p>

<?php else: ?>
    <h1>Ostuarved</h1>
    <p><a class="button" href="create_purchase_invoice.php">Uus ostuarve</a> <a class="button" href="import_vat_documents.php">Tollidokumendid ja impordi KM</a></p>
	
	<form method="get" class="purchase-filter-card">
    <label for="payment-filter">Näita arveid</label>

    <select id="payment-filter" name="payment_filter">
        <option value="all" <?= $paymentFilter === 'all' ? 'selected' : '' ?>>
            Kõiki arveid
        </option>        <option value="draft" <?= $paymentFilter === 'draft' ? 'selected' : '' ?>>
            Kinnitamata mustandid
        </option>
        <option value="unpaid" <?= $paymentFilter === 'unpaid' ? 'selected' : '' ?>>
            Tasumata
        </option>
        <option value="partial" <?= $paymentFilter === 'partial' ? 'selected' : '' ?>>
            Osaliselt tasutud
        </option>
        <option value="overdue" <?= $paymentFilter === 'overdue' ? 'selected' : '' ?>>
            Tähtaja ületanud
        </option>
    </select>

    <button type="submit">Filtreeri</button>
</form>

    <?php if (!$invoices): ?>
        <p>Ostuarveid pole veel loodud.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Tarnija arve nr</th>
                    <th>Tarnija</th>
                    <th>Kuupäev</th>
                    <th>Maksetähtaeg</th>
                    <th>Olek</th>
                    <th class="number">Summa</th>
					<th class="number">Makstud</th>
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
} elseif (
    $item['status'] === 'posted' &&
    !empty($item['due_date']) &&
    $item['due_date'] < date('Y-m-d') &&
    $remainingCents > 0
) {
    $displayStatus = 'Tähtaeg ületatud';
} elseif ($grossCents<0) {
    $displayStatus='Kreeditarve';
} elseif ($remainingCents === 0) {
    $displayStatus = 'Tasutud';
} elseif ($paidCents > 0) {
    $displayStatus = 'Osaliselt tasutud';
} else {
    $displayStatus = 'Tasumata';
}
?>
                    <tr>
                        <td>
                            <a href="purchase_invoices.php?id=<?= (int) $item['id'] ?>">
                                <?= h($item['supplier_invoice_number']) ?>
                            </a>
                        </td>
                        <td><?= h($item['supplier_name']) ?></td>
                        <td><?= h($item['invoice_date']) ?></td>
                        <td><?= h($item['due_date'] ?? '') ?></td>
                        <td><?= h($displayStatus) ?></td>
                        <td class="number"><?= money(toCents((string) $item['gross_total'])) ?></td>
						<td class="number"><?= money($paidCents) ?></td>
                        <td class="number"><?= money($remainingCents) ?></td>
						<td>
							<?php if ($item['status'] === 'draft'): ?>
								<form method="post" action="delete_purchase_invoice.php" onsubmit="return confirm('Kas kustutad ostuarve mustandi <?= h($item['supplier_invoice_number']) ?> ja selle read? Seda ei saa tagasi võtta.');" style="margin:0">
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
	

   
<?php endif; ?>

    <p><a class="button" href="index.php">Tagasi avalehele</a></p>


</body>
</html>
