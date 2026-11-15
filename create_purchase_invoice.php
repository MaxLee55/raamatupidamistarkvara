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
require_once __DIR__ . '/company_vat_status.php';
require_once __DIR__ . '/tax_code_lib.php';
require_once __DIR__ . '/period_lock.php';
require_once __DIR__ . '/business_registry.php';

$companyId = (int) $_SESSION['company_id'];
$productsStmt = $pdo->prepare('SELECT id, sku, name FROM inventory_products WHERE company_id = ? AND is_active = 1 ORDER BY name, sku');
$productsStmt->execute([$companyId]);
$products = $productsStmt->fetchAll(PDO::FETCH_ASSOC);
$warehousesStmt = $pdo->prepare('SELECT id, code, name FROM inventory_warehouses WHERE company_id = ? AND is_active = 1 ORDER BY is_default DESC, name, code');
$warehousesStmt->execute([$companyId]);
$warehouses = $warehousesStmt->fetchAll(PDO::FETCH_ASSOC);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['stock_options'] ?? '') === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(['products'=>$products,'warehouses'=>$warehouses], JSON_THROW_ON_ERROR);
    exit;
}

ensureTaxCodeSchema($pdo);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function purchaseTaxCodeOptionLabel(array $taxCode): string
{
    if ($taxCode['tax_category'] === 'reverse_charge') return (string) $taxCode['label'];
    $rate = rtrim(rtrim(number_format((float) $taxCode['rate'], 2, '.', ''), '0'), '.');
    $description = preg_replace('/\s*[—–-]?\s*\d+(?:[,.]\d+)?\s*%/u', '', (string) $taxCode['label']);
    $description = preg_replace('/^[\s—–-]+|[\s—–-]+$/u', '', (string) $description);
    return $rate . '% - ' . $description;
}

function validDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function toScaled(string $value, int $scale, int $maxIntegerDigits): ?int
{
    $pattern = '/^\d{1,' . $maxIntegerDigits . '}(?:\.\d{1,' . $scale . '})?$/';

    if (!preg_match($pattern, $value)) {
        return null;
    }

    $multipliers = [2 => 100, 3 => 1000, 4 => 10000];
    $multiplier = $multipliers[$scale];

    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $fraction = str_pad(substr($fraction, 0, $scale), $scale, '0');

    return ((int) $whole * $multiplier) + (int) $fraction;
}

function centsToDecimal(int $cents): string
{
    return intdiv($cents, 100) . '.' .
        str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

$withoutVat = companyIsNonVatRegistered($pdo, $companyId);
$taxCodes = $withoutVat ? [] : getTaxCodes($pdo, $companyId, 'purchase');
$defaultTaxCodeId = 0;
foreach ($taxCodes as $taxCode) {
    if ($taxCode['code'] === 'O_EE24') { $defaultTaxCodeId = (int) $taxCode['id']; break; }
}
$errors = [];
$form = $_POST;
$formLines = [];

$contactsStmt = $pdo->prepare(
    "SELECT id, name, registry_code
     FROM contacts
     WHERE company_id = :company_id
       AND is_active = 1
       AND contact_type IN ('supplier', 'both')
     ORDER BY name"
);
$contactsStmt->execute(['company_id' => $companyId]);
$suppliers = $contactsStmt->fetchAll();
$selectedSupplierId = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string) ($_POST['contact_id'] ?? '')
    : '';
$registryName = trim((string) ($_POST['registry_name'] ?? ''));
$registryCode = trim((string) ($_POST['registry_code'] ?? ''));
$registryAddress = trim((string) ($_POST['registry_address'] ?? ''));
$selectedSupplierLabel = trim((string) ($_POST['supplier_search'] ?? ''));
$newRegistrySupplier = null;

if ($selectedSupplierLabel === '' && $selectedSupplierId !== '') {
    foreach ($suppliers as $supplier) {
        if ((string) $supplier['id'] === $selectedSupplierId) {
            $selectedSupplierLabel = $supplier['name'] . ($supplier['registry_code'] ? ' — ' . $supplier['registry_code'] : '');
            break;
        }
    }
}

$accountsStmt = $pdo->prepare(
    "SELECT id, code, name, account_type
     FROM accounts
     WHERE company_id = :company_id
       AND is_active = 1
       AND account_type IN ('expense', 'asset')
     ORDER BY code"
);
$accountsStmt->execute(['company_id' => $companyId]);
$accounts = $accountsStmt->fetchAll();

