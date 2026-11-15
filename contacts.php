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
require_once __DIR__ . '/eu_reverse_vat.php';
ensureForeignPurchaseContactSchema($pdo);

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$companyId = (int) $_SESSION['company_id'];
$errors = [];
$form = $_POST;

$typeNames = [
    'customer' => 'Klient',
    'supplier' => 'Tarnija',
    'both' => 'Klient ja tarnija',
];
$kmdPartyCategories = [
    '1' => 'Ettevõte või registreeritud osapool',
    '2' => 'Eraisik või registrikoodita osapool',
    '3' => 'Alla 1 000 € osapool ilma tunnuseta',
];
$kmdIdentifierTypes = [
    '' => 'Määra registrikoodi järgi automaatselt',
    'ARIREGISTRIKOOD' => 'Äriregistri kood',
    'ISIKUKOOD' => 'Isikukood',
    'KMKR_NUMBER' => 'Käibemaksukohustuslase number',
    'MRR_KOOD' => 'Mitteresidendi kood',
];
$bankEInvoiceOptions = [
    '' => 'Tavaline e-arve / panga e-arvet ei saadeta',
    'EKRDEE22' => 'Coop Pank',
    'LHVBEE22' => 'LHV',
    'RIKOEE22' => 'Luminor',
    'EEUHEE2X' => 'SEB',
    'HABAEE2X' => 'Swedbank',
];

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
$editingContact = null;

