<?php
session_start();
if(empty($_SESSION['user_id'])||empty($_SESSION['company_id'])) { header('Location: login.php'); exit; }
if($_SERVER['REQUEST_METHOD']!=='POST' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],(string)($_POST['csrf_token']??''))) { http_response_code(422); exit('Vorm aegus.'); }
require_once __DIR__.'/db.php'; require_once __DIR__.'/credit_note_lib.php'; require_once __DIR__.'/period_lock.php';
ensureCreditNoteSchema($pdo);
try {
    $kind=(string)($_POST['kind']??''); $id=filter_var($_POST['invoice_id']??null,FILTER_VALIDATE_INT);
    if(!$id) throw new RuntimeException('Vigane arve ID.');
    $back=creditTables($kind)[3];
    voidCreditNote($pdo,(int)$_SESSION['company_id'],(int)$_SESSION['user_id'],$kind,(int)$id,trim((string)($_POST['reason']??'')));
    header('Location: '.$back.'?id='.$id); exit;
} catch(Throwable $exception) { http_response_code(422); echo htmlspecialchars($exception->getMessage(),ENT_QUOTES,'UTF-8'); }
