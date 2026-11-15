<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('See toiming tuleb teha vormi kaudu.');
}

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    exit('Vorm aegus. Mine kande juurde tagasi ja proovi uuesti.');
}

require_once __DIR__ . '/db.php';
require_once __DIR__.'/journal_draft_lib.php';
ensureJournalCorrectionSchema($pdo);
require_once __DIR__ . '/period_lock.php';

$originalId = filter_input(INPUT_POST, 'entry_id', FILTER_VALIDATE_INT);

if (!$originalId) {
    http_response_code(400);
    exit('Vigane kande ID.');
}

$companyId = (int) $_SESSION['company_id'];

try {
    $newEntryId=createJournalReversalDraft($pdo,$companyId,(int)$_SESSION['user_id'],$originalId,date('Y-m-d'));
    header('Location: entry.php?id='.$newEntryId); exit;
} catch(Throwable $e) {
    if($pdo->inTransaction()) $pdo->rollBack();
    error_log($e->getMessage()); http_response_code(400);
    echo htmlspecialchars($e instanceof PDOException?'Paranduskande mustandi loomine ebaõnnestus.':$e->getMessage(),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    echo '<p><a href="entry.php?id='.(int)$originalId.'">Tagasi algse kande juurde</a></p>';
}