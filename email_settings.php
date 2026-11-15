<?php
declare(strict_types=1);
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('E-posti seadeid saab muuta ainult administraator.');
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/email_lib.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$notice = '';
try {
    $saved = emailLoadSettings(false);
} catch (Throwable $e) {
    $saved = [];
    $errors[] = $e->getMessage();
}
$form = [
    'gmail_address' => (string) ($saved['gmail_address'] ?? ''),
    'sender_name' => (string) ($saved['sender_name'] ?? ''),
    'test_recipient' => (string) ($_SESSION['email'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    }
    $form['gmail_address'] = trim((string) ($_POST['gmail_address'] ?? ''));
    $form['sender_name'] = trim((string) ($_POST['sender_name'] ?? ''));
    $form['test_recipient'] = trim((string) ($_POST['test_recipient'] ?? ''));
    $appPassword = trim((string) ($_POST['app_password'] ?? ''));
    $action = (string) ($_POST['action'] ?? 'save');

    if (!$errors) {
        try {
            emailSaveSettings($form['gmail_address'], $form['sender_name'], $appPassword);
            if ($action === 'save_and_test') {
                if (!filter_var($form['test_recipient'], FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Testkirja saaja e-posti aadress pole korrektne.');
                }
                $safeName = h($form['sender_name']);
                emailSend(
                    $form['test_recipient'],
                    '',
                    'Raamatupidamise programmi testkiri',
                    '<p>Tere!</p><p>See testkiri kinnitab, et programmi Gmaili e-posti saatmine töötab.</p><p>Parimate soovidega<br>' . $safeName . '</p>'
                );
                $notice = 'Gmaili seaded salvestati ja testkiri saadeti aadressile ' . $form['test_recipient'] . '.';
            } else {
                $notice = 'Gmaili saatmise seaded salvestati.';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$hasSavedPassword = false;
try {
    $fullSettings = emailLoadSettings(true);
    $hasSavedPassword = !empty($fullSettings['app_password']);
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>E-posti saatmine</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px 18px; background: #f4f7fb; color: #243447; font-family: Arial, sans-serif; }
        main { max-width: 850px; margin: 0 auto; }
        .card { margin: 18px 0; padding: 24px; border: 1px solid #dce3ec; border-radius: 12px; background: #fff; }
        h1, h2 { color: #17324d; }
        .muted { color: #647589; line-height: 1.55; }
        .fields { display: grid; grid-template-columns: repeat(2, minmax(220px, 1fr)); gap: 16px; }
        label { display: block; font-weight: 600; }
        input { width: 100%; margin-top: 7px; padding: 11px 12px; border: 1px solid #bdc9d6; border-radius: 7px; font: inherit; }
        .button { display: inline-block; min-height: 42px; margin: 12px 8px 0 0; padding: 11px 16px; border: 0; border-radius: 7px; background: #1769aa; color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .secondary { background: #647589; }
        .message { margin: 14px 0; padding: 13px 15px; border-radius: 8px; }
        .error { background: #fff0f0; color: #a12626; border: 1px solid #efb4b4; }
        .success { background: #eaf7ef; color: #176b3a; border: 1px solid #b6dfc2; }
        .tip { margin-top: 16px; padding: 14px; background: #fff8df; border: 1px solid #ead9a2; border-radius: 8px; line-height: 1.55; }
        @media (max-width: 600px) { .fields { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<main>
    <h1>E-posti saatmine</h1>
    <p class="muted">Gmaili SMTP seaded müügiarvete ja palgateatiste saatmiseks.</p>

    <?php foreach ($errors as $error): ?><div class="message error"><?= h($error) ?></div><?php endforeach; ?>
    <?php if ($notice !== ''): ?><div class="message success"><?= h($notice) ?></div><?php endif; ?>

    <section class="card">
        <h2>Gmaili saatja seadistus</h2>
        <p class="muted">Kasutame Gmaili turvalist ühendust (smtp.gmail.com, TLS). Seaded salvestatakse selle programmikoopia enda .env faili, nii et teise koopia muutmine neid ei muuda.</p>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <div class="fields">
                <label>Gmaili aadress
                    <input type="email" name="gmail_address" maxlength="254" required value="<?= h($form['gmail_address']) ?>" autocomplete="username">
                </label>
                <label>Arvetel kuvatav saatja nimi
                    <input type="text" name="sender_name" maxlength="190" required value="<?= h($form['sender_name']) ?>">
                </label>
                <label>Gmaili rakenduse parool
                    <input type="password" name="app_password" maxlength="40" <?= $hasSavedPassword ? '' : 'required' ?> autocomplete="new-password" placeholder="<?= $hasSavedPassword ? 'Salvestatud parooli säilitamiseks jäta tühjaks' : '16 märki, ilma tühikuteta' ?>">
                </label>
                <label>Testkirja saaja
                    <input type="email" name="test_recipient" maxlength="254" required value="<?= h($form['test_recipient']) ?>">
                </label>
            </div>
            <div class="tip"><strong>Turvalisus:</strong> kasuta ainult Gmaili rakenduse parooli, mitte Gmaili tavalist parooli. .env fail sisaldab parooli loetaval kujul; ära jaga seda faili ega lisa seda avalikku varukoopiasse. Failile on lisatud veebist lugemise keeld.</div>
            <button class="button" type="submit" name="action" value="save">Salvesta seaded</button>
            <button class="button secondary" type="submit" name="action" value="save_and_test" onclick="return confirm('Salvestan Gmaili seaded ja saadan testkirja määratud aadressile. Kas jätkan?')">Salvesta ja saada testkiri</button>
        </form>
    </section>

    <p><a class="button secondary" href="settings.php">Tagasi seadetesse</a></p>
</main>
</body>
</html>