if ($editId) {
    $editStmt = $pdo->prepare(
        'SELECT id, contact_type, name, registry_code, vat_number, email, phone, address,
                bank_einvoice_bic, bank_einvoice_account,
                kmd_identifier_type, kmd_party_category, estonia_vat_number, country_code
         FROM contacts
         WHERE id = ?
           AND company_id = ?'
    );
    $editStmt->execute([$editId, $companyId]);
    $editingContact = $editStmt->fetch(PDO::FETCH_ASSOC);

    if (!$editingContact) {
        $errors[] = 'Muudetavat kontakti ei leitud.';
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $form = $editingContact;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
    ) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    } else {
        $action = $_POST['action'] ?? '';

        $estoniaVatInput = trim((string) ($_POST['estonia_vat_number'] ?? ''));
        $estoniaVat = foreignPurchaseEstonianVat(['estonia_vat_number'=>$estoniaVatInput]);
        $countryCode = isset($_POST['country_code']) ? strtoupper(trim((string) $_POST['country_code'])) : null;
        if (in_array($action, ['add', 'update'], true) && $estoniaVatInput !== '' && $estoniaVat === null) {
            $errors[] = 'Eesti KMKR number peab algama EE-ga ja sisaldama üheksat numbrit.';
        }
        if (in_array($action, ['add', 'update'], true) && $countryCode !== null && !preg_match('/^[A-Z]{2}$/', $countryCode)) {
            $errors[] = 'Sisesta kahetäheline riigikood, näiteks EE või FI.';
        }
        if ($action === 'add') {
            $type = $_POST['contact_type'] ?? '';
            $name = trim($_POST['name'] ?? '');
            $registryCode = trim($_POST['registry_code'] ?? '');
			$vatNumber = trim($_POST['vat_number'] ?? '');
            $kmdIdentifierType = trim($_POST['kmd_identifier_type'] ?? '');
            $kmdPartyCategory = trim($_POST['kmd_party_category'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $bankEInvoiceBic = strtoupper(trim($_POST['bank_einvoice_bic'] ?? ''));
            $bankEInvoiceAccount = strtoupper(preg_replace('/\\s+/', '', trim($_POST['bank_einvoice_account'] ?? '')));

            if (!isset($typeNames[$type])) {
                $errors[] = 'Vali kontakti tüüp.';
            }

            if ($name === '' || mb_strlen($name) > 200) {
                $errors[] = 'Nimi on kohustuslik ja võib olla kuni 200 märki.';
            }

            if (mb_strlen($registryCode) > 20) {
                $errors[] = 'Registrikood võib olla kuni 20 märki.';
            }
			if (mb_strlen($vatNumber) > 30) {
                $errors[] = 'KMKR number võib olla kuni 30 märki.';
			}
            if (!array_key_exists($kmdIdentifierType, $kmdIdentifierTypes)) {
                $errors[] = 'Valitud KMD registritunnuse liik ei ole lubatud.';
            }
            if ($kmdPartyCategory !== '' && !array_key_exists($kmdPartyCategory, $kmdPartyCategories)) {
                $errors[] = 'Valitud KMD osapoole liik ei ole lubatud.';
            }

            if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254)) {
                $errors[] = 'Sisesta kehtiv e-posti aadress.';
            }

            if (mb_strlen($phone) > 50) {
                $errors[] = 'Telefoninumber võib olla kuni 50 märki.';
            }

            if (mb_strlen($address) > 500) {
                $errors[] = 'Aadress võib olla kuni 500 märki.';
            }
            if (!array_key_exists($bankEInvoiceBic, $bankEInvoiceOptions)) {
                $errors[] = 'Vali toetatud panga e-arve pank.';
            }
            if (mb_strlen($bankEInvoiceAccount) > 34) {
                $errors[] = 'Saaja pangakonto võib olla kuni 34 märki.';
            }
            if ($bankEInvoiceBic !== '' && $registryCode === '') {
                $errors[] = 'Panga e-arve jaoks sisesta saaja registrikood või isikukood.';
            }
            if ($bankEInvoiceBic !== '' && $bankEInvoiceBic !== 'RIKOEE22' && !preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $bankEInvoiceAccount)) {
                $errors[] = 'Valitud panga panga-arve jaoks sisesta saaja kehtiva kujuga IBAN.';
            }

            if (!$errors) {
                try {
                    $stmt = $pdo->prepare(
                        'INSERT INTO contacts
                            (company_id, contact_type, name, registry_code, vat_number,
                             email, phone, address, kmd_identifier_type, kmd_party_category,
                             bank_einvoice_bic, bank_einvoice_account, estonia_vat_number, country_code, is_active)
                         VALUES
                            (:company_id, :contact_type, :name, :registry_code, :vat_number,
                             :email, :phone, :address, :kmd_identifier_type, :kmd_party_category,
                             :bank_einvoice_bic, :bank_einvoice_account, :estonia_vat_number, :country_code, 1)'
                    );

                    $stmt->execute([
                        'company_id' => $companyId,
                        'contact_type' => $type,
                        'name' => $name,
                        'registry_code' => $registryCode !== '' ? $registryCode : null,
                        'vat_number' => $vatNumber !== '' ? $vatNumber : null,
                        'estonia_vat_number' => $estoniaVat,
                        'country_code' => $countryCode ?? 'EE',
                        'kmd_identifier_type' => $kmdIdentifierType !== '' ? $kmdIdentifierType : null,
                        'kmd_party_category' => $kmdPartyCategory !== '' ? (int) $kmdPartyCategory : null,
                        'email' => $email !== '' ? $email : null,
                        'phone' => $phone !== '' ? $phone : null,
                        'address' => $address !== '' ? $address : null,
                        'bank_einvoice_bic' => $bankEInvoiceBic !== '' ? $bankEInvoiceBic : null,
                        'bank_einvoice_account' => $bankEInvoiceAccount !== '' ? $bankEInvoiceAccount : null,
                    ]);

                    header('Location: contacts.php?message=added');
                    exit;
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        $errors[] = 'Selle registrikoodiga kontakt on juba olemas.';
                    } else {
                        error_log($e->getMessage());
                        $errors[] = 'Kontakti lisamine ebaõnnestus.';
                    }
                }
            }
                } elseif ($action === 'update') {
            $contactId = filter_var($_POST['contact_id'] ?? null, FILTER_VALIDATE_INT);
            $type = $_POST['contact_type'] ?? '';
            $name = trim($_POST['name'] ?? '');
            $registryCode = trim($_POST['registry_code'] ?? '');
			$vatNumber = trim($_POST['vat_number'] ?? '');
            $kmdIdentifierType = trim($_POST['kmd_identifier_type'] ?? '');
            $kmdPartyCategory = trim($_POST['kmd_party_category'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $bankEInvoiceBic = strtoupper(trim($_POST['bank_einvoice_bic'] ?? ''));
            $bankEInvoiceAccount = strtoupper(preg_replace('/\\s+/', '', trim($_POST['bank_einvoice_account'] ?? '')));

            if (!$contactId) {
                $errors[] = 'Kontakti ID on vigane.';
            }
            if (!isset($typeNames[$type])) {
                $errors[] = 'Vali kontakti tüüp.';
            }
            if ($name === '' || mb_strlen($name) > 200) {
                $errors[] = 'Nimi on kohustuslik ja võib olla kuni 200 märki.';
            }
            if (mb_strlen($registryCode) > 20) {
                $errors[] = 'Registrikood võib olla kuni 20 märki.';
            }
			if (mb_strlen($vatNumber) > 30) {
                $errors[] = 'KMKR number võib olla kuni 30 märki.';
}
            if (!array_key_exists($kmdIdentifierType, $kmdIdentifierTypes)) {
                $errors[] = 'Valitud KMD registritunnuse liik ei ole lubatud.';
            }
            if ($kmdPartyCategory !== '' && !array_key_exists($kmdPartyCategory, $kmdPartyCategories)) {
                $errors[] = 'Valitud KMD osapoole liik ei ole lubatud.';
            }
            if (
                $email !== ''
                && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254)
            ) {
                $errors[] = 'Sisesta kehtiv e-posti aadress.';
            }
            if (mb_strlen($phone) > 50) {
                $errors[] = 'Telefoninumber võib olla kuni 50 märki.';
            }
            if (mb_strlen($address) > 500) {
                $errors[] = 'Aadress võib olla kuni 500 märki.';
            }
            if (!array_key_exists($bankEInvoiceBic, $bankEInvoiceOptions)) {
                $errors[] = 'Vali toetatud panga e-arve pank.';
            }
            if (mb_strlen($bankEInvoiceAccount) > 34) {
                $errors[] = 'Saaja pangakonto võib olla kuni 34 märki.';
            }
            if ($bankEInvoiceBic !== '' && $registryCode === '') {
                $errors[] = 'Panga e-arve jaoks sisesta saaja registrikood või isikukood.';
            }
            if ($bankEInvoiceBic !== '' && $bankEInvoiceBic !== 'RIKOEE22' && !preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $bankEInvoiceAccount)) {
                $errors[] = 'Valitud panga panga-arve jaoks sisesta saaja kehtiva kujuga IBAN.';
            }

            if (!$errors) {
                try {
                    $updateStmt = $pdo->prepare(
                        'UPDATE contacts
                         SET contact_type = ?,
                             name = ?,
                             registry_code = ?,
							 vat_number = ?,
                             estonia_vat_number = ?,
                             country_code = COALESCE(?, country_code),
                             kmd_identifier_type = ?,
                             kmd_party_category = ?,
                             bank_einvoice_bic = ?,
                             bank_einvoice_account = ?,
                             email = ?,
                             phone = ?,
                             address = ?
                         WHERE id = ?
                           AND company_id = ?'
                    );
                    $updateStmt->execute([
                        $type,
                        $name,
                        $registryCode !== '' ? $registryCode : null,
						$vatNumber !== '' ? $vatNumber : null,
                        $estoniaVat,
                        $countryCode,
                        $kmdIdentifierType !== '' ? $kmdIdentifierType : null,
                        $kmdPartyCategory !== '' ? (int) $kmdPartyCategory : null,
                        $bankEInvoiceBic !== '' ? $bankEInvoiceBic : null,
                        $bankEInvoiceAccount !== '' ? $bankEInvoiceAccount : null,
                        $email !== '' ? $email : null,
                        $phone !== '' ? $phone : null,
                        $address !== '' ? $address : null,
                        $contactId,
                        $companyId,
                    ]);

                    header('Location: contacts.php?message=contact_updated');
                    exit;
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        $errors[] = 'Selle registrikoodiga kontakt on juba olemas.';
                    } else {
                        error_log($e->getMessage());
                        $errors[] = 'Kontakti muutmine ebaõnnestus.';
                    }
                }
            }
               } elseif ($action === 'delete') {
            $contactId = filter_var($_POST['contact_id'] ?? null, FILTER_VALIDATE_INT);

            if (!$contactId) {
                $errors[] = 'Kontakti ID on vigane.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $contactStmt = $pdo->prepare(
                        'SELECT id FROM contacts WHERE id = ? AND company_id = ? FOR UPDATE'
                    );
                    $contactStmt->execute([$contactId, $companyId]);

                    if (!$contactStmt->fetchColumn()) {
                        throw new RuntimeException('Kontakti ei leitud.');
                    }

                    $referenceStmt = $pdo->prepare(
                        'SELECT
                            (SELECT COUNT(*) FROM sales_invoices WHERE company_id = ? AND contact_id = ?) AS sales_count,
                            (SELECT COUNT(*) FROM purchase_invoices WHERE company_id = ? AND contact_id = ?) AS purchase_count'
                    );
                    $referenceStmt->execute([$companyId, $contactId, $companyId, $contactId]);
                    $references = $referenceStmt->fetch(PDO::FETCH_ASSOC);

                    if ((int) $references['sales_count'] + (int) $references['purchase_count'] > 0) {
                        throw new RuntimeException(
                            'Kontakti ei saa kustutada, sest sellega on seotud arveid. '
                            . 'Arveajaloo säilitamiseks muuda kontakt passiivseks.'
                        );
                    }

                    $deleteStmt = $pdo->prepare(
                        'DELETE FROM contacts WHERE id = ? AND company_id = ?'
                    );
                    $deleteStmt->execute([$contactId, $companyId]);
                    $pdo->commit();

                    header('Location: contacts.php?message=deleted');
                    exit;
                } catch (RuntimeException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = $e->getMessage();
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log($e->getMessage());
                    $errors[] = 'Kontakti kustutamine ebaõnnestus.';
                }
            }
        } elseif ($action === 'toggle') {
            $contactId = filter_var(
                $_POST['contact_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$contactId) {
                $errors[] = 'Kontakti ID on vigane.';
            } else {
                $toggleStmt = $pdo->prepare(
                    'UPDATE contacts
                     SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
                     WHERE id = ?
                       AND company_id = ?'
                );
                $toggleStmt->execute([$contactId, $companyId]);

                header('Location: contacts.php?message=updated');
                exit;
            }
        }
    }
}

