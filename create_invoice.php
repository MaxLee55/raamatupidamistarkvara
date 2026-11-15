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

ensureTaxCodeSchema($pdo);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function taxCodeOptionLabel(array $taxCode): string
{
    if($taxCode['tax_category']==='exempt') return 'Maksuvaba — '.$taxCode['label'];
    if ($taxCode['tax_category'] === 'reverse_charge') return (string)$taxCode['label'];
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

$companyId = (int) $_SESSION['company_id'];
$withoutVat = companyIsNonVatRegistered($pdo, $companyId);
$cashVatPeriod = companyCashVatPeriod($pdo, $companyId);
$taxCodes = $withoutVat ? [] : getTaxCodes($pdo, $companyId, 'sales');
$standardTaxCodeId = $cashTaxCodeId = 0;
foreach ($taxCodes as $taxCode) {
    if ($taxCode['code'] === 'M_EE24') $standardTaxCodeId = (int)$taxCode['id'];
    if ($taxCode['code'] === 'M_CASH24') $cashTaxCodeId = (int)$taxCode['id'];
}
$defaultTaxCodeId = companyUsesCashVat($cashVatPeriod, (string)(($_POST['supply_date']??'') ?: ($_POST['invoice_date'] ?? date('Y-m-d'))))
    || (($_POST['supply_date']??'')>($_POST['invoice_date']??date('Y-m-d')) && companyUsesCashVat($cashVatPeriod,(string)($_POST['invoice_date']??date('Y-m-d'))))
    ? ($cashTaxCodeId ?: $standardTaxCodeId) : $standardTaxCodeId;
$errors = [];
$form = $_POST;
$formLines = [];

$contactsStmt = $pdo->prepare(
    "SELECT id, name, registry_code
     FROM contacts
     WHERE company_id = :company_id
       AND is_active = 1
       AND contact_type IN ('customer', 'both')
     ORDER BY name"
);
$contactsStmt->execute(['company_id' => $companyId]);
$contacts = $contactsStmt->fetchAll();
$inventoryProductsStmt = $pdo->prepare('SELECT id, sku, name, unit, inventory_account_id, cogs_account_id FROM inventory_products WHERE company_id = ? AND is_active = 1 ORDER BY name, sku');
$inventoryProductsStmt->execute([$companyId]);
$inventoryProducts = $inventoryProductsStmt->fetchAll();
$warehousesStmt = $pdo->prepare('SELECT id, code, name FROM inventory_warehouses WHERE company_id = ? AND is_active = 1 ORDER BY is_default DESC, name, code');
$warehousesStmt->execute([$companyId]);
$inventoryWarehouses = $warehousesStmt->fetchAll();

$selectedContactId = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string) ($_POST['contact_id'] ?? '')
    : '';
$registryName = trim((string) ($_POST['registry_name'] ?? ''));
$registryCode = trim((string) ($_POST['registry_code'] ?? ''));
$registryAddress = trim((string) ($_POST['registry_address'] ?? ''));
$selectedCustomerLabel = trim((string) ($_POST['customer_search'] ?? ''));
$newRegistryContact = null;

