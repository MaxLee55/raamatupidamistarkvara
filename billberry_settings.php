<?php
declare(strict_types=1);
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) { header('Location: login.php'); exit; }
if (($_SESSION['role'] ?? '') !== 'admin') { http_response_code(403); exit('Billberry seadeid saab muuta ainult administraator.'); }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
require_once __DIR__ . '/billberry_lib.php';
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
$companyId = (int) $_SESSION['company_id']; $error = ''; $notice = '';
$environment = 'production';
try { $saved = billberrySettingsForEnvironment($companyId, $environment); } catch (Throwable $e) { $saved = []; $error = $e->getMessage(); }
$apiId = (string) ($saved['api_id'] ?? '');
$hasKey = false;
try { $hasKey = !empty(billberrySettingsForEnvironment($companyId, $environment, true)['api_key']); } catch (Throwable $e) { $error = $e->getMessage(); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) $_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) $error = 'Vorm aegus. Värskenda lehte.';
    $action = (string) ($_POST['action'] ?? 'save');
    $environment = 'production';
    $apiId = trim((string) ($_POST['api_id'] ?? ''));
    $apiKey = trim((string) ($_POST['api_key'] ?? ''));
    if ($error === '') {
        try {
            billberrySaveSettings($companyId, $environment, $apiId, $apiKey);
            $hasKey = !empty(billberrySettingsForEnvironment($companyId, $environment, true)['api_key']);
            if ($action === 'test') {
                $result = billberryRequest($companyId, '/invoices/received');
                $decoded = json_decode($result['body'], true);
                if (!is_array($decoded)) throw new RuntimeException('Billberry vastas päringule, kuid arvete loendit ei saanud lugeda.');
                $notice = 'Päriskeskkonna ühendus toimib. Billberry tagastas ' . count($decoded) . ' saabunud arvet.';
            } else $notice = 'Päriskeskkonna seaded salvestati.';
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}
?>
<!doctype html><html lang="et"><head>
    <link rel="icon" type="image/png" href="favicon.png?v=1"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Billberry e-arved</title><style>
*{box-sizing:border-box}body{margin:0;padding:32px 18px;background:#f4f7fb;color:#243447;font-family:Arial,sans-serif}main{max-width:820px;margin:auto}h1,h2{color:#17324d}.card{margin:18px 0;padding:24px;background:#fff;border:1px solid #dce3ec;border-radius:12px}label{display:block;margin:14px 0;font-weight:600}input{display:block;width:100%;margin-top:7px;padding:11px 12px;border:1px solid #bdc9d6;border-radius:7px;font:inherit}.button{display:inline-block;margin:10px 8px 0 0;padding:11px 16px;border:0;border-radius:7px;background:#1769aa;color:white;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}.secondary{background:#647589}.muted{color:#647589;line-height:1.55}.message{padding:13px 15px;border-radius:8px;margin:14px 0}.error{background:#fff0f0;border:1px solid #efb4b4;color:#a12626}.success{background:#eaf7ef;border:1px solid #b6dfc2;color:#176b3a}.tip{padding:14px;background:#fff8df;border:1px solid #ead9a2;border-radius:8px;line-height:1.55}</style></head><body><main>
<h1>Billberry e-arved</h1><p class="muted">Siin seadistatakse Billberry päriskeskkonna ühendus. Tunnused salvestatakse selle programmikoopia enda .env faili. Arve saadetakse päriselt alles siis, kui avad eelvaate ja vajutad saatmisnuppu.</p>
<?php if ($error !== ''): ?><div class="message error"><?= h($error) ?></div><?php endif; ?><?php if ($notice !== ''): ?><div class="message success"><?= h($notice) ?></div><?php endif; ?>
<section class="card"><h2>Ühenduse seaded</h2>
<div class="tip"><strong>Päriskeskkond (api.billberry.ee).</strong> Saatmine edastab arve päriselt saajale. Kasuta Billberry päriskeskkonna API ID-d ja võtit; testkeskkonna tunnused siin ei tööta.</div>
<form method="post" autocomplete="off"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="environment" value="production"><label>Billberry ID<input name="api_id" inputmode="numeric" required maxlength="20" value="<?= h($apiId) ?>"></label><label>Billberry võti<input type="password" name="api_key" maxlength="64" autocomplete="new-password" <?= $hasKey ? '' : 'required' ?> placeholder="<?= $hasKey ? 'Salvestatud võtme säilitamiseks jäta tühjaks' : 'Sisesta päriskeskkonna API võti' ?>"></label><div class="tip">Võti on selle programmi .env failis loetaval kujul. Ära jaga seda faili ega lisa seda avalikku varukoopiasse. Ära saada võtit vestlusesse ega kirjuta seda arvele. E-arvete tabelid peavad olema andmebaasi imporditud: <a href="billberry_invoice_tables.sql">billberry_invoice_tables.sql</a>.</div><button class="button" name="action" value="save">Salvesta seaded</button><button class="button secondary" name="action" value="test">Salvesta ja kontrolli pärisühendust</button></form></section>
<p><a class="button secondary" href="settings.php">Tagasi seadetesse</a> <a class="button secondary" href="index.php">Avalehele</a></p></main></body></html>
