<?php
declare(strict_types=1);
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) { header('Location: login.php'); exit; }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
require_once __DIR__ . '/db.php';
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
$companyId = (int) $_SESSION['company_id'];
$notice = (string) ($_SESSION['billberry_notice'] ?? ''); $error = (string) ($_SESSION['billberry_error'] ?? ''); unset($_SESSION['billberry_notice'], $_SESSION['billberry_error']);
$stmt = $pdo->prepare('SELECT id, billberry_invoice_id, invoice_number, invoice_date, due_date, sender_name, sender_registry_code, received_at, purchase_invoice_id FROM billberry_inbox WHERE company_id = ? ORDER BY COALESCE(invoice_date, DATE(created_at)) DESC, id DESC');
$stmt->execute([$companyId]); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html><html lang="et"><head>
    <link rel="icon" type="image/png" href="favicon.png?v=1"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Saabunud e-arved</title><style>
*{box-sizing:border-box}body{margin:0;padding:30px 18px;background:#f4f7fb;color:#243447;font-family:Arial,sans-serif}main{max-width:1200px;margin:auto}h1{color:#17324d}.card{padding:22px;background:#fff;border:1px solid #dce3ec;border-radius:12px}.button{display:inline-block;margin:6px 8px 12px 0;padding:11px 16px;border:0;border-radius:7px;background:#1769aa;color:white;font-weight:700;text-decoration:none;cursor:pointer}.secondary{background:#647589}.message{padding:13px 15px;border-radius:8px;margin:14px 0}.error{background:#fff0f0;color:#a12626;border:1px solid #efb4b4}.success{background:#eaf7ef;color:#176b3a;border:1px solid #b6dfc2}.muted{color:#647589}div.tablewrap{overflow:auto}table{width:100%;border-collapse:collapse;background:white}th,td{text-align:left;padding:11px 12px;border-bottom:1px solid #dce3ec;white-space:nowrap}th{background:#eef3f8;color:#33475b}</style></head><body><main>
<h1>Saabunud e-arved</h1><p class="muted">Billberryst toodud arved salvestatakse esmalt vaatamiseks. Neid ei kanta automaatselt raamatupidamisse.</p>
<?php if ($error !== ''): ?><div class="message error"><?= h($error) ?></div><?php endif; ?><?php if ($notice !== ''): ?><div class="message success"><?= h($notice) ?></div><?php endif; ?>
<section class="card"><form method="post" action="billberry_sync.php"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>"><button class="button" type="submit">Otsi uusi e-arveid Billberryst</button></form><div class="tablewrap"><table><thead><tr><th>Saabumise aeg</th><th>Kuupäev</th><th>Arve nr</th><th>Tarnija</th><th>Registrikood</th><th>Tähtaeg</th><th>Algfail</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7">Saabunud e-arveid pole veel sünkroonitud.</td></tr><?php endif; ?>
<?php foreach ($rows as $row): ?><tr><td><?= h((string) $row['received_at']) ?></td><td><?= h((string) $row['invoice_date']) ?></td><td><?= h((string) $row['invoice_number']) ?></td><td><?= h((string) $row['sender_name']) ?></td><td><?= h((string) $row['sender_registry_code']) ?></td><td><?= h((string) $row['due_date']) ?></td><td><?php if ($row['purchase_invoice_id']): ?><a href="purchase_invoices.php?id=<?= (int) $row['purchase_invoice_id'] ?>">Ostuarve avatud</a><?php else: ?><a href="billberry_import_purchase.php?id=<?= (int) $row['id'] ?>">Loo ostuarve mustand</a><?php endif; ?><br><a href="billberry_inbox_xml.php?id=<?= (int) $row['id'] ?>">Laadi XML alla</a></td></tr><?php endforeach; ?>
</tbody></table></div></section><p><a class="button secondary" href="index.php">Avalehele</a> <a class="button secondary" href="billberry_settings.php">Billberry seaded</a></p></main></body></html>