if ($selectedCustomerLabel === '' && $selectedContactId !== '') {
    foreach ($contacts as $contact) {
        if ((string) $contact['id'] === $selectedContactId) {
            $selectedCustomerLabel = $contact['name'] . ($contact['registry_code'] ? ' — ' . $contact['registry_code'] : '');
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
    ) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    }

    $contactId = filter_var($_POST['contact_id'] ?? null, FILTER_VALIDATE_INT);
    $invoiceNumber = trim($_POST['invoice_number'] ?? '');
    $invoiceDate = trim($_POST['invoice_date'] ?? '');
	$supplyDate = trim($_POST['supply_date'] ?? '');
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
            $errors[] = 'Äriregistri ettevõtte kontroll ebaõnnestus. Proovi uuesti või lisa klient kontaktide lehel.';
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
                    && in_array($existingContact['contact_type'], ['customer', 'both'], true)) {
                    $contactId = (int) $existingContact['id'];
                } else {
                    $errors[] = 'Selle registrikoodiga kontakt on juba olemas, kuid pole aktiivne klient. Muuda kontaktide lehel selle tüübiks „Klient ja tarnija” või aktiveeri see.';
                }
            } else {
                $newRegistryContact = $verifiedCompany;
            }
        }
    }

    if (!$contactId && !$newRegistryContact) {
        $errors[] = 'Vali klient või otsi ettevõte Äriregistrist.';
    } elseif ($contactId) {
        $checkContact = $pdo->prepare(
            "SELECT COUNT(*)
             FROM contacts
             WHERE id = :contact_id
               AND company_id = :company_id
               AND is_active = 1
               AND contact_type IN ('customer', 'both')"
        );
        $checkContact->execute([
            'contact_id' => $contactId,
            'company_id' => $companyId,
        ]);

        if ((int) $checkContact->fetchColumn() !== 1) {
            $errors[] = 'Valitud klienti ei leitud.';
        }
    }

    if ($invoiceNumber === '' || mb_strlen($invoiceNumber) > 50) {
        $errors[] = 'Arve number on kohustuslik ja võib olla kuni 50 märki.';
    }

    if (!validDate($invoiceDate)) {
        $errors[] = 'Sisesta kehtiv arve kuupäev.';
    } elseif ($lockMessage = closedAccountingPeriodMessage($pdo, $companyId, $invoiceDate)) {
        $errors[] = $lockMessage;
    }	if ($supplyDate !== '' && !validDate($supplyDate)) {
    $errors[] = 'Sisesta kehtiv teenuse osutamise või kauba üleandmise kuupäev.';
}

    if ($dueDate !== '' && !validDate($dueDate)) {
        $errors[] = 'Sisesta kehtiv maksetähtaeg.';
    }

    if (mb_strlen($notes) > 5000) {
        $errors[] = 'Märkused võivad olla kuni 5000 märki.';
    }

    $descriptions = is_array($_POST['line_description'] ?? null) ? $_POST['line_description'] : [];
    $quantities = is_array($_POST['quantity'] ?? null) ? $_POST['quantity'] : [];
    $unitPrices = is_array($_POST['unit_price'] ?? null) ? $_POST['unit_price'] : [];
    $vatRates = is_array($_POST['vat_rate'] ?? null) ? $_POST['vat_rate'] : [];
    $taxCodeIds = is_array($_POST['tax_code_id'] ?? null) ? $_POST['tax_code_id'] : [];
    $productIds = is_array($_POST['product_id'] ?? null) ? $_POST['product_id'] : [];
    $warehouseIds = is_array($_POST['warehouse_id'] ?? null) ? $_POST['warehouse_id'] : [];

    $lineCount = max(
        count($descriptions),
        count($quantities),
        count($unitPrices),
        count($vatRates),
        count($taxCodeIds),
        count($productIds),
        count($warehouseIds)
    );

    if ($lineCount > 20) {
        $errors[] = 'Arvel võib olla kuni 20 rida.';
    }

    $cleanLines = [];
    $netTotal = 0;
    $vatTotal = 0;

    for ($i = 0; $i < min($lineCount, 20); $i++) {
        $lineDescription = trim((string) ($descriptions[$i] ?? ''));
        $quantityText = trim((string) ($quantities[$i] ?? ''));
        $unitPriceText = trim((string) ($unitPrices[$i] ?? ''));
        $vatRateText = trim((string) ($vatRates[$i] ?? ''));
        $taxCodeIdText = trim((string) ($taxCodeIds[$i] ?? ''));
        $productIdText = trim((string) ($productIds[$i] ?? ''));
        $warehouseIdText = trim((string) ($warehouseIds[$i] ?? ''));

        $formLines[] = [
            'description' => $lineDescription,
            'quantity' => $quantityText,
            'unit_price' => $unitPriceText,
            'vat_rate' => $vatRateText,
            'tax_code_id' => $taxCodeIdText,
            'product_id' => $productIdText,
            'warehouse_id' => $warehouseIdText,
        ];

        if (
            $lineDescription === '' &&
            $quantityText === '' &&
            $unitPriceText === '' &&
            $vatRateText === '' &&
            $productIdText === '' &&
            $warehouseIdText === ''
        ) {
            continue;
        }

        if ($lineDescription === '' || mb_strlen($lineDescription) > 500) {
            $errors[] = 'Iga kasutatud arverida vajab kirjeldust (kuni 500 märki).';
            continue;
        }

        // Piirid hoiavad arvutuse täisarvudes täpse ja andmebaasi mahtuvana.
        $quantity = toScaled($quantityText, 3, 5);
        $unitPrice = toScaled($unitPriceText, 4, 6);
        $taxCodeId = filter_var($taxCodeIdText, FILTER_VALIDATE_INT);
        $taxCode = $withoutVat
            ? ['id' => null, 'code' => '', 'rate' => 0]
            : ($taxCodeId ? getTaxCodeById($pdo, $companyId, 'sales', (int) $taxCodeId, $invoiceDate) : null);
        if (!$taxCode) {
            $errors[] = 'Vali arve kuupäeval kehtiv käibemaksukood (rida ' . ($i + 1) . ').';
            continue;
        }
        $cashMode=companyUsesCashVat($cashVatPeriod,$supplyDate?:$invoiceDate) || ($supplyDate>$invoiceDate && companyUsesCashVat($cashVatPeriod,$invoiceDate));
        if(!$withoutVat && $cashMode && in_array($taxCode['code'],['M_EE24','M_EE13','M_EE9'],true)) {
            $cashCode='M_CASH'.(int)$taxCode['rate'];
            foreach($taxCodes as $candidate) if($candidate['code']===$cashCode) { $taxCode=$candidate; break; }
        }
        if ($reverseSpec = reverseVatSpec((string)$taxCode['code'])) {
            $partnerStmt=$pdo->prepare('SELECT country_code, vat_number FROM contacts WHERE id = ? AND company_id = ?');
            $partnerStmt->execute([(int)$contactId,$companyId]);
            $partner=$partnerStmt->fetch(PDO::FETCH_ASSOC);
            $partyError=reversePartyError($reverseSpec,(string)($partner['country_code']??''),(string)($partner['vat_number']??''));
            $companyError=reverseCompanyError($pdo,$companyId,$reverseSpec);
            if ($companyError!==null) { $errors[]=$companyError; continue; }
            if ($partyError!==null) { $errors[]=$partyError; continue; }
        }
        $vatRateText = number_format((float) $taxCode['rate'], 2, '.', '');
        $vatRate = toScaled($vatRateText, 2, 3);

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

        if ($productIdText !== '' || $warehouseIdText !== '') {
            $productId = filter_var($productIdText, FILTER_VALIDATE_INT);
            $warehouseId = filter_var($warehouseIdText, FILTER_VALIDATE_INT);
            if (!$productId || !$warehouseId) {
                $errors[] = 'Laotoote ja lao sidumiseks vali mõlemad (arverida ' . ($i + 1) . ').';
                continue;
            }
            $selectedProduct = null;
            foreach ($inventoryProducts as $candidate) {
                if ((int) $candidate['id'] === $productId) { $selectedProduct = $candidate; break; }
            }
            $validWarehouse = false;
            foreach ($inventoryWarehouses as $candidateWarehouse) {
                if ((int) $candidateWarehouse['id'] === $warehouseId) { $validWarehouse = true; break; }
            }
            if (!$selectedProduct || !$validWarehouse) {
                $errors[] = 'Valitud laotoode või ladu ei ole aktiivne (arverida ' . ($i + 1) . ').';
                continue;
            }
            if (empty($selectedProduct['inventory_account_id']) || empty($selectedProduct['cogs_account_id'])) {
                $errors[] = 'Enne laotoote müügiarvele lisamist määra tootekaardil varude ja kaubakulu kontod.';
                continue;
            }
        }

        // Kogus × ühikuhind, ümardatuna sendini.
        $lineNet = intdiv(($quantity * $unitPrice) + 50000, 100000);

        // Maks arvutatakse rea netosummalt ja ümardatakse sendini.
        $lineVat = intdiv(($lineNet * $vatRate) + 5000, 10000);
        $lineGross = $lineNet + $lineVat;

        $netTotal += $lineNet;
        $vatTotal += $lineVat;

        $cleanLines[] = [
            'description' => $lineDescription,
            'quantity' => $quantityText,
            'unit_price' => $unitPriceText,
            'vat_rate' => $vatRateText,
            'tax_code_id' => $taxCode['id'] === null ? null : (int) $taxCode['id'],
            'tax_code' => (string)$taxCode['code'],
            'net_amount' => centsToDecimal($lineNet),
            'vat_amount' => centsToDecimal($lineVat),
            'gross_amount' => centsToDecimal($lineGross),
            'product_id' => $productIdText !== '' ? (int) $productIdText : null,
            'warehouse_id' => $warehouseIdText !== '' ? (int) $warehouseIdText : null,
        ];
    }

    $reverseRows=count(array_filter($cleanLines,static fn(array $line):bool=>reverseVatSpec((string)($line['tax_code']??''))!==null));
    if ($reverseRows>0 && $reverseRows!==count($cleanLines)) $errors[]='Siseriiklik pöördmaksu müük ja tavaline müük sisesta eraldi arvetena.';
    $cashRows = count(array_filter($cleanLines, static fn(array $line): bool => str_starts_with($line['tax_code'],'M_CASH')));
    if ($cashRows) {
        if ($cashRows !== count($cleanLines)) {
            $errors[] = 'Kassapõhine müük sisesta eraldi arvena.';
        } elseif (stripos($notes, 'kassapõhine arvestus') === false) {
            $notes = $notes === '' ? 'Kassapõhine arvestus' : $notes . "\nKassapõhine arvestus";
            if (mb_strlen($notes) > 5000) $errors[] = 'Jäta arve märkustes ruumi tekstile „Kassapõhine arvestus“.';
        }
    }
    if (count($cleanLines) === 0) {
        $errors[] = 'Lisa arvele vähemalt üks korrektne rida.';
    }

    $fx = null;
    if (!$errors) {
        try {
            $fx = invoiceFxPrepare($_POST,$invoiceDate,$supplyDate ?: null,!$withoutVat,$cleanLines);
        } catch (RuntimeException|InvalidArgumentException $error) {
            $errors[] = $error->getMessage();
        }
    }
    if (!$errors) {
        try {
            $pdo->beginTransaction();

            if ($newRegistryContact) {
                $newContactStmt = $pdo->prepare(
                    'INSERT INTO contacts
                        (company_id, contact_type, name, registry_code, vat_number,
                         email, phone, address, is_active)
                     VALUES
                        (:company_id, \'customer\', :name, :registry_code, NULL,
                         NULL, NULL, :address, 1)'
                );
                $newContactStmt->execute([
                    'company_id' => $companyId,
                    'name' => $newRegistryContact['name'],
                    'registry_code' => $newRegistryContact['registry_code'],
                    'address' => $newRegistryContact['address'] !== '' ? $newRegistryContact['address'] : null,
                ]);
                $contactId = (int) $pdo->lastInsertId();
            }

            $invoiceStmt = $pdo->prepare(
                "INSERT INTO sales_invoices
                    (company_id, contact_id, invoice_number, invoice_date, supply_date,
                     due_date, status, notes, created_by, journal_entry_id)
                 VALUES
                    (:company_id, :contact_id, :invoice_number, :invoice_date, :supply_date,
                     :due_date, 'draft', :notes, :created_by, NULL)"
            );
            $invoiceStmt->execute([
                'company_id' => $companyId,
                'contact_id' => $contactId,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $invoiceDate,
				'supply_date' => $supplyDate !== '' ? $supplyDate : null,
                'due_date' => $dueDate !== '' ? $dueDate : null,
                'notes' => $notes !== '' ? $notes : null,
                'created_by' => (int) $_SESSION['user_id'],
            ]);

            $invoiceId = (int) $pdo->lastInsertId();
            if ($fx) invoiceFxSave($pdo,'sales',$invoiceId,$fx);

            $lineStmt = $pdo->prepare(
                'INSERT INTO sales_invoice_lines
                    (invoice_id, description, quantity, unit_price, vat_rate, tax_code_id,
                     net_amount, vat_amount, gross_amount, product_id, warehouse_id)
                 VALUES
                    (:invoice_id, :description, :quantity, :unit_price, :vat_rate, :tax_code_id,
                     :net_amount, :vat_amount, :gross_amount, :product_id, :warehouse_id)'
            );

            foreach ($cleanLines as $line) {
                $lineStmt->execute([
                    'invoice_id' => $invoiceId,
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'vat_rate' => $line['vat_rate'],
                    'tax_code_id' => $line['tax_code_id'],
                    'net_amount' => $line['net_amount'],
                    'vat_amount' => $line['vat_amount'],
                    'gross_amount' => $line['gross_amount'],
                    'product_id' => $line['product_id'],
                    'warehouse_id' => $line['warehouse_id'],
                ]);
                if ($fx) invoiceFxSaveLine($pdo,'sales',(int)$pdo->lastInsertId(),$line['fx_original']);
            }

            $zeroForm = $_POST;
            if ($fx) $zeroForm['zero_tax_date'] = $fx[3];
            saveZeroSalesContext($pdo,$companyId,(int)$_SESSION['user_id'],$invoiceId,$zeroForm);
            $pdo->commit();

            header('Location: create_invoice.php?created=' . $invoiceId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if (!($e instanceof PDOException)) {
                $errors[]=$e->getMessage();
            } elseif ($e->getCode() === '23000') {
                $errors[] = 'Selle arve numbriga arve on juba olemas.';
            } else {
                error_log($e->getMessage());
                $errors[] = 'Arve salvestamine ebaõnnestus.';
            }
        }
    }
}

