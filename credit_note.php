<?php
session_start();
if(empty($_SESSION['user_id'])||empty($_SESSION['company_id'])) { header('Location: login.php'); exit; }
require_once __DIR__.'/db.php';
require_once __DIR__.'/tax_code_lib.php';
require_once __DIR__.'/kmd_inf_confirmation.php';
require_once __DIR__.'/credit_note_lib.php';
require_once __DIR__.'/period_lock.php';
ensureTaxCodeSchema($pdo); ensureInfConfirmationSchema($pdo); ensureCreditNoteSchema($pdo);
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
function h(string $v): string { return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
$companyId=(int)$_SESSION['company_id']; $error='';
$kind=(string)($_GET['kind']??''); $id=filter_var($_GET['invoice_id']??null,FILTER_VALIDATE_INT);
try { [$table,$lines,$column,$back]=creditTables($kind); } catch(Throwable $e) { http_response_code(400); exit('Vigane arve liik.'); }
$stmt=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=? AND company_id=?');
$stmt->execute([(int)$id,$companyId]); $original=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$original || $original['status']!=='posted') { http_response_code(404); exit('Algset kinnitatud arvet ei leitud.'); }
$stmt=$pdo->prepare('SELECT id,description,net_amount,vat_rate,product_id FROM '.$lines.' WHERE invoice_id=? ORDER BY id');
$stmt->execute([$id]); $creditLines=$stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt=$pdo->prepare("SELECT x.original_line_id,-SUM(t.net_amount) AS credited FROM credit_note_line_links x JOIN ".$table." c ON c.id=x.credit_invoice_id AND c.company_id=x.company_id JOIN ".$lines." t ON t.id=x.credit_line_id AND t.invoice_id=c.id WHERE x.company_id=? AND x.invoice_kind=? AND c.status='posted' GROUP BY x.original_line_id");
$stmt->execute([$companyId,$kind]); $past=$stmt->fetchAll(PDO::FETCH_KEY_PAIR); $remaining=0;
foreach($creditLines as &$line) { $line['remaining']=creditCents($line['net_amount'])-creditCents((string)($past[$line['id']]??'0')); $remaining+=$line['remaining']; } unset($line);
$assets=[];
if($kind==='purchase') {
    $stmt=$pdo->prepare("SELECT id,asset_number,name FROM fixed_assets WHERE company_id=? AND source_document=? AND status IN ('draft','active') ORDER BY asset_number");
    $stmt->execute([$companyId,$original[$column]]); $assets=$stmt->fetchAll(PDO::FETCH_ASSOC);
}
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if(!hash_equals($_SESSION['csrf_token'],(string)($_POST['csrf_token']??''))) throw new RuntimeException('Vorm aegus, värskenda lehte.');
        $creditId=createCreditNote($pdo,$companyId,(int)$_SESSION['user_id'],$kind,(int)$id,
            trim((string)($_POST['number']??'')),(string)($_POST['date']??''),10000,trim((string)($_POST['reason']??'')),is_array($_POST['line_net']??null)?$_POST['line_net']:[],is_array($_POST['asset_id']??null)?$_POST['asset_id']:[]);
        header('Location: '.$back.'?id='.$creditId); exit;
    } catch(PDOException $e) { $error='Kreeditarvet ei salvestatud. Kontrolli, et arve number ei oleks juba kasutusel.'; }
      catch(Throwable $e) { $error=$e->getMessage(); }
}
?>
<!doctype html><html lang="et"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kreeditarve</title><style>body{font:16px Arial;background:#f4f7fb;color:#263746;max-width:850px;margin:36px auto;padding:0 20px}.card{background:white;border:1px solid #dce3ec;border-radius:12px;padding:24px}label{display:grid;gap:7px;margin:18px 0}input,textarea{font:inherit;padding:10px;border:1px solid #b8c4d1;border-radius:7px}button,.button{display:inline-block;padding:11px 16px;border:0;border-radius:7px;background:#1769aa;color:white;font:inherit;text-decoration:none;cursor:pointer}.error{background:#fff0f0;color:#9b2020;padding:14px}.small{font-size:14px;line-height:1.5;color:#526579}</style></head>
<body><main class="card"><h1>Koosta kreeditarve</h1>
<p>Algne <?= $kind==='sales'?'müügiarve':'ostuarve' ?>: <strong><?= h($original[$column]) ?></strong>, <?= h($original['invoice_date']) ?>.</p>
<p>Veel krediteeritav netosumma: <strong><?= h(creditDecimal(max(0,$remaining))) ?> €</strong>.</p>
<p class="small">Sisesta krediteeritav netosumma iga soovitud rea juurde. Teistele ridadele jäta 0. Käibemaks, pöördmaks ja mahaarvamise osakaal võetakse algselt arvelt; viimane kreedit taastab ka ümardussendid.
Laokauba krediteerimine tähendab siin kauba füüsilist tagastamist algsesse lattu; kogus väheneb netosummaga samas proportsioonis. Pelgalt laokauba hinnaalanduseks seda vormi ära kasuta.
Algne arve jääb alles. Kinnitus loob kreeditarve, paranduskande, mahaarvamata käibemaksu vastukande ning vajadusel laotagastuse. Põhivara soetusmaksumuse parandamiseks vali rea juures vastav kaart; kaardi algdokument peab olema selle ostuarve number. Käibemaksu parandusega seotud kaart võetakse automaatselt. Täieliku tagastuse eel tuleb vara arvestatud amortisatsioon põhivara moodulis parandada. Tegelik tagasimakse kirjendatakse eraldi.</p>
<p class="small">Kassapõhise arve korral vähendab kreedit esmalt veel deklareerimata jääki. Deklareeritud osa parandus arvutatakse registreeritud maksete järgi; järgmised maksed arvestavad vähendatud arvesummat. Kasuta kreeditarvet hinna muutmise või tagastuse jaoks. Ettemaksu tagastamine ja lootusetu nõude mahakandmine vajavad eraldi käsitlust.</p>
<?php if($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
<form method="post" onsubmit="return confirm('Kas koostad ja kinnitad algse arvega seotud kreeditarve ning paranduskande?')">
<input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
<label>Kreeditarve number<input name="number" required maxlength="50" value="<?= h((string)($_POST['number']??'')) ?>"></label>
<label>Kreeditarve kuupäev<input name="date" type="date" required min="<?= h($original['invoice_date']) ?>" value="<?= h((string)($_POST['date']??max($original['invoice_date'],date('Y-m-d')))) ?>"></label>
<p><button type="button" onclick="document.querySelectorAll('[data-credit-net]').forEach(e=>e.value=e.max)">Kogu allesjäänud summa</button> <button type="button" onclick="document.querySelectorAll('[data-credit-net]').forEach(e=>e.value='0.00')">Tühjenda valik</button></p>
<label>Ühtlane osa allesjäänud summast (%)<input id="credit-percent" type="number" min="0.01" max="100" step="0.01" value="100"></label><button type="button" onclick="const p=Number(document.getElementById('credit-percent').value);if(p>0&&p<=100)document.querySelectorAll('[data-credit-net]').forEach(e=>e.value=(Math.round(Number(e.max)*p)/100).toFixed(2))">Rakenda kõigile ridadele</button>
<?php foreach($creditLines as $line): ?><label><?= h($line['description']) ?> — <?= h($line['vat_rate']) ?>%<?= $line['product_id']?' · kaubatagastus':'' ?><span class="small">Krediteerimata netosumma <?= h(creditDecimal(max(0,$line['remaining']))) ?> €</span><input data-credit-net name="line_net[<?= (int)$line['id'] ?>]" type="number" min="0" max="<?= h(creditDecimal(max(0,$line['remaining']))) ?>" step="0.01" required value="<?= h((string)($_POST['line_net'][$line['id']]??creditDecimal(max(0,$line['remaining'])))) ?>"></label>
<?php if($assets && !$line['product_id']): ?><label>Seotud põhivarakaart<select name="asset_id[<?= (int)$line['id'] ?>]"><option value="0">Selle rea põhivarakaarti ei muudeta</option><?php foreach($assets as $asset): ?><option value="<?= (int)$asset['id'] ?>" <?= (int)($_POST['asset_id'][$line['id']]??0)===(int)$asset['id']?'selected':'' ?>><?= h($asset['asset_number'].' — '.$asset['name']) ?></option><?php endforeach; ?></select></label><?php endif; ?><?php endforeach; ?>
<label>Paranduse põhjus<textarea name="reason" maxlength="500" required><?= h((string)($_POST['reason']??'')) ?></textarea></label>
<button type="submit" <?= $remaining<=0?'disabled':'' ?>>Koosta ja kinnita kreeditarve</button>
<a class="button" href="<?= h($back) ?>?id=<?= (int)$id ?>">Tagasi arvele</a>
</form></main></body></html>