$allowedAccountIds = [];
$accountTypes = [];
foreach ($accounts as $account) {
    $allowedAccountIds[(int) $account['id']] = true;
    $accountTypes[(int) $account['id']] = $account['account_type'];
}
$allowedProductIds = array_fill_keys(array_map('intval', array_column($products, 'id')), true);
$allowedWarehouseIds = array_fill_keys(array_map('intval', array_column($warehouses, 'id')), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
    ) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    }

    $contactId = filter_var($_POST['contact_id'] ?? null, FILTER_VALIDATE_INT);
    $invoiceNumber = trim($_POST['supplier_invoice_number'] ?? '');
    $invoiceDate = trim($_POST['invoice_date'] ?? '');
    $dueDate = trim($_POST['due_date'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if (!$contactId && $registryCode !== '' && $registryName !== '') {
        $registryMatches = fetchBusinessRegistryCompanies($registryCode);
        $verifiedCompany = null;
        foreach ($registryMatches as $registryCompany) {
            if ($registryCompany['registry_code'] === $registryCode) {
                $verifiedCompany = $registryCompany;
                break;
            }
        }

        if (!$verifiedCompany) {
            $errors[] = 'Äriregistri ettevõtte kontroll ebaõnnestus. Proovi uuesti või lisa tarnija kontaktide lehel.';
        } else {
            $existingContactStmt = $pdo->prepare(
                'SELECT id, contact_type, is_active
                 FROM contacts
                 WHERE company_id = :company_id AND registry_code = :registry_code
                 LIMIT 1'
            );
            $existingContactStmt->execute([
                'company_id' => $companyId,
                'registry_code' => $registryCode,
            ]);
            $existingContact = $existingContactStmt->fetch(PDO::FETCH_ASSOC);

            if ($existingContact) {
                if ((int) $existingContact['is_active'] === 1
                    && in_array($existingContact['contact_type'], ['supplier', 'both'], true)) {
                    $contactId = (int) $existingContact['id'];
                } else {
                    $errors[] = 'Selle registrikoodiga kontakt on juba olemas, kuid pole aktiivne tarnija. Muuda kontaktide lehel selle tüübiks „Klient ja tarnija” või aktiveeri see.';
                }
            } else {
                $newRegistrySupplier = $verifiedCompany;
            }
        }
    }

    if (!$contactId && !$newRegistrySupplier) {
        $errors[] = 'Vali tarnija või otsi ettevõte Äriregistrist.';
    } elseif ($contactId) {
        $checkContact = $pdo->prepare(
            "SELECT COUNT(*)
             FROM contacts
             WHERE id = :contact_id
               AND company_id = :company_id
               AND is_active = 1
               AND contact_type IN ('supplier', 'both')"
        );
        $checkContact->execute([
            'contact_id' => $contactId,
            'company_id' => $companyId,
        ]);

        if ((int) $checkContact->fetchColumn() !== 1) {
            $errors[] = 'Valitud tarnijat ei leitud.';
        }
    }

    if ($invoiceNumber === '' || mb_strlen($invoiceNumber) > 50) {
        $errors[] = 'Tarnija arve number on kohustuslik ja võib olla kuni 50 märki.';
    }

    if (!validDate($invoiceDate)) {
        $errors[] = 'Sisesta kehtiv arve kuupäev.';
    } elseif ($lockMessage = closedAccountingPeriodMessage($pdo, $companyId, $invoiceDate)) {
        $errors[] = $lockMessage;
    }
    if ($dueDate !== '' && !validDate($dueDate)) {
        $errors[] = 'Sisesta kehtiv maksetähtaeg.';
    }

    if (mb_strlen($notes) > 5000) {
        $errors[] = 'Märkused võivad olla kuni 5000 märki.';
    }

    $accountsInput = is_array($_POST['account_id'] ?? null) ? $_POST['account_id'] : [];
    $descriptions = is_array($_POST['line_description'] ?? null) ? $_POST['line_description'] : [];
    $quantities = is_array($_POST['quantity'] ?? null) ? $_POST['quantity'] : [];
    $unitPrices = is_array($_POST['unit_price'] ?? null) ? $_POST['unit_price'] : [];
    $vatRates = is_array($_POST['vat_rate'] ?? null) ? $_POST['vat_rate'] : [];
    $taxCodeIds = is_array($_POST['tax_code_id'] ?? null) ? $_POST['tax_code_id'] : [];
    $productInputs = is_array($_POST['product_id'] ?? null) ? $_POST['product_id'] : [];
    $warehouseInputs = is_array($_POST['warehouse_id'] ?? null) ? $_POST['warehouse_id'] : [];

    $lineCount = max(
        count($accountsInput),
        count($descriptions),
        count($quantities),
        count($unitPrices),
        count($vatRates),
        count($taxCodeIds),
        count($productInputs),
        count($warehouseInputs)
    );

    if ($lineCount > 20) {
        $errors[] = 'Ostuarvel võib olla kuni 20 rida.';
    }

    $cleanLines = [];
    $hasEnteredLine = false;
    $netTotal = 0;
    $vatTotal = 0;

    for ($i = 0; $i < min($lineCount, 20); $i++) {
        $accountValue = trim((string) ($accountsInput[$i] ?? ''));
        $lineDescription = trim((string) ($descriptions[$i] ?? ''));
        $quantityText = trim((string) ($quantities[$i] ?? ''));
        $unitPriceText = trim((string) ($unitPrices[$i] ?? ''));
        $vatRateText = trim((string) ($vatRates[$i] ?? ''));
        $taxCodeIdText = trim((string) ($taxCodeIds[$i] ?? ''));
        $productValue = trim((string) ($productInputs[$i] ?? ''));
        $warehouseValue = trim((string) ($warehouseInputs[$i] ?? ''));

        $formLines[] = [
            'account_id' => $accountValue,
            'description' => $lineDescription,
            'quantity' => $quantityText,
            'unit_price' => $unitPriceText,
            'vat_rate' => $vatRateText,
            'tax_code_id' => $taxCodeIdText,
            'product_id' => $productValue,
            'warehouse_id' => $warehouseValue,
        ];

        if (
            $accountValue === '' &&
            $lineDescription === '' &&
            $quantityText === '' &&
            $unitPriceText === '' &&
            $vatRateText === '' &&
            $productValue === '' &&
            $warehouseValue === ''
        ) {
            continue;
        }
        $hasEnteredLine = true;
        if (($productValue === '') !== ($warehouseValue === '')) {
            $missing = $productValue === '' ? 'laotoode' : 'ladu';
            $errors[] = 'Rida ' . ($i + 1) . ': vali samale reale ka ' . $missing . '.';
            continue;
        }

        $accountId = filter_var($accountValue, FILTER_VALIDATE_INT);
        $quantity = toScaled($quantityText, 3, 5);
        $unitPrice = toScaled($unitPriceText, 4, 6);
        $taxCodeId = filter_var($taxCodeIdText, FILTER_VALIDATE_INT);
        $taxCode = $withoutVat
            ? ['id' => null, 'code' => '', 'rate' => 0]
            : ($taxCodeId ? getTaxCodeById($pdo, $companyId, 'purchase', (int) $taxCodeId, $invoiceDate) : null);
        if (!$taxCode) {
            $errors[] = 'Vali ostuarve kuupäeval kehtiv käibemaksukood.';
            continue;
        }
        if ($reverseSpec = reverseVatSpec((string) $taxCode['code'])) {
            $supplierStmt = $pdo->prepare('SELECT country_code, vat_number FROM contacts WHERE id = ? AND company_id = ?');
            $supplierStmt->execute([(int) $contactId, $companyId]);
            $supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC);
            $supplierError = reversePartyError($reverseSpec, (string) ($supplier['country_code'] ?? ''), (string) ($supplier['vat_number'] ?? ''));
            $companyError=reverseCompanyError($pdo,$companyId,$reverseSpec);
            if ($companyError!==null) { $errors[]=$companyError; continue; }
            if ($supplierError !== null) { $errors[] = $supplierError; continue; }
        }
        $vatRateText = number_format((float) $taxCode['rate'], 2, '.', '');
        $vatRate = toScaled($vatRateText, 2, 3);

        if (!$accountId || !isset($allowedAccountIds[$accountId])) {
            $errors[] = 'Vali igale kasutatud reale oma ettevõtte aktiivne kulu- või varakonto.';
            continue;
        }

        $productId = $productValue === '' ? null : filter_var($productValue, FILTER_VALIDATE_INT);
        $warehouseId = $warehouseValue === '' ? null : filter_var($warehouseValue, FILTER_VALIDATE_INT);
        if ($productValue !== '') {
            if (!$productId || !isset($allowedProductIds[$productId]) || !$warehouseId || !isset($allowedWarehouseIds[$warehouseId])) {
                $errors[] = 'Vali reale oma ettevõtte aktiivne laotoode ja ladu.';
                continue;
            }
            if (($accountTypes[$accountId] ?? '') !== 'asset') {
                $errors[] = 'Laotoote arverea kontoks vali varakonto, millele kauba maksumus ostuarve kinnitamisel kirjendatakse.';
                continue;
            }
        }

        if ($lineDescription === '' || mb_strlen($lineDescription) > 500) {
            $errors[] = 'Iga kasutatud rida vajab kirjeldust (kuni 500 märki).';
            continue;
        }

        if ($quantity === null || $quantity <= 0) {
            $errors[] = 'Kogus peab olema positiivne; kasuta kuni 3 komakohta.';
            continue;
        }

        if ($unitPrice === null) {
            $errors[] = 'Ühikuhind peab olema null või suurem; kasuta kuni 4 komakohta.';
            continue;
        }

        if ($vatRate === null || $vatRate > 10000) {
            $errors[] = 'Käibemaksumäär peab olema 0 kuni 100.00.';
            continue;
        }

        $lineNet = intdiv(($quantity * $unitPrice) + 50000, 100000);
        $lineVat = intdiv(($lineNet * $vatRate) + 5000, 10000);
        $lineGross = $lineNet + $lineVat;

        $netTotal += $lineNet;
        $vatTotal += $lineVat;

        $cleanLines[] = [
            'account_id' => $accountId,
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'description' => $lineDescription,
            'quantity' => $quantityText,
            'unit_price' => $unitPriceText,
            'vat_rate' => $vatRateText,
            'tax_code_id' => $taxCode['id'] === null ? null : (int) $taxCode['id'],
            'tax_code' => (string) $taxCode['code'],
            'net_amount' => centsToDecimal($lineNet),
            'vat_amount' => centsToDecimal($lineVat),
            'gross_amount' => centsToDecimal($lineGross),
        ];
    }

    if (!$hasEnteredLine) {
        $errors[] = 'Lisa ostuarvele vähemalt üks rida.';
    }

    $importRows=count(array_filter($cleanLines,static fn(array $line):bool=>($line['tax_code']??'')==='O_IMPORT0'));
    if($importRows && $importRows!==count($cleanLines)) $errors[]='Impordi kaubaarve sisesta eraldi, ainult O_IMPORT0 ridadega. Tollimaks ja maakleritasud sisesta eraldi arvena.';
    if ($vatTotal !== 0 && $contactId) {
        $supplierStmt = $pdo->prepare('SELECT country_code, vat_number, estonia_vat_number FROM contacts WHERE id = ? AND company_id = ?');
        $supplierStmt->execute([$contactId, $companyId]);
        $taxPartner = $supplierStmt->fetch(PDO::FETCH_ASSOC);
        if ($taxPartner && ($issue = foreignPurchaseSupplierVatError($taxPartner, $vatTotal))) $errors[] = $issue;
    }

    $fx = null;
    if (!$errors) {
        try {
            $fx = invoiceFxPrepare($_POST,$invoiceDate,null,!$withoutVat,$cleanLines);
            if ($fx && !$withoutVat && array_filter($cleanLines,static fn(array $line): bool => str_starts_with($line['tax_code'],'O_EE'))
                && companyUsesCashVat(companyCashVatPeriod($pdo,$companyId),$fx[3])) {
                throw new InvalidArgumentException('Kassapõhise käibemaksuga välisvaluutaarve vajab eraldi arvestust.');
            }
        } catch (RuntimeException|InvalidArgumentException $error) {
            $errors[] = $error->getMessage();
        }
    }
    if (!$errors) {
        try {
            if ($newRegistrySupplier) {
                $newRegistrySupplier['vat_number'] = fetchBusinessRegistryVatNumber($newRegistrySupplier['registry_code']);
            }
            $pdo->beginTransaction();

            if ($newRegistrySupplier) {
                $newContactStmt = $pdo->prepare(
                    'INSERT INTO contacts
                        (company_id, contact_type, name, registry_code, vat_number,
                         email, phone, address, is_active)
                     VALUES
                        (:company_id, \'supplier\', :name, :registry_code, :vat_number,
                         NULL, NULL, :address, 1)'
                );
                $newContactStmt->execute([
                    'company_id' => $companyId,
                    'name' => $newRegistrySupplier['name'],
                    'registry_code' => $newRegistrySupplier['registry_code'],
                    'vat_number' => $newRegistrySupplier['vat_number'],
                    'address' => $newRegistrySupplier['address'] !== '' ? $newRegistrySupplier['address'] : null,
                ]);
                $contactId = (int) $pdo->lastInsertId();
            }

            $invoiceStmt = $pdo->prepare(
                "INSERT INTO purchase_invoices
                    (company_id, contact_id, supplier_invoice_number,
                     invoice_date, due_date, status, notes, created_by,
                     journal_entry_id)
                 VALUES
                    (:company_id, :contact_id, :invoice_number,
                     :invoice_date, :due_date, 'draft', :notes, :created_by,
                     NULL)"
            );
            $invoiceStmt->execute([
                'company_id' => $companyId,
                'contact_id' => $contactId,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate !== '' ? $dueDate : null,
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => (int) $_SESSION['user_id'],
            ]);

            $purchaseInvoiceId = (int) $pdo->lastInsertId();
            if ($fx) invoiceFxSave($pdo,'purchase',$purchaseInvoiceId,$fx);

            $lineStmt = $pdo->prepare(
                'INSERT INTO purchase_invoice_lines
                    (invoice_id, account_id, product_id, warehouse_id, description, quantity,
                     unit_price, vat_rate, tax_code_id, net_amount, vat_amount, gross_amount)
                 VALUES
                    (:invoice_id, :account_id, :product_id, :warehouse_id, :description, :quantity,
                     :unit_price, :vat_rate, :tax_code_id, :net_amount, :vat_amount, :gross_amount)'
            );

            foreach ($cleanLines as $line) {
                $lineStmt->execute([
                    'invoice_id' => $purchaseInvoiceId,
                    'account_id' => $line['account_id'],
                    'product_id' => $line['product_id'],
                    'warehouse_id' => $line['warehouse_id'],
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'vat_rate' => $line['vat_rate'],
                    'tax_code_id' => $line['tax_code_id'],
                    'net_amount' => $line['net_amount'],
                    'vat_amount' => $line['vat_amount'],
                    'gross_amount' => $line['gross_amount'],
                ]);
                if ($fx) invoiceFxSaveLine($pdo,'purchase',(int)$pdo->lastInsertId(),$line['fx_original']);
            }

            $pdo->commit();

            header('Location: create_purchase_invoice.php?created=' . $purchaseInvoiceId);
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e->getCode() === '23000') {
                $errors[] = 'Sellel tarnijal on juba sama numbriga arve.';
            } else {
                error_log($e->getMessage());
                $errors[] = 'Ostuarve salvestamine ebaõnnestus.';
            }
        }
    }
}

