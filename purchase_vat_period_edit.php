<?php
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__.'/db.php';
require_once __DIR__.'/kmd_inf_confirmation.php';
require_once __DIR__.'/kmd_inf_data.php';
require_once __DIR__.'/cash_vat_preview.php';
ensureInfConfirmationSchema($pdo);
ensureCreditNoteSchema($pdo);

$company = (int)$_SESSION['company_id'];
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
$query = $pdo->prepare("SELECT invoice_date, supplier_invoice_number, invoice_currency, fx_event_date FROM purchase_invoices
    WHERE company_id=? AND id=? AND status='posted'");
$query->execute([$company, $id]);
$invoice = $query->fetch(PDO::FETCH_ASSOC);
if (!$invoice) {
    http_response_code(404);
    exit('Kinnitatud ostuarvet ei leitud.');
}

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$_SESSION['vat_period_csrf'] ??= bin2hex(random_bytes(32));
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($_SESSION['vat_period_csrf'], (string)($_POST['csrf'] ?? ''))) {
            throw new InvalidArgumentException('Vorm aegus. Värskenda lehte.');
        }
        savePurchaseVatPeriodPlan($pdo, $company, (int)$_SESSION['user_id'], $id, $_POST);
        header('Location: purchase_vat_period_edit.php?id='.$id.'&saved=1');
        exit;
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log($exception->getMessage());
        $error = 'Jaotuse salvestamine ebaõnnestus.';
    }
}

