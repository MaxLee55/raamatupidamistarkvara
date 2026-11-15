<?php
session_start();
if(empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) { header('Location: login.php'); exit; }
require_once __DIR__.'/db.php'; require_once __DIR__.'/tax_code_lib.php'; require_once __DIR__.'/period_lock.php'; require_once __DIR__.'/credit_note_lib.php';
ensureTaxCodeSchema($pdo); ensureCreditNoteSchema($pdo);
$_SESSION['csrf_token']??=bin2hex(random_bytes(32));
function h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
$company=(int)$_SESSION['company_id']; $id=(int)($_POST['invoice_id']??$_GET['id']??0); $error=''; $source=null; $c=null;
try {
    $source=zeroSource($pdo,$company,$id); if(!zeroHasLines($source)) throw new InvalidArgumentException('Arvel ei ole 0% või maksuvaba käivet.');
    $c=zeroContext($pdo,$company,$id);
    if($_SERVER['REQUEST_METHOD']==='POST') {
        if(!hash_equals($_SESSION['csrf_token'],(string)($_POST['csrf_token']??''))) throw new InvalidArgumentException('Vorm aegus. Värskenda lehte.');
        $pdo->beginTransaction();
        $s=$pdo->prepare('SELECT status FROM sales_invoices WHERE company_id=? AND id=? FOR UPDATE'); $s->execute([$company,$id]); $status=$s->fetchColumn();
        if(!in_array($status,['draft','posted'],true)) throw new InvalidArgumentException('Tühistatud arve käsitlust ei muudeta.');
        $links=loadCreditLinks($pdo,$company,'sales');
        foreach($links as $creditId=>$link) if($link['credit_status']==='posted' && ((int)$creditId===$id || (int)$link['original_invoice_id']===$id)) throw new InvalidArgumentException('Arvel on aktiivne kreeditseos. Paranda esmalt kreeditarve; käsitlust ei muudeta algse arve ja kreediti vahel erinevaks.');
        saveZeroSalesContext($pdo,$company,(int)$_SESSION['user_id'],$id,$_POST,$status==='draft' || !$c);
        $pdo->commit(); header('Location: invoices.php?id='.$id); exit;
    }
} catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); $error=$e instanceof PDOException?'Salvestamine ebaõnnestus.':$e->getMessage(); }
$form=$_SERVER['REQUEST_METHOD']==='POST'?$_POST:($c?zeroFormFromContext($c):['zero_tax_date'=>$source['invoice']['invoice_date']??'']);
?>
<!doctype html><html lang="et"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>0% / maksuvaba käibe käsitlus</title><style>body{max-width:950px;margin:30px auto;padding:0 20px;background:#f4f7fb;color:#263746;font:16px Arial;line-height:1.5}.card{padding:24px;border:1px solid #dce3ec;border-radius:12px;background:white}input,select{padding:9px;border:1px solid #b7c5d5;border-radius:6px;font:inherit}button,a.button{display:inline-block;border:0;padding:12px 16px;border-radius:7px;background:#1769aa;color:white;text-decoration:none;font:600 16px Arial}.error{padding:14px;background:#fee;color:#922}label{display:block}small{color:#617589}</style></head><body><h1>0% / maksuvaba käibe käsitlus</h1><p><a href="invoices.php?id=<?= $id ?>">Tagasi arvele</a></p><?php if($error): ?><p class="error"><?= h($error) ?></p><?php endif ?>
<?php if($source): ?><section class="card"><h2>Arve <?= h($source['invoice']['invoice_number']) ?></h2><p>Siin täpsustatakse maksukäsitlust ja aruandeperioodi. Arve summad ega pearaamatukanne ei muutu. Varasema aruande muutus võib nõuda parandatud KMD-d ja VDP-d; programm neid automaatselt ei esita. Kinnitatud arve varasemad partneriandmed säilivad.</p><form method="post"><input type="hidden" name="invoice_id" value="<?= $id ?>"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><label>Arvepõhine täpsustus (ühtse 0% / maksuvaba arve korral)<select name="zero_override"><option value="">Kasuta arveridade täpseid maksukoode</option><?php foreach(zeroSalesSpecs() as $code=>$spec): ?><option value="<?= h($code) ?>" <?= ($form['zero_override']??'')===$code?'selected':'' ?>><?= h($spec['label']) ?></option><?php endforeach ?></select></label><?php require __DIR__.'/zero_sales_fields.php'; ?><button>Salvesta kontrollitud käsitlus</button></form></section><?php endif ?></body></html>