while (count($formLines) < 4) {
    $formLines[] = [
        'account_id' => '',
        'description' => '',
        'quantity' => '',
        'unit_price' => '',
        'vat_rate' => '',
        'tax_code_id' => '',
        'product_id' => '',
        'warehouse_id' => '',
    ];
}

$createdId = filter_input(INPUT_GET, 'created', FILTER_VALIDATE_INT);
$createdInvoice = null;

if ($createdId) {
    $createdStmt = $pdo->prepare(
        'SELECT id, supplier_invoice_number
         FROM purchase_invoices
         WHERE id = :invoice_id
           AND company_id = :company_id'
    );
    $createdStmt->execute([
        'invoice_id' => $createdId,
        'company_id' => $companyId,
    ]);
    $createdInvoice = $createdStmt->fetch();
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Uus ostuarve</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 32px; }
        label { display: block; margin: 12px 0; }
        input, select, textarea { padding: 7px; }
        table { border-collapse: collapse; margin: 16px 0; }
        th, td { padding: 8px; border: 1px solid #ccc; text-align: left; }
        .error { color: #b00020; background: #fdecec; border: 1px solid #e5a1a1; padding: 10px 12px; border-radius: 5px; }
        .success { color: #176b32; background: #eaf7ed; padding: 10px 12px; }
		
		<style>
    body {
        max-width: 1150px;
        margin: 36px auto;
        padding: 0 24px;
        background: #f4f7fb;
        color: #243447;
        font-family: Arial, sans-serif;
    }

    h1, h2 { color: #17324d; }

    .purchase-form {
        margin-top: 20px;
        padding: 24px;
        background: white;
        border: 1px solid #dce3ec;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(23, 50, 77, .04);
    }

    .purchase-form > label {
        display: grid;
        gap: 7px;
        width: min(100%, 420px);
        margin: 0 0 18px;
        color: #33475b;
        font-weight: 600;
    }

    .purchase-form input,
    .purchase-form select,
    .purchase-form textarea {
        box-sizing: border-box;
        width: 100%;
        min-height: 42px;
        padding: 10px 12px;
        border: 1px solid #b8c4d1;
        border-radius: 7px;
        background: white;
        color: #243447;
        font: inherit;
    }

    .purchase-form textarea {
        min-height: 85px;
        resize: vertical;
    }

    .purchase-form table {
        width: 100%;
        table-layout: fixed;
        margin: 14px 0 18px;
        border-collapse: collapse;
        background: white;
    }

    .purchase-form th,
    .purchase-form td {
        padding: 9px;
        border: 1px solid #dce3ec;
        text-align: left;
    }

    .purchase-form th {
        background: #eaf0f6;
        color: #17324d;
    }

    .purchase-form td input,
    .purchase-form td select {
        min-width: 115px;
    }

    .purchase-form td select {
        min-width: 0;
    }

    .purchase-form td:nth-child(5) input {
        min-width: 0;
    }

    .supplier-autocomplete { position: relative; }

    .supplier-suggestions:not([hidden]) {
        position: absolute;
        z-index: 20;
        top: calc(100% - 17px);
        left: 0;
        width: min(100%, 420px);
        max-height: 300px;
        overflow-y: auto;
        border: 1px solid #cbd5df;
        border-radius: 7px;
        background: #fff;
        box-shadow: 0 8px 20px rgba(23, 50, 77, .16);
    }

    .purchase-form .supplier-suggestion {
        display: block;
        width: 100%;
        min-height: 0;
        padding: 10px 12px;
        border: 0;
        border-bottom: 1px solid #e7edf3;
        border-radius: 0;
        background: #fff;
        color: #243447;
        text-align: left;
        font-weight: 400;
    }

    .purchase-form .supplier-suggestion:hover,
    .purchase-form .supplier-suggestion:focus {
        background: #eef5fb;
        color: #17324d;
    }

    .supplier-suggestion-name { display: block; font-weight: 600; }
    .supplier-suggestion-meta { display: block; margin-top: 3px; color: #66788a; font-size: .88em; }
    .supplier-selection-note { display: block; min-height: 1.2em; color: #52677b; font-weight: 400; }

    .purchase-form td:nth-child(2) input {
        min-width: 190px;
    }

    .purchase-form button,
    .button {
        display: inline-block;
        min-height: 42px;
        padding: 10px 17px;
        border: 0;
        border-radius: 7px;
        background: #1769aa;
        color: white;
        font: inherit;
        font-weight: 600;
        text-decoration: none;
        cursor: pointer;
    }

    .purchase-form button:hover,
    .button:hover {
        background: #0f4f83;
    }

    .purchase-form #add-line {
        margin-bottom: 14px;
        background: #eaf0f6;
        color: #17324d;
        border: 1px solid #cbd5df;
    }

    .purchase-vat-guide { display: block; width: fit-content; margin-bottom: 3px; color: #1769aa; font-size: 0.8rem; font-weight: normal; }

    .purchase-form #add-line:hover {
        background: #dce8f2;
    }

    .error {
        color: #b00020;
        background: #fdecec;
        border: 1px solid #e5a1a1;
        padding: 10px 12px;
        border-radius: 5px;
    }

    .success {
        color: #176b32;
        background: #eaf7ed;
        border: 1px solid #cfe4d4;
        padding: 12px 14px;
        border-radius: 7px;
    }

    @media (max-width: 700px) {
        body { margin: 22px auto; padding: 0 14px; }
        .purchase-form { padding: 16px; }
        .purchase-form table { display: block; overflow-x: auto; }
    }
</style>
    </style>
</head>
<body>
    <h1>Uus ostuarve</h1>

    <?php if ($createdInvoice): ?>
        <p class="success">
            Ostuarve <?= h($createdInvoice['supplier_invoice_number']) ?> salvestati mustandina.
        </p>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endforeach; ?>

    <?php if (!$accounts): ?>
        <p>Lisa kõigepealt aktiivne kulu- või varakonto kontoplaani lehel.</p>
    <?php else: ?>
        <form method="post" class="purchase-form">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="contact_id" id="supplier-contact-id" value="<?= h($selectedSupplierId) ?>">
            <input type="hidden" name="registry_name" id="supplier-registry-name" value="<?= h($registryName) ?>">
            <input type="hidden" name="registry_code" id="supplier-registry-code" value="<?= h($registryCode) ?>">
            <input type="hidden" name="registry_address" id="supplier-registry-address" value="<?= h($registryAddress) ?>">

            <label class="supplier-autocomplete">
                Tarnija
                <input
                    type="text"
                    id="supplier-search"
                    name="supplier_search"
                    value="<?= h($selectedSupplierLabel) ?>"
                    placeholder="Otsi tarnijat nime või registrikoodi järgi"
                    autocomplete="off"
                    aria-autocomplete="list"
                    aria-controls="supplier-suggestions"
                    required
                >
                <div id="supplier-suggestions" class="supplier-suggestions" role="listbox" hidden></div>
                <small id="supplier-selection-note" class="supplier-selection-note" aria-live="polite">
                    <?php if ($registryCode !== ''): ?>Uus ettevõte lisatakse kontaktiks ostuarve salvestamisel.<?php endif; ?>
                </small>
            </label>

            <label>
                Tarnija arve number
                <input type="text" name="supplier_invoice_number" maxlength="50"
                       value="<?= h($form['supplier_invoice_number'] ?? '') ?>" required>
            </label>

            <label>
                Arve kuupäev
                <input type="date" name="invoice_date"
                       value="<?= h($form['invoice_date'] ?? date('Y-m-d')) ?>" required>
            </label>

            <label>
                Maksetähtaeg
                <input type="date" name="due_date" value="<?= h($form['due_date'] ?? '') ?>">
            </label>

            <?php require __DIR__.'/invoice_fx_fields.php'; ?>
            <h2>Arveread</h2>

            <table>
                <colgroup>
                    <col style="width:18%">
                    <col style="width:15%">
                    <col style="width:10%">
                    <col style="width:22%">
                    <col style="width:8%">
                    <col style="width:9%">
                    <col style="width:18%">
                </colgroup>
                <thead>
                    <tr>
                        <th>Kulu- või varakonto</th>
                        <th>Laotoode (valikuline)</th>
                        <th>Ladu</th>
                        <th>Kirjeldus</th>
                        <th>Kogus</th>
                        <th>Ühikuhind</th>
                        <?php if (!$withoutVat): ?>
                            <th scope="col">
                                <a class="purchase-vat-guide" href="manual_document.php?id=purchase-vat" target="_blank" rel="noopener" aria-label="Käibemaksu valikute juhend (avaneb uuel vahelehel)">Valikute juhend</a>
                                Käibemaks
                            </th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="purchase-lines">
                    <?php foreach ($formLines as $line): ?>
                        <tr>
                            <td>
                                <select name="account_id[]">
                                    <option value="">Vali konto</option>
                                    <?php foreach ($accounts as $account): ?>
                                        <option
                                            value="<?= (int) $account['id'] ?>"
                                            <?= (string) $line['account_id'] === (string) $account['id'] ? 'selected' : '' ?>
                                        >
                                            <?= h($account['code'] . ' — ' . $account['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="product_id[]">
                                    <option value="">Tavaline kulu / muu ost</option>
                                    <?php foreach ($products as $product): ?>
                                        <option value="<?= (int) $product['id'] ?>" <?= (string) $line['product_id'] === (string) $product['id'] ? 'selected' : '' ?>><?= h($product['sku'] . ' — ' . $product['name']) ?></option>
                                    <?php endforeach; ?>
                                    <option value="create" data-stock-create="products">＋ Loo uus laokaart</option>
                                </select>
                            </td>
                            <td>
                                <select name="warehouse_id[]">
                                    <option value="">Vali ladu</option>
                                    <?php foreach ($warehouses as $warehouse): ?>
                                        <option value="<?= (int) $warehouse['id'] ?>" <?= (string) $line['warehouse_id'] === (string) $warehouse['id'] ? 'selected' : '' ?>><?= h($warehouse['code'] . ' — ' . $warehouse['name']) ?></option>
                                    <?php endforeach; ?>
                                    <option value="create" data-stock-create="warehouses">＋ Loo uus ladu</option>
                                </select>
                            </td>
                            <td><input type="text" name="line_description[]" maxlength="500" value="<?= h($line['description']) ?>"></td>
                            <td><input type="number" name="quantity[]" min="0.001" step="0.001" value="<?= h($line['quantity']) ?>"></td>
                            <td><input type="number" name="unit_price[]" min="0" step="0.0001" value="<?= h($line['unit_price']) ?>"></td>
                            <?php if (!$withoutVat): ?><td><select name="tax_code_id[]" class="tax-code-select" required>
                                <?php foreach ($taxCodes as $taxCode): ?>
                                    <option value="<?= (int) $taxCode['id'] ?>" data-valid-from="<?= h((string) ($taxCode['valid_from'] ?? '')) ?>" data-valid-to="<?= h((string) ($taxCode['valid_to'] ?? '')) ?>" <?= (string) ($line['tax_code_id'] ?: $defaultTaxCodeId) === (string) $taxCode['id'] ? 'selected' : '' ?>><?= h(purchaseTaxCodeOptionLabel($taxCode)) ?></option>
                                <?php endforeach; ?>
                            </select></td><?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <button type="button" id="add-line">Lisa arverida</button>
            <p id="stock-options-status" class="muted" aria-live="polite" hidden></p>
            <p class="muted">Laotoote valimisel vali ka ladu ning kasuta varakontot. Arve kinnitamisel lisatakse kaubakogus lattu ja ostuarve kanne suurendab sama varakontot. Tavalise kulu puhul jäta laotoode valimata.</p>

            <label>
                Märkused
                <textarea name="notes" maxlength="5000" rows="3" cols="50"><?= h($form['notes'] ?? '') ?></textarea>
            </label>

            <button type="submit">Salvesta ostuarve mustand</button>
			
        </form>
    <?php endif; ?>
	<?php if ($createdInvoice): ?>
    <p>
        <a class="button" href="purchase_invoices.php?id=<?= (int) $createdInvoice['id'] ?>">
            Ava ostuarve
        </a>
    </p>
<?php endif; ?>
<p><a href="index.php">Tagasi avalehele</a></p>
    <template id="purchase-line-template">
        <tr>
            <td>
                <select name="account_id[]">
                    <option value="">Vali konto</option>
                    <?php foreach ($accounts as $account): ?>
                        <option value="<?= (int) $account['id'] ?>">
                            <?= h($account['code'] . ' — ' . $account['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <select name="product_id[]">
                    <option value="">Tavaline kulu / muu ost</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= (int) $product['id'] ?>"><?= h($product['sku'] . ' — ' . $product['name']) ?></option>
                    <?php endforeach; ?>
                    <option value="create" data-stock-create="products">＋ Loo uus laokaart</option>
                </select>
            </td>
            <td>
                <select name="warehouse_id[]">
                    <option value="">Vali ladu</option>
                    <?php foreach ($warehouses as $warehouse): ?>
                        <option value="<?= (int) $warehouse['id'] ?>"><?= h($warehouse['code'] . ' — ' . $warehouse['name']) ?></option>
                    <?php endforeach; ?>
                    <option value="create" data-stock-create="warehouses">＋ Loo uus ladu</option>
                </select>
            </td>
            <td><input type="text" name="line_description[]" maxlength="500"></td>
            <td><input type="number" name="quantity[]" min="0.001" step="0.001"></td>
            <td><input type="number" name="unit_price[]" min="0" step="0.0001"></td>
            <?php if (!$withoutVat): ?><td><select name="tax_code_id[]" class="tax-code-select" required>
                <?php foreach ($taxCodes as $taxCode): ?>
                    <option value="<?= (int) $taxCode['id'] ?>" data-valid-from="<?= h((string) ($taxCode['valid_from'] ?? '')) ?>" data-valid-to="<?= h((string) ($taxCode['valid_to'] ?? '')) ?>" <?= (int) $taxCode['id'] === $defaultTaxCodeId ? 'selected' : '' ?>><?= h(purchaseTaxCodeOptionLabel($taxCode)) ?></option>
                <?php endforeach; ?>
            </select></td><?php endif; ?>
        </tr>
    </template>

    <script>
        const supplierSearch = document.getElementById('supplier-search');
        const supplierSuggestions = document.getElementById('supplier-suggestions');
        const supplierSelectionNote = document.getElementById('supplier-selection-note');
        const supplierContactIdInput = document.getElementById('supplier-contact-id');
        const supplierRegistryNameInput = document.getElementById('supplier-registry-name');
        const supplierRegistryCodeInput = document.getElementById('supplier-registry-code');
        const supplierRegistryAddressInput = document.getElementById('supplier-registry-address');
        let supplierSearchTimer;
        let supplierSearchController;

        function hideSupplierSuggestions() {
            supplierSuggestions.hidden = true;
            supplierSuggestions.replaceChildren();
        }

        function chooseSupplier(result) {
            supplierSearch.value = result.name + (result.registry_code ? ' — ' + result.registry_code : '');
            supplierContactIdInput.value = result.kind === 'contact' ? String(result.id) : '';
            supplierRegistryNameInput.value = result.kind === 'registry' ? result.name : '';
            supplierRegistryCodeInput.value = result.kind === 'registry' ? result.registry_code : '';
            supplierRegistryAddressInput.value = result.kind === 'registry' ? (result.address || '') : '';
            supplierSelectionNote.textContent = result.kind === 'registry'
                ? 'Äriregistri ettevõte lisatakse tarnijana ostuarve mustandi salvestamisel.'
                : 'Kasutusel on juba salvestatud tarnija.';
            hideSupplierSuggestions();
        }

        supplierSearch?.addEventListener('input', function () {
            supplierContactIdInput.value = '';
            supplierRegistryNameInput.value = '';
            supplierRegistryCodeInput.value = '';
            supplierRegistryAddressInput.value = '';
            supplierSelectionNote.textContent = '';
            clearTimeout(supplierSearchTimer);

            const query = supplierSearch.value.trim();
            if (query.length < 3) {
                hideSupplierSuggestions();
                return;
            }

            supplierSearchTimer = setTimeout(async function () {
                if (supplierSearchController) supplierSearchController.abort();
                supplierSearchController = new AbortController();
                supplierSuggestions.hidden = false;
                supplierSuggestions.textContent = 'Otsin tarnijaid…';

                try {
                    const response = await fetch('company_autocomplete.php?type=supplier&q=' + encodeURIComponent(query), {
                        signal: supplierSearchController.signal,
                        headers: { 'Accept': 'application/json' }
                    });
                    if (!response.ok) throw new Error('Search failed');
                    const payload = await response.json();
                    const results = Array.isArray(payload.results) ? payload.results : [];
                    supplierSuggestions.replaceChildren();

                    if (results.length === 0) {
                        supplierSuggestions.textContent = 'Vasteid ei leitud. Erakliendi saad lisada kontaktide lehel.';
                        return;
                    }

                    results.forEach(function (result) {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'supplier-suggestion';
                        button.setAttribute('role', 'option');
                        const name = document.createElement('span');
                        name.className = 'supplier-suggestion-name';
                        name.textContent = result.name;
                        const meta = document.createElement('span');
                        meta.className = 'supplier-suggestion-meta';
                        const source = result.kind === 'contact' ? 'Olemasolev tarnija' : 'Äriregister';
                        meta.textContent = source + (result.registry_code ? ' · Registrikood ' + result.registry_code : '');
                        button.append(name, meta);
                        button.addEventListener('mousedown', function (event) { event.preventDefault(); });
                        button.addEventListener('click', function () { chooseSupplier(result); });
                        supplierSuggestions.appendChild(button);
                    });
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        supplierSuggestions.textContent = 'Ettevõtteid ei saanud praegu otsida. Proovi hiljem uuesti.';
                    }
                }
            }, 300);
        });

        document.addEventListener('click', function (event) {
            if (!event.target.closest('.supplier-autocomplete')) hideSupplierSuggestions();
        });

        const purchaseLines = document.getElementById('purchase-lines');
        const stockStatus = document.getElementById('stock-options-status');
        const stockPaths = {products: 'ladu/products.php#new-product', warehouses: 'ladu/warehouses.php#new-warehouse'};
        const stockSelectors = {products: '[name="product_id[]"]', warehouses: '[name="warehouse_id[]"]'};
        let refreshStock = false;
        let loadingStock = false;
        purchaseLines?.addEventListener('focusin', event => {
            const select = event.target;
            if (select.matches(Object.values(stockSelectors).join(','))) select.dataset.previousValue = select.value;
        });
        purchaseLines?.addEventListener('change', event => {
            const select = event.target;
            if (!select.matches(Object.values(stockSelectors).join(','))) return;
            const kind = select.selectedOptions[0]?.dataset.stockCreate;
            if (!kind) {
                select.dataset.previousValue = select.value;
                return;
            }
            select.value = select.dataset.previousValue || '';
            refreshStock = true;
            stockStatus.hidden = false;
            stockStatus.textContent = 'Pärast salvestamist naase siia ja vali lisatud kirje rippmenüüst.';
            window.open(stockPaths[kind], '_blank', 'noopener');
        });
        async function refreshStockOptions() {
            if (!refreshStock || loadingStock || document.hidden) return;
            loadingStock = true;
            try {
                const response = await fetch('create_purchase_invoice.php?stock_options=1', {cache: 'no-store'});
                if (!response.ok) throw new Error('Stock options request failed');
                const records = await response.json();
                const template = document.getElementById('purchase-line-template');
                let added = false;
                for (const [kind, selector] of Object.entries(stockSelectors)) {
                    const selects = [...purchaseLines.querySelectorAll(selector), ...template.content.querySelectorAll(selector)];
                    for (const select of selects) {
                        const existing = new Set(Array.from(select.options, option => option.value));
                        const action = select.querySelector('[data-stock-create]');
                        for (const row of records[kind]) {
                            if (!existing.has(String(row.id))) {
                                select.add(new Option((row.sku ?? row.code) + ' — ' + row.name, row.id), action);
                                added = true;
                            }
                        }
                    }
                }
                if (added) {
                    refreshStock = false;
                    stockStatus.textContent = 'Lisatud kirje on nüüd rippmenüüs.';
                }
            } catch (error) {
                stockStatus.textContent = 'Valikuid ei õnnestunud värskendada. Ava arve vahekaart uuesti, et uuesti proovida.';
            } finally {
                loadingStock = false;
            }
        }
        window.addEventListener('focus', refreshStockOptions);
        document.addEventListener('visibilitychange', refreshStockOptions);

        document.getElementById('add-line')?.addEventListener('click', function () {
            const body = document.getElementById('purchase-lines');

            if (body.rows.length >= 20) {
                alert('Ostuarvel võib olla kuni 20 rida.');
                return;
            }

            const template = document.getElementById('purchase-line-template');
            body.appendChild(template.content.cloneNode(true));
            updateTaxCodeOptions();
        });

        function updateTaxCodeOptions() {
            const invoiceDate = document.querySelector('[name="invoice_date"]')?.value || '';
            document.querySelectorAll('.tax-code-select').forEach(select => {
                const previous = select.value;
                let selectedValid = false;
                Array.from(select.options).forEach(option => {
                    const from = option.dataset.validFrom || '';
                    const to = option.dataset.validTo || '';
                    const valid = (!from || !invoiceDate || from <= invoiceDate) && (!to || !invoiceDate || to >= invoiceDate);
                    option.disabled = !valid;
                    option.hidden = !valid;
                    if (option.value === previous && valid) selectedValid = true;
                });
                if (!selectedValid) {
                    const firstValid = Array.from(select.options).find(option => !option.disabled);
                    if (firstValid) select.value = firstValid.value;
                }
            });
        }
        document.querySelector('[name="invoice_date"]')?.addEventListener('change', updateTaxCodeOptions);
        updateTaxCodeOptions();
    </script>
</body>
</html>
