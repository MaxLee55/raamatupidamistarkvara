<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/eu_reverse_vat.php';
require_once __DIR__ . '/credit_note_lib.php';
require_once __DIR__ . '/invoice_fx.php';

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

function dateEt(?string $date): string
{
    if (!$date) {
        return '';
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

    return $parsed ? $parsed->format('d.m.Y') : $date;
}

$companyId = (int) $_SESSION['company_id'];
$invoiceId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);

if (!$invoiceId) {
    http_response_code(404);
    exit('Arve ID puudub või on vigane.');
}

$invoiceStmt = $pdo->prepare(
    'SELECT
        si.*,
        c.name AS contact_name,
        c.registry_code AS contact_registry_code,
		c.vat_number AS contact_vat_number,
        c.address AS contact_address,
        c.email AS contact_email,
        c.phone AS contact_phone
     FROM sales_invoices si
     JOIN contacts c
       ON c.id = si.contact_id
      AND c.company_id = si.company_id
     WHERE si.id = ?
       AND si.company_id = ?'
);
$invoiceStmt->execute([$invoiceId, $companyId]);
$invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    http_response_code(404);
    exit('Arvet ei leitud.');
}

$companyStmt = $pdo->prepare(
    'SELECT
        name,
        registry_code,
        address,
        postal_code,
        city,
        country,
        phone,
        invoice_email,
        vat_number,
        bank_name,
        bank_account
     FROM companies
     WHERE id = ?'
);
$companyStmt->execute([$companyId]);
$company = $companyStmt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    http_response_code(404);
    exit('Ettevõtte andmeid ei leitud.');
}

$linesStmt = $pdo->prepare(
    'SELECT
        id, description,
        (SELECT code FROM tax_codes WHERE id = sales_invoice_lines.tax_code_id) AS tax_code,
        quantity,
        unit_price,
        vat_rate,
        net_amount,
        vat_amount,
        gross_amount, fx_unit_price, fx_net, fx_vat, fx_gross
     FROM sales_invoice_lines
     WHERE invoice_id = ?
     ORDER BY id'
);
$linesStmt->execute([$invoiceId]);
$lines = $linesStmt->fetchAll(PDO::FETCH_ASSOC);
$fxCurrency = invoiceFxCurrency($invoice);
$foreignInvoice = $fxCurrency !== 'EUR';
$invoice=zeroOutputInvoice($pdo,$companyId,(int)$invoiceId,$invoice);
if(!empty($invoice['zero_seller_vat'])) $company['vat_number']=$invoice['zero_seller_vat'];
$zeroMeta=zeroContext($pdo,$companyId,(int)$invoiceId);
foreach($lines as &$line) {
    if($zeroMeta && (float)$line['vat_rate']===0.0 && $zeroMeta['override_code']) $line['tax_code']=$zeroMeta['override_code'];
    $line['is_exempt']=(zeroSalesSpecs()[$line['tax_code']??'']['category']??'')==='exempt';
} unset($line);

$netTotal = 0;
$vatTotal = 0;
$grossTotal = 0;
$vatSummary = [];
$reverseInfoVat=0;
$creditReverse=creditReverseAmounts($pdo,$companyId,'sales');

foreach ($lines as $line) {
    $netCents = toCents((string) $line['net_amount']);
    $vatCents = toCents((string) $line['vat_amount']);
    $grossCents = toCents((string) $line['gross_amount']);

    $reverseSpec=reverseVatSpec((string)($line['tax_code']??''));
    if ($reverseSpec) $reverseInfoVat+=isset($creditReverse[$line['id']??0])?-(int)$creditReverse[$line['id']]:creditDisplayReverseVat($netCents,$reverseSpec['rate']);
    $netTotal += $netCents;
    $vatTotal += $vatCents;
    $grossTotal += $grossCents;

    $rate = $line['is_exempt']?'exempt':number_format((float) $line['vat_rate'], 2, '.', '');

    if (!isset($vatSummary[$rate])) {
        $vatSummary[$rate] = ['net' => 0, 'vat' => 0];
    }

    $vatSummary[$rate]['net'] += $netCents;
    $vatSummary[$rate]['vat'] += $vatCents;
}

