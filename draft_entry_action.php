<?php
session_start();
if(empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) { header('Location: login.php'); exit; }
if($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); exit('Kasuta mustandi toimingunuppu.'); }
if(empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],(string)($_POST['csrf_token']??''))) { http_response_code(400); exit('Vorm aegus. Värskenda lehte.'); }
require_once __DIR__.'/db.php'; require_once __DIR__.'/journal_draft_lib.php';
$id=filter_var($_POST['entry_id']??null,FILTER_VALIDATE_INT); $action=(string)($_POST['action']??'');
try {
    if(!$id || $id<1) throw new InvalidArgumentException('Vigane kande tunnus.');
    changeJournalDraft($pdo,(int)$_SESSION['company_id'],$id,$action);
    $_SESSION['journal_draft_notice']=$action==='delete'?'Mustand ja selle read kustutati.':'Mustand tühistati. Soovi korral saad selle nüüd kustutada.';
    header('Location: '.($action==='delete'?'index.php':'entry.php?id='.$id)); exit;
} catch(Throwable $e) { http_response_code(422); echo htmlspecialchars($e instanceof PDOException?'Mustandi toiming ebaõnnestus. Andmeid ei muudetud.':$e->getMessage(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); echo '<p><a href="entry.php?id='.(int)$id.'">Tagasi kande juurde</a></p>'; }
