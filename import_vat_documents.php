<?php
session_start();
if(empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) { header('Location: login.php'); exit; }
require_once __DIR__.'/db.php';
require_once __DIR__.'/tax_code_lib.php';
require_once __DIR__.'/import_vat.php';
ensureTaxCodeSchema($pdo); ensureCreditNoteSchema($pdo); ensureImportVatSchema($pdo);
$_SESSION['csrf_token']??=bin2hex(random_bytes(32));
function h($s): string { return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function imoney($c): string { return number_format((int)$c/100,2,',',' ').' €'; }
function eventDescription(array $event): string {
    $data=json_decode($event['details_json'],true)??[]; $parts=[];
    if(isset($data['journal_entry_id'])) $parts[]='Kanne #'.(int)$data['journal_entry_id'];
    if(isset($data['amount_cents'])) $parts[]=imoney($data['amount_cents']);
    if(isset($data['created_entry'])) $parts[]=$data['created_entry']?'Maksekanne loodud pangareast':'Olemasolev maksekanne seotud';
    if(isset($data['linked_existing'])) $parts[]=$data['linked_existing']?'Olemasolev KM kanne seotud':'KM kanne loodud';
    if(isset($data['reason'])) $parts[]=$data['reason'];
    if(isset($data['message'])) $parts[]=$data['message'];
    return implode(' · ',$parts);
}
$company=(int)$_SESSION['company_id']; $user=(int)$_SESSION['user_id']; $error='';
$id=(int)($_GET['id']??0); $form=$_POST;
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if(!hash_equals($_SESSION['csrf_token'],(string)($_POST['csrf_token']??''))) throw new InvalidArgumentException('Vorm aegus. Värskenda lehte ja proovi uuesti.');
        $action=(string)($_POST['action']??''); $id=(int)($_POST['id']??0);
        if($action==='create') $id=createImportVat($pdo,$company,$user,$_POST);
        elseif($action==='payment') payImportVat($pdo,$company,$user,$id,(int)($_POST['bank_line_id']??0),(int)($_POST['payment_entry_id']??0));
        elseif($action==='unlink') unlinkImportPayment($pdo,$company,$user,$id,(int)($_POST['payment_id']??0));
        elseif($action==='void') voidImportVat($pdo,$company,$user,$id,trim((string)($_POST['reason']??'')));
        else throw new InvalidArgumentException('Tundmatu toiming.');
        header('Location: import_vat_documents.php?id='.$id.'&saved=1'); exit;
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); error_log($e->getMessage()); $error=$e instanceof PDOException?'Salvestamine ebaõnnestus. Kontrolli, kas dokument või makse on juba seotud; värskenda lehte.':$e->getMessage(); }
}
$s=$pdo->prepare('SELECT d.*,p.supplier_invoice_number,c.name AS supplier,COALESCE((SELECT SUM(amount_cents) FROM import_vat_payments ip WHERE ip.company_id=d.company_id AND ip.document_id=d.id),0) AS paid FROM import_vat_documents d LEFT JOIN purchase_invoices p ON p.id=d.invoice_id AND p.company_id=d.company_id LEFT JOIN contacts c ON c.id=p.contact_id AND c.company_id=d.company_id WHERE d.company_id=? ORDER BY d.release_date DESC,d.id DESC');
$s->execute([$company]); $documents=$s->fetchAll(PDO::FETCH_ASSOC); $document=null;
foreach($documents as $d) if((int)$d['id']===$id) $document=$d;
if($id && !$document && !$error) { http_response_code(404); $error='Selle ettevõtte tollidokumenti ei leitud.'; }
$s=$pdo->prepare("SELECT p.*,c.name FROM purchase_invoices p JOIN contacts c ON c.id=p.contact_id AND c.company_id=p.company_id WHERE p.company_id=? AND p.status='posted' ORDER BY p.invoice_date DESC,p.id DESC"); $s->execute([$company]); $invoices=$s->fetchAll(PDO::FETCH_ASSOC);
$s=$pdo->prepare('SELECT id,code,name,account_type FROM accounts WHERE company_id=? AND is_active=1 ORDER BY code'); $s->execute([$company]); $accounts=$s->fetchAll(PDO::FETCH_ASSOC);
$s=$pdo->prepare("SELECT id,transaction_date,amount,description,matched_journal_line_id,draft_journal_entry_id FROM bank_statement_lines WHERE company_id=? AND debit_credit='D' AND service_fee=0 ORDER BY transaction_date DESC,id DESC"); $s->execute([$company]); $bankRows=$s->fetchAll(PDO::FETCH_ASSOC);
$payments=[]; $events=[];
if($document) {
    $s=$pdo->prepare('SELECT * FROM import_vat_payments WHERE company_id=? AND document_id=? ORDER BY payment_date,id'); $s->execute([$company,$id]); $payments=$s->fetchAll(PDO::FETCH_ASSOC);
    $s=$pdo->prepare('SELECT * FROM import_vat_events WHERE company_id=? AND document_id=? ORDER BY id DESC'); $s->execute([$company,$id]); $events=$s->fetchAll(PDO::FETCH_ASSOC);
}
$invoiceDetails = [];
$lineQuery = $pdo->prepare("SELECT l.*,t.code AS tax_code FROM purchase_invoice_lines l
    JOIN purchase_invoices p ON p.id=l.invoice_id AND p.company_id=?
    LEFT JOIN tax_codes t ON t.id=l.tax_code_id AND t.company_id=p.company_id
    WHERE p.status='posted' ORDER BY l.id");
$lineQuery->execute([$company]);
$invoiceSums = [];
foreach ($lineQuery->fetchAll(PDO::FETCH_ASSOC) as $line) {
    $invoiceId = (int) $line['invoice_id'];
    $invoiceSums[$invoiceId] ??= ['eur'=>0, 'foreign'=>0, 'import'=>true];
    $invoiceSums[$invoiceId]['eur'] += creditCents((string) $line['gross_amount']);
    $invoiceSums[$invoiceId]['foreign'] += creditCents((string) ($line['fx_gross'] ?? $line['gross_amount']));
    $invoiceSums[$invoiceId]['import'] = $invoiceSums[$invoiceId]['import'] && $line['tax_code'] === 'O_IMPORT0';
}
foreach ($invoices as $invoice) {
    $invoiceId = (int) $invoice['id'];
    $totals = $invoiceSums[$invoiceId] ?? ['eur'=>0, 'foreign'=>0, 'import'=>false];
    $currency = $invoice['invoice_currency'] ?? 'EUR';
    $summary = 'Arve ' . $invoice['supplier_invoice_number'] . ' · ' . $invoice['name']
        . ' · ' . $invoice['invoice_date'] . ' · '
        . number_format($totals['foreign'] / 100, 2, ',', ' ') . ' ' . $currency;
    if ($currency !== 'EUR') {
        $summary .= ' · raamatupidamises ' . imoney($totals['eur']);
        if (!empty($invoice['fx_rate'])) {
            $summary .= ' · 1 EUR = ' . rtrim(rtrim($invoice['fx_rate'], '0'), '.') . ' ' . $currency
                . ' (' . ($invoice['fx_rate_date'] ?? '') . ')';
        }
    }
    $invoiceDetails[$invoiceId] = ['summary'=>$summary, 'import'=>$totals['import']];
}
$importAccountDefaults = ['input'=>'', 'liability'=>''];
foreach ($accounts as $account) {
    if ($account['code'] === '1600' && $account['account_type'] === 'asset') $importAccountDefaults['input'] = (string) $account['id'];
    if ($account['code'] === '2100' && $account['account_type'] === 'liability') $importAccountDefaults['liability'] = (string) $account['id'];
}
foreach ($documents as $previousDocument) {
    if ($previousDocument['status'] !== 'posted') continue;
    foreach (['input', 'liability'] as $kind) {
        foreach ($accounts as $account) {
            if ((int) $account['id'] === (int) $previousDocument[$kind . '_account_id']
                && $account['account_type'] === ($kind === 'input' ? 'asset' : 'liability')) {
                $importAccountDefaults[$kind] = (string) $account['id'];
                break;
            }
        }
    }
    break;
}
$v=static fn(string $key,string $default=''):string=>h($form[$key]??$default);
$invoiceOptions=static function(string $key) use($invoices,$form):void { echo '<option value="">Vali arve</option>'; foreach($invoices as $i) echo '<option value="'.(int)$i['id'].'" '.((int)($form[$key]??0)===(int)$i['id']?'selected':'').'>'.h($i['invoice_date'].' · '.$i['supplier_invoice_number'].' · '.$i['name']).'</option>'; };
$accountOptions=static function(string $key,string $type) use($accounts,$form):void { echo '<option value="">Vali konto</option>'; foreach($accounts as $a) if($a['account_type']===$type) echo '<option value="'.(int)$a['id'].'" '.((int)($form[$key]??0)===(int)$a['id']?'selected':'').'>'.h($a['code'].' — '.$a['name']).'</option>'; };
?>
<!doctype html><html lang="et"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tollidokumendid ja impordi KM</title><link rel="icon" href="favicon.png?v=1"><style>
*{box-sizing:border-box}body{margin:28px auto;padding:0 22px;max-width:1320px;background:#f4f7fb;color:#263c50;font:16px Arial,sans-serif}h1,h2{color:#17324d}h2{font-size:22px;margin-top:0}.card{background:white;border:1px solid #dce4ed;border-radius:13px;padding:23px;margin:20px 0}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:17px}.wide{grid-column:1/-1}label{display:grid;gap:7px;font-weight:600}input,select,textarea{width:100%;min-height:42px;font:inherit;border:1px solid #b9c8d8;border-radius:7px;padding:9px;background:white;color:inherit}textarea{min-height:80px}.check{display:flex;align-items:flex-start;gap:10px;font-weight:400;line-height:1.5}.check input{width:19px;min-height:19px;margin-top:3px;flex-shrink:0}.button,button{display:inline-block;padding:11px 16px;border:0;border-radius:7px;background:#1769aa;color:white;text-decoration:none;cursor:pointer;font:600 15px Arial;min-height:41px}.secondary{background:#63788b}.danger{background:#a33232}.muted,small{color:#617589;line-height:1.5}small{font-weight:400}.error,.success,.notice{padding:14px;border-radius:8px;line-height:1.5}.error{background:#fee;color:#9b2020}.success{background:#e6f6eb;color:#176138}.notice{background:#eef4fb}.scroll{overflow:auto}table{width:100%;border-collapse:collapse}th,td{text-align:left;vertical-align:top;border-bottom:1px solid #e1e8ef;padding:11px}th{background:#f3f6fa}.num{text-align:right;white-space:nowrap}.actions{display:flex;flex-wrap:wrap;gap:9px;margin:15px 0}details>summary{cursor:pointer;font-weight:600;padding:8px 0}.status{font-weight:600;color:#20733c}.metrics{display:flex;gap:25px;flex-wrap:wrap}.metrics strong{display:block;font-size:24px;margin-top:8px}form p{line-height:1.5}@media(max-width:720px){.grid{grid-template-columns:1fr}.wide{grid-column:auto}.card{padding:15px}body{padding:0 12px}}
#import-deduction-percent-box[hidden]{display:none}
#import-vat-form input:not([type="checkbox"]),
#import-vat-form select,
#import-vat-form textarea { color: #7b8997; font-weight: 400; }
#import-vat-form input::placeholder,
#import-vat-form textarea::placeholder { color: #a0aab5; opacity: 1; }
#import-vat-form input:focus,
#import-vat-form select:focus,
#import-vat-form textarea:focus,
#import-vat-form option { color: #263c50; }
.unpaid{color:#a32121;font-weight:600}
</style></head><body>
<h1>Tollidokumendid ja impordi käibemaks</h1><div class="actions"><a class="button secondary" href="index.php">Avalehele</a><a class="button secondary" href="purchase_invoices.php">Ostuarved</a><a class="button secondary" href="vat_report.php">Käibemaksu aruanne</a><a class="button secondary" href="kmd_export_legacy.php">KMD6 ettevalmistus</a></div>
<?php if($error): ?><p class="error" role="alert"><?= h($error) ?></p><?php endif ?>
<?php if(isset($_GET['saved'])): ?><p class="success">Toiming salvestatud.</p><?php endif ?>
<section class="card"><h2>Kuidas importi kajastada?</h2><ol class="muted"><li>Sisesta kauba ostuarve maksukoodiga <strong>O_IMPORT0 — Kauba import; tarnija KM 0</strong> ja kinnita arve. Kauba hind ja tarnijavõlg tekivad ainult sellest arvest.</li><li>Sisesta allpool tollideklaratsiooni või tolliotsuse andmed. Mahaarvamise periood tuleb kauba vabastamise kuupäevast. Impordi KM ei pea võrduma tarnijaarve summa × määr: tolli maksustatav väärtus võib sisaldada ka muid kulusid.</li><li>Tollimaksu ja maakleri-/veotasude ostuarved sisesta eraldi. Nende summad on siin kontrollandmed ning uut kulu ega võlga neist ei tehta. Maakleri arvele ära lisa teist korda tollidokumendi impordi KM-i.</li><li>Tollile/maaklerile tasutav KM seotakse pangamaksega dokumendi juures. Juba kirjendatud maksele uut kannet ei tehta. KMD-l tasumisel kinnita kehtiv luba; MTA täidab lahtri 4.1 ise.</li></ol>
<p class="notice">Mahaarvatav impordi KM läheb KMD lahtrisse 5 ja selle alajaotusse 5.1; põhivara puhul ka 5.2. Tollidokumenti KMD INF B-ossa ei kanta. Sõiduautode import ning lao-/põhivaramaksumusse jaotatav mahaarvamata impordi KM vajavad veel eraldi lahendust. Tollideklaratsiooni muutus ja kauba ostuarve kreedit on erinevad toimingud.</p></section>
<?php if($document): ?>
<section class="card"><h2><?= h($document['mrn']) ?> · <?php if ($document['status']==='posted'): ?><span class="status">Kinnitatud</span><?php else: ?>Tühistatud<?php endif; ?></h2><p>Kauba vabastamine: <?= h($document['release_date']) ?>. Ostuarve: <a href="purchase_invoices.php?id=<?= (int)$document['invoice_id'] ?>"><?= h($document['supplier_invoice_number']) ?></a> · <?= h($document['supplier']) ?>.</p>
<div class="metrics"><div>Tolli maksustatav väärtus<strong><?= imoney($document['taxable_cents']) ?></strong></div><div>Impordi KM<strong><?= imoney($document['vat_cents']) ?></strong></div><div>Mahaarvatav (5 ja 5.1)<strong><?= imoney($document['deductible_cents']) ?></strong></div><?php if ($document['payment_mode']==='kmd'): ?>
<div>KMD-l tasutav KM<strong><?= imoney($document['vat_cents']) ?></strong></div>
<?php else: ?>
<?php if ((int)$document['paid'] > 0): ?><div>Tasutud<strong><?= imoney($document['paid']) ?></strong></div><?php endif; ?>
<?php if ((int)$document['paid'] < (int)$document['vat_cents']): ?><div class="unpaid">Tasumata<strong><?= imoney($document['vat_cents']-$document['paid']) ?></strong></div><?php endif; ?>
<?php endif; ?></div>
<p><a href="entry.php?id=<?= (int)$document['journal_entry_id'] ?>">Ava impordi KM kanne #<?= (int)$document['journal_entry_id'] ?></a><?php if($document['void_entry_id']): ?> · <a href="entry.php?id=<?= (int)$document['void_entry_id'] ?>">Ava tühistamiskanne</a><?php endif ?></p>
<p class="muted">Tasumisviis: <?= $document['payment_mode']==='kmd'?'KMD-l kehtiva loa alusel — '.h($document['permission_reference']):'Tollile või tolliagentuurile' ?>. Kasutus: <?= $document['vat_purpose']==='fixed_asset'?'põhivara':'kaup' ?>.<br>Tollimaks <?= imoney($document['duty_cents']) ?>; muud tasud <?= imoney($document['other_cents']) ?> (kontrollandmed).
<?php if($document['cost_invoice_id']): ?><a href="purchase_invoices.php?id=<?= (int)$document['cost_invoice_id'] ?>">Tolli-/teenustasude arve</a><?php endif ?><br><?= h($document['notes']) ?></p>
<?php if($payments): ?><h3>Seotud KM maksed</h3><div class="scroll"><table><tr><th>Kuupäev</th><th>Kanne</th><th>Summa</th><th>Seos</th></tr><?php foreach($payments as $p): ?><tr><td><?= h($p['payment_date']) ?></td><td><a href="entry.php?id=<?= (int)$p['journal_entry_id'] ?>">#<?= (int)$p['journal_entry_id'] ?></a></td><td><?= imoney($p['amount_cents']) ?></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="unlink"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>"><button class="secondary">Eemalda seos; jäta pangakanne alles</button></form></td></tr><?php endforeach ?></table></div><?php endif ?>
<?php if($document['status']==='posted' && $document['payment_mode']==='customs' && $document['vat_cents']>$document['paid']): ?>
<details><summary>Seo või kirjenda impordi KM makse</summary><form method="post" class="grid"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="payment"><input type="hidden" name="id" value="<?= $id ?>">
<label>Pangaväljavõtte rida<select name="bank_line_id"><option value="">Vali pangarida või sisesta olemasolev kanne</option><?php foreach($bankRows as $b): ?><option value="<?= (int)$b['id'] ?>"><?= h($b['transaction_date'].' · '.$b['amount'].' € · '.$b['description'].($b['matched_journal_line_id']?' · juba kirjendatud':($b['draft_journal_entry_id']?' · mustand':''))) ?></option><?php endforeach ?></select></label>
<label>Olemasoleva kinnitatud maksekande nr (valikuline)<input type="number" name="payment_entry_id" min="1"><small>Juba kirjendatud makse seotakse kontrolli järel. Uus kanne tehakse ainult kirjendamata pangareast.</small></label><p class="wide muted">Vali ainult selle impordi KM makse või osamakse. Tollimaksu, teenustasude või mitme tollidokumendi koondmakse vajab enne eraldi jaotamist. Makse vähendab ainult kohustust, käibemaksu mahaarvamine ei kordu.</p><div><button>Salvesta makse seos</button></div></form></details>
<?php endif ?>
<?php if($document['status']==='posted'): ?><details><summary>Paranda või tühista dokument</summary><p class="muted">Tühistamine teeb samasse avatud perioodi vastukande. Seejärel sisesta parandatud tollidokument uuesti. Algne dokument ja ajalugu säilivad. Tegelikke pangamakseid ei tühistata. Hilisema tolliparanduse eraldi perioodi käsitlust see vorm veel ei tee.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?= $id ?>"><label>Tühistamise põhjus<input name="reason" maxlength="500" required></label><p><button class="danger">Tühista tollidokumendi KM kanne</button></p></form></details><?php endif ?>
<details><summary>Toimingute ajalugu</summary><?php $labels=['confirmed'=>'Dokument kinnitatud','payment_linked'=>'Makse seotud','payment_unlinked'=>'Makse seos eemaldatud; pangakanne säilis','reversed'=>'Dokument tühistatud']; foreach($events as $e): ?><p><?= h($e['created_at'].' · '.($labels[$e['event_name']]??$e['event_name'])) ?> · kasutaja #<?= (int)$e['created_by'] ?><br><small><?= h(eventDescription($e)) ?></small></p><?php endforeach ?></details>
</section>
<?php endif ?>
<section class="card"><h2>Uus tollidokument</h2><form method="post" class="grid" id="import-vat-form"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="create">
<label>Tollideklaratsiooni MRN / tolliotsuse nr<input name="mrn" maxlength="50" required value="<?= $v('mrn') ?>"></label><label>Kauba vabastamise kuupäev<input type="date" name="release_date" required value="<?= $v('release_date',(new DateTimeImmutable('now',new DateTimeZone('Europe/Tallinn')))->format('Y-m-d')) ?>"><small>Määrab impordi KM arvestuskuu.</small></label>
<label class="wide">Kauba ostuarve (kõik read koodiga O_IMPORT0)<select name="invoice_id" required><?php $invoiceOptions('invoice_id') ?></select></label>
<div class="notice wide" id="import-invoice-summary" aria-live="polite" hidden></div>
<p class="muted wide">Tolli maksustatav väärtus ja impordi käibemaks sisesta tollidokumendilt. Arve eurosumma on võrdluseks; tolli valuutakurss ja maksustatav väärtus võivad erineda.</p>
<label>Tolli maksustatav väärtus (€)<input name="taxable" inputmode="decimal" required value="<?= $v('taxable') ?>"></label><label>Impordi käibemaks tollidokumendilt (€)<input name="vat" inputmode="decimal" required value="<?= $v('vat') ?>"></label><label>Mahaarvamisõigus<select name="deduction_mode" id="import-deduction-mode">
    <option value="">Sisestan mahaarvatava summa ise</option>
    <?php foreach (['full'=>'Täielik mahaarvamine (100%)','partial'=>'Osaline mahaarvamine','none'=>'Mahaarvamisõigus puudub (0%)'] as $mode=>$label): ?>
        <option value="<?= $mode ?>" <?= ($form['deduction_mode']??'')===$mode?'selected':'' ?>><?= h($label) ?></option>
    <?php endforeach; ?>
</select></label>
<label id="import-deduction-percent-box" <?= ($form['deduction_mode']??'')==='partial'?'':'hidden' ?>>Mahaarvamise osatähtsus (%)<input name="deduction_percent" id="import-deduction-percent" type="number" min="0" max="100" step="0.01" value="<?= $v('deduction_percent') ?>"></label><label>Mahaarvatav impordi KM (€)<input name="deductible" inputmode="decimal" required value="<?= $v('deductible') ?>"><small>0 kuni tollidokumendi KM. Osaline mahaarvamine on siin toetatud kulukontole ostetud kaubal.</small></label>
<label>Kasutus<select name="vat_purpose"><option value="ordinary">Kauba import (v.a sõiduauto)</option><option value="fixed_asset" <?= ($form['vat_purpose']??'')==='fixed_asset'?'selected':'' ?>>Põhivara import (v.a sõiduauto; täielik mahaarvamine)</option></select></label>
<label>Sisendkäibemaksu konto<select name="input_account_id" required><?php $accountOptions('input_account_id','asset') ?></select></label><label>Impordi KM kohustuse konto<select name="liability_account_id" required><?php $accountOptions('liability_account_id','liability') ?></select><small>Vali kauba tarnijavõlast eraldi maksukohustuse või tolliagentuuri võla konto.</small></label>
<label>Mahaarvamata KM kulukonto (vajadusel)<select name="cost_account_id"><?php $accountOptions('cost_account_id','expense') ?></select></label><label>Impordi KM tasumine<select name="payment_mode"><option value="customs">Tollile / tolliagentuurile</option><option value="kmd" <?= ($form['payment_mode']??'')==='kmd'?'selected':'' ?>>KMD-l kehtiva loa alusel</option></select></label>
<label class="wide">Impordiloa viide (nõutav KMD-l tasumisel)<input name="permission_reference" maxlength="160" value="<?= $v('permission_reference') ?>"></label>
<details class="wide"><summary>Tollimaks ja muud tasud — juba eraldi arvel kajastatud summad</summary><div class="grid"><label>Tollimaks (€)<input name="duty" inputmode="decimal" value="<?= $v('duty','0.00') ?>"></label><label>Muud tasud ilma eraldi arve KM-ta (€)<input name="other" inputmode="decimal" value="<?= $v('other','0.00') ?>"></label><label class="wide">Tolli-/teenustasude kinnitatud ostuarve<select name="cost_invoice_id"><?php $invoiceOptions('cost_invoice_id') ?></select><small>Kontrollviide. Selle arve summasid tollidokumendist uuesti ei kirjendata; lao või põhivara maksumuse jaotus peab olema lahendatud algarvestuses.</small></label></div></details>
<details class="wide"><summary>Impordi KM on juba pearaamatus kirjendatud</summary><label>Olemasoleva kinnitatud impordi KM kande nr<input type="number" min="1" name="existing_entry_id" value="<?= $v('existing_entry_id') ?>"><small>Väldi topeltkannet: programm kontrollib kuupäeva, kontosid ja täpseid summasid ning seob olemasoleva kande.</small></label></details>
<label class="wide">Selgitus<textarea name="notes" maxlength="500"><?= $v('notes') ?></textarea></label>
<label class="check wide"><input type="checkbox" name="confirmed_basis" value="1" required <?= !empty($form['confirmed_basis'])?'checked':'' ?>><span>Kontrollisin tollidokumenti: ettevõte on importija, kaup on vabastatud ning mahaarvatav summa vastab kasutusele. Impordi KM-i ei ole lisatud kauba ega maakleri ostuarve käibemaksule. KMD-l tasumise valikul on ettevõttel kehtiv luba.</span></label><div class="wide"><button>Kinnita tollidokument ja impordi KM</button></div></form></section>
<section class="card"><h2>Tollidokumentide register</h2><?php if(!$documents): ?><p class="muted">Tollidokumente pole veel lisatud.</p><?php else: ?><div class="scroll"><table><thead><tr><th>Vabastamine / dokument</th><th>Kauba ostuarve</th><th class="num">Impordi KM</th><th class="num">Mahaarvatav</th><th>Staatus / tasumine</th></tr></thead><tbody><?php foreach($documents as $d): ?><tr><td><?= h($d['release_date']) ?><br><a href="?id=<?= (int)$d['id'] ?>"><?= h($d['mrn']) ?></a></td><td><a href="purchase_invoices.php?id=<?= (int)$d['invoice_id'] ?>"><?= h($d['supplier_invoice_number']) ?></a><br><?= h($d['supplier']) ?></td><td class="num"><?= imoney($d['vat_cents']) ?></td><td class="num"><?= imoney($d['deductible_cents']) ?></td><td><?php if ($d['status']==='posted'): ?><span class="status">Kinnitatud</span>
<?php if ($d['payment_mode']==='kmd'): ?><br>KMD-l; võrdle MTA lahtriga 4.1
<?php else: ?>
<?php if ((int)$d['paid'] > 0): ?><br>Tasutud <?= imoney($d['paid']) ?><?php endif; ?>
<?php if ((int)$d['paid'] < (int)$d['vat_cents']): ?><br><span class="unpaid">Tasumata <?= imoney($d['vat_cents']-$d['paid']) ?></span><?php endif; ?>
<?php endif; ?>
<?php else: ?>Tühistatud<?php endif; ?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?></section><script>
(() => {
    const form = document.getElementById('import-vat-form');
    const fields = form.elements;
    const invoices = <?= json_encode($invoiceDetails, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?>;
    const defaults = <?= json_encode($importAccountDefaults, JSON_THROW_ON_ERROR) ?>;
    const summary = document.getElementById('import-invoice-summary');
    const percentBox = document.getElementById('import-deduction-percent-box');

    function showInvoice() {
        const invoice = invoices[fields.invoice_id.value];
        summary.hidden = !invoice;
        summary.textContent = invoice
            ? invoice.summary + (invoice.import ? '' : ' · Kontrolli maksukoodi: selle arve kõik read ei ole kauba impordi koodiga.')
            : '';
        if (!invoice || !invoice.import) return;
        if (!fields.input_account_id.value) fields.input_account_id.value = defaults.input;
        if (!fields.liability_account_id.value) fields.liability_account_id.value = defaults.liability;
    }

    function updateDeduction() {
        const mode = fields.deduction_mode.value;
        percentBox.hidden = mode !== 'partial';
        fields.deduction_percent.required = mode === 'partial';
        if (!mode) return;
        if (mode === 'none') {
            fields.deductible.value = '0.00';
            return;
        }
        const vat = fields.vat.value.trim().replace(',', '.');
        if (!/^\d+(?:\.\d{1,2})?$/.test(vat)) return;
        const percent = mode === 'full' ? 100 : Number(fields.deduction_percent.value);
        if (mode === 'partial' && (!fields.deduction_percent.value || !fields.deduction_percent.validity.valid)) return;
        const cents = Math.round(Number(vat) * 100);
        fields.deductible.value = (Math.round(cents * Math.round(percent * 100) / 10000) / 100).toFixed(2);
    }

    fields.invoice_id.addEventListener('change', showInvoice);
    fields.deduction_mode.addEventListener('change', updateDeduction);
    fields.deduction_percent.addEventListener('input', updateDeduction);
    fields.vat.addEventListener('input', updateDeduction);
    fields.deductible.addEventListener('input', () => {
        fields.deduction_mode.value = '';
        percentBox.hidden = true;
        fields.deduction_percent.required = false;
    });
    percentBox.hidden = fields.deduction_mode.value !== 'partial';
    fields.deduction_percent.required = fields.deduction_mode.value === 'partial';
    showInvoice();
})();
</script>
</body></html>