$pdfMode = isset($_GET['pdf']) && $_GET['pdf'] === '1';
if ($pdfMode) {
    ob_start();
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $netTotal<0?'Kreeditarve':'Arve' ?> <?= h($invoice['invoice_number']) ?></title>
    <style>
        body {
            margin: 24px;
            background: #eef2f6;
            color: #243447;
            font-family: Arial, sans-serif;
        }

        .actions {
            max-width: 900px;
            margin: 0 auto 16px;
            display: flex;
            gap: 10px;
        }

        .button {
            display: inline-block;
            padding: 10px 16px;
            border: 0;
            border-radius: 7px;
            background: #1769aa;
            color: #fff;
            font: inherit;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .invoice-sheet {
            position: relative;
            box-sizing: border-box;
            max-width: 900px;
            min-height: 900px;
            margin: 0 auto;
            padding: 48px;
            background: #fff;
            box-shadow: 0 4px 18px rgba(25, 45, 70, .12);
        }

        .invoice-header {
            display: flex;
            justify-content: space-between;
            gap: 30px;
            padding-bottom: 22px;
            border-bottom: 2px solid #1769aa;
        }

        h1 {
            margin: 0;
            color: #17324d;
            font-size: 34px;
        }

        h2 {
            margin: 28px 0 10px;
            color: #17324d;
            font-size: 16px;
        }

        .invoice-number {
            margin-top: 8px;
            color: #526273;
        }

        .invoice-meta {
            text-align: right;
        }

        .parties {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 36px;
            margin: 28px 0;
        }

        .party-name {
            margin-bottom: 8px;
            color: #17324d;
            font-size: 18px;
            font-weight: 700;
        }

        .muted {
            color: #526273;
            line-height: 1.55;
        }

        table {
            width: 100%;
            margin-top: 18px;
            border-collapse: collapse;
        }

        th, td {
            padding: 10px 9px;
            border-bottom: 1px solid #dce3ec;
            text-align: left;
            vertical-align: top;
        }

        th {
            background: #f3f6fa;
            color: #526273;
            font-size: 12px;
        }

        .number {
            text-align: right;
            white-space: nowrap;
        }

        .totals {
            width: 340px;
            margin: 22px 0 0 auto;
        }

        .totals td {
            border: 0;
            padding: 6px 4px;
        }

        .grand-total td {
            padding-top: 12px;
            border-top: 2px solid #17324d;
            color: #17324d;
            font-size: 18px;
            font-weight: 700;
        }

        .payment-details {
            margin-top: 32px;
            padding: 16px;
            border-radius: 8px;
            background: #f3f6fa;
        }

        .draft-mark {
            margin-top: 16px;
            padding: 10px;
            border: 2px solid #b77900;
            color: #8a5a00;
            font-weight: 700;
            text-align: center;
        }

        .notice {
            max-width: 900px;
            margin: 0 auto 14px;
            padding: 12px 16px;
            border: 1px solid #ead9a2;
            border-radius: 8px;
            background: #fff8df;
            color: #715600;
        }

        @media print {
            @page {
                size: A4;
                margin: 14mm;
            }

            body {
                margin: 0;
                background: #fff;
            }

            .no-print {
                display: none !important;
            }

            .invoice-sheet {
                max-width: none;
                min-height: 0;
                margin: 0;
                padding: 0;
                box-shadow: none;
            }

            th {
                background: #f3f6fa !important;
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
        }

        .pdf-mode .no-print { display: none !important; }

        @media (max-width: 650px) {
            body {
                margin: 12px;
            }

            .invoice-sheet {
                min-height: 0;
                padding: 22px;
            }

            .invoice-header,
            .parties {
                display: block;
            }

            .invoice-meta {
                margin-top: 18px;
                text-align: left;
            }

            .totals {
                width: 100%;
            }
        }
    </style>
</head>
<body class="<?= $pdfMode ? 'pdf-mode' : '' ?>">
    <div class="actions no-print">
        <button class="button" type="button" onclick="window.print()">
            Prindi / Salvesta PDF-ina
        </button>
        <a class="button" href="invoices.php?id=<?= (int) $invoice['id'] ?>">
            Tagasi arvele
        </a>
    </div>

    <?php if (empty($company['address']) || empty($invoice['contact_address'])): ?>
        <p class="notice no-print">
            Enne arve saatmist kontrolli, et müüja ja ostja aadressid oleksid täidetud.
            Neid saad muuta Seadetes ja Kliendid ja tarnijad lehel.
        </p>
    <?php endif; ?>

    <main class="invoice-sheet">
        <?php if ($invoice['status'] === 'draft'): ?>
            <div class="draft-mark">MUSTAND — EI OLE KINNITATUD ARVE</div>
        <?php endif; ?>

        <header class="invoice-header">
            <div>
                <h1><?= $netTotal<0?'Kreeditarve':'Arve' ?></h1>
                <div class="invoice-number">
                    <?= $netTotal<0?'Kreeditarve nr':'Arve nr' ?> <?= h($invoice['invoice_number']) ?>
                </div>
            </div>

            <div class="invoice-meta">
                <div><strong>Arve kuupäev:</strong> <?= h(dateEt($invoice['invoice_date'])) ?></div>
                <?php if (!empty($invoice['supply_date'])): ?>
                    <div>
                        <strong>Teenuse/kauba kuupäev:</strong>
                        <?= h(dateEt($invoice['supply_date'])) ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($invoice['due_date'])): ?>
                    <div><strong>Maksetähtaeg:</strong> <?= h(dateEt($invoice['due_date'])) ?></div>
                <?php endif; ?>
            </div>
        </header>

        <section class="parties">
            <div>
                <h2>Müüja</h2>
                <div class="party-name"><?= h($company['name']) ?></div>
                <div class="muted">
                    <?php if (!empty($company['registry_code'])): ?>
                        Registrikood: <?= h($company['registry_code']) ?><br>
                    <?php endif; ?>
                    <?php if (!empty($company['vat_number'])): ?>
                        KMKR nr: <?= h($company['vat_number']) ?><br>
                    <?php endif; ?>
                    <?php if (!empty($company['address'])): ?>
                        <?= nl2br(h($company['address'])) ?><br>
                    <?php endif; ?>
                    <?= h(trim(($company['postal_code'] ?? '') . ' ' . ($company['city'] ?? ''))) ?><br>
                    <?= h($company['country'] ?? '') ?><br>
                    <?= !empty($company['phone']) ? 'Telefon: ' . h($company['phone']) . '<br>' : '' ?>
                    <?= !empty($company['invoice_email']) ? 'E-post: ' . h($company['invoice_email']) : '' ?>
                </div>
            </div>

            <div>
                <h2>Ostja</h2>
                <div class="party-name"><?= h($invoice['contact_name']) ?></div>
                <div class="muted">
                    <?php if (!empty($invoice['contact_registry_code'])): ?>
                        Registrikood: <?= h($invoice['contact_registry_code']) ?><br>
                    <?php endif; ?>
					<?php if (!empty($invoice['contact_vat_number'])): ?>
                       KMKR nr: <?= h($invoice['contact_vat_number']) ?><br>
                    <?php endif; ?>
                    <?php if (!empty($invoice['contact_address'])): ?>
                        <?= nl2br(h($invoice['contact_address'])) ?><br>
                    <?php endif; ?>
                    <?= !empty($invoice['contact_email']) ? 'E-post: ' . h($invoice['contact_email']) . '<br>' : '' ?>
                    <?= !empty($invoice['contact_phone']) ? 'Telefon: ' . h($invoice['contact_phone']) : '' ?>
                </div>
            </div>
        </section>

        <table>
            <thead>
                <tr>
                    <th>Kirjeldus</th>
                    <th class="number">Kogus</th>
                    <th class="number">Ühikuhind</th>
                    <th class="number">KM %</th>
                    <th class="number">Summa km-ta</th>
                    <th class="number">KM</th>
                    <th class="number"><?= $reverseInfoVat>0 ? 'Tasuda' : 'Kokku' ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $line): ?>
                    <tr>
                        <td><?= nl2br(h($line['description'])) ?></td>
                        <td class="number"><?= h((string) $line['quantity']) ?></td>
                        <td class="number">
                            <?= $foreignInvoice ? h(invoiceFxFormat($line['fx_unit_price'],$fxCurrency,true)) : number_format((float) $line['unit_price'], 4, ',', ' ').' €' ?>
                        </td>
                        <td class="number">
                            <?= $line['is_exempt']?'Maksuvaba':number_format((float) ((reverseVatSpec((string)($line['tax_code']??'')))['rate'] ?? $line['vat_rate']), 2, ',', ' ') ?>
                        </td>
                        <td class="number"><?= $foreignInvoice ? h(invoiceFxFormat($line['fx_net'],$fxCurrency)) : money(toCents((string) $line['net_amount'])) ?></td>
                        <td class="number"><?= $foreignInvoice ? h(invoiceFxFormat($line['fx_vat'],$fxCurrency)) : money(reverseVatSpec((string)($line['tax_code']??'')) ? (isset($creditReverse[$line['id']??0])?-(int)$creditReverse[$line['id']]:creditDisplayReverseVat(toCents((string)$line['net_amount']),24)) : toCents((string)$line['vat_amount'])) ?></td>
                        <td class="number"><?= $foreignInvoice ? h(invoiceFxFormat($line['fx_gross'],$fxCurrency)) : money(toCents((string) $line['gross_amount'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($reverseInfoVat>0): ?><p><strong>Pöördmaksustamine (KMS § 41¹).</strong>
    Käibemaksu arvestab ja tasub ostja. Arvestuslik käibemaks <?= money($reverseInfoVat) ?> ei kuulu müüjale tasumisele.</p><?php endif; ?>
    <?php if(!empty($invoice['zero_vat_notice'])): ?><p><strong><?= nl2br(h($invoice['zero_vat_notice'])) ?></strong></p><?php endif ?>
    <table class="totals">
            <tr>
                <td>Summa km-ta:</td>
                <td class="number"><?= $foreignInvoice ? h(invoiceFxFormat(invoiceFxSum($lines,'fx_net'),$fxCurrency)) : money($netTotal) ?></td>
            </tr>
            <?php foreach ($vatSummary as $rate => $totals): if ($reverseInfoVat>0 && (float)$rate===0.0) continue; ?>
                <tr>
                    <td><?= $rate==='exempt'?'Maksuvaba käibe KM:':'KM '.h($rate).'%'.($foreignInvoice?' eurodes':'').':' ?></td>
                    <td class="number"><?= money($totals['vat']) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="grand-total">
                <td><?= $netTotal<0?'Kreeditarve kokku:':'Tasuda kokku:' ?></td>
                <td class="number"><?= $foreignInvoice ? h(invoiceFxFormat(invoiceFxSum($lines,'fx_gross'),$fxCurrency)) : money($grossTotal) ?></td>
            </tr>
        </table>
        <?php if ($foreignInvoice): ?><p class="muted">Kurss 1 EUR = <?= h(rtrim(rtrim($invoice['fx_rate'],'0'),'.').' '.$fxCurrency) ?>
            (<?= h(dateEt($invoice['fx_rate_date'])) ?>). Arvestuslik kogusumma <?= money($grossTotal) ?>.</p><?php endif; ?>

        <?php if (!empty($invoice['notes'])): ?>
            <h2>Märkused</h2>
            <p class="muted"><?= nl2br(h($invoice['notes'])) ?></p>
        <?php endif; ?>

        <?php if (!empty($company['bank_name']) || !empty($company['bank_account'])): ?>
            <section class="payment-details">
                <strong>Makseandmed</strong><br>
                <?= !empty($company['bank_name']) ? h($company['bank_name']) . '<br>' : '' ?>
                <?= !empty($company['bank_account']) ? 'IBAN: ' . h($company['bank_account']) : '' ?>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
<?php
if ($pdfMode) {
    $html = ob_get_clean();
    require_once __DIR__ . '/vendor/autoload.php';
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
    header('Content-Disposition: inline; filename="arve-' . preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $invoice['invoice_number']) . '.pdf"');
    echo $pdfBytes;
    exit;
}
?>
