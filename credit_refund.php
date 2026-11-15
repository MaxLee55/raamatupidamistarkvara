<?php
session_start();
if(empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) { header('Location: login.php'); exit; }
require_once __DIR__.'/db.php';
require_once __DIR__.'/credit_note_lib.php';
require_once __DIR__.'/period_lock.php';
require_once __DIR__.'/cash_balance_guard.php';
ensureCreditNoteSchema($pdo);
$_SESSION['csrf_token']??=bin2hex(random_bytes(32));
function h(string $v):string { return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
$companyId=(int)$_SESSION['company_id']; $kind=(string)($_GET['kind']??''); $creditId=(int)($_GET['invoice_id']??0); $error='';
try { [$table,$lines,$number,$back]=creditTables($kind); } catch(Throwable $e) { http_response_code(400); exit('Vigane arve liik.'); }
$link=loadCreditLinks($pdo,$companyId,$kind)[$creditId]??null;
if(!$link) { http_response_code(404); exit('Kreeditarvet ei leitud.'); }
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if(!hash_equals($_SESSION['csrf_token'],(string)($_POST['csrf_token']??''))) throw new RuntimeException('Vorm aegus. Värskenda lehte.');
        if(($_POST['action']??'')==='void') {
            $stmt=$pdo->prepare('SELECT id FROM credit_note_refunds WHERE company_id=? AND invoice_kind=? AND credit_invoice_id=? AND id=?');
            $stmt->execute([$companyId,$kind,$creditId,(int)($_POST['refund_id']??0)]);
            if(!$stmt->fetchColumn()) throw new RuntimeException('Selle kreeditarve tagasimakset ei leitud.');
            voidCreditRefund($pdo,$companyId,(int)$_SESSION['user_id'],(int)$_POST['refund_id'],trim((string)($_POST['reason']??'')));
        } else createCreditRefund($pdo,$companyId,(int)$_SESSION['user_id'],$kind,$creditId,(string)($_POST['date']??''),creditCents((string)($_POST['amount']??'')),(int)($_POST['bank_id']??0),trim((string)($_POST['notes']??'')));
        header('Location: credit_refund.php?'.http_build_query(['kind'=>$kind,'invoice_id'=>$creditId])); exit;
    } catch(Throwable $e) { $error=$e instanceof PDOException?'Tagasimakset ei salvestatud. Kontrolli andmeid.':$e->getMessage(); }
}
$stmt=$pdo->prepare('SELECT invoice_date FROM '.$table.' WHERE company_id=? AND id=?'); $stmt->execute([$companyId,$creditId]); $creditDate=(string)$stmt->fetchColumn();
$stmt=$pdo->prepare("SELECT MAX(refund_date) FROM credit_note_refunds WHERE company_id=? AND invoice_kind=? AND original_invoice_id=? AND status='posted'"); $stmt->execute([$companyId,$kind,$link['original_invoice_id']]); $lastRefund=(string)$stmt->fetchColumn();
$date=(string)($_POST['date']??max(date('Y-m-d'),$creditDate,$lastRefund)); $available=0;
try { $available=creditRefundAvailable($pdo,$companyId,$kind,$creditId,$date)['available']; } catch(Throwable $e) { if(!$error) $error=$e->getMessage(); }
$stmt=$pdo->prepare("SELECT id,code,name FROM accounts WHERE company_id=? AND is_active=1 AND account_type='asset' AND code REGEXP '^10[0-9]{2}$' ORDER BY code"); $stmt->execute([$companyId]); $banks=$stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt=$pdo->prepare('SELECT r.*,a.code AS bank_code,a.name AS bank_name FROM credit_note_refunds r JOIN accounts a ON a.id=r.bank_account_id AND a.company_id=r.company_id WHERE r.company_id=? AND r.invoice_kind=? AND r.credit_invoice_id=? ORDER BY r.refund_date,r.id'); $stmt->execute([$companyId,$kind,$creditId]); $refunds=$stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="et"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kreeditarve tagasimaksed</title><style>body{font:16px Arial;background:#f4f7fb;color:#263746;max-width:900px;margin:30px auto;padding:0 18px}.card{background:white;border:1px solid #dce3ec;border-radius:12px;padding:24px;margin:16px 0}label{display:grid;gap:6px;margin:15px 0}input,select{padding:10px;font:inherit;border:1px solid #b8c4d1;border-radius:7px}button,.button{padding:10px 15px;background:#1769aa;color:white;border:0;border-radius:7px;font:inherit;text-decoration:none}.error{color:#9b2020;background:#fff0f0;padding:12px}.small{font-size:14px;line-height:1.5;color:#526579}</style></head><body><main class="card"><h1>Kreeditarve tagasimaksed</h1><p>Algne arve: <strong><?= h($link['original_number']) ?></strong>. Veel tagastatav: <strong><?= h(creditDecimal($available)) ?> €</strong>.</p><p class="small">Kirjenda siin ainult tegelikult toimunud <?= $kind==='sales'?'kliendile tagastatud':'tarnijalt tagasi saadud' ?> makse. Salvestamine seob makse algse arve ja kreeditarvega ning loob panga ja arvelduskonto kande. Pangale maksekorraldust ei saadeta. Kui sama tagasimakse on juba käsitsi pearaamatus kirjendatud, paranda see enne siin salvestamist, et vältida topeltkannet.</p>
<?php if($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><label>Tagasimakse kuupäev<input type="date" name="date" required value="<?= h($date) ?>"></label><label>Summa (€)<input name="amount" type="number" min="0.01" step="0.01" required value="<?= h((string)($_POST['amount']??creditDecimal($available))) ?>"></label><label>Panga- või kassakonto<select name="bank_id" required><option value="">Vali konto</option><?php foreach($banks as $bank): ?><option value="<?= (int)$bank['id'] ?>" <?= (int)($_POST['bank_id']??0)===(int)$bank['id']?'selected':'' ?>><?= h($bank['code'].' — '.$bank['name']) ?></option><?php endforeach; ?></select></label><label>Selgitus<input name="notes" maxlength="500" value="<?= h((string)($_POST['notes']??'')) ?>"></label><button>Salvesta tegelik tagasimakse</button></form><p><a class="button" href="<?= h($back) ?>?id=<?= $creditId ?>">Tagasi kreeditarvele</a></p></main>
<?php foreach($refunds as $refund): ?><section class="card"><p><?= h($refund['refund_date']) ?> · <?= h(creditDecimal((int)$refund['amount_cents'])) ?> € · <?= h($refund['bank_code'].' — '.$refund['bank_name']) ?> · <?= $refund['status']==='posted'?'Kirjendatud':'Tühistatud' ?></p><p><?= h($refund['notes']) ?> · Kanne nr <?= (int)$refund['journal_entry_id'] ?></p><?php if($refund['status']==='posted'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="void"><input type="hidden" name="refund_id" value="<?= (int)$refund['id'] ?>"><label>Tühistamise põhjus<input name="reason" required maxlength="500"></label><button>Tühista tagasimakse kirjendus</button></form><?php endif; ?></section><?php endforeach; ?></body></html>
