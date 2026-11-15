<?php
declare(strict_types=1);
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) { header('Location: login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/period_lock.php';
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function billberryParseIncomingLines(string $xml): array
{
    if (strlen($xml) > 5_000_000) throw new RuntimeException('E-arve XML on lubatust suurem.');
    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
    if (!$doc || $doc->getName() !== 'E_Invoice') throw new RuntimeException('Saabunud fail ei ole toetatud Eesti e-arve XML.');
    $invoiceNodes = $doc->xpath('/E_Invoice/Invoice');
    if (!$invoiceNodes || count($invoiceNodes) !== 1) throw new RuntimeException('Failis peab olema täpselt üks arve.');
    $inv = $invoiceNodes[0];
    $information = $inv->InvoiceInformation;
    $items = $inv->InvoiceItem->xpath('.//ItemEntry');
    if (!$items || count($items) < 1 || count($items) > 20) throw new RuntimeException('Ostuarve mustandi jaoks peab e-arvel olema 1 kuni 20 arverida.');
    $lines = [];
    foreach ($items as $item) {
        $description = trim((string) $item->Description);
        $qty = trim((string) $item->ItemDetailInfo[0]->ItemAmount);
        $unitPrice = trim((string) $item->ItemDetailInfo[0]->ItemPrice);
        $net = trim((string) $item->ItemSum);
        $gross = trim((string) $item->ItemTotal);
        $vatNode = isset($item->VAT[0]) ? $item->VAT[0] : null;
        $vatRate = $vatNode !== null && isset($vatNode->VATRate)
            ? trim((string) $vatNode->VATRate)
            : '0';
        $vat = $vatNode !== null && isset($vatNode->VATSum)
            ? trim((string) $vatNode->VATSum)
            : '0';
        if ($description === '') throw new RuntimeException('Arverida on ilma kirjelduseta.');
        $quantity = is_numeric($qty) && (float) $qty > 0 ? (float) $qty : 1.0;
        $netAmount = is_numeric($net) ? (float) $net : 0.0;
        $vatAmount = is_numeric($vat) ? (float) $vat : 0.0;
        $grossAmount = is_numeric($gross) ? (float) $gross : $netAmount + $vatAmount;
        if ($netAmount < 0 || $vatAmount < 0 || $grossAmount < 0 || abs($netAmount + $vatAmount - $grossAmount) > 0.011) throw new RuntimeException('E-arve rea neto-, käibemaksu- ja brutosumma ei klapi: ' . $description);
        if (!is_numeric($unitPrice) || (float) $unitPrice < 0) $unitPrice = number_format($quantity > 0 ? $netAmount / $quantity : $netAmount, 4, '.', '');
        if (!is_numeric($vatRate) || (float) $vatRate < 0 || (float) $vatRate > 100) $vatRate = '0';
        $lines[] = [
            'description' => mb_substr($description, 0, 500),
            'quantity' => number_format($quantity, 3, '.', ''),
            'unit_price' => number_format((float) $unitPrice, 4, '.', ''),
            'vat_rate' => number_format((float) $vatRate, 2, '.', ''),
            'net_amount' => number_format($netAmount, 2, '.', ''),
            'vat_amount' => number_format($vatAmount, 2, '.', ''),
            'gross_amount' => number_format($grossAmount, 2, '.', ''),
        ];
    }
    $parties = $inv->InvoiceParties;
    $seller = $parties->SellerParty;
    return [
        'number' => trim((string) $information->InvoiceNumber),
        'date' => trim((string) $information->InvoiceDate),
        'due_date' => trim((string) ($information->DueDate ?: $inv->PaymentInfo->PayDueDate)),
        'seller_name' => trim((string) $seller->Name),
        'seller_registry_code' => trim((string) $seller->RegNumber),
        'lines' => $lines,
    ];
}
$companyId = (int) $_SESSION['company_id'];
$inboxId = filter_var($_REQUEST['id'] ?? null, FILTER_VALIDATE_INT);
if (!$inboxId) { http_response_code(400); exit('Vigane saabunud arve tunnus.'); }
$stmt = $pdo->prepare('SELECT * FROM billberry_inbox WHERE id = ? AND company_id = ?');
$stmt->execute([$inboxId, $companyId]); $incoming = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$incoming) { http_response_code(404); exit('Saabunud e-arvet ei leitud.'); }
if (!empty($incoming['purchase_invoice_id'])) { header('Location: purchase_invoices.php?id=' . (int) $incoming['purchase_invoice_id']); exit; }
$errors = [];
try { $data = billberryParseIncomingLines((string) $incoming['invoice_xml']); } catch (Throwable $e) { $data = ['lines' => []]; $errors[] = $e->getMessage(); }
$number = (string) ($data['number'] ?: $incoming['invoice_number']);
$invoiceDate = (string) ($data['date'] ?: $incoming['invoice_date']);
$dueDate = (string) ($data['due_date'] ?: $incoming['due_date']);
$sellerName = (string) ($data['seller_name'] ?: $incoming['sender_name']);
$registryCode = (string) ($data['seller_registry_code'] ?: $incoming['sender_registry_code']);
$accountsStmt = $pdo->prepare("SELECT id, code, name, account_type FROM accounts WHERE company_id = ? AND is_active = 1 AND account_type IN ('expense','asset') ORDER BY code");
$accountsStmt->execute([$companyId]); $accounts = $accountsStmt->fetchAll(PDO::FETCH_ASSOC);
$accountTypes = [];
foreach ($accounts as $account) $accountTypes[(int) $account['id']] = $account['account_type'];
$productsStmt = $pdo->prepare('SELECT id, sku, name, unit FROM inventory_products WHERE company_id = ? AND is_active = 1 ORDER BY name, sku');
$productsStmt->execute([$companyId]); $products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);
$warehousesStmt = $pdo->prepare('SELECT id, code, name FROM inventory_warehouses WHERE company_id = ? AND is_active = 1 ORDER BY is_default DESC, name, code');
$warehousesStmt->execute([$companyId]); $warehouses = $warehousesStmt->fetchAll(PDO::FETCH_ASSOC);
$productIds = array_fill_keys(array_map('intval', array_column($products, 'id')), true);
$warehouseIds = array_fill_keys(array_map('intval', array_column($warehouses, 'id')), true);
$descriptionKey = static function (string $description): string {
    $normalized = preg_replace('/\\s+/u', ' ', trim($description));
    return hash('sha256', mb_strtolower($normalized === null ? trim($description) : $normalized, 'UTF-8'));
};
$supplierContactId = null;
$defaultsTableCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'purchase_import_defaults'");
$defaultsTableCheck->execute();
$defaultsReady = (int) $defaultsTableCheck->fetchColumn() === 1;
$contactLookup = $pdo->prepare("SELECT id FROM contacts WHERE company_id = ? AND registry_code = ? AND is_active = 1 AND contact_type IN ('supplier','both') LIMIT 1");
$contactLookup->execute([$companyId, $registryCode]);
$foundSupplierId = $contactLookup->fetchColumn();
if ($foundSupplierId !== false) $supplierContactId = (int) $foundSupplierId;
$savedDefaults = [];
if ($defaultsReady && $supplierContactId !== null) {
    $defaultsStmt = $pdo->prepare('SELECT description_key, account_id, product_id, warehouse_id FROM purchase_import_defaults WHERE company_id = ? AND contact_id = ?');
    $defaultsStmt->execute([$companyId, $supplierContactId]);
    foreach ($defaultsStmt->fetchAll(PDO::FETCH_ASSOC) as $savedDefault) $savedDefaults[(string) $savedDefault['description_key']] = $savedDefault;
}
$selectedAccounts = [];
$selectedProducts = [];
$selectedWarehouses = [];
$rememberDefaults = [];
foreach ($data['lines'] as $i => $line) {
    $default = $savedDefaults[$descriptionKey((string) $line['description'])] ?? [];
    $selectedAccounts[$i] = (string) ($default['account_id'] ?? '');
    $selectedProducts[$i] = (string) ($default['product_id'] ?? '');
    $selectedWarehouses[$i] = (string) ($default['warehouse_id'] ?? '');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
    if (empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) $errors[] = 'Vorm aegus. Värskenda lehte.';
    if ($number === '' || mb_strlen($number) > 50) $errors[] = 'Tarnija arve number puudub või on üle 50 märgi.';
    $dateObj = DateTimeImmutable::createFromFormat('!Y-m-d', $invoiceDate);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $invoiceDate) $errors[] = 'E-arvel pole sobivat arve kuupäeva.';
    if ($invoiceDate !== '' && ($lock = closedAccountingPeriodMessage($pdo, $companyId, $invoiceDate))) $errors[] = $lock;
    if (!preg_match('/^[0-9]{5,20}$/', $registryCode)) $errors[] = 'Tarnija registrikood puudub või pole Eesti registrikoodi vormingus.';
    $selectedAccounts = is_array($_POST['account_id'] ?? null) ? $_POST['account_id'] : [];
    $selectedProducts = is_array($_POST['product_id'] ?? null) ? $_POST['product_id'] : [];
    $selectedWarehouses = is_array($_POST['warehouse_id'] ?? null) ? $_POST['warehouse_id'] : [];
    $rememberDefaults = is_array($_POST['remember_default'] ?? null) ? $_POST['remember_default'] : [];
    if (count($selectedAccounts) !== count($data['lines'])) $errors[] = 'Vali igale arverea kulukonto või varukonto.';
    foreach ($data['lines'] as $i => $_line) {
        $accountId = (int) ($selectedAccounts[$i] ?? 0);
        $productId = (int) ($selectedProducts[$i] ?? 0);
        $warehouseId = (int) ($selectedWarehouses[$i] ?? 0);
        if (!isset($accountTypes[$accountId])) { $errors[] = 'Valitud konto ei kuulu ettevõtte aktiivsesse kontoplaani.'; break; }
        if ($productId > 0 && !isset($productIds[$productId])) { $errors[] = 'Valitud laotoode ei kuulu sellele ettevõttele.'; break; }
        if ($warehouseId > 0 && !isset($warehouseIds[$warehouseId])) { $errors[] = 'Valitud ladu ei kuulu sellele ettevõttele.'; break; }
        if (($productId > 0) !== ($warehouseId > 0)) { $errors[] = 'Laokaubale vali nii toode kui ka ladu; teenuse või muu kulu korral jäta mõlemad valimata.'; break; }
        if ($productId > 0 && $accountTypes[$accountId] !== 'asset') { $errors[] = 'Laotoote konto peab olema varakonto.'; break; }
    }
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $contactStmt = $pdo->prepare('SELECT id, contact_type, is_active FROM contacts WHERE company_id = ? AND registry_code = ? LIMIT 1 FOR UPDATE');
            $contactStmt->execute([$companyId, $registryCode]); $contact = $contactStmt->fetch(PDO::FETCH_ASSOC);
            if ($contact) {
                if ((int) $contact['is_active'] !== 1 || !in_array($contact['contact_type'], ['supplier','both'], true)) throw new RuntimeException('Selle registrikoodiga kontakt on olemas, kuid pole aktiivne tarnija. Muuda kontaktide lehel kontakti.');
                $contactId = (int) $contact['id'];
            } else {
                $insertContact = $pdo->prepare("INSERT INTO contacts (company_id, contact_type, name, registry_code, is_active) VALUES (?, 'supplier', ?, ?, 1)");
                $insertContact->execute([$companyId, $sellerName !== '' ? $sellerName : $registryCode, $registryCode]);
                $contactId = (int) $pdo->lastInsertId();
            }
            $supplierContactId = $contactId;
            $insertInvoice = $pdo->prepare("INSERT INTO purchase_invoices (company_id, contact_id, supplier_invoice_number, invoice_date, due_date, status, notes, created_by, journal_entry_id) VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, NULL)");
            $notes = 'Billberry e-arve ID ' . (int) $incoming['billberry_invoice_id'];
            $insertInvoice->execute([$companyId, $contactId, $number, $invoiceDate, preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dueDate) ? $dueDate : null, $notes, (int) $_SESSION['user_id']]);
            $purchaseInvoiceId = (int) $pdo->lastInsertId();
            $insertLine = $pdo->prepare('INSERT INTO purchase_invoice_lines (invoice_id, account_id, product_id, warehouse_id, description, quantity, unit_price, vat_rate, net_amount, vat_amount, gross_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $saveDefault = $pdo->prepare('INSERT INTO purchase_import_defaults (company_id, contact_id, description_key, description, account_id, product_id, warehouse_id) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE description = VALUES(description), account_id = VALUES(account_id), product_id = VALUES(product_id), warehouse_id = VALUES(warehouse_id), updated_at = CURRENT_TIMESTAMP');
            foreach ($data['lines'] as $i => $line) {
                $productId = (int) ($selectedProducts[$i] ?? 0) ?: null;
                $warehouseId = (int) ($selectedWarehouses[$i] ?? 0) ?: null;
                $accountId = (int) $selectedAccounts[$i];
                $insertLine->execute([$purchaseInvoiceId, $accountId, $productId, $warehouseId, $line['description'], $line['quantity'], $line['unit_price'], $line['vat_rate'], $line['net_amount'], $line['vat_amount'], $line['gross_amount']]);
                if ($supplierContactId !== null && ($rememberDefaults[$i] ?? '') === '1') {
                    $saveDefault->execute([$companyId, $supplierContactId, $descriptionKey((string) $line['description']), $line['description'], $accountId, $productId, $warehouseId]);
                }
            }
            $linkStmt = $pdo->prepare('UPDATE billberry_inbox SET purchase_invoice_id = ? WHERE id = ? AND company_id = ? AND purchase_invoice_id IS NULL');
            $linkStmt->execute([$purchaseInvoiceId, $inboxId, $companyId]);
            if ($linkStmt->rowCount() !== 1) throw new RuntimeException('Seda saabunud arvet on juba kasutatud ostuarve loomiseks.');
            $pdo->commit();
            header('Location: purchase_invoices.php?id=' . $purchaseInvoiceId); exit;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $errors[] = $e instanceof PDOException ? 'Ostuarve mustandi loomine ebaõnnestus. Kontrolli, ega sama tarnija arvenumber pole juba olemas.' : $e->getMessage(); }
    }
}
?>
<!doctype html><html lang="et"><head>
    <link rel="icon" type="image/png" href="favicon.png?v=1"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Impordi saabunud e-arve</title><style>
