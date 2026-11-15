<?php
declare(strict_types=1);

require_once __DIR__ . '/app_env.php';

function billberryPrivatePaths(): array
{
    $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'raamatupidamine_private';
    return [
        'directory' => $directory,
        'key' => $directory . DIRECTORY_SEPARATOR . 'billberry.key',
        'settings' => $directory . DIRECTORY_SEPARATOR . 'billberry_company_settings.json',
    ];
}

function billberryReadAllSettings(): array
{
    $env = appEnvRead();
    $all = [];
    foreach ($env as $name => $value) {
        if (!preg_match('/^APP_BILLBERRY_(\d+)_(PRODUCTION|DEVELOPMENT)_(API_ID|API_KEY|UPDATES_LINK)$/', $name, $match)) continue;
        $companyId = $match[1];
        $environment = strtolower($match[2]);
        $field = match ($match[3]) { 'API_ID' => 'api_id', 'API_KEY' => 'api_key', 'UPDATES_LINK' => 'updates_link' };
        $all[$companyId]['environments'][$environment][$field] = (string) $value;
    }
    if ($all !== []) return $all;

    // One-time migration from the previous shared and encrypted private folder.
    $legacy = billberryReadLegacySettings();
    if ($legacy !== []) {
        billberryWriteAllSettings($legacy);
        return $legacy;
    }
    appEnvEnsureFile();
    return [];
}

