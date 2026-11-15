<?php
declare(strict_types=1);
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) { header('Location: login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billberry_lib.php';
require_once __DIR__ . '/zero_sales_vat.php';

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function euro(string $value): string { return number_format((float) $value, 2, ',', ' ') . ' €'; }

$companyId = (int) $_SESSION['company_id'];
$invoiceId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$error = '';
$invoice = null;
$company = null;
$lines = [];
$alreadySent = false;
$billberryEnvironment = 'production';

try {
    if (!$invoiceId || $invoiceId < 1) throw new RuntimeException('Arve tunnus puudub või on vigane.');
    $companyStmt = $pdo->prepare('SELECT * FROM companies WHERE id = ?');
    $companyStmt->execute([$companyId]);
    $company = $companyStmt->fetch(PDO::FETCH_ASSOC);

    $invoiceStmt = $pdo->prepare(
        'SELECT si.*, c.name AS contact_name, c.registry_code AS contact_registry_code,
                c.bank_einvoice_bic, c.bank_einvoice_account
         FROM sales_invoices si
         JOIN contacts c ON c.id = si.contact_id AND c.company_id = si.company_id
         WHERE si.id = ? AND si.company_id = ?'
    );
    $invoiceStmt->execute([$invoiceId, $companyId]);
    $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
    if (!$company || !$invoice || $invoice['status'] !== 'posted') throw new RuntimeException('E-arve eelvaadet saab vaadata ainult selle ettevõtte kinnitatud müügiarvele.');

    if ($billberryEnvironment === 'production') {
        $sentStmt = $pdo->prepare('SELECT billberry_invoice_id FROM billberry_sent_invoices WHERE company_id = ? AND sales_invoice_id = ?');
        $sentStmt->execute([$companyId, $invoiceId]);
        $alreadySent = $sentStmt->fetchColumn() !== false;
    }

    $linesStmt = $pdo->prepare('SELECT description, quantity, unit_price, vat_rate, net_amount, vat_amount, gross_amount FROM sales_invoice_lines WHERE invoice_id = ? ORDER BY id');
    $linesStmt->execute([$invoiceId]);
    $lines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);
    $invoiceGrossTotal = array_sum(array_map(static fn(array $line): float => (float) $line['gross_amount'], $lines));
    $buyerBankBic = strtoupper(trim((string) ($invoice['bank_einvoice_bic'] ?? '')));
    $buyerBankNames = billberryBankBics();
    $buyerBankName = $buyerBankNames[$buyerBankBic] ?? '';
    // Kasuta sama koostajat, mida saatmisel kasutatakse, et puuduvad andmed ilmneksid enne saatmist.
    $invoice=zeroEinvoiceData($pdo,$companyId,(int)$invoiceId,$invoice);
    billberryBuildSalesInvoiceXml($company, $invoice, $lines);
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>E-arve eelvaade</title>
<style>
*{box-sizing:border-box}body{margin:0;padding:30px 18px;background:#f3f6fa;color:#243447;font-family:Arial,sans-serif}main{max-width:1050px;margin:auto}h1,h2{color:#17324d}.card{background:#fff;border:1px solid #dce3ec;border-radius:12px;padding:22px;margin:18px 0}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 28px}.label{color:#647589;font-size:.9rem}.value{font-weight:600;margin-top:4px}.warning{padding:14px 16px;background:#fff5dd;border:1px solid #e8d397;border-radius:8px;color:#735500;line-height:1.5}.error{padding:14px 16px;background:#fff0f0;border:1px solid #eab5b5;border-radius:8px;color:#9d2929}.table-wrap{overflow-x:auto}table{border-collapse:collapse;width:100%;min-width:760px}th,td{padding:10px 9px;border-bottom:1px solid #e2e8ef;text-align:left}th{background:#f1f5f9;color:#425b74}td.num,th.num{text-align:right;white-space:nowrap}.actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:20px}.button{display:inline-block;padding:11px 16px;border:0;border-radius:7px;background:#1769aa;color:white;font-weight:700;text-decoration:none;cursor:pointer;font-size:1rem}.button.danger{background:#a93434}.button.secondary{background:#647589}.total{font-size:1.12rem;font-weight:700;text-align:right;padding-top:15px}.muted{color:#647589;line-height:1.5}@media(max-width:650px){.grid{grid-template-columns:1fr}}
</style>
</head>
<body><main>
<h1>E-arve eelvaade</h1>
<p class="muted">Kontrolli enne saatmist saajat, arveridu ja summasid. Arvet ei saadeta enne, kui vajutad all eraldi saatmisnuppu.</p>
<?php if ($error !== ''): ?>
    <div class="error"><?= h($error) ?></div>
    <p><a class="button secondary" href="invoices.php<?= $invoiceId ? '?id=' . (int) $invoiceId : '' ?>">Tagasi arvele</a></p>
<?php elseif ($invoice && $company): ?>
    <?php if ($alreadySent): ?><div class="warning">See arve on Billberry kaudu juba saadetud. Uuesti saatmine pole siin lubatud.</div><?php endif; ?>
    <?php if ($buyerBankBic !== ''): ?>
        <div class="warning"><strong>Panga e-arve päriskeskkonnas:</strong> arve suunatakse <?= h($buyerBankName) ?> panka. Billberry pangakanali saatmine on tasuline; kontrolli teenustasu Billberrys enne saatmist. Kontrolli eriti saaja isikukoodi, panka, IBAN-i ja arve summat.</div>
    <?php else: ?>
        <div class="warning"><strong>Päriskeskkond aktiivne:</strong> saatmisnupp edastab tavalise e-arve Billberry päristeenuse kaudu nimetatud saajale.</div>
    <?php endif; ?>
    <section class="card">
        <h2>Arve ja osapooled</h2>
        <div class="grid">
            <div><div class="label">Arve nr</div><div class="value"><?= h((string) $invoice['invoice_number']) ?></div></div>
            <div><div class="label">Arve kuupäev / maksetähtaeg</div><div class="value"><?= h((string) $invoice['invoice_date']) ?> / <?= h((string) $invoice['due_date']) ?></div></div>
            <div><div class="label">Müüja</div><div class="value"><?= h((string) $company['name']) ?> · registrikood <?= h((string) ($company['registry_code'] ?? '')) ?></div></div>
            <div><div class="label">Ostja ehk e-arve saaja</div><div class="value"><?= h((string) $invoice['contact_name']) ?> · tunnus <?= h((string) ($invoice['contact_registry_code'] ?? '')) ?><?php if ($buyerBankBic !== ''): ?><br>Panga e-arve: <?= h($buyerBankName) ?><?php if ($buyerBankBic !== 'RIKOEE22'): ?> · <?= h((string) ($invoice['bank_einvoice_account'] ?? '')) ?><?php endif; ?><?php endif; ?></div></div>
            <div><div class="label">Müüja pangakonto</div><div class="value"><?= h((string) ($company['bank_account'] ?? '')) ?></div></div>
            <div><div class="label">Valuuta</div><div class="value">EUR</div></div>
        </div>
    </section>
    <section class="card">
        <h2>Arveread</h2>
        <div class="table-wrap"><table><thead><tr><th>Kirjeldus</th><th class="num">Kogus</th><th class="num">Ühikuhind</th><th class="num">KM %</th><th class="num">Neto</th><th class="num">KM</th><th class="num">Kokku</th></tr></thead><tbody>
        <?php foreach ($lines as $line): ?>
            <tr><td><?= h((string) $line['description']) ?></td><td class="num"><?= h((string) $line['quantity']) ?></td><td class="num"><?= euro((string) $line['unit_price']) ?></td><td class="num"><?= h((string) $line['vat_rate']) ?>%</td><td class="num"><?= euro((string) $line['net_amount']) ?></td><td class="num"><?= euro((string) $line['vat_amount']) ?></td><td class="num"><?= euro((string) $line['gross_amount']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <div class="total">Arve kokku: <?= euro((string) $invoiceGrossTotal) ?></div>
    </section>
    <div class="actions">
        <a class="button" href="billberry_invoice_xml.php?id=<?= (int) $invoice['id'] ?>">Laadi e-arve XML alla</a>
        <?php if (!$alreadySent): ?><form method="post" action="billberry_send_invoice.php" onsubmit="return confirm('See saadab arve kohe päris saajale<?= $buyerBankBic !== '' ? ' ja pangakanali saatmine võib olla tasuline' : '' ?>. Kas oled saaja ja arve andmed üle kontrollinud?')"><input type="hidden" name="csrf_token" value="<?= h((string) $_SESSION['csrf_token']) ?>"><input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>"><input type="hidden" name="confirm_send" value="1"><button class="button danger" type="submit"><?= $buyerBankBic !== '' ? 'Saada panka e-arvena' : 'Saada e-arve' ?></button></form><?php endif; ?>
        <a class="button secondary" href="invoices.php?id=<?= (int) $invoice['id'] ?>">Katkesta ja tagasi arvele</a>
    </div>
<?php endif; ?>
</main></body></html>
