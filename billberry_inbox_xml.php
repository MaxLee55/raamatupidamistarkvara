<?php
declare(strict_types=1);
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/db.php';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { http_response_code(400); exit('Vigane arve tunnus.'); }
$stmt = $pdo->prepare('SELECT invoice_number, invoice_xml FROM billberry_inbox WHERE id = ? AND company_id = ?');
$stmt->execute([$id, (int) $_SESSION['company_id']]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit('Saabunud e-arvet ei leitud.'); }
$safeName = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) ($row['invoice_number'] ?: 'arve'));
header('Content-Type: application/xml; charset=UTF-8'); header('Content-Disposition: attachment; filename="e-arve-' . $safeName . '.xml"'); header('X-Content-Type-Options: nosniff'); echo $row['invoice_xml'];
