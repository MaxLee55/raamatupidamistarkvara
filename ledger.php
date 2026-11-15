<?php
session_start();
if(empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) { header('Location: login.php'); exit; }
require_once __DIR__.'/db.php';
require_once __DIR__.'/ledger_data.php';
require_once __DIR__.'/ledger_exports.php';
$companyId=(int)$_SESSION['company_id']; session_write_close();
$report=null; $errors=[]; $filters=ledgerFilters([]); $export=null;
try {
    $filters=ledgerFilters($_GET); $format=$_GET['download']??'';
    if(!is_string($format) || !in_array($format,['','csv','pdf','xlsx'],true)) throw new InvalidArgumentException('Vali CSV, PDF või XLSX.');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $pdo->exec('SET TRANSACTION READ ONLY'); $pdo->beginTransaction();
    $report=ledgerLoad($pdo,$companyId,$filters);
    if($format!=='') {
        if(function_exists('set_time_limit')) set_time_limit(0);
        $export=ledgerExport($pdo,$report,$format); $pdo->commit();
        header('Content-Type: '.$export['mime']); header('Content-Disposition: attachment; filename="'.$export['filename'].'"'); header('Content-Length: '.filesize($export['path']));
        header('Cache-Control: no-store, no-cache, must-revalidate'); header('X-Content-Type-Options: nosniff');
        readfile($export['path']); ledgerCleanup($export['directory']); exit;
    }
    ob_start(); require __DIR__.'/ledger_view.php'; $html=ob_get_clean(); $pdo->commit(); echo $html;
} catch(Throwable $e) {
    if(ob_get_level()) ob_end_clean();
    if($pdo->inTransaction()) $pdo->rollBack();
    if($export) ledgerCleanup($export['directory']);
    http_response_code($e instanceof InvalidArgumentException?400:500);
    if($e instanceof PDOException || $e instanceof Error) { error_log($e->getMessage()); $errors[]='Pearaamatut ei saanud koostada. Proovi uuesti.'; }
    else $errors[]=$e->getMessage();
    $report=null; require __DIR__.'/ledger_view.php';
}
