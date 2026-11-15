<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/app_env.php';

function emailPrivateDirectory(): string
{
    // Keep SMTP credentials outside the web document root (htdocs).
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'raamatupidamine_private';
}

function emailPrivatePaths(): array
{
    $directory = emailPrivateDirectory();
    return [
        'directory' => $directory,
        'key' => $directory . DIRECTORY_SEPARATOR . 'mail.key',
        'settings' => $directory . DIRECTORY_SEPARATOR . 'mail.json',
    ];
}

function emailLoadSettings(bool $withPassword = false): array
{
    $env = appEnvRead();
    $hasLocalConfig = array_key_exists('APP_GMAIL_ADDRESS', $env)
        || array_key_exists('APP_GMAIL_SENDER_NAME', $env)
        || array_key_exists('APP_GMAIL_APP_PASSWORD', $env);

    // One-time migration from the older shared private folder into this app copy.
    if (!$hasLocalConfig) {
        $legacy = emailReadLegacySettings();
        if ($legacy !== []) {
            appEnvSetMany([
                'APP_GMAIL_ADDRESS' => (string) ($legacy['gmail_address'] ?? ''),
                'APP_GMAIL_SENDER_NAME' => (string) ($legacy['sender_name'] ?? ''),
                'APP_GMAIL_APP_PASSWORD' => (string) ($legacy['app_password'] ?? ''),
            ]);
            $env = appEnvRead();
        }
    }

    appEnvEnsureFile();
    $settings = [
        'gmail_address' => (string) ($env['APP_GMAIL_ADDRESS'] ?? ''),
        'sender_name' => (string) ($env['APP_GMAIL_SENDER_NAME'] ?? ''),
    ];
    if ($withPassword) $settings['app_password'] = (string) ($env['APP_GMAIL_APP_PASSWORD'] ?? '');
    return $settings;
}

function emailReadLegacySettings(): array
{
    $paths = emailPrivatePaths();
    if (!is_file($paths['settings'])) return [];
    $stored = json_decode((string) file_get_contents($paths['settings']), true);
    if (!is_array($stored)) throw new RuntimeException('Vana e-posti seadete fail on vigane.');
    $password = '';
    if (!empty($stored['password']) && is_file($paths['key'])) {
        $key = file_get_contents($paths['key']);
        $encrypted = base64_decode((string) $stored['password'], true);
        if ($key === false || strlen($key) !== 32 || $encrypted === false || strlen($encrypted) <= 28) {
            throw new RuntimeException('Vanu Gmaili seadeid ei saanud lugeda.');
        }
        $password = openssl_decrypt(substr($encrypted, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($encrypted, 0, 12), substr($encrypted, 12, 16), 'raamatupidamine-gmail-v1');
        if ($password === false) throw new RuntimeException('Vana Gmaili rakenduse parooli ei saanud avada.');
    }
    return [
        'gmail_address' => (string) ($stored['gmail_address'] ?? ''),
        'sender_name' => (string) ($stored['sender_name'] ?? ''),
        'app_password' => $password,
    ];
}

function emailSaveSettings(string $gmailAddress, string $senderName, string $appPassword): void
{
    if (!filter_var($gmailAddress, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Sisesta kehtiv Gmaili aadress.');
    }
    if (mb_strlen($senderName) > 190 || trim($senderName) === '') {
        throw new RuntimeException('Saatja nimi on kohustuslik ja võib olla kuni 190 märki.');
    }

    $current = emailLoadSettings(true);
    $appPassword = preg_replace('/\s+/', '', $appPassword);
    if ($appPassword === '') $appPassword = (string) ($current['app_password'] ?? '');
    if (!preg_match('/^[A-Za-z0-9]{16}$/', $appPassword)) {
        throw new RuntimeException('Sisesta Gmaili 16-märgiline rakenduse parool. Tavaline Gmaili parool ei sobi.');
    }
    appEnvSetMany([
        'APP_GMAIL_ADDRESS' => $gmailAddress,
        'APP_GMAIL_SENDER_NAME' => trim($senderName),
        'APP_GMAIL_APP_PASSWORD' => $appPassword,
    ]);
}

function emailSend(string $to, string $recipientName, string $subject, string $html, ?string $attachmentName = null, ?string $attachmentBytes = null): void
{
    if (!is_file(__DIR__ . '/vendor/autoload.php')) {
        throw new RuntimeException('E-posti teegid puuduvad. Rakenduse paigaldus vajab uuendamist.');
    }
    require_once __DIR__ . '/vendor/autoload.php';

    $settings = emailLoadSettings(true);
    if (empty($settings['gmail_address']) || empty($settings['app_password'])) {
        throw new RuntimeException('Gmaili saatmise seaded puuduvad. Ava Seaded → E-posti saatmine.');
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Saaja e-posti aadress puudub või pole korrektne.');
    }

    $mailer = new PHPMailer(true);
    try {
        $mailer->isSMTP();
        $mailer->Host = 'smtp.gmail.com';
        $mailer->SMTPAuth = true;
        $mailer->Username = $settings['gmail_address'];
        $mailer->Password = $settings['app_password'];
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->Port = 587;
        $mailer->Timeout = 30;
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->setFrom($settings['gmail_address'], $settings['sender_name']);
        $mailer->addAddress($to, $recipientName);
        $mailer->isHTML(true);
        $mailer->Subject = $subject;
        $mailer->Body = $html;
        $mailer->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($attachmentName !== null && $attachmentBytes !== null) {
            $mailer->addStringAttachment($attachmentBytes, $attachmentName, 'base64', 'application/pdf');
        }
        $mailer->send();
    } catch (Throwable $e) {
        error_log('E-kirja saatmine ebaõnnestus: ' . $e->getMessage());
        throw new RuntimeException('E-kirja saatmine Gmaili kaudu ebaõnnestus. Kontrolli Gmaili aadressi, rakenduse parooli ja internetiühendust.');
    }
}

function emailEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function emailMoney(string $amount): string
{
    return number_format((float) $amount, 2, ',', ' ') . ' €';
}
