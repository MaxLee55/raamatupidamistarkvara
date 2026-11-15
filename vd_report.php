<?php
session_start();
if(empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) { header('Location: login.php'); exit; }
require_once __DIR__.'/db.php'; require_once __DIR__.'/tax_code_lib.php'; require_once __DIR__.'/credit_note_lib.php';
ensureTaxCodeSchema($pdo); ensureCreditNoteSchema($pdo);
function h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function euro(int $v): string { return number_format($v/100,2,',',' ').' €'; }
$company=(int)$_SESSION['company_id']; $now=new DateTimeImmutable('now',new DateTimeZone('Europe/Tallinn'));
$year=filter_var($_GET['year']??$now->format('Y'),FILTER_VALIDATE_INT); $month=filter_var($_GET['month']??$now->format('n'),FILTER_VALIDATE_INT); $errors=[];
if(!$year || $year<2000 || $year>2100 || !$month || $month<1 || $month>12) { $errors[]='Vali korrektne aasta ja kuu.'; $year=(int)$now->format('Y'); $month=(int)$now->format('n'); }
$start=sprintf('%04d-%02d-01',$year,$month); $end=(new DateTimeImmutable($start))->format('Y-m-t');
$s=$pdo->prepare('SELECT name,registry_code FROM companies WHERE id=?'); $s->execute([$company]); $firm=$s->fetch(PDO::FETCH_ASSOC);
$data=['rows'=>[],'partners'=>[],'boxes'=>['3.1'=>0,'3.1.1'=>0]];
if(!$errors) {
    try {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY'); $pdo->beginTransaction();
        $data=loadZeroSales($pdo,$company,$start,$end); $errors=$data['errors'];
        foreach($data['partners'] as $key=>$p) if($p['goods']===0 && $p['services']===0) unset($data['partners'][$key]);
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); $errors[]=$e instanceof PDOException?'Aruande koostamine ebaõnnestus.':$e->getMessage(); }
}
$download=(string)($_GET['download']??'');
if($download && !$errors) {
    if(!$data['partners']) $errors[]='Valitud kuul pole VD-sse kuuluvaid tehinguid. Tühja VD aruannet ei koostata.';
    elseif($download==='xml') {
        try {
            $xml=vdXml(trim((string)($firm['registry_code']??'')),$year,$month,$data['partners']);
            $dom=new DOMDocument(); $dom->loadXML($xml,LIBXML_NONET);
            if(!$dom->schemaValidate(__DIR__.'/mta_vd_validation/vd.xsd')) throw new RuntimeException('VD fail ei vasta MTA skeemile.');
            header('Content-Type: application/xml; charset=UTF-8'); header('Content-Disposition: attachment; filename="VD-'.$year.'-'.sprintf('%02d',$month).'.xml"'); header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff'); echo $xml; exit;
        } catch(Throwable $e) { $errors[]=$e->getMessage(); }
    } elseif($download==='csv') {
        header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="VD-kontroll-'.$year.'-'.sprintf('%02d',$month).'.csv"'); header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff');
        $out=fopen('php://output','wb'); fwrite($out,"\xEF\xBB\xBF"); fputcsv($out,['Riik','KMKR number','Klient','Kaup EUR täpselt','Teenus EUR täpselt','VD kaup täiseurodes','VD teenus täiseurodes'],';','"','');
        foreach($data['partners'] as $p) { $name=$p['party']['name']; if(preg_match('/^[=+@\-\t\r\n]/',$name)) $name="'".$name; fputcsv($out,[substr($p['party']['vat_number'],0,2),$p['party']['vat_number'],$name,number_format($p['goods']/100,2,'.',''),number_format($p['services']/100,2,'.',''),vdRound($p['goods']),vdRound($p['services'])],';','"',''); } fclose($out); exit;
    } else $errors[]='Tundmatu allalaadimisvorming.';
}
if($download && $errors) http_response_code(422);
$query=http_build_query(['year'=>$year,'month'=>$month]);
?>
<!doctype html><html lang="et"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>VD — EL müügi aruanne</title><link rel="icon" href="favicon.png?v=1"><style>*{box-sizing:border-box}body{max-width:1250px;margin:30px auto;padding:0 20px;background:#f4f7fb;color:#263746;font:16px Arial;line-height:1.5}h1,h2{color:#17324d}.card{padding:22px;margin:20px 0;border:1px solid #dce3ec;border-radius:12px;background:white}.actions{display:flex;flex-wrap:wrap;gap:10px;align-items:end}.button,button{display:inline-block;padding:11px 15px;border:0;border-radius:7px;background:#1769aa;color:white;text-decoration:none;font:600 15px Arial;cursor:pointer}.secondary{background:#657789}label{display:grid;gap:6px}input{padding:9px;border:1px solid #b9c7d6;border-radius:6px;font:inherit;width:125px}table{width:100%;border-collapse:collapse}td,th{padding:10px;border-bottom:1px solid #dce3ec;text-align:left;vertical-align:top}th{background:#f3f6fa}.num{text-align:right;white-space:nowrap}.scroll{overflow:auto}.error{padding:13px;background:#fee;color:#922;border-radius:7px}.notice{padding:14px;background:#edf4fb;border-radius:7px}small,.muted{color:#617589}@media print{.no-print{display:none}body{max-width:none;background:white;margin:0;padding:0}.card{border:0;padding:0}thead{display:table-header-group}}</style></head><body><h1>VD — ühendusesisese käibe aruanne</h1><p><?= h($firm['name']??'') ?> · <?= h($firm['registry_code']??'') ?></p><form class="actions no-print" method="get"><label>Aasta<input name="year" type="number" min="2000" max="2100" value="<?= $year ?>" required></label><label>Kuu<input name="month" type="number" min="1" max="12" value="<?= $month ?>" required></label><button>Näita</button><a class="button secondary" href="index.php">Avalehele</a><a class="button secondary" href="kmd_export_legacy.php?<?= h($query) ?>">KMD6</a></form>
<?php foreach($errors as $error): ?><p class="error"><?= h($error) ?></p><?php endforeach ?>
<p class="notice">Aruandesse lähevad kinnitatud EL kauba- ja põhireegli teenusemüügid kliendi KMKR numbri kaupa. Kaup ja teenus liidetakse eraldi sentides ning ümardatakse alles kliendi kogusumma järgi täiseurodeks. Kreeditarve vähendab oma väljastamise kuu VD-d. Eksporti, importi, Eesti müüki ja EL-väliseid teenuseid VD-s ei näidata.</p>
<section class="card"><h2><?= h(sprintf('%04d-%02d',$year,$month)) ?> · partnerite kokkuvõte</h2><?php if(!$data['partners']): ?><p>VD-sse kuuluvaid tehinguid ei ole.</p><?php else: ?><div class="scroll"><table><thead><tr><th>Klient / ajalooline KMKR</th><th class="num">Kaup täpselt</th><th class="num">Teenus täpselt</th><th class="num">VD kaup (€)</th><th class="num">VD teenus (€)</th></tr></thead><tbody><?php foreach($data['partners'] as $p): ?><tr><td><?= h($p['party']['name']) ?><br><?= h($p['party']['vat_number']) ?></td><td class="num"><?= euro($p['goods']) ?></td><td class="num"><?= euro($p['services']) ?></td><td class="num"><?= vdRound($p['goods']) ?></td><td class="num"><?= vdRound($p['services']) ?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?>
<p>KMD 3.1: <strong><?= euro($data['boxes']['3.1']) ?></strong>; sellest kaubad 3.1.1: <strong><?= euro($data['boxes']['3.1.1']) ?></strong>. KMD säilib senditäpsusega; VD täiseurodest võib tekkida ümarduserinevus.</p>
<?php if(!$errors && $data['partners']): ?><div class="actions no-print"><a class="button" href="?<?= h($query) ?>&amp;download=xml">Laadi VD XML</a><a class="button" href="?<?= h($query) ?>&amp;download=csv">Kontrolltabel CSV</a><button class="secondary" onclick="window.print()">Prindi / salvesta PDF</button></div><?php endif ?>
<p class="muted">XML on e-MTAsse käsitsi importimiseks; midagi automaatselt ei saadeta. CSV on kontrolltabel. Varasema VD vea parandamiseks on vaja VDP-d, mida see leht ei koosta. Kolmnurktehing, nõudmiseni varu ja uue transpordivahendi erijuht vajavad eraldi käsitlust.</p></section>
<section class="card"><h2>Aluseks olevad 0% ja maksuvabad müügiread</h2><div class="scroll"><table><thead><tr><th>Maksustamiskuupäev / arve</th><th>Maksukäsitlus</th><th>Klient</th><th class="num">Neto</th></tr></thead><tbody><?php foreach($data['rows'] as $r): ?><tr><td><?= h($r['tax_date']) ?><br><a href="invoices.php?id=<?= (int)$r['invoice_id'] ?>"><?= h($r['invoice_number']) ?></a></td><td><?= h($r['label']) ?><br><small>KMD <?= h($r['box']) ?></small></td><td><?= h($r['party']['name']) ?></td><td class="num"><?= euro($r['net']) ?></td></tr><?php endforeach ?></tbody></table></div></section></body></html>
