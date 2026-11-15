<?php
declare(strict_types=1);
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/billberry_lib.php';
$companyId = (int) $_SESSION['company_id'];
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Vorm aegus. Värskenda lehte.');
    $settings = billberrySettings($companyId);
    $link = (string) ($settings['updates_link'] ?? '');
    $result = billberryRequest($companyId, $link !== '' ? $link : '/invoices/received');
    $items = json_decode($result['body'], true);
    if (!is_array($items)) throw new RuntimeException('Billberry vastust ei saanud arvete loendina lugeda.');
    $pdo->beginTransaction();
    $newCount = 0;
    foreach ($items as $item) {
        if (!is_array($item) || empty($item['id'])) continue;
        $remoteId = filter_var($item['id'], FILTER_VALIDATE_INT);
        if (!$remoteId) continue;
        $check = $pdo->prepare('SELECT id FROM billberry_inbox WHERE company_id = ? AND billberry_invoice_id = ?');
        $check->execute([$companyId, $remoteId]);
        if ($check->fetchColumn()) continue;
        $xmlResult = billberryRequest($companyId, '/invoices/' . $remoteId . '.xml', 'GET', null, 'application/xml');
        if (trim($xmlResult['body']) === '' || stripos($xmlResult['body'], '<E_Invoice') === false) continue;
        $date = (string) ($item['date'] ?? ''); $due = (string) ($item['dueDate'] ?? '');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
        $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null;
        $received = (string) ($item['receivedAt'] ?? '');
        $received = $received !== '' ? date('Y-m-d H:i:s', strtotime($received)) : null;
        $insert = $pdo->prepare('INSERT INTO billberry_inbox (company_id, billberry_invoice_id, invoice_number, invoice_date, due_date, sender_name, sender_registry_code, received_at, metadata_json, invoice_xml) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$companyId, $remoteId, (string) ($item['number'] ?? ''), $date, $due, (string) ($item['senderName'] ?? ''), (string) ($item['senderRegistryCode'] ?? ''), $received, json_encode($item, JSON_UNESCAPED_UNICODE), $xmlResult['body']]);
        $newCount++;
    }
    $pdo->commit();
    if ($result['updates_link'] !== '') billberrySaveUpdatesLink($companyId, $result['updates_link']);
    $_SESSION['billberry_notice'] = 'Sünkroonimine valmis. Uusi saabunud e-arveid lisati: ' . $newCount . '.';
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['billberry_error'] = $e->getMessage();
}
header('Location: billberry_inbox.php');
exit;
