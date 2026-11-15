<?php
declare(strict_types=1);
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) { header('Location: login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
require_once __DIR__ . '/db.php';
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }

$companyId = (int) $_SESSION['company_id'];
$categories = ['micro'=>'Mikroettevõtja','small'=>'Väikeettevõtja','medium'=>'Keskmise suurusega ettevõtja','large'=>'Suurettevõtja'];
$errors = [];
$saved = false;
$stmt = $pdo->prepare('SELECT report_category, income_statement_scheme FROM annual_report_settings WHERE company_id = ?');
$stmt->execute([$companyId]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['report_category'=>'small','income_statement_scheme'=>2];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) $_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    } else {
        $category = (string) ($_POST['report_category'] ?? '');
        $scheme = filter_var($_POST['income_statement_scheme'] ?? null, FILTER_VALIDATE_INT);
        if (!isset($categories[$category])) $errors[] = 'Vali aruandekategooria.';
        if (!in_array($scheme, [1, 2], true)) $errors[] = 'Vali kasumiaruande skeem.';
        if (!$errors) {
            $save = $pdo->prepare(
                'INSERT INTO annual_report_settings (company_id, report_category, income_statement_scheme)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE report_category = VALUES(report_category), income_statement_scheme = VALUES(income_statement_scheme), updated_at = CURRENT_TIMESTAMP'
            );
            $save->execute([$companyId, $category, $scheme]);
            $settings = ['report_category'=>$category,'income_statement_scheme'=>$scheme];
            $saved = true;
        }
    }
}

$scheme = (int) $settings['income_statement_scheme'];
?>
<!doctype html><html lang="et"><head>
    <link rel="icon" type="image/png" href="favicon.png?v=1"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Aruandeprofiil</title><style>*{box-sizing:border-box}body{max-width:900px;margin:32px auto;padding:0 18px;background:#f4f7fb;color:#263746;font-family:Arial,sans-serif}h1,h2{color:#17324d}.card{margin:16px 0;padding:20px;background:#fff;border:1px solid #dce3ec;border-radius:12px;box-shadow:0 4px 14px rgba(25,45,70,.04)}.topbar,.actions{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.muted{color:#667788;line-height:1.5}.button,button{display:inline-block;min-height:40px;padding:10px 14px;border:0;border-radius:7px;background:#1769aa;color:#fff;font:inherit;font-weight:600;text-decoration:none;cursor:pointer}.secondary{background:#657789}label{display:grid;gap:7px;margin:16px 0;font-weight:600}select{min-height:44px;padding:9px;border:1px solid #b8c4d1;border-radius:7px;background:#fff;font:inherit}.note,.success,.error{padding:13px 15px;border-radius:8px;line-height:1.5}.note{background:#eaf3fb;color:#17324d}.success{background:#eaf7ef;color:#176b3a;border:1px solid #b8dfc7}.error{background:#fff0f0;color:#9b2525;border:1px solid #edc4c4}</style></head><body>
<header class="topbar"><div><h1>Aruandeprofiil</h1><p class="muted">Vali ettevõtte aastaaruande koostamisel kasutatav kategooria ja kasumiaruande skeem.</p></div><div class="actions"><a class="button secondary" href="annual_report_prep.php">Tagasi aruande ettevalmistusse</a></div></header>
<?php if ($saved): ?><p class="success">Aruandeprofiil salvestati. Kontode seoseid ei muudetud.</p><?php endif; ?><?php foreach ($errors as $error): ?><p class="error"><?= h($error) ?></p><?php endforeach; ?>
<form method="post" class="card"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><label>Aruandekategooria<select name="report_category" required><?php foreach ($categories as $key=>$label): ?><option value="<?= h($key) ?>" <?= $settings['report_category']===$key?'selected':'' ?>><?= h($label) ?></option><?php endforeach; ?></select></label><label>Kasumiaruanne<select name="income_statement_scheme" required><option value="1" <?= $scheme===1?'selected':'' ?>>Skeem 1 · kulud liikide järgi</option><option value="2" <?= $scheme===2?'selected':'' ?>>Skeem 2 · kulud funktsioonide järgi</option></select></label><p class="note"><strong>Praegune valik:</strong> <?= h($categories[(string)$settings['report_category']] ?? '') ?> · kasumiaruande skeem <?= $scheme ?>. Skeemi vahetamine jätab teise skeemi kontoseosed alles; need seosed hallatakse eraldi.</p><p class="muted">Aruandekategooria ja nõutavad lisad tuleb lõplikult kontrollida e-äriregistri vormivalikus. See valik salvestab programmi tööprofiili ega esita aruannet.</p><button type="submit">Salvesta aruandeprofiil</button></form>
<section class="card"><h2>Aruandeprofiili valikuinfo</h2><p class="muted">RIK-i kategooria määramisel lähtutakse vastava aruandeperioodi kehtivatest nõuetest. Mikroettevõtja tingimused muutusid 2024. aastal algavate perioodide jaoks. Kasumiaruande skeem 2 rühmitab kulud funktsiooni järgi, mistõttu võib sama kulu olla vaja jaotada näiteks müüdud toodangu kuluks, turustuskuluks või üldhalduskuluks.</p><a href="https://abiinfo.rik.ee/majandusaasta-aruannete-esitamine/aruande-kategooria" target="_blank" rel="noopener">RIK-i aruandekategooriate juhend</a> · <a href="https://abiinfo.rik.ee/index.php/majandusaasta-aruannete-esitamine/5-aruande-koostamine/53-aruande-vormide-valimine" target="_blank" rel="noopener">RIK-i vormide valiku juhend</a></section></body></html>