$plan = purchaseVatPeriodPlan($pdo, $company, $id);
$snapshot = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));
$cashPlan = isCashVatPlan($plan['allocation_json'] ?? null);
$ownCashPlan = ($plan['allocation_json'] ?? '') === CASH_COMPANY_PLAN;
$companyCashPeriod=companyCashVatPeriod($pdo,$company);
$ownCashAvailable = !empty($companyCashPeriod['cash_vat_from']) || !empty($companyCashPeriod['cash_vat_history']);
$allocations = $plan && !$cashPlan ? json_decode($plan['allocation_json'], true, 512, JSON_THROW_ON_ERROR) : [];
$source = null;
try {
    $loaded = loadInfRows($pdo, $company, $invoice['invoice_date'], $invoice['invoice_date'], 'purchase', [$id]);
    if (!$loaded['invoices']) throw new InvalidArgumentException('Arve read puuduvad.');
    $source = purchaseVatPeriodSource($pdo, $company, $loaded['invoices'][0], !$plan);
} catch (InvalidArgumentException $exception) {
    $error = $error ?: $exception->getMessage();
}
$months = array_keys($allocations);
$lineAllocations = $source['vat_line_allocations'] ?? [];
$amounts = array_map(static fn($amount): string => is_array($amount) ? '' : creditDecimal($amount), array_values($allocations));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_array($_POST['month'] ?? null)) {
    $months = array_slice(array_values($_POST['month']), 0, 24);
    $amounts = is_array($_POST['amount'] ?? null) ? array_slice(array_values($_POST['amount']), 0, 24) : [];
}
$rowCount = max(2, count($months));
$origin = substr($invoice['invoice_date'], 0, 7);
$paymentMonths = [];
if ($source && $source['deduction_confirmed'] && $source['deductible_cents'] > 0
    && !$source['reverse_line_count'] && count($source['rates']) === 1
    && count($source['qualifying_rates']) === 1 && $source['country_code'] === 'EE'
    && (int) (creditAppliedTotals($pdo, $company, 'purchase')[$id] ?? 0) === 0) {
    $paymentRows = purchaseCashSupplierPayments($pdo, $company, $id);
    if ($paymentRows && $paymentRows[0]['payment_date'] >= $invoice['invoice_date']) {
        $paymentMonths = cashVatPaymentMonths($paymentRows, $source['gross'], $source['deductible_cents']);
    }
}
?>
<!doctype html>
<html lang="et"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sisendkäibemaksu jaotus kuude vahel</title>
<style>
body{max-width:840px;margin:32px auto;padding:0 20px;background:#f4f7fb;color:#20354a;font:16px Arial;line-height:1.5}
.card{background:white;padding:24px;border:1px solid #dce4ee;border-radius:12px;margin:18px 0}
label{display:block;margin:10px 0}input,textarea,button{font:inherit;padding:8px;border:1px solid #bccbd9;border-radius:6px}
textarea{box-sizing:border-box;width:100%}button{background:#1769aa;color:white;cursor:pointer}button.secondary{background:#526478}
.row{display:flex;flex-wrap:wrap;gap:12px}.error{background:#ffecec;padding:12px}.success{background:#e6f5ea;padding:12px}a{color:#1769aa}
</style></head><body>
<h1>Sisendkäibemaksu jaotus kuude vahel</h1>
<p><a href="purchase_invoices.php?id=<?= $id ?>">Tagasi ostuarvele</a> ·
<a href="kmd_inf_preview.php?year=<?= h(substr($origin,0,4)) ?>&amp;month=<?= (int)substr($origin,5) ?>">Arve kuu KMD INF</a></p>
<p>Ostuarve <strong><?= h($invoice['supplier_invoice_number']) ?></strong> · <?= h($invoice['invoice_date']) ?></p>
<?php if ($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><p class="success">Jaotus salvestatud.</p><?php endif; ?>
<?php if ($source): ?>
<?php if ($cashPlan): ?>
<p class="success"><?= $ownCashPlan ? 'Ettevõtte kassapõhine ost on kinnitatud.' : 'Kassapõhine tarnija on kinnitatud.' ?> KMD ja INF B kasutavad registreeritud maksete kuupäevi.</p>
<?php endif; ?>
<?php if ($paymentMonths): ?>
<details class="card">
    <summary>Kassapõhise ostu arvutusabi</summary>
    <p>Siin on kinnitatud sisendkäibemaksu jaotus registreeritud maksete järgi.</p>
    <ul>
        <?php foreach ($paymentMonths as $month => $vat): ?>
            <li><?= h($month) ?> · <?= h(infEuros($vat)) ?></li>
        <?php endforeach; ?>
    </ul>
</details>
<?php endif; ?>
<?php if ($invoice['invoice_currency']==='EUR' && !$plan && $source && $source['deduction_confirmed'] && $source['country_code']==='EE'
    && (!$source['reverse_line_count'] || $source['reverse_scope']==='domestic')): ?>
<form method="post" class="card">
    <h2>Kassapõhine ost</h2>
    <input type="hidden" name="csrf" value="<?= h($_SESSION['vat_period_csrf']) ?>">
    <input type="hidden" name="snapshot" value="<?= h($snapshot) ?>">
    <input type="hidden" name="invoice_snapshot" value="<?= h($source['confirmation_hash']) ?>">
    <label>Arve saamise kuupäev <input type="date" name="received_date" min="<?= h($invoice['invoice_date']) ?>" required></label>
    <label>Kauba või teenuse saamise kuupäev <input type="date" name="tax_event_date" value="<?= h($invoice['invoice_date']) ?>" required></label>
    <label>Ettemakse kuu deklaratsiooni tegelik esitamise kuupäev, kui arve saadi enne esitamist <input type="date" name="filed_date"></label>
    <p>Kui arve jõudis enne eelmise maksekuu deklaratsiooni esitamist ja selle tähtaega, saab ettemakse maha arvata maksekuus. Tühja esitamise kuupäeva korral algab mahaarvamine kõige varem arve saamise kuust.</p>
    <p>Ettemakse arvestatakse maha kõige varem arve saamisel. Põhivara-, auto- ja segaarvel kinnita esmalt iga rea mahaarvamine ning kasutusliik. Makse jaotub ridade vahel proportsionaalselt arve jäägiga.</p>
    <label>Kassapõhise arvestuse alus <input name="basis" maxlength="500" required></label>
    <label><input type="checkbox" name="confirmed" value="1" required> Kontrollisin, et selle arve mahaarvamise õigus tekib tasumisel.</label>
    <?php if ($ownCashAvailable): ?><button type="submit" name="action" value="cash_company">Ettevõtte kassapõhine ost</button><?php endif; ?>
    <button type="submit" name="action" value="cash_supplier">Kassapõhise tarnija ost</button>
</form>
<?php endif; ?>
<?php if (!$cashPlan): ?><form method="post" class="card">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['vat_period_csrf']) ?>">
    <input type="hidden" name="snapshot" value="<?= h($snapshot) ?>">
    <input type="hidden" name="invoice_snapshot" value="<?= h($source['confirmation_hash']) ?>">
    <?php if ($source['deduction_confirmed']): ?>
        <p>Kinnitatud mahaarvatav kogusumma on <strong><?= h(infEuros($source['deductible_cents'])) ?></strong>.</p>
    <?php else: ?>
        <label>Arve kogu mahaarvatav KM (€)
            <input name="deductible_total" inputmode="decimal" required value="<?= h((string)($_POST['deductible_total'] ?? creditDecimal($source['deduction_vat']))) ?>">
        </label>
        <label>Ostu käibemaksu liik
            <select name="vat_purpose">
            <?php foreach (vatPurposeOptions() as $purpose => $label): ?>
                <option value="<?= h($purpose) ?>" <?= ($_POST['vat_purpose'] ?? 'ordinary') === $purpose ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
            </select>
        </label>
        <label>Sõiduauto registreerimismärk või VIN <input name="car_ids" value="<?= h((string)($_POST['car_ids'] ?? '')) ?>"></label>
    <?php endif; ?>
    <p>Jaga kogusumma kuude vahel vastavalt mahaarvamise õiguse tekkimisele. Iga summa jõuab sama kuu KMD-sse ja vajaduse korral INF B-ossa.</p>
    <label>Arve saamise kuupäev <input type="date" name="received_date" min="<?= h($invoice['invoice_date']) ?>" required value="<?= h((string)($_POST['received_date'] ?? $plan['received_date'] ?? '')) ?>"></label>
    <label>Maksustatava sündmuse kuupäev
        <input type="date" name="tax_event_date" value="<?= h((string)($invoice['fx_event_date'] ?? $_POST['tax_event_date'] ?? $plan['tax_event_date'] ?? $invoice['invoice_date'])) ?>" <?= $invoice['fx_event_date'] ? 'readonly' : '' ?>>
    </label>
    <label>Varasema kuu KMD esitamise kuupäev, kui arve oli esitamise ajaks käes
        <input type="date" name="filed_date" value="<?= h((string)($_POST['filed_date'] ?? $plan['filed_date'] ?? '')) ?>"></label>
    <label>Mahaarvamise kuude alus <textarea name="basis" required maxlength="500" rows="3"><?= h((string)($_POST['basis'] ?? $plan['basis'] ?? '')) ?></textarea></label>
    <div id="allocation-rows">
    <?php for ($index=0; $index<$rowCount; $index++): ?>
        <div class="row"><label>Kuu <input type="month" name="month[]" value="<?= h((string)($months[$index] ?? '')) ?>"></label>
        <?php if ($lineAllocations): ?>
            <?php $lineNumber=0; foreach ($lineAllocations as $lineId=>$line): ?>
                <label>Rida <?= ++$lineNumber ?> · kokku <?= h(infEuros($line['deductible_cents'])) ?>
                    <input name="line_amount[<?= $lineId ?>][]" inputmode="decimal"
                        value="<?= h((string)($_POST['line_amount'][$lineId][$index] ?? (isset($months[$index],$allocations[$months[$index]][$lineId]) ? creditDecimal($allocations[$months[$index]][$lineId]) : ''))) ?>">
                </label>
            <?php endforeach; ?>
        <?php else: ?>
            <label>Mahaarvatav KM (€) <input name="amount[]" inputmode="decimal" value="<?= h((string)($amounts[$index] ?? '')) ?>"></label>
        <?php endif; ?></div>
    <?php endfor; ?>
    </div>
    <button type="button" class="secondary" id="add-period">Lisa kuu</button>
    <label><input type="checkbox" name="confirmed" value="1" required> Kontrollisin, et sisendkäibemaksu mahaarvamise õigus tekib näidatud kuudel ja summades.</label>
    <button type="submit" name="action" value="save">Salvesta kuude jaotus</button>
</form>
<?php endif; ?>
<?php endif; ?>
<?php if ($plan): ?>
<form method="post" class="card" onsubmit="return confirm('Kas taastad kogu kinnitatud mahaarvamise arve kuus?')">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['vat_period_csrf']) ?>">
    <input type="hidden" name="snapshot" value="<?= h($snapshot) ?>">
    <button type="submit" class="secondary" name="action" value="remove">Taasta mahaarvamine arve kuus</button>
</form>
<?php endif; ?>
<script>
document.getElementById('add-period')?.addEventListener('click', () => {
    const rows = document.getElementById('allocation-rows');
    if (rows.children.length >= 24) return;
    const row = rows.firstElementChild.cloneNode(true);
    row.querySelectorAll('input').forEach(input => input.value = '');
    rows.append(row);
    row.querySelector('input').focus();
});
</script></body></html>
