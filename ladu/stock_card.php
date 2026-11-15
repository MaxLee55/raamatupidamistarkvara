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

function validDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function consumeFifo(array &$lots, float $quantity): void
{
    while ($quantity > 0.0000001) {
        if (!$lots) {
            throw new RuntimeException('Liikumiste FIFO laojääk läheb negatiivseks. Kontrolli varasemaid liikumisi.');
        }
        $take = min($quantity, $lots[0]['quantity']);
        $lots[0]['quantity'] -= $take;
        $quantity -= $take;
        if ($lots[0]['quantity'] < 0.0000001) {
            array_shift($lots);
        }
    }
}

$companyId = (int) $_SESSION['company_id'];
$today = date('Y-m-d');
$startDate = trim((string) ($_GET['start_date'] ?? date('Y-01-01')));
$endDate = trim((string) ($_GET['end_date'] ?? $today));
$productId = filter_var($_GET['product_id'] ?? null, FILTER_VALIDATE_INT);
$warehouseId = filter_var($_GET['warehouse_id'] ?? null, FILTER_VALIDATE_INT);
$errors = [];

if (!validDate($startDate) || !validDate($endDate)) {
    $errors[] = 'Vali kehtiv algus- ja lõpukuupäev.';
    $startDate = date('Y-01-01');
    $endDate = $today;
} elseif ($startDate > $endDate) {
    $errors[] = 'Perioodi alguskuupäev peab olema enne lõpukuupäeva.';
}

$productsStmt = $pdo->prepare('SELECT id, sku, name, unit FROM inventory_products WHERE company_id = ? ORDER BY name, sku');
$productsStmt->execute([$companyId]);
$products = $productsStmt->fetchAll();
$warehousesStmt = $pdo->prepare('SELECT id, code, name FROM inventory_warehouses WHERE company_id = ? ORDER BY name, code');
$warehousesStmt->execute([$companyId]);
$warehouses = $warehousesStmt->fetchAll();

$selectedProduct = null;
foreach ($products as $product) {
    if ($productId && (int) $product['id'] === $productId) {
        $selectedProduct = $product;
        break;
    }
}
$selectedWarehouse = null;
foreach ($warehouses as $warehouse) {
    if ($warehouseId && (int) $warehouse['id'] === $warehouseId) {
        $selectedWarehouse = $warehouse;
        break;
    }
}
if ($productId && !$selectedProduct) $errors[] = 'Valitud toodet ei leitud.';
if ($warehouseId && !$selectedWarehouse) $errors[] = 'Valitud ladu ei leitud.';

$openingQuantity = 0.0;
$openingValue = 0;
$runningQuantity = 0.0;
$runningValue = 0;
$rows = [];
$periodIn = 0.0;
$periodOut = 0.0;
$periodInValue = 0;
$periodOutValue = 0;

if ($selectedProduct && $selectedWarehouse && !$errors) {
    $eventsStmt = $pdo->prepare(
        'SELECT m.id AS movement_id, m.movement_type, m.movement_date, m.document_number,
                m.description AS movement_description, m.journal_entry_id,
                m.source_purchase_invoice_id, m.source_sales_invoice_id,
                l.id AS line_id, l.quantity_in, l.quantity_out, l.unit_cost, l.total_cost
         FROM inventory_movements m
         JOIN inventory_movement_lines l ON l.movement_id = m.id
         WHERE m.company_id = ? AND m.status = ? AND m.movement_date <= ?
           AND l.product_id = ? AND l.warehouse_id = ?
         ORDER BY m.movement_date, m.id, l.id'
    );
    $eventsStmt->execute([$companyId, 'posted', $endDate, (int) $selectedProduct['id'], (int) $selectedWarehouse['id']]);
    $events = $eventsStmt->fetchAll();
    $lots = [];
    foreach ($events as $event) {
        $in = (float) $event['quantity_in'];
        $out = (float) $event['quantity_out'];
        $lineValue = cents((string) $event['total_cost']);

        if ($in > 0) {
            $lots[] = ['quantity' => $in, 'unit_cost' => (string) $event['unit_cost']];
            $runningQuantity += $in;
            $runningValue += $lineValue;
        }
        if ($out > 0) {
            consumeFifo($lots, $out);
            $runningQuantity -= $out;
            $runningValue -= $lineValue;
        }

        if ($event['movement_date'] < $startDate) {
            $openingQuantity = $runningQuantity;
            $openingValue = $runningValue;
            continue;
        }

        if ($in > 0) {
            $periodIn += $in;
            $periodInValue += $lineValue;
        }
        if ($out > 0) {
            $periodOut += $out;
            $periodOutValue += $lineValue;
        }
        $rows[] = [
            'event' => $event,
            'quantity_in' => $in,
            'quantity_out' => $out,
            'value_in' => $in > 0 ? $lineValue : 0,
            'value_out' => $out > 0 ? $lineValue : 0,
            'balance_quantity' => $runningQuantity,
            'balance_value' => $runningValue,
        ];
    }
}

