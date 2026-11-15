<?php
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) { header('Location: login.php'); exit; }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
require_once __DIR__ . '/db.php';

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
$companyId = (int) $_SESSION['company_id'];
$errors = [];
$message = '';
$identifierTypes = [
    'ARIREGISTRIKOOD' => 'Registrikood või isikukood (väljal Registrikood)',
    'ISIKUKOOD' => 'Isikukood (väljal Registrikood)',
    'KMKR_NUMBER' => 'KMKR number',
    'MRR_KOOD' => 'Mitteresidendi registrikood (väljal Registrikood)',
];
$partyCategories = [
    1 => 'Eesti ettevõte või FIE',
    2 => 'Eraisik või välismaa ettevõte',
    3 => 'Käibemaksugrupi liige',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_contact') {
            $contactId = filter_var($_POST['contact_id'] ?? null, FILTER_VALIDATE_INT);
            $identifierType = trim((string) ($_POST['kmd_identifier_type'] ?? ''));
            $partyCategory = filter_var($_POST['kmd_party_category'] ?? null, FILTER_VALIDATE_INT);
            $countryCode = strtoupper(trim((string) ($_POST['country_code'] ?? '')));
            if (!$contactId) $errors[] = 'Kontakti ID on vigane.';
            if (!isset($identifierTypes[$identifierType])) $errors[] = 'Vali tunnuse liik.';
            if (!isset($partyCategories[$partyCategory])) $errors[] = 'Vali osapoole kategooria.';
            if (!preg_match('/^[A-Z]{2}$/', $countryCode)) $errors[] = 'Riigikood peab olema kahetäheline ISO kood, näiteks EE või FI.';
            if (!$errors) {
                $stmt = $pdo->prepare('SELECT registry_code, vat_number FROM contacts WHERE id = ? AND company_id = ?');
                $stmt->execute([$contactId, $companyId]);
                $contact = $stmt->fetch();
                if (!$contact) {
                    $errors[] = 'Kontakti ei leitud.';
                } else {
                    $identifierValue = $identifierType === 'KMKR_NUMBER' ? $contact['vat_number'] : $contact['registry_code'];
                    if (trim((string) $identifierValue) === '') $errors[] = 'Valitud tunnuse väärtus puudub kontakti kaardilt. Täida vastavalt Registrikood või KMKR number.';
                    if (!$errors) {
                        $update = $pdo->prepare('UPDATE contacts SET kmd_identifier_type = ?, kmd_party_category = ?, country_code = ? WHERE id = ? AND company_id = ?');
                        $update->execute([$identifierType, $partyCategory, $countryCode, $contactId, $companyId]);
                        header('Location: kmd_setup.php?message=contact_saved'); exit;
                    }
                }
            }
        } elseif ($action === 'save_account') {
            $accountId = filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT);
            $kmdTypeCode = strtoupper(trim((string) ($_POST['kmd_type_code'] ?? '')));
            if (!$accountId) $errors[] = 'Konto ID on vigane.';
            if (mb_strlen($kmdTypeCode) > 50 || ($kmdTypeCode !== '' && !preg_match('/^[A-Z0-9_]+$/', $kmdTypeCode))) {
                $errors[] = 'KMD liik peab sisaldama ainult suuri ladina tähti, numbreid ja alakriipse.';
            }
            if (!$errors) {
                $update = $pdo->prepare('UPDATE accounts SET kmd_type_code = ? WHERE id = ? AND company_id = ?');
                $update->execute([$kmdTypeCode !== '' ? $kmdTypeCode : null, $accountId, $companyId]);
                if ($update->rowCount() === 0) {
                    $exists = $pdo->prepare('SELECT 1 FROM accounts WHERE id = ? AND company_id = ?');
                    $exists->execute([$accountId, $companyId]);
                    if (!$exists->fetchColumn()) $errors[] = 'Kontot ei leitud.';
                }
                if (!$errors) { header('Location: kmd_setup.php?message=account_saved'); exit; }
            }
        } else {
            $errors[] = 'Tundmatu toiming.';
        }
    }
}