$listStmt = $pdo->prepare(
    'SELECT id, contact_type, name, registry_code, vat_number, email, phone, address, is_active
     FROM contacts
     WHERE company_id = :company_id
     ORDER BY name'
);
$listStmt->execute(['company_id' => $companyId]);
$contacts = $listStmt->fetchAll();

$message = $_GET['message'] ?? '';
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kliendid ja tarnijad</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 32px; }
        label { display: block; margin: 12px 0; }
        input, select, textarea { padding: 7px; width: 320px; max-width: 100%; }
        textarea { height: 70px; }
        table { border-collapse: collapse; margin-top: 20px; width: 100%; }
        th, td { padding: 8px 12px; border: 1px solid #ccc; text-align: left; }
        .error { color: #b00020; background: #fdecec; border: 1px solid #e5a1a1; padding: 10px 12px; border-radius: 5px; }
        .success { color: #176b32; }
        .button { display: inline-block; padding: 9px 14px; background: #1769aa; color: white; text-decoration: none; border: 0; border-radius: 6px; cursor: pointer; }
        .inactive { color: #777; }
        .contact-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .contact-action-form { display: inline; margin: 0; }
        .button.button-danger { background: #b42318; }
        .button.button-danger:hover { background: #842018; }
        .button.button-secondary { background: #64748b; }
		<style>
    body {
        max-width: 1200px;
        margin: 36px auto;
        padding: 0 24px;
        background: #f4f7fb;
        color: #243447;
        font-family: Arial, sans-serif;
    }

    h1, h2 { color: #17324d; }

    .contact-card {
        max-width: 850px;
        margin: 20px 0 32px;
        padding: 22px 24px;
        background: #f7f9fc;
        border: 1px solid #dce3ec;
        border-radius: 12px;
    }

    .contact-card h2 { margin-top: 0; }

    .contact-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(220px, 1fr));
        gap: 15px 18px;
    }

    .contact-field label {
        display: block;
        margin-bottom: 7px;
        color: #33475b;
        font-weight: 600;
    }

    .contact-field input,
    .contact-field select,
    .contact-field textarea {
        box-sizing: border-box;
        width: 100%;
        padding: 10px 12px;
        border: 1px solid #b8c4d1;
        border-radius: 7px;
        background: white;
        font: inherit;
    }

    .contact-field textarea {
        min-height: 78px;
        resize: vertical;
    }

    .contact-field.full-width { grid-column: 1 / -1; }

    .button {
        display: inline-block;
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

    .button:hover { background: #0f4f83; }

    table {
        display: block;
        width: 100%;
        margin: 16px 0 22px;
        overflow-x: auto;
        border-collapse: collapse;
        background: white;
        border: 1px solid #dce3ec;
        border-radius: 10px;
    }

    th, td {
        padding: 11px 13px;
        border-bottom: 1px solid #e7edf3;
        text-align: left;
        white-space: nowrap;
    }

    thead th {
        background: #eaf0f6;
        color: #17324d;
    }

    tbody tr:nth-child(even) { background: #f8fafc; }
    tbody tr:hover { background: #eef5fb; }

    .error {
        color: #b00020;
        background: #fdecec;
        border: 1px solid #e5a1a1;
        padding: 10px 12px;
        border-radius: 5px;
    }

    .success { color: #176b32; }
    .inactive { color: #777; }

    @media (max-width: 650px) {
        body { margin: 22px auto; padding: 0 14px; }
        .contact-grid { grid-template-columns: 1fr; }
        .contact-field.full-width { grid-column: auto; }
    }
</style>
    </style>
</head>
<body>
    <h1>Kliendid ja tarnijad</h1>
	<p>
    <a class="button" href="customer_statement.php">
        Kliendi konto väljavõte
    </a>
</p>

    <?php foreach ($errors as $error): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endforeach; ?>

    <?php if ($message === 'added'): ?>
        <p class="success">Kontakt lisati.</p>
    <?php elseif ($message === 'updated'): ?>
        <p class="success">Kontakti olekut muudeti.</p>
		    <?php elseif ($message === 'contact_updated'): ?>
        <p class="success">Kontakti andmed salvestati.</p>
    <?php elseif ($message === 'deleted'): ?>
        <p class="success">Kontakt kustutati.</p>
    <?php endif; ?>

        <?php if ($editingContact): ?>
        <section class="contact-card">
            <h2>Muuda kontakti</h2>

            <form method="post" action="contacts.php?edit=<?= (int) $editingContact['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="contact_id" value="<?= (int) $editingContact['id'] ?>">

                <div class="contact-grid">
                    <div class="contact-field">
                        <label for="edit-contact-type">Tüüp</label>
                        <select id="edit-contact-type" name="contact_type" required>
                            <?php foreach ($typeNames as $type => $typeName): ?>
                                <option
                                    value="<?= h($type) ?>"
                                    <?= ($form['contact_type'] ?? '') === $type ? 'selected' : '' ?>
                                >
                                    <?= h($typeName) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="contact-field">
                        <label for="edit-contact-name">Nimi</label>
                        <input
                            id="edit-contact-name"
                            type="text"
                            name="name"
                            maxlength="200"
                            value="<?= h($form['name'] ?? '') ?>"
                            required
                        >
                    </div>

                    <div class="contact-field">
                        <label for="edit-registry-code">Registrikood</label>
                        <input
                            id="edit-registry-code"
                            type="text"
                            name="registry_code"
                            maxlength="20"
                            value="<?= h($form['registry_code'] ?? '') ?>"
                        >
                    </div>
                    <div class="contact-field">
                        <label for="edit-country-code">Riigikood</label>
                        <input id="edit-country-code" name="country_code" maxlength="2" pattern="[A-Za-z]{2}" required
                               value="<?= h($form['country_code'] ?? 'EE') ?>">
                    </div>
                    <div class="contact-field">
                    <label for="edit-vat-number">KMKR number</label>
                 <input
                     id="edit-vat-number"
                     type="text"
                     name="vat_number"
                     maxlength="30"
                     value="<?= h($form['vat_number'] ?? '') ?>"
                   >
                    </div>
                    <div class="contact-field">
                        <label for="edit-estonia-vat-number">Välistarnija Eesti KMKR number</label>
                        <input id="edit-estonia-vat-number" name="estonia_vat_number" maxlength="11" value="<?= h($form['estonia_vat_number'] ?? '') ?>">
                        <small>Täida ainult Eesti KMKR numbri alusel väljastatud osturidade jaoks.</small>
                    </div>
                    <div class="contact-field">
                        <label for="edit-kmd-party-category">KMD osapoole liik</label>
                        <select id="edit-kmd-party-category" name="kmd_party_category">
                            <option value="">Vali automaatselt registrikoodi järgi</option>
                            <?php foreach ($kmdPartyCategories as $category => $categoryName): ?>
                                <option value="<?= h($category) ?>" <?= (string) ($form['kmd_party_category'] ?? '') === $category ? 'selected' : '' ?>><?= h($categoryName) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="contact-field">
                        <label for="edit-kmd-identifier-type">KMD tunnuse liik</label>
                        <select id="edit-kmd-identifier-type" name="kmd_identifier_type">
                            <?php foreach ($kmdIdentifierTypes as $identifierType => $identifierLabel): ?>
                                <option value="<?= h($identifierType) ?>" <?= (string) ($form['kmd_identifier_type'] ?? '') === $identifierType ? 'selected' : '' ?>><?= h($identifierLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="contact-field">
                        <label for="edit-bank-einvoice-bic">Panga e-arve saaja pank</label>
                        <select id="edit-bank-einvoice-bic" name="bank_einvoice_bic">
                            <?php foreach ($bankEInvoiceOptions as $bic => $bankLabel): ?>
                                <option value="<?= h($bic) ?>" <?= (string) ($form['bank_einvoice_bic'] ?? '') === $bic ? 'selected' : '' ?>><?= h($bankLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="contact-field">
                        <label for="edit-bank-einvoice-account">Saaja IBAN</label>
                        <input
                            id="edit-bank-einvoice-account"
                            type="text"
                            name="bank_einvoice_account"
                            maxlength="34"
                            value="<?= h($form['bank_einvoice_account'] ?? '') ?>"
                            placeholder="Luminori puhul jäta tühjaks"
                        >
                    </div>
                    <div class="contact-field">
                        <label for="edit-contact-email">E-post</label>
                        <input
                            id="edit-contact-email"
                            type="email"
                            name="email"
                            maxlength="254"
                            value="<?= h($form['email'] ?? '') ?>"
                        >
                    </div>

                    <div class="contact-field">
                        <label for="edit-contact-phone">Telefon</label>
                        <input
                            id="edit-contact-phone"
                            type="text"
                            name="phone"
                            maxlength="50"
                            value="<?= h($form['phone'] ?? '') ?>"
                        >
                    </div>

                    <div class="contact-field full-width">
                        <label for="edit-contact-address">Aadress</label>
                        <textarea
                            id="edit-contact-address"
                            name="address"
                            maxlength="500"
                        ><?= h($form['address'] ?? '') ?></textarea>
                    </div>
                </div>

                <button class="button" type="submit">Salvesta muudatused</button>
                <a class="button" href="contacts.php">Katkesta</a>
            </form>
        </section>
    <?php endif; ?>

    <section class="contact-card">
    <h2>Lisa kontakt</h2>


                       
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="add">

        <div class="contact-grid">
            <div class="contact-field">
                <label for="contact-type">Tüüp</label>
                <select id="contact-type" name="contact_type" required>
                    <option value="">Vali tüüp</option>
                    <?php foreach ($typeNames as $type => $typeName): ?>
                        <option value="<?= h($type) ?>"
                            <?= ($form['contact_type'] ?? '') === $type ? 'selected' : '' ?>>
                            <?= h($typeName) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="contact-field">
                <label for="contact-name">Nimi</label>
                <input id="contact-name" type="text" name="name" maxlength="200"
                       value="<?= h($form['name'] ?? '') ?>" required>
            </div>

            <div class="contact-field">
                <label for="registry-code">Registrikood / isikukood</label>
                <input id="registry-code" type="text" name="registry_code" maxlength="20"
                       value="<?= h($form['registry_code'] ?? '') ?>">
            </div>
            <div class="contact-field">
                <label for="country-code">Riigikood</label>
                <input id="country-code" name="country_code" maxlength="2" pattern="[A-Za-z]{2}" required
                       value="<?= h($form['country_code'] ?? 'EE') ?>">
            </div>
            <div class="contact-field">
                <label for="vat-number">KMKR number</label>
                <input
                 id="vat-number"
                 type="text"
                  name="vat_number"
                 maxlength="30"
                value="<?= h($form['vat_number'] ?? '') ?>"
               >
            </div>
            <div class="contact-field">
                <label for="estonia-vat-number">Välistarnija Eesti KMKR number</label>
                <input id="estonia-vat-number" name="estonia_vat_number" maxlength="11" value="<?= h($form['estonia_vat_number'] ?? '') ?>">
                <small>Täida ainult Eesti KMKR numbri alusel väljastatud osturidade jaoks.</small>
            </div>
            <div class="contact-field">
                <label for="kmd-party-category">KMD osapoole liik</label>
                <select id="kmd-party-category" name="kmd_party_category">
                    <option value="">Vali automaatselt registrikoodi järgi</option>
                    <?php foreach ($kmdPartyCategories as $category => $categoryName): ?>
                        <option value="<?= h($category) ?>" <?= (string) ($form['kmd_party_category'] ?? '') === $category ? 'selected' : '' ?>><?= h($categoryName) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="contact-field">
                <label for="kmd-identifier-type">KMD tunnuse liik</label>
                <select id="kmd-identifier-type" name="kmd_identifier_type">
                    <?php foreach ($kmdIdentifierTypes as $identifierType => $identifierLabel): ?>
                        <option value="<?= h($identifierType) ?>" <?= (string) ($form['kmd_identifier_type'] ?? '') === $identifierType ? 'selected' : '' ?>><?= h($identifierLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="contact-field">
                <label for="contact-email">E-post</label>
                <input id="contact-email" type="email" name="email" maxlength="254"
                       value="<?= h($form['email'] ?? '') ?>">
            </div>

            <div class="contact-field">
                <label for="bank-einvoice-bic">Panga e-arve saaja pank</label>
                <select id="bank-einvoice-bic" name="bank_einvoice_bic">
                    <?php foreach ($bankEInvoiceOptions as $bic => $bankLabel): ?>
                        <option value="<?= h($bic) ?>" <?= (string) ($form['bank_einvoice_bic'] ?? '') === $bic ? 'selected' : '' ?>><?= h($bankLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="contact-field">
                <label for="bank-einvoice-account">Saaja IBAN</label>
                <input id="bank-einvoice-account" type="text" name="bank_einvoice_account" maxlength="34"
                       value="<?= h($form['bank_einvoice_account'] ?? '') ?>"
                       placeholder="Luminori puhul jäta tühjaks">
            </div>

            <div class="contact-field">
                <label for="contact-phone">Telefon</label>
                <input id="contact-phone" type="text" name="phone" maxlength="50"
                       value="<?= h($form['phone'] ?? '') ?>">
            </div>

            <div class="contact-field full-width">
                <label for="contact-address">Aadress</label>
                <textarea id="contact-address" name="address" maxlength="500"><?= h($form['address'] ?? '') ?></textarea>
            </div>
        </div>

        <p><button class="button" type="submit">Lisa kontakt</button></p>
    </form>
</section>

    <h2>Kontaktid</h2>

    <table>
        <thead>
            <tr>
                <th>Tüüp</th>
                <th>Nimi</th>
                <th>Registrikood</th>
				<th>KMKR nr</th>
                <th>E-post</th>
                <th>Telefon</th>
                <th>Aadress</th>
                <th>Olek</th>
				
                <th>Toiming</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($contacts as $contact): ?>
                <tr class="<?= (int) $contact['is_active'] ? '' : 'inactive' ?>">
                    <td><?= h($typeNames[$contact['contact_type']] ?? $contact['contact_type']) ?></td>
                    <td><?= h($contact['name']) ?></td>
                    <td><?= h($contact['registry_code'] ?? '') ?></td>
					<td><?= h($contact['vat_number'] ?? '') ?></td>
                    <td><?= h($contact['email'] ?? '') ?></td>
                    <td><?= h($contact['phone'] ?? '') ?></td>
                    <td><?= h($contact['address'] ?? '') ?></td>
                    <td><?= (int) $contact['is_active'] ? 'Aktiivne' : 'Passiivne' ?></td>
                    <td>
                        <div class="contact-actions">
                            <a class="button" href="contacts.php?edit=<?= (int) $contact['id'] ?>">Muuda</a>
                            <form class="contact-action-form" method="post" onsubmit="return confirm('Kas kustutad selle kontakti? Kui sellega on arveid seotud, kustutada ei saa.');">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="contact_id" value="<?= (int) $contact['id'] ?>">
                                <button class="button button-danger" type="submit">Kustuta</button>
                            </form>
                            <form class="contact-action-form" method="post">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="contact_id" value="<?= (int) $contact['id'] ?>">
                                <button class="button button-secondary" type="submit">
                                    <?= (int) $contact['is_active'] ? 'Muuda passiivseks' : 'Muuda aktiivseks' ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p><a class="button" href="index.php">Tagasi avalehele</a></p>
</body>
</html>
