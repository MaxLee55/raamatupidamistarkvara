<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once dirname(__DIR__) . '/db.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function cents(string $amount): int
{
    $negative = isset($amount[0]) && $amount[0] === '-';
    $amount = ltrim($amount, '-');
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
    $value = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    return $negative ? -$value : $value;
}

function eur(int $value): string
{
    return number_format($value / 100, 2, ',', ' ') . ' €';
}

function qty(string $value): string
{
    return rtrim(rtrim(number_format((float) $value, 3, ',', ' '), '0'), ',');
}

$companyId = (int) $_SESSION['company_id'];
$date = trim((string) ($_GET['date'] ?? date('Y-m-d')));
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
$errors = [];
if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
    $errors[] = 'Vali kehtiv kuupäev.';
    $date = date('Y-m-d');
}

$stockStmt = $pdo->prepare(
    'SELECT p.id, p.sku, p.name, p.unit, p.inventory_account_id,
            a.code AS account_code, a.name AS account_name, a.account_type,
            COALESCE(SUM(CASE WHEN m.id IS NOT NULL THEN l.quantity_in - l.quantity_out ELSE 0 END), 0) AS quantity,
            COALESCE(SUM(CASE WHEN m.id IS NOT NULL AND l.quantity_in > 0 THEN l.total_cost
                              WHEN m.id IS NOT NULL THEN -l.total_cost ELSE 0 END), 0) AS stock_value
     FROM inventory_products p
     LEFT JOIN inventory_movement_lines l ON l.product_id = p.id
     LEFT JOIN inventory_movements m ON m.id = l.movement_id
        AND m.company_id = p.company_id AND m.status = ? AND m.movement_date <= ?
     LEFT JOIN accounts a ON a.id = p.inventory_account_id AND a.company_id = p.company_id
     WHERE p.company_id = ?
     GROUP BY p.id, p.sku, p.name, p.unit, p.inventory_account_id,
              a.code, a.name, a.account_type
     ORDER BY a.code, p.name, p.sku'
);
$stockStmt->execute(['posted', $date, $companyId]);
$products = $stockStmt->fetchAll();

$ledgerStmt = $pdo->prepare(
    'SELECT a.id, a.code, a.name, a.account_type,
            COALESCE(SUM(CASE WHEN je.id IS NOT NULL THEN jl.debit - jl.credit ELSE 0 END), 0) AS balance
     FROM accounts a
     LEFT JOIN journal_lines jl ON jl.account_id = a.id
     LEFT JOIN journal_entries je ON je.id = jl.entry_id
        AND je.company_id = a.company_id AND je.status = ? AND je.entry_date <= ?
     WHERE a.company_id = ? AND a.is_active = 1
     GROUP BY a.id, a.code, a.name, a.account_type
     ORDER BY a.code'
);
$ledgerStmt->execute(['posted', $date, $companyId]);
$ledgerAccounts = [];
foreach ($ledgerStmt->fetchAll() as $account) {
    $ledgerAccounts[(int) $account['id']] = $account;
}

$byAccount = [];
$unmapped = [];
foreach ($products as &$product) {
    $product['stock_value_cents'] = cents((string) $product['stock_value']);
    if (empty($product['inventory_account_id'])) {
        if ((float) $product['quantity'] !== 0.0 || $product['stock_value_cents'] !== 0) $unmapped[] = $product;
        continue;
    }
    $accountId = (int) $product['inventory_account_id'];
    if (!isset($byAccount[$accountId])) {
        $byAccount[$accountId] = [
            'code' => $product['account_code'] ?? '—',
            'name' => $product['account_name'] ?? 'Konto puudub',
            'account_type' => $product['account_type'] ?? '',
            'stock_cents' => 0,
            'ledger_cents' => isset($ledgerAccounts[$accountId]) ? cents((string) $ledgerAccounts[$accountId]['balance']) : 0,
        ];
    }
    $byAccount[$accountId]['stock_cents'] += $product['stock_value_cents'];
}
unset($product);