$contactsStmt = $pdo->prepare('SELECT id, name, contact_type, registry_code, vat_number, country_code, kmd_identifier_type, kmd_party_category FROM contacts WHERE company_id = ? AND is_active = 1 ORDER BY name');
$contactsStmt->execute([$companyId]);
$contacts = $contactsStmt->fetchAll();
$accountsStmt = $pdo->prepare('SELECT id, code, name, account_type, kmd_type_code FROM accounts WHERE company_id = ? AND is_active = 1 ORDER BY code');
$accountsStmt->execute([$companyId]);
$accounts = $accountsStmt->fetchAll();
$message = (string) ($_GET['message'] ?? $message);
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>KMD andmete ettevalmistus</title>
<style>
body{max-width:1200px;margin:32px auto;padding:0 20px;background:#f4f7fb;color:#263746;font-family:Arial,sans-serif}h1,h2{color:#17324d}.card{margin:20px 0;padding:20px;background:#fff;border:1px solid #dce3ec;border-radius:12px}.muted{color:#667788}table{width:100%;border-collapse:collapse;margin:14px 0}th,td{padding:10px;border-bottom:1px solid #e5eaf0;text-align:left;vertical-align:top}th{background:#f3f6fa}input,select{box-sizing:border-box;width:100%;min-height:40px;padding:8px;border:1px solid #b8c4d1;border-radius:6px;font:inherit}.save{padding:9px 13px;border:0;border-radius:6px;background:#1769aa;color:#fff;font:inherit;font-weight:600;cursor:pointer}.button{display:inline-block;padding:10px 15px;border-radius:6px;background:#657789;color:#fff;text-decoration:none}.error{padding:10px;background:#fff0f0;color:#a32121;border-radius:6px}.success{padding:10px;background:#eaf7ef;color:#176b3a;border-radius:6px}.scroll{overflow-x:auto}.grid{min-width:850px}.account-grid{min-width:700px}@media(max-width:650px){body{margin:20px auto;padding:0 12px}}
</style>
</head>
<body>
<h1>KMD andmete ettevalmistus</h1>
<p class="card">Arvetel on juba arve number, kuupäev, partner ning ridade käibemaksumäärad ja summad. Siin lisad partneri tunnuse tüübi ja KMD liigi, mida saab hiljem kasutada andmete koostamisel. Koodid tuleb määrata MTA kehtiva juhendi järgi; see leht ei saada andmeid MTA-le.</p>
<?php foreach ($errors as $error): ?><p class="error"><?= h($error) ?></p><?php endforeach; ?>
<?php if ($message === 'contact_saved'): ?><p class="success">Partneri KMD andmed salvestati.</p><?php elseif ($message === 'account_saved'): ?><p class="success">Konto KMD liik salvestati.</p><?php endif; ?>
<section class="card"><h2>Partnerite tunnused</h2><p class="muted">Tunnuse väärtus võetakse kontakti Registrikoodi või KMKR numbri väljast. Riigikood on kahetäheline, näiteks EE või FI.</p><div class="scroll"><table class="grid"><thead><tr><th>Partner</th><th>Registrikood</th><th>KMKR nr</th><th>Tunnuse liik</th><th>Osapoole kategooria</th><th>Riik</th><th></th></tr></thead><tbody>
<?php foreach ($contacts as $contact): $formId = "partner-" . (int) $contact["id"]; ?>
<tr>
<td><?= h($contact["name"]) ?><form method="post" id="<?= h($formId) ?>"><input type="hidden" name="csrf_token" value="<?= h($_SESSION["csrf_token"]) ?>"><input type="hidden" name="action" value="save_contact"><input type="hidden" name="contact_id" value="<?= (int) $contact["id"] ?>"></form></td>
<td><?= h($contact["registry_code"] ?? "") ?></td><td><?= h($contact["vat_number"] ?? "") ?></td>
<td><select form="<?= h($formId) ?>" name="kmd_identifier_type" required><option value="">Vali</option><?php foreach ($identifierTypes as $key => $label): ?><option value="<?= h($key) ?>" <?= $contact["kmd_identifier_type"] === $key ? "selected" : "" ?>><?= h($label) ?></option><?php endforeach; ?></select></td>
<td><select form="<?= h($formId) ?>" name="kmd_party_category" required><option value="">Vali</option><?php foreach ($partyCategories as $key => $label): ?><option value="<?= (int) $key ?>" <?= (int) $contact["kmd_party_category"] === $key ? "selected" : "" ?>><?= h($label) ?></option><?php endforeach; ?></select></td>
<td><input form="<?= h($formId) ?>" name="country_code" maxlength="2" pattern="[A-Za-z]{2}" value="<?= h($contact["country_code"] ?? "EE") ?>" required></td><td><button class="save" form="<?= h($formId) ?>" type="submit">Salvesta</button></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="card"><h2>Kontode KMD liigid</h2><p class="muted">Sisesta MTA taksonoomia KMD liigi kood konto järgi (nt arendaja näidetes M_101). Ära kasuta koodi oletuse põhjal; vali see alles pärast konto käibe liigi kontrollimist.</p><div class="scroll"><table class="account-grid"><thead><tr><th>Konto</th><th>Tüüp</th><th>KMD liigi kood</th><th></th></tr></thead><tbody>
<?php foreach ($accounts as $account): $formId = "account-" . (int) $account["id"]; ?>
<tr><td><?= h($account["code"] . " — " . $account["name"]) ?><form method="post" id="<?= h($formId) ?>"><input type="hidden" name="csrf_token" value="<?= h($_SESSION["csrf_token"]) ?>"><input type="hidden" name="action" value="save_account"><input type="hidden" name="account_id" value="<?= (int) $account["id"] ?>"></form></td><td><?= h($account["account_type"]) ?></td><td><input form="<?= h($formId) ?>" name="kmd_type_code" maxlength="50" pattern="[A-Za-z0-9_]*" value="<?= h($account["kmd_type_code"] ?? "") ?>" placeholder="Näide: M_101"></td><td><button class="save" form="<?= h($formId) ?>" type="submit">Salvesta</button></td></tr><?php endforeach; ?></tbody></table></div></section>
<p><a class="button" href="kmd_inf_preview.php">Tagasi KMD INF eelvaatesse</a> <a class="button" href="index.php">Avalehele</a></p>
</body></html>