$endingQuantity = $openingQuantity;
$endingValue = $openingValue;
if ($rows) {
    $last = $rows[count($rows) - 1];
    $endingQuantity = $last['balance_quantity'];
    $endingValue = $last['balance_value'];
}
$typeLabels = ['receipt' => 'Vastuvõtt', 'issue' => 'Väljaminek', 'transfer' => 'Laosiire', 'adjustment' => 'Inventuuri korrigeerimine'];
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laokaart</title>
    <style>
        *{box-sizing:border-box}body{max-width:1450px;margin:32px auto;padding:0 20px;background:#f4f7fb;color:#263746;font-family:Arial,sans-serif}h1,h2{color:#17324d}.card{margin:18px 0;padding:20px;background:#fff;border:1px solid #dce3ec;border-radius:12px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:14px}.muted{color:#667788;line-height:1.5}.filters,.actions{display:flex;align-items:end;flex-wrap:wrap;gap:10px}label{display:grid;gap:6px;font-weight:600;color:#33475b}input,select{min-width:190px;min-height:42px;padding:9px 11px;border:1px solid #b8c4d1;border-radius:7px;background:#fff;font:inherit}.button,button{display:inline-block;min-height:40px;padding:10px 14px;border:0;border-radius:7px;background:#1769aa;color:#fff;font:inherit;font-weight:600;text-decoration:none;cursor:pointer}.secondary{background:#657789}.summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.metric{padding:15px;border:1px solid #e2e8f0;border-radius:9px;background:#f8fafc}.metric span{display:block;color:#667788;font-size:14px}.metric strong{display:block;margin-top:7px;color:#17324d;font-size:20px}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e3e9f0;text-align:left;vertical-align:top}th{background:#f3f6fa;color:#526579;white-space:nowrap}.num{text-align:right;white-space:nowrap}.empty{color:#667788}.error{padding:11px 14px;border:1px solid #e5a3a3;border-radius:7px;background:#fff0f0;color:#a32121}@media(max-width:850px){body{padding:0 12px}.topbar{align-items:flex-start;flex-direction:column}.summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
    </style>
</head>
<body>
    <header class="topbar">
        <div><h1>Laokaart</h1><p class="muted">Toote liikumised ja FIFO laojääk valitud laos. Summad põhinevad kinnitatud liikumistel.</p></div>
        <div class="actions"><a class="button secondary" href="movements.php">Kauba liikumised</a><a class="button secondary" href="index.php">Lao ülevaade</a><a class="button secondary" href="../index.php">Avalehele</a></div>
    </header>
    <?php foreach ($errors as $error): ?><p class="error"><?= h($error) ?></p><?php endforeach; ?>
    <section class="card">
        <form method="get" class="filters">
            <label>Toode *<select name="product_id" required><option value="">Vali toode</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>" <?= $selectedProduct && (int) $selectedProduct['id'] === (int) $product['id'] ? 'selected' : '' ?>><?= h($product['sku'] . ' — ' . $product['name']) ?></option><?php endforeach; ?></select></label>
            <label>Ladu *<select name="warehouse_id" required><option value="">Vali ladu</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= (int) $warehouse['id'] ?>" <?= $selectedWarehouse && (int) $selectedWarehouse['id'] === (int) $warehouse['id'] ? 'selected' : '' ?>><?= h($warehouse['code'] . ' — ' . $warehouse['name']) ?></option><?php endforeach; ?></select></label>
            <label>Alates<input type="date" name="start_date" value="<?= h($startDate) ?>" required></label>
            <label>Kuni<input type="date" name="end_date" value="<?= h($endDate) ?>" required></label>
            <button type="submit">Näita laokaarti</button>
        </form>
    </section>
    <?php if ($selectedProduct && $selectedWarehouse && !$errors): ?>
    <section class="card">
        <h2><?= h($selectedProduct['sku'] . ' — ' . $selectedProduct['name']) ?></h2>
        <p class="muted"><?= h($selectedWarehouse['code'] . ' — ' . $selectedWarehouse['name']) ?> · Periood <?= h($startDate) ?> kuni <?= h($endDate) ?></p>
        <div class="summary">
            <div class="metric"><span>Algsaldo</span><strong><?= h(qty((string) $openingQuantity)) ?> <?= h($selectedProduct['unit']) ?></strong><span><?= h(eur($openingValue)) ?></span></div>
            <div class="metric"><span>Sissetulekud</span><strong><?= h(qty((string) $periodIn)) ?> <?= h($selectedProduct['unit']) ?></strong><span><?= h(eur($periodInValue)) ?></span></div>
            <div class="metric"><span>Väljaminekud</span><strong><?= h(qty((string) $periodOut)) ?> <?= h($selectedProduct['unit']) ?></strong><span><?= h(eur($periodOutValue)) ?></span></div>
            <div class="metric"><span>Lõppsaldo</span><strong><?= h(qty((string) $endingQuantity)) ?> <?= h($selectedProduct['unit']) ?></strong><span><?= h(eur($endingValue)) ?></span></div>
        </div>
    </section>
    <section class="card">
        <h2>Liikumised</h2>
        <?php if (!$rows): ?><p class="empty">Valitud perioodis liikumisi ei ole.</p><?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Kuupäev</th><th>Liik</th><th>Dokument</th><th>Kirjeldus</th><th class="num">Sisse</th><th class="num">Välja</th><th class="num">Ühikuhind</th><th class="num">Liikumise väärtus</th><th class="num">Jääk</th><th class="num">FIFO väärtus</th><th>Algdokument</th></tr></thead><tbody>
        <tr><td><?= h($startDate) ?></td><td>Algsaldo</td><td>—</td><td>Perioodi alguse laojääk</td><td class="num">—</td><td class="num">—</td><td class="num">—</td><td class="num">—</td><td class="num"><?= h(qty((string) $openingQuantity)) ?> <?= h($selectedProduct['unit']) ?></td><td class="num"><?= h(eur($openingValue)) ?></td><td>—</td></tr>
        <?php foreach ($rows as $row): $event = $row['event']; $sourceLink = ''; if (!empty($event['source_purchase_invoice_id'])) $sourceLink = '../purchase_invoices.php?id=' . (int) $event['source_purchase_invoice_id']; elseif (!empty($event['source_sales_invoice_id'])) $sourceLink = '../invoices.php?id=' . (int) $event['source_sales_invoice_id']; elseif (!empty($event['journal_entry_id'])) $sourceLink = '../entry.php?id=' . (int) $event['journal_entry_id']; ?>
        <tr>
            <td><?= h($event['movement_date']) ?></td>
            <td><?= h($typeLabels[$event['movement_type']] ?? $event['movement_type']) ?></td>
            <td><?= h($event['document_number'] !== null && $event['document_number'] !== '' ? $event['document_number'] : '—') ?></td>
            <td><?= h($event['movement_description'] ?? '') ?></td>
            <td class="num"><?= $row['quantity_in'] > 0 ? h(qty((string) $row['quantity_in']) . ' ' . $selectedProduct['unit']) : '—' ?></td>
            <td class="num"><?= $row['quantity_out'] > 0 ? h(qty((string) $row['quantity_out']) . ' ' . $selectedProduct['unit']) : '—' ?></td>
            <td class="num"><?= h(number_format((float) $event['unit_cost'], 4, ',', ' ') . ' €') ?></td>
            <td class="num"><?= h($row['quantity_in'] > 0 ? '+' . eur($row['value_in']) : ($row['quantity_out'] > 0 ? '−' . eur($row['value_out']) : '—')) ?></td>
            <td class="num"><?= h(qty((string) $row['balance_quantity'])) ?> <?= h($selectedProduct['unit']) ?></td>
            <td class="num"><?= h(eur($row['balance_value'])) ?></td>
            <td><?= $sourceLink !== '' ? '<a href="' . h($sourceLink) . '">Ava</a>' : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </section>
    <?php endif; ?>
</body>
</html>