$totalStock = 0;
$totalLedger = 0;
foreach ($byAccount as &$row) {
    $row['difference_cents'] = $row['ledger_cents'] - $row['stock_cents'];
    $row['valid'] = $row['account_type'] === 'asset' && $row['difference_cents'] === 0;
    $totalStock += $row['stock_cents'];
    $totalLedger += $row['ledger_cents'];
}
unset($row);
$message = (string) ($_GET['message'] ?? '');
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laovaru ja pearaamatu võrdlus</title>
    <style>
        *{box-sizing:border-box}body{max-width:1200px;margin:32px auto;padding:0 20px;background:#f4f7fb;color:#263746;font-family:Arial,sans-serif}h1,h2{color:#17324d}.card{margin:18px 0;padding:20px;background:#fff;border:1px solid #dce3ec;border-radius:12px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:14px}.muted{color:#667788;line-height:1.5}.filters,.actions{display:flex;align-items:end;flex-wrap:wrap;gap:10px}label{display:grid;gap:6px;font-weight:600;color:#33475b}input{min-height:42px;padding:9px 11px;border:1px solid #b8c4d1;border-radius:7px;background:#fff;font:inherit}.button,button{display:inline-block;min-height:40px;padding:10px 14px;border:0;border-radius:7px;background:#1769aa;color:#fff;font:inherit;font-weight:600;text-decoration:none;cursor:pointer}.secondary{background:#657789}.warning,.error{padding:12px 14px;border-radius:8px;line-height:1.5}.warning{border:1px solid #ead9a3;background:#fff8e6;color:#71520a}.error{border:1px solid #e5a3a3;background:#fff0f0;color:#a32121}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e3e9f0;text-align:left;vertical-align:top}th{background:#f3f6fa;color:#526579;white-space:nowrap}.num{text-align:right;white-space:nowrap}.ok{color:#176b32;font-weight:700}.bad{color:#a32121;font-weight:700}@media(max-width:700px){body{padding:0 12px}.topbar{align-items:flex-start;flex-direction:column}}
    </style>
</head>
<body>
    <header class="topbar"><div><h1>Laovaru ja pearaamatu võrdlus</h1><p class="muted">Võrdleb valitud kuupäeva FIFO laoväärtust tootekaartidel määratud varukontode saldodega.</p></div><div class="actions"><a class="button secondary" href="index.php">Lao ülevaade</a><a class="button secondary" href="movements.php">Kauba liikumised</a><a class="button secondary" href="../index.php">Avalehele</a></div></header>
    <?php foreach ($errors as $error): ?><p class="error"><?= h($error) ?></p><?php endforeach; ?>
    <section class="card">
        <form method="get" class="filters"><label>Seisuga kuupäev<input type="date" name="date" value="<?= h($date) ?>" required></label><button type="submit">Näita võrdlust</button></form>
        <p class="muted">Arvesse lähevad ainult kinnitatud laoliikumised ja pearaamatu kanded kuni <?= h($date) ?>. Täpseks võrdluseks kasuta laovaru jaoks eraldi varakontosid.</p>
        <?php if ($unmapped): ?><p class="warning">Mõne laojäägiga toote tootekaardil puudub varude konto. Need summad on toodud eraldi allpool ega sisaldu kontode võrdluses.</p><?php endif; ?>
        <?php if (!$byAccount): ?><p class="muted">Toodetel pole kuupäeva seisuga varude kontoga seotud laojääki.</p><?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Varude konto</th><th class="num">FIFO laoväärtus</th><th class="num">Pearaamatu saldo</th><th class="num">Erinevus</th><th>Kontroll</th></tr></thead><tbody>
        <?php foreach ($byAccount as $row): ?><tr><td><?= h($row['code'] . ' — ' . $row['name']) ?><?= $row['account_type'] !== 'asset' ? '<br><span class="bad">Konto pole varakonto</span>' : '' ?></td><td class="num"><?= h(eur($row['stock_cents'])) ?></td><td class="num"><?= h(eur($row['ledger_cents'])) ?></td><td class="num"><?= h(eur($row['difference_cents'])) ?></td><td class="<?= $row['valid'] ? 'ok' : 'bad' ?>"><?= $row['valid'] ? 'Klapib' : 'Kontrolli' ?></td></tr><?php endforeach; ?>
        <tr><th>Kokku</th><th class="num"><?= h(eur($totalStock)) ?></th><th class="num"><?= h(eur($totalLedger)) ?></th><th class="num"><?= h(eur($totalLedger - $totalStock)) ?></th><th></th></tr>
        </tbody></table></div><?php endif; ?>
    </section>
    <section class="card"><h2>Toodete FIFO laojääk</h2>
        <?php if (!$products): ?><p class="muted">Valitud kuupäeval kinnitatud laojääke pole.</p><?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Tootekood</th><th>Toode</th><th class="num">Kogus</th><th>Varude konto</th><th class="num">FIFO väärtus</th></tr></thead><tbody>
        <?php foreach ($products as $product): ?><?php if ((float) $product['quantity'] === 0.0 && $product['stock_value_cents'] === 0) continue; ?><tr><td><?= h($product['sku']) ?></td><td><?= h($product['name']) ?></td><td class="num"><?= h(qty((string) $product['quantity'])) ?> <?= h($product['unit']) ?></td><td><?= empty($product['inventory_account_id']) ? '<span class="bad">Määramata</span>' : h(($product['account_code'] ?? '—') . ' — ' . ($product['account_name'] ?? 'Konto puudub')) ?></td><td class="num"><?= h(eur($product['stock_value_cents'])) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </section>
</body>
</html>