function billberryReadLegacySettings(): array
{
    $paths = billberryPrivatePaths();
    if (!is_file($paths['settings'])) return [];
    $stored = json_decode((string) file_get_contents($paths['settings']), true);
    if (!is_array($stored)) throw new RuntimeException('Vana Billberry seadete fail on vigane.');
    $key = is_file($paths['key']) ? file_get_contents($paths['key']) : false;
    $all = [];
    foreach ($stored as $companyId => $company) {
        if (!ctype_digit((string) $companyId) || !is_array($company)) continue;
        $configs = $company['environments'] ?? [];
        if (!is_array($configs)) $configs = [];
        if (!isset($configs['production']) && (isset($company['api_id']) || isset($company['secret']))) {
            $configs['production'] = ['api_id' => $company['api_id'] ?? '', 'secret' => $company['secret'] ?? '', 'updates_link' => $company['updates_link'] ?? ''];
        }
        foreach (['production', 'development'] as $environment) {
            $config = $configs[$environment] ?? null;
            if (!is_array($config)) continue;
            $plainKey = '';
            if (!empty($config['secret'])) {
                $blob = base64_decode((string) $config['secret'], true);
                if ($key === false || strlen($key) !== 32 || $blob === false || strlen($blob) < 29) {
                    throw new RuntimeException('Vana Billberry võtit ei saanud lugeda.');
                }
                $aad = 'raamatupidamine-billberry-' . $companyId . '-' . $environment;
                $plainKey = openssl_decrypt(substr($blob, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($blob, 0, 12), substr($blob, 12, 16), $aad);
                if ($plainKey === false && $environment === 'production' && !isset($company['environments']['production'])) {
                    $plainKey = openssl_decrypt(substr($blob, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($blob, 0, 12), substr($blob, 12, 16), 'raamatupidamine-billberry-' . $companyId);
                }
                if ($plainKey === false) throw new RuntimeException('Vana Billberry võtit ei saanud avada.');
            }
            $all[(string) $companyId]['environments'][$environment] = [
                'api_id' => (string) ($config['api_id'] ?? ''),
                'api_key' => $plainKey,
                'updates_link' => (string) ($config['updates_link'] ?? ''),
            ];
        }
    }
    return $all;
}

function billberryWriteAllSettings(array $all): void
{
    $updates = [];
    foreach ($all as $companyId => $company) {
        if (!ctype_digit((string) $companyId) || !is_array($company)) continue;
        foreach (['production', 'development'] as $environment) {
            $config = $company['environments'][$environment] ?? null;
            if (!is_array($config)) continue;
            $prefix = 'APP_BILLBERRY_' . $companyId . '_' . strtoupper($environment) . '_';
            $updates[$prefix . 'API_ID'] = (string) ($config['api_id'] ?? '');
            $updates[$prefix . 'API_KEY'] = (string) ($config['api_key'] ?? '');
            $updates[$prefix . 'UPDATES_LINK'] = (string) ($config['updates_link'] ?? '');
        }
    }
    appEnvSetMany($updates);
}

function billberryEnvironment(string $environment): string
{
    if (!in_array($environment, ['production', 'development'], true)) throw new RuntimeException('Billberry keskkond ei sobi.');
    return $environment;
}

function billberrySettingsForEnvironment(int $companyId, string $environment, bool $withSecret = false): array
{
    $environment = billberryEnvironment($environment);
    $all = billberryReadAllSettings();
    $stored = $all[(string) $companyId] ?? null;
    if (!is_array($stored)) return ['environment' => $environment, 'api_id' => '', 'api_key' => '', 'updates_link' => ''];
    $config = $stored['environments'][$environment] ?? [];
    $result = [
        'environment' => $environment,
        'api_id' => (string) ($config['api_id'] ?? ''),
        'api_key' => $withSecret ? (string) ($config['api_key'] ?? '') : '',
        'updates_link' => (string) ($config['updates_link'] ?? ''),
        'base_url' => $environment === 'development' ? 'https://dev-api.billberry.ee' : 'https://api.billberry.ee',
    ];
    return $result;
}

function billberrySettings(int $companyId, bool $withSecret = false): array
{
    // The live application is now locked to production; development credentials
    // remain stored separately but are no longer selected by application flows.
    return billberrySettingsForEnvironment($companyId, 'production', $withSecret);
}

function billberrySaveSettings(int $companyId, string $environment, string $apiId, string $apiKey): void
{
    $environment = billberryEnvironment($environment);
    $apiId = trim($apiId);
    $apiKey = preg_replace('/\\s+/', '', trim($apiKey));
    if ($companyId < 1 || !preg_match('/^[0-9]{1,20}$/', $apiId)) throw new RuntimeException('Billberry ID peab olema numbriline.');
    if ($apiKey !== '' && !preg_match('/^[a-fA-F0-9]{32}$/', $apiKey)) throw new RuntimeException('Billberry võti peab olema 32 heksadetsimaalmärki.');
    $all = billberryReadAllSettings();
    $companySettings = is_array($all[(string) $companyId] ?? null) ? $all[(string) $companyId] : [];
    if (!isset($companySettings['environments']) || !is_array($companySettings['environments'])) $companySettings['environments'] = [];
    $existing = is_array($companySettings['environments'][$environment] ?? null) ? $companySettings['environments'][$environment] : [];
    if ($apiKey === '') $apiKey = (string) ($existing['api_key'] ?? '');
    if ($apiKey === '') {
        throw new RuntimeException('Sisesta Billberry võti.');
    }
    $existing['api_id'] = $apiId;
    $existing['api_key'] = $apiKey;
    $companySettings['environments'][$environment] = $existing;
    $all[(string) $companyId] = $companySettings;
    billberryWriteAllSettings($all);
}

function billberrySaveUpdatesLink(int $companyId, string $link): void
{
    $all = billberryReadAllSettings();
    if (!isset($all[(string) $companyId]) || !is_array($all[(string) $companyId])) return;
    $environment = 'production';
    if (!isset($all[(string) $companyId]['environments']) || !is_array($all[(string) $companyId]['environments'])) $all[(string) $companyId]['environments'] = [];
    if (!isset($all[(string) $companyId]['environments'][$environment])) $all[(string) $companyId]['environments'][$environment] = [];
    $all[(string) $companyId]['environments'][$environment]['updates_link'] = $link;
    billberryWriteAllSettings($all);
}

function billberryRequest(int $companyId, string $path, string $method = 'GET', ?string $body = null, string $accept = 'application/vnd.billberry.invoice+json; v=1'): array
{
    $settings = billberrySettings($companyId, true);
    if (empty($settings['api_id']) || empty($settings['api_key'])) throw new RuntimeException('Billberry ühenduse seaded puuduvad.');
    if (preg_match('~^https?://~i', $path)) {
        $parts = parse_url($path);
        $expectedHost = parse_url($settings['base_url'], PHP_URL_HOST);
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== $expectedHost || !str_starts_with((string) ($parts['path'] ?? ''), '/invoices/received')) {
            throw new RuntimeException('Billberry tagastatud sünkroonimisviide ei ole turvaline.');
        }
        $url = $path;
    } else {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\r") || str_contains($path, "\n")) throw new RuntimeException('Billberry päringu tee ei sobi.');
        $url = $settings['base_url'] . $path;
    }
    $headers = [
        'Authorization: Basic ' . base64_encode($settings['api_id'] . ':' . $settings['api_key']),
        'Accept: ' . $accept,
        'User-Agent: Raamatupidamine/1.0',
    ];
    if ($body !== null) $headers[] = 'Content-Type: application/xml';
    if ($method === 'POST') $headers[] = 'X-Send: immediately';
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $body ?? '',
        'timeout' => 22,
        'ignore_errors' => true,
        'protocol_version' => 1.1,
    ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $response = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    foreach ($responseHeaders as $header) if (preg_match('~^HTTP/\\S+\\s+(\\d{3})~', $header, $m)) $status = (int) $m[1];
    if ($response === false) throw new RuntimeException('Billberryga ühendus ebaõnnestus. Kontrolli internetiühendust ja PHP HTTPS-i tuge.');
    if ($status < 200 || $status >= 300) {
        $error = '';
        $decoded = json_decode($response, true);
        if (is_array($decoded)) $error = (string) ($decoded['message'] ?? '');
        if ($error === '') $error = trim((string) ($responseHeaders[0] ?? 'Billberry päring ebaõnnestus.'));
        throw new RuntimeException('Billberry vastas veaga ' . $status . ': ' . mb_substr($error, 0, 500));
    }
    $link = '';
    foreach ($responseHeaders as $header) {
        if (stripos($header, 'Link:') === 0 && preg_match('~<([^>]+)>;\\s*rel="updates"~i', $header, $m)) { $link = $m[1]; break; }
    }
    return ['status' => $status, 'body' => $response, 'headers' => $responseHeaders, 'updates_link' => $link, 'environment' => $settings['environment']];
}

function billberryXmlEscape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function billberryMoney(string $value): string
{
    return number_format((float) $value, 2, '.', '');
}

function billberryBankBics(): array
{
    return [
        'EKRDEE22' => 'Coop Pank',
        'LHVBEE22' => 'LHV',
        'RIKOEE22' => 'Luminor',
        'EEUHEE2X' => 'SEB',
        'HABAEE2X' => 'Swedbank',
    ];
}

function billberryNormalizeIban(string $iban): string
{
    return strtoupper((string) preg_replace('/\\s+/', '', trim($iban)));
}

function billberryBuildSalesInvoiceXml(array $company, array $invoice, array $lines): string
{
    if (($invoice['invoice_currency'] ?? 'EUR') !== 'EUR') {
        throw new RuntimeException('Valuutaarve jaoks kasuta arve PDF-i. Billberry XML töövoog toetab euroarveid.');
    }
    if(!empty($invoice['zero_vat_notice']) && $lines) $lines[array_key_first($lines)]['description'].="\n".$invoice['zero_vat_notice'];
    if(!empty($invoice['zero_seller_vat'])) $company['vat_number']=$invoice['zero_seller_vat'];
    foreach (['registry_code' => 'ettevõtte registrikood', 'contact_registry_code' => 'kliendi registrikood', 'bank_account' => 'ettevõtte pangakonto'] as $field => $label) {
        if (trim((string) ($invoice[$field] ?? $company[$field] ?? '')) === '') throw new RuntimeException('E-arve saatmiseks puudub ' . $label . '.');
    }
    $sellerCode = trim((string) $company['registry_code']);
    $buyerCode = trim((string) $invoice['contact_registry_code']);
    $invoiceNo = trim((string) $invoice['invoice_number']);
    $buyerBic = strtoupper(trim((string) ($invoice['bank_einvoice_bic'] ?? '')));
    $buyerBankIban = billberryNormalizeIban((string) ($invoice['bank_einvoice_account'] ?? ''));
    $bankBics = billberryBankBics();
    $bankDelivery = $buyerBic !== '';
    $channelAttributes = '';
    $bankAccountInfo = '';
    $paymentBic = '';
    $sellerAccountNumber = (string) ($company['bank_account'] ?? '');
    if ($bankDelivery) {
        if (!isset($bankBics[$buyerBic])) throw new RuntimeException('Saaja panga BIC ei ole toetatud. Kontrolli kontakti seadeid.');
        if (!preg_match('/^[0-9]{11}$/', $buyerCode) && !preg_match('/^[0-9]{1,20}$/', $buyerCode)) {
            throw new RuntimeException('Panga e-arve saaja registrikood või isikukood puudub või ei sobi.');
        }
        if ($buyerBic !== 'RIKOEE22' && !preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $buyerBankIban)) {
            throw new RuntimeException('Panga e-arve saaja IBAN on puudu või vigase kujuga.');
        }
        $sellerBic = strtoupper(trim((string) ($company['bank_bic'] ?? '')));
        $sellerIban = billberryNormalizeIban((string) ($company['bank_account'] ?? ''));
        if (!isset($bankBics[$sellerBic])) throw new RuntimeException('Ettevõtte panga BIC puudub. Lisa see Seaded → Ettevõtte andmed lehel.');
        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $sellerIban)) throw new RuntimeException('Ettevõtte pangakonto / IBAN puudub või ei sobi panga e-arve saatmiseks.');
        $channelAddress = $buyerBic === 'RIKOEE22' ? $buyerCode : $buyerBankIban;
        $sellerAccountNumber = $sellerIban;
        $channelAttributes = ' channelId="' . billberryXmlEscape($buyerBic) . '" channelAddress="' . billberryXmlEscape($channelAddress) . '"';
        $bankAccountInfo = '<BIC>' . billberryXmlEscape($sellerBic) . '</BIC><BankName>' . billberryXmlEscape($bankBics[$sellerBic]) . '</BankName>';
        $paymentBic = '<PayToBIC>' . billberryXmlEscape($sellerBic) . '</PayToBIC>';
    }
    if (!$lines) throw new RuntimeException('Arvel puuduvad read.');
    if (empty($invoice['due_date'])) throw new RuntimeException('E-arve saatmiseks lisa arvele maksetähtaeg.');
    $total = 0.0; $net = 0.0; $vatGroups = [];
    $lineXml = '';
    foreach ($lines as $line) {
        $rate = number_format((float) $line['vat_rate'], 2, '.', '');
        $net += (float) $line['net_amount'];
        $total += (float) $line['gross_amount'];
        if (!isset($vatGroups[$rate])) $vatGroups[$rate] = 0.0;
        $vatGroups[$rate] += (float) $line['vat_amount'];
        $lineXml .= '<InvoiceItemGroup><ItemEntry><Description>' . billberryXmlEscape((string) $line['description']) . '</Description>'
            . '<ItemDetailInfo><ItemUnit>tk</ItemUnit><ItemAmount>' . billberryXmlEscape((string) $line['quantity']) . '</ItemAmount><ItemPrice>' . billberryMoney((string) $line['unit_price']) . '</ItemPrice></ItemDetailInfo>'
            . '<ItemSum>' . billberryMoney((string) $line['net_amount']) . '</ItemSum>';
        if ((float) $line['vat_rate'] > 0 || (float) $line['vat_amount'] !== 0.0) $lineXml .= '<VAT><VATRate>' . $rate . '</VATRate><VATSum>' . billberryMoney((string) $line['vat_amount']) . '</VATSum></VAT>';
        $lineXml .= '<ItemTotal>' . billberryMoney((string) $line['gross_amount']) . '</ItemTotal></ItemEntry></InvoiceItemGroup>';
    }
    $vatXml = '';
    foreach ($vatGroups as $rate => $amount) $vatXml .= '<VAT><VATRate>' . $rate . '</VATRate><VATSum>' . number_format($amount, 2, '.', '') . '</VATSum></VAT>';
    $fileId = substr(bin2hex(random_bytes(10)), 0, 20);
    $sellerParty = '<SellerParty><Name>' . billberryXmlEscape((string) $company['name']) . '</Name><RegNumber>' . billberryXmlEscape($sellerCode) . '</RegNumber>';
    if (!empty($company['vat_number']) && $company['vat_number'] !== '---') $sellerParty .= '<VATRegNumber>' . billberryXmlEscape((string) $company['vat_number']) . '</VATRegNumber>';
    $sellerParty .= '<ContactData>';
    if (!empty($company['phone'])) $sellerParty .= '<PhoneNumber>' . billberryXmlEscape((string) $company['phone']) . '</PhoneNumber>';
    if (!empty($company['invoice_email'])) $sellerParty .= '<E-mailAddress>' . billberryXmlEscape((string) $company['invoice_email']) . '</E-mailAddress>';
    $bankAccount = billberryXmlEscape($sellerAccountNumber);
    $sellerParty .= '</ContactData><AccountInfo><AccountNumber>' . $bankAccount . '</AccountNumber><IBAN>' . $bankAccount . '</IBAN>' . $bankAccountInfo . '</AccountInfo></SellerParty>';
    $due = (string) $invoice['due_date'];
    // Billberry dev upload validator rejects AppId in Header, so keep the
    // header to the three elements it accepts: Date, FileId and Version.
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<E_Invoice><Header><Date>' . date('Y-m-d') . '</Date><FileId>' . $fileId . '</FileId><Version>1.2</Version></Header>';
    $xml .= '<Invoice invoiceId="' . billberryXmlEscape($invoiceNo) . '" regNumber="' . billberryXmlEscape($buyerCode) . '" sellerRegnumber="' . billberryXmlEscape($sellerCode) . '"' . $channelAttributes . '>';
    $buyerVat=!empty($invoice['contact_vat_number'])?'<VATRegNumber>'.billberryXmlEscape($invoice['contact_vat_number']).'</VATRegNumber>':'';
    $xml .= '<InvoiceParties>' . $sellerParty . '<BuyerParty><Name>' . billberryXmlEscape((string) $invoice['contact_name']) . '</Name><RegNumber>' . billberryXmlEscape($buyerCode) . '</RegNumber>'.$buyerVat.'</BuyerParty></InvoiceParties>';
    $xml .= '<InvoiceInformation><Type type="DEB"/><DocumentName>Arve</DocumentName><InvoiceNumber>' . billberryXmlEscape($invoiceNo) . '</InvoiceNumber><InvoiceDate>' . billberryXmlEscape((string) $invoice['invoice_date']) . '</InvoiceDate><DueDate>' . billberryXmlEscape($due) . '</DueDate></InvoiceInformation>';
    $xml .= '<InvoiceSumGroup><InvoiceSum>' . number_format($net, 2, '.', '') . '</InvoiceSum>' . $vatXml . '<TotalSum>' . number_format($total, 2, '.', '') . '</TotalSum><TotalToPay>' . number_format($total, 2, '.', '') . '</TotalToPay><Currency>EUR</Currency></InvoiceSumGroup>';
    $xml .= '<InvoiceItem>' . $lineXml . '</InvoiceItem>';
    $xml .= '<PaymentInfo><Currency>EUR</Currency><PaymentDescription>Arve ' . billberryXmlEscape($invoiceNo) . '</PaymentDescription><Payable>YES</Payable><PayDueDate>' . billberryXmlEscape($due) . '</PayDueDate><PaymentTotalSum>' . number_format($total, 2, '.', '') . '</PaymentTotalSum><PayerName>' . billberryXmlEscape((string) $invoice['contact_name']) . '</PayerName><PaymentId>' . billberryXmlEscape($invoiceNo) . '</PaymentId><PayToAccount>' . billberryXmlEscape($sellerAccountNumber) . '</PayToAccount><PayToName>' . billberryXmlEscape((string) $company['name']) . '</PayToName>' . $paymentBic . '</PaymentInfo></Invoice>';
    return $xml . '<Footer><TotalNumberInvoices>1</TotalNumberInvoices><TotalAmount>' . number_format($total, 2, '.', '') . '</TotalAmount></Footer></E_Invoice>';
}
