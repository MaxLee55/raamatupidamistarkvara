<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    http_response_code(401);
    echo json_encode(['results' => [], 'error' => 'Sessioon on aegunud.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/business_registry.php';

$query = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($query, 'UTF-8') < 3 || mb_strlen($query, 'UTF-8') > 100) {
    echo json_encode(['results' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$companyId = (int) $_SESSION['company_id'];
$contactType = ($_GET['type'] ?? 'customer') === 'supplier' ? 'supplier' : 'customer';
$contactTypeFilter = $contactType === 'supplier'
    ? "contact_type IN ('supplier', 'both')"
    : "contact_type IN ('customer', 'both')";
$localStmt = $pdo->prepare(
    "SELECT id, name, registry_code
     FROM contacts
     WHERE company_id = :company_id
       AND is_active = 1
       AND $contactTypeFilter
       AND (name LIKE :name_query OR registry_code LIKE :code_query)
     ORDER BY name
     LIMIT 10"
);
$localStmt->execute([
    'company_id' => $companyId,
    'name_query' => '%' . $query . '%',
    'code_query' => '%' . $query . '%',
]);

$results = [];
$knownRegistryCodes = [];
foreach ($localStmt->fetchAll(PDO::FETCH_ASSOC) as $contact) {
    $registryCode = (string) ($contact['registry_code'] ?? '');
    if ($registryCode !== '') {
        $knownRegistryCodes[$registryCode] = true;
    }
    $results[] = [
        'kind' => 'contact',
        'id' => (int) $contact['id'],
        'name' => (string) $contact['name'],
        'registry_code' => $registryCode,
    ];
}

foreach (fetchBusinessRegistryCompanies($query) as $company) {
    if (isset($knownRegistryCodes[$company['registry_code']])) {
        continue;
    }

    $results[] = [
        'kind' => 'registry',
        'name' => $company['name'],
        'registry_code' => $company['registry_code'],
        'address' => $company['address'],
        'status' => $company['status'],
        'url' => $company['url'],
    ];

    if (count($results) >= 10) {
        break;
    }
}

echo json_encode(['results' => array_slice($results, 0, 10)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
