<?php
session_start();
if(empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once __DIR__.'/manual_documents.php';
$id=is_string($_GET['id']??null)?$_GET['id']:'';
$catalog=manualDocuments(); $document=$catalog[$id]??null;
if(!$document || !is_file(__DIR__.'/manual_documents/'.$document['file'])) { http_response_code(404); header('Content-Type: text/html; charset=UTF-8'); echo '<!doctype html><html lang="et"><meta charset="utf-8"><title>Juhendit ei leitud</title><h1>Juhendit ei leitud</h1><a href="manual.php#lisajuhendid">Tagasi kasutusjuhenditesse</a></html>'; exit; }
$text=file_get_contents(__DIR__.'/manual_documents/'.$document['file']);
if($text===false) { http_response_code(500); exit('Juhendi avamine ebaõnnestus.'); }
header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
if(($_GET['download']??'')==='1') {
    header('Content-Type: text/plain; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.basename($document['file']).'"');
    echo $text; exit;
}
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html><html lang="et"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= manualEscape($document['title']) ?> — Kasutusjuhendid</title><link rel="icon" href="favicon.png?v=1"><style>
*{box-sizing:border-box}body{max-width:1050px;margin:30px auto;padding:0 20px;background:#f4f7fb;color:#263746;font:16px Arial,sans-serif;line-height:1.65}h1,h2,h3,h4{color:#17324d;line-height:1.3}h1{font-size:30px}h2{margin-top:32px;font-size:25px}h3{margin-top:26px;font-size:21px}.actions{display:flex;flex-wrap:wrap;gap:10px;margin:20px 0}.button{display:inline-block;padding:10px 15px;border:0;border-radius:7px;background:#1769aa;color:#fff;text-decoration:none;font:600 15px Arial;cursor:pointer}.secondary{background:#657789}article{padding:28px;background:#fff;border:1px solid #dce3ec;border-radius:12px;overflow-wrap:anywhere}li{margin:9px 0}code{background:#eef3f8;padding:2px 5px;border-radius:4px;font-size:.9em}pre{white-space:pre-wrap;overflow-wrap:anywhere;padding:16px;background:#eef3f8;border-radius:8px}pre code{padding:0}a{color:#1769aa}.notice{padding:14px 18px;background:#fff4d9;border:1px solid #ead59c;border-radius:8px}.muted{color:#637589}hr{border:0;border-top:1px solid #dce3ec;margin:25px 0}@media(max-width:600px){body{padding:0 12px}article{padding:18px}h1{font-size:25px}}@media print{body{max-width:none;margin:0;padding:0;background:#fff;font-size:10pt}.actions{display:none}article{border:0;padding:0}h1,h2,h3,h4{break-after:avoid}pre{white-space:pre-wrap}a{color:inherit;text-decoration:underline}}
</style></head><body><nav class="actions" aria-label="Juhendi toimingud"><a class="button secondary" href="manual.php#lisajuhendid">Kõik kasutusjuhendid</a><a class="button secondary" href="index.php">Avalehele</a><button class="button" type="button" onclick="window.print()">Prindi / salvesta PDF</button><a class="button" href="?id=<?= rawurlencode($id) ?>&amp;download=1">Laadi juhend alla</a></nav><h1><?= manualEscape($document['title']) ?></h1><p class="muted">Juhendi kuupäev: <?= manualEscape($document['date']) ?></p>
<?php if(!empty($document['notice'])): ?><p class="notice"><?= manualEscape($document['notice']) ?></p><?php endif ?>
<article><?= str_ends_with($document['file'],'.txt')?'<pre>'.manualEscape($text).'</pre>':manualMarkdown($text) ?></article><p class="actions"><a class="button secondary" href="manual.php#lisajuhendid">Tagasi kasutusjuhenditesse</a></p></body></html>