while (count($formLines) < 4) {
    $formLines[] = [
        'description' => '',
        'quantity' => '',
        'unit_price' => '',
        'vat_rate' => '',
        'tax_code_id' => '',
        'product_id' => '',
        'warehouse_id' => '',
    ];
}

$createdInvoice = null;
$createdId = filter_input(INPUT_GET, 'created', FILTER_VALIDATE_INT);

if ($createdId) {
    $createdStmt = $pdo->prepare(
        'SELECT id, invoice_number
         FROM sales_invoices
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
    <title>Uus müügiarve</title>
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
        max-width: 1100px;
        margin: 36px auto;
        padding: 0 24px;
        background: #f4f7fb;
        color: #243447;
        font-family: Arial, sans-serif;
    }

    h1, h2 { color: #17324d; }

    .invoice-form {
        margin-top: 20px;
        padding: 24px;
        background: white;
        border: 1px solid #dce3ec;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(23, 50, 77, .04);
    }

    .invoice-form > label {
        display: inline-grid;
        vertical-align: top;
        gap: 7px;
        width: min(100%, 320px);
        margin: 0 14px 18px 0;
        color: #33475b;
        font-weight: 600;
    }

    .invoice-form input,
    .invoice-form select,
    .invoice-form textarea {
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

    .invoice-form textarea {
        min-height: 85px;
        resize: vertical;
    }

    .invoice-form table {
        width: 100%;
        table-layout: fixed;
        margin: 14px 0 18px;
        border-collapse: collapse;
        background: white;
    }

    .invoice-form th,
    .invoice-form td {
        padding: 9px;
        border: 1px solid #dce3ec;
        text-align: left;
    }

    .invoice-form th {
        background: #eaf0f6;
        color: #17324d;
    }

    .invoice-form td input {
        min-width: 105px;
    }

    .invoice-form td:first-child input {
        min-width: 190px;
    }

    .invoice-form td select {
        min-width: 0;
    }

    .customer-autocomplete { position: relative; }

    .customer-suggestions:not([hidden]) {
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

    .invoice-form .customer-suggestion {
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

    .invoice-form .customer-suggestion:hover,
    .invoice-form .customer-suggestion:focus {
        background: #eef5fb;
        color: #17324d;
    }

    .customer-suggestion-name { display: block; font-weight: 600; }
    .customer-suggestion-meta { display: block; margin-top: 3px; color: #66788a; font-size: .88em; }
    .customer-selection-note { display: block; min-height: 1.2em; color: #52677b; font-weight: 400; }

    .invoice-form button,
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

    .invoice-form button:hover,
    .button:hover {
        background: #0f4f83;
    }

    .invoice-form #add-invoice-line {
        margin-bottom: 14px;
        background: #eaf0f6;
        color: #17324d;
        border: 1px solid #cbd5df;
    }

    .invoice-form #add-invoice-line:hover {
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
        .invoice-form { padding: 16px; }
        .invoice-form table { display: block; overflow-x: auto; }
    }
	
	.invoice-form #add-invoice-line,
.invoice-form > button,
.invoice-form > a {
    display: block;
    width: fit-content;
    max-width: 100%;
    margin: 14px 0;
}
</style>
    </style>
</head>
<body>
    <h1>Uus müügiarve</h1>

    <?php if ($createdInvoice): ?>
        <p class="success">
            Arve <?= h($createdInvoice['invoice_number']) ?> salvestati mustandina.
        </p>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endforeach; ?>

        <form method="post" class="invoice-form">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="contact_id" id="contact-id" value="<?= h($selectedContactId) ?>">
            <input type="hidden" name="registry_name" id="registry-name" value="<?= h($registryName) ?>">
            <input type="hidden" name="registry_code" id="registry-code" value="<?= h($registryCode) ?>">
            <input type="hidden" name="registry_address" id="registry-address" value="<?= h($registryAddress) ?>">

            <label class="customer-autocomplete">
                Klient
                <input
                    type="text"
                    id="customer-search"
                    name="customer_search"
                    value="<?= h($selectedCustomerLabel) ?>"
                    placeholder="Otsi klienti nime või registrikoodi järgi"
                    autocomplete="off"
                    aria-autocomplete="list"
                    aria-controls="customer-suggestions"
                    required
                >
                <div id="customer-suggestions" class="customer-suggestions" role="listbox" hidden></div>
                <small id="customer-selection-note" class="customer-selection-note" aria-live="polite">
                    <?php if ($registryCode !== ''): ?>Uus ettevõte lisatakse kontaktiks arve salvestamisel.<?php endif; ?>
                </small>
            </label>

            <label>
                Arve number
                <input type="text" name="invoice_number" maxlength="50"
                       value="<?= h($form['invoice_number'] ?? '') ?>" required>
            </label>

            <label>
                Arve kuupäev
                <input type="date" name="invoice_date"
                       value="<?= h($form['invoice_date'] ?? date('Y-m-d')) ?>" required>
            </label>
            <label>
               Teenuse osutamise või kauba üleandmise kuupäev
                <input
                      type="date"
                      name="supply_date"
                      value="<?= h($form['supply_date'] ?? '') ?>"
                 >
            </label>
            <label>
                Maksetähtaeg
                <input type="date" name="due_date"
                       value="<?= h($form['due_date'] ?? '') ?>">
            </label>

            <?php require __DIR__.'/invoice_fx_fields.php'; ?>
            <h2>Arveread</h2>
            <p>Vali igale reale sobiv maksukood. Määr ja käibemaks arvutatakse valiku järgi ning summad ümardatakse rea kaupa sendini.</p>
            <p>Kui müüd laokaupa, vali arverea juures laotoode ja ladu. Arve kinnitamisel väheneb laojääk FIFO järgi ning müüdud kauba kulu lisatakse samasse pearaamatukandesse. Teenuse või muu rea puhul jäta mõlemad valikud tühjaks.</p>

            <table>
                <colgroup>
                    <col style="width:34%">
                    <col style="width:9%">
                    <col style="width:13%">
                    <col style="width:20%">
                    <col style="width:12%">
                    <col style="width:12%">
                </colgroup>
                <thead>
                    <tr>
                        <th>Kirjeldus</th>
                        <th>Kogus</th>
                        <th>Ühikuhind</th>
                        <?php if (!$withoutVat): ?><th>Käibemaks</th><?php endif; ?>
                        <th>Laotoode (valikuline)</th>
                        <th>Ladu</th>
                    </tr>
                </thead>
                <tbody id="invoice-lines">
                    <?php foreach ($formLines as $line): ?>
                        <tr>
                            <td>
                                <input type="text" name="line_description[]" maxlength="500"
                                       value="<?= h($line['description']) ?>">
                            </td>
                            <td>
                                <input type="number" name="quantity[]" min="0.001" step="0.001"
                                       value="<?= h($line['quantity']) ?>">
                            </td>
                            <td>
                                <input type="number" name="unit_price[]" min="0" step="0.0001"
                                       value="<?= h($line['unit_price']) ?>">
                            </td>
                            <?php if (!$withoutVat): ?><td>
                                <select name="tax_code_id[]" class="tax-code-select" required>
                                    <?php foreach ($taxCodes as $taxCode): ?>
                                        <option value="<?= (int) $taxCode['id'] ?>"
                                            data-valid-from="<?= h((string) ($taxCode['valid_from'] ?? '')) ?>"
                                            data-valid-to="<?= h((string) ($taxCode['valid_to'] ?? '')) ?>"
                                            <?= (string) ($line['tax_code_id'] ?: $defaultTaxCodeId) === (string) $taxCode['id'] ? 'selected' : '' ?>>
                                            <?= h(taxCodeOptionLabel($taxCode)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td><?php endif; ?>
                            <td><select name="product_id[]"><option value="">Teenuse või muu rea</option><?php foreach ($inventoryProducts as $product): ?><option value="<?= (int) $product['id'] ?>" <?= (string) ($line['product_id'] ?? '') === (string) $product['id'] ? 'selected' : '' ?>><?= h($product['sku'] . ' — ' . $product['name']) ?></option><?php endforeach; ?></select></td>
                            <td><select name="warehouse_id[]"><option value="">Vali ladu</option><?php foreach ($inventoryWarehouses as $warehouse): ?><option value="<?= (int) $warehouse['id'] ?>" <?= (string) ($line['warehouse_id'] ?? '') === (string) $warehouse['id'] ? 'selected' : '' ?>><?= h($warehouse['code'] . ' — ' . $warehouse['name']) ?></option><?php endforeach; ?></select></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <button type="button" id="add-invoice-line">Lisa arverida</button>

            <?php if (!$withoutVat) require __DIR__.'/zero_sales_fields.php'; ?>
            <label>
                Märkused
                <textarea name="notes" maxlength="5000" rows="3" cols="50"><?= h($form['notes'] ?? '') ?></textarea>
            </label>

            <button type="submit">Salvesta arve mustand</button>
			
        </form>
		<?php if ($createdInvoice): ?>
    <p>
        <a class="button" href="invoices.php?id=<?= (int) $createdInvoice['id'] ?>">
            Ava arve
        </a>
    </p>
<?php endif; ?>
   
	
<p><a href="index.php">Tagasi avalehele</a></p>
    <template id="invoice-line-template">
        <tr>
            <td><input type="text" name="line_description[]" maxlength="500"></td>
            <td><input type="number" name="quantity[]" min="0.001" step="0.001"></td>
            <td><input type="number" name="unit_price[]" min="0" step="0.0001"></td>
            <?php if (!$withoutVat): ?><td><select name="tax_code_id[]" class="tax-code-select" required>
                <?php foreach ($taxCodes as $taxCode): ?>
                    <option value="<?= (int) $taxCode['id'] ?>" data-valid-from="<?= h((string) ($taxCode['valid_from'] ?? '')) ?>" data-valid-to="<?= h((string) ($taxCode['valid_to'] ?? '')) ?>" <?= (int) $taxCode['id'] === $defaultTaxCodeId ? 'selected' : '' ?>><?= h(taxCodeOptionLabel($taxCode)) ?></option>
                <?php endforeach; ?>
            </select></td><?php endif; ?>
            <td><select name="product_id[]"><option value="">Teenuse või muu rea</option><?php foreach ($inventoryProducts as $product): ?><option value="<?= (int) $product['id'] ?>"><?= h($product['sku'] . ' — ' . $product['name']) ?></option><?php endforeach; ?></select></td>
            <td><select name="warehouse_id[]"><option value="">Vali ladu</option><?php foreach ($inventoryWarehouses as $warehouse): ?><option value="<?= (int) $warehouse['id'] ?>"><?= h($warehouse['code'] . ' — ' . $warehouse['name']) ?></option><?php endforeach; ?></select></td>
        </tr>
    </template>

    <script>
        const customerSearch = document.getElementById('customer-search');
        const customerSuggestions = document.getElementById('customer-suggestions');
        const customerSelectionNote = document.getElementById('customer-selection-note');
        const contactIdInput = document.getElementById('contact-id');
        const registryNameInput = document.getElementById('registry-name');
        const registryCodeInput = document.getElementById('registry-code');
        const registryAddressInput = document.getElementById('registry-address');
        let customerSearchTimer;
        let customerSearchController;

        function hideCustomerSuggestions() {
            customerSuggestions.hidden = true;
            customerSuggestions.replaceChildren();
        }

        function chooseCustomer(result) {
            customerSearch.value = result.name + (result.registry_code ? ' — ' + result.registry_code : '');
            contactIdInput.value = result.kind === 'contact' ? String(result.id) : '';
            registryNameInput.value = result.kind === 'registry' ? result.name : '';
            registryCodeInput.value = result.kind === 'registry' ? result.registry_code : '';
            registryAddressInput.value = result.kind === 'registry' ? (result.address || '') : '';
            customerSelectionNote.textContent = result.kind === 'registry'
                ? 'Äriregistri ettevõte lisatakse kliendina arve mustandi salvestamisel.'
                : 'Kasutusel on juba salvestatud klient.';
            hideCustomerSuggestions();
        }

        customerSearch?.addEventListener('input', function () {
            contactIdInput.value = '';
            registryNameInput.value = '';
            registryCodeInput.value = '';
            registryAddressInput.value = '';
            customerSelectionNote.textContent = '';
            clearTimeout(customerSearchTimer);

            const query = customerSearch.value.trim();
            if (query.length < 3) {
                hideCustomerSuggestions();
                return;
            }

            customerSearchTimer = setTimeout(async function () {
                if (customerSearchController) customerSearchController.abort();
                customerSearchController = new AbortController();
                customerSuggestions.hidden = false;
                customerSuggestions.textContent = 'Otsin kontakte…';

                try {
                    const response = await fetch('company_autocomplete.php?q=' + encodeURIComponent(query), {
                        signal: customerSearchController.signal,
                        headers: { 'Accept': 'application/json' }
                    });
                    if (!response.ok) throw new Error('Search failed');
                    const payload = await response.json();
                    const results = Array.isArray(payload.results) ? payload.results : [];
                    customerSuggestions.replaceChildren();

                    if (results.length === 0) {
                        customerSuggestions.textContent = 'Vasteid ei leitud. Erakliendi saad lisada kontaktide lehel.';
                        return;
                    }

                    results.forEach(function (result) {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'customer-suggestion';
                        button.setAttribute('role', 'option');
                        const name = document.createElement('span');
                        name.className = 'customer-suggestion-name';
                        name.textContent = result.name;
                        const meta = document.createElement('span');
                        meta.className = 'customer-suggestion-meta';
                        const source = result.kind === 'contact' ? 'Olemasolev klient' : 'Äriregister';
                        meta.textContent = source + (result.registry_code ? ' · Registrikood ' + result.registry_code : '');
                        button.append(name, meta);
                        button.addEventListener('mousedown', function (event) { event.preventDefault(); });
                        button.addEventListener('click', function () { chooseCustomer(result); });
                        customerSuggestions.appendChild(button);
                    });
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        customerSuggestions.textContent = 'Ettevõtteid ei saanud praegu otsida. Proovi hiljem uuesti.';
                    }
                }
            }, 300);
        });

        document.addEventListener('click', function (event) {
            if (!event.target.closest('.customer-autocomplete')) hideCustomerSuggestions();
        });

        document.getElementById('add-invoice-line')?.addEventListener('click', function () {
            const body = document.getElementById('invoice-lines');

            if (body.rows.length >= 20) {
                alert('Arvel võib olla kuni 20 rida.');
                return;
            }

            const template = document.getElementById('invoice-line-template');
            body.appendChild(template.content.cloneNode(true));
            updateTaxCodeOptions(true);
        });

        const cashVatFrom = <?= json_encode($cashVatPeriod['cash_vat_from'] ?? '') ?>;
        const cashVatUntil = <?= json_encode($cashVatPeriod['cash_vat_until'] ?? '') ?>;
        const standardTaxCode = <?= $standardTaxCodeId ?>;
        const cashTaxCode = <?= $cashTaxCodeId ?>;
        const cashHistory = <?= json_encode(json_decode($cashVatPeriod['cash_vat_history']??'[]',true)??[]) ?>;
        function updateTaxCodeOptions(refreshDefault = false) {
            const invoiceDate = document.querySelector('[name="invoice_date"]')?.value || '';
            const invoiceMonth = (document.querySelector('[name="supply_date"]')?.value || invoiceDate).slice(0, 7);
            const applies=m=>[...cashHistory,{cash_vat_from:cashVatFrom,cash_vat_until:cashVatUntil}].some(p=>p.cash_vat_from&&m>=p.cash_vat_from&&(!p.cash_vat_until||m<=p.cash_vat_until));
            const cashByDefault = applies(invoiceMonth) || (invoiceMonth>invoiceDate.slice(0,7) && applies(invoiceDate.slice(0,7)));
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
                if (refreshDefault && !select.dataset.chosen && [standardTaxCode, cashTaxCode].includes(Number(previous))) {
                    const preferred = Array.from(select.options).find(option =>
                        !option.disabled && Number(option.value) === (cashByDefault ? cashTaxCode : standardTaxCode));
                    if (preferred) {
                        select.value = preferred.value;
                        selectedValid = true;
                    }
                }
                if (!selectedValid) {
                    const firstValid = Array.from(select.options).find(option => !option.disabled);
                    if (firstValid) select.value = firstValid.value;
                }
            });
        }
        document.addEventListener('change', event => {
            if (event.target.matches('.tax-code-select')) event.target.dataset.chosen = '1';
        });
        document.querySelector('[name="invoice_date"]')?.addEventListener('change', () => updateTaxCodeOptions(true));
        document.querySelector('[name="supply_date"]')?.addEventListener('change', () => updateTaxCodeOptions(true));
        updateTaxCodeOptions();
    </script>
</body>
</html>