*{box-sizing:border-box}body{margin:0;padding:30px 18px;background:#f4f7fb;color:#243447;font-family:Arial,sans-serif}main{max-width:1050px;margin:auto}h1,h2{color:#17324d}.card{margin:16px 0;padding:22px;background:#fff;border:1px solid #dce3ec;border-radius:12px}.grid{display:grid;grid-template-columns:repeat(3,minmax(150px,1fr));gap:14px}label{display:block;font-weight:600}input,select{width:100%;margin-top:6px;padding:10px;border:1px solid #bdc9d6;border-radius:7px;font:inherit}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px;border-bottom:1px solid #dce3ec}th{background:#eef3f8}.button{display:inline-block;margin:10px 8px 0 0;padding:11px 16px;border:0;border-radius:7px;background:#1769aa;color:#fff;font-weight:700;text-decoration:none;cursor:pointer}.secondary{background:#647589}.error{padding:12px;background:#fff0f0;color:#a12626;border:1px solid #efb4b4;border-radius:8px}.note{padding:13px;background:#fff8df;border:1px solid #ead9a2;border-radius:8px;line-height:1.5}@media(max-width:700px){.grid{grid-template-columns:1fr}}</style></head><body><main>
<h1>Saabunud e-arve mustandiks</h1><?php foreach ($errors as $error): ?><p class="error"><?= h($error) ?></p><?php endforeach; ?>
<?php if (!$defaultsReady): ?><p class="error">Enne importimist lisa andmebaasi vaikimisi valikute tabel. Ava phpMyAdminis andmebaas „raamatupidamine” → Import ja vali <a href="billberry_purchase_defaults.sql">billberry_purchase_defaults.sql</a>.</p><?php endif; ?>
<?php if (!$errors && $defaultsReady && $data['lines']): ?><div class="note">Kontrolli tarnija, kuupäev ja read üle. Vali igale reale konto. Kui sama tarnija sama kirjeldusega arverida kordub, saad valiku järgmiseks korraks vaikimisi meelde jätta. Laokauba puhul vali konto, toode ja ladu. Programm loob mustandi; pearaamatu kanne ja laoliikumine tekivad alles ostuarve kinnitamisel.</div>
<section class="card"><div class="grid"><label>Tarnija<input readonly value="<?= h($sellerName) ?>"></label><label>Registrikood<input readonly value="<?= h($registryCode) ?>"></label><label>Arve nr<input readonly value="<?= h($number) ?>"></label><label>Kuupäev<input readonly value="<?= h($invoiceDate) ?>"></label><label>Tähtaeg<input readonly value="<?= h($dueDate) ?>"></label></div></section>
<form method="post"><input type="hidden" name="id" value="<?= (int) $inboxId ?>"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><section class="card"><div style="overflow-x:auto"><table><thead><tr><th>Kirjeldus</th><th>Kogus</th><th>Hind</th><th>KM %</th><th>Neto</th><th>KM</th><th>Kokku</th><th>Konto</th><th>Laotoode</th><th>Ladu</th><th>Vaikimisi</th></tr></thead><tbody><?php foreach ($data['lines'] as $i => $line): ?><tr><td><?= h($line['description']) ?></td><td><?= h($line['quantity']) ?></td><td><?= h($line['unit_price']) ?></td><td><?= h($line['vat_rate']) ?></td><td><?= h($line['net_amount']) ?> €</td><td><?= h($line['vat_amount']) ?> €</td><td><?= h($line['gross_amount']) ?> €</td><td><select name="account_id[]" required><option value="">Vali konto</option><?php foreach ($accounts as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (string) ($selectedAccounts[$i] ?? '') === (string) $account['id'] ? 'selected' : '' ?>><?= h($account['code'] . ' — ' . $account['name']) ?></option><?php endforeach; ?></select></td><td><select name="product_id[]"><option value="">Teenus / kulu</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>" <?= (string) ($selectedProducts[$i] ?? '') === (string) $product['id'] ? 'selected' : '' ?>><?= h($product['sku'] . ' — ' . $product['name']) ?></option><?php endforeach; ?></select></td><td><select name="warehouse_id[]"><option value="">Vali ladu</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= (int) $warehouse['id'] ?>" <?= (string) ($selectedWarehouses[$i] ?? '') === (string) $warehouse['id'] ? 'selected' : '' ?>><?= h($warehouse['code'] . ' — ' . $warehouse['name']) ?></option><?php endforeach; ?></select></td><td><label style="font-weight:normal"><input type="checkbox" name="remember_default[<?= (int) $i ?>]" value="1" <?= ($rememberDefaults[$i] ?? '') === '1' ? 'checked' : '' ?>> Jäta meelde</label></td></tr><?php endforeach; ?></tbody></table></div><button class="button" type="submit">Loo ostuarve mustand</button><a class="button secondary" href="billberry_inbox.php">Katkesta</a></section></form><?php endif; ?>
</main></body></html>
