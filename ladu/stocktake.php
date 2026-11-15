<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: ../login.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once dirname(__DIR__) . '/db.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function stocktakeDateIsValid(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function stocktakeDecimal(string $value, int $scale): string
{
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $whole = ltrim($whole, '0');
    return ($whole === '' ? '0' : $whole) . '.' . str_pad(substr($fraction, 0, $scale), $scale, '0');
}

function displayQuantity(string $value): string
{
    return rtrim(rtrim(number_format((float) $value, 3, ',', ' '), '0'), ',');
}

$companyId = (int) $_SESSION['company_id'];
$today = date('Y-m-d');
$date = trim((string) ($_POST['movement_date'] ?? $_GET['date'] ?? $today));
$form = [
    'warehouse_id' => trim((string) ($_POST['warehouse_id'] ?? '')),
    'product_id' => trim((string) ($_POST['product_id'] ?? '')),
    'counted_quantity' => str_replace(',', '.', trim((string) ($_POST['counted_quantity'] ?? ''))),
    'surplus_unit_cost' => str_replace(',', '.', trim((string) ($_POST['surplus_unit_cost'] ?? ''))),
    'document_number' => trim((string) ($_POST['document_number'] ?? '')),
    'description' => trim((string) ($_POST['description'] ?? '')),
];
$errors = [];
$message = '';

$warehousesStmt = $pdo->prepare('SELECT id, code, name FROM inventory_warehouses WHERE company_id = ? AND is_active = 1 ORDER BY name, code');
$warehousesStmt->execute([$companyId]);
$warehouses = $warehousesStmt->fetchAll();
$productsStmt = $pdo->prepare('SELECT id, sku, name, unit FROM inventory_products WHERE company_id = ? AND is_active = 1 ORDER BY name, sku');
$productsStmt->execute([$companyId]);
$products = $productsStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    }
    if (!stocktakeDateIsValid($date)) $errors[] = 'Vali kehtiv inventuuri kuupäev.';
    $warehouseId = filter_var($form['warehouse_id'], FILTER_VALIDATE_INT);
    $productId = filter_var($form['product_id'], FILTER_VALIDATE_INT);
    if (!$warehouseId || $warehouseId < 1) $errors[] = 'Vali ladu.';
    if (!$productId || $productId < 1) $errors[] = 'Vali toode.';
    if (!preg_match('/^\d{1,12}(?:\.\d{1,3})?$/', $form['counted_quantity'])) $errors[] = 'Loendatud kogus peab olema null või positiivne ja kuni kolme komakohaga.';
    if ($form['document_number'] !== '' && mb_strlen($form['document_number']) > 80) $errors[] = 'Dokumendi number võib olla kuni 80 märki.';
    if (mb_strlen($form['description']) > 500) $errors[] = 'Kirjeldus võib olla kuni 500 märki.';
    if (!$errors) {
        $check = $pdo->prepare('SELECT id FROM inventory_warehouses WHERE id = ? AND company_id = ? AND is_active = 1');
        $check->execute([$warehouseId, $companyId]);
        if (!$check->fetchColumn()) $errors[] = 'Valitud ladu puudub või ei ole aktiivne.';
        $check = $pdo->prepare('SELECT id FROM inventory_products WHERE id = ? AND company_id = ? AND is_active = 1');
        $check->execute([$productId, $companyId]);
        if (!$check->fetchColumn()) $errors[] = 'Valitud toode puudub või ei ole aktiivne.';
    }
    if (!$errors) {
        $laterMovementCheck = $pdo->prepare(
            'SELECT COUNT(*) FROM inventory_movement_lines l
             JOIN inventory_movements m ON m.id = l.movement_id
             WHERE m.company_id = ? AND m.status = ? AND m.movement_date > ?
               AND l.warehouse_id = ? AND l.product_id = ?'
        );
        $laterMovementCheck->execute([$companyId, 'posted', $date, $warehouseId, $productId]);
        if ((int) $laterMovementCheck->fetchColumn() > 0) {
            $errors[] = 'Selle toote ja lao kohta on valitud kuupäevast hilisemaid kinnitatud liikumisi. FIFO arvestuse kaitseks vali hilisem kuupäev.';
        }
    }
    if (!$errors) {
        $balanceStmt = $pdo->prepare(
            'SELECT COALESCE(SUM(l.quantity_in - l.quantity_out), 0) AS quantity
             FROM inventory_movement_lines l
             JOIN inventory_movements m ON m.id = l.movement_id
             WHERE m.company_id = ? AND m.status = ? AND m.movement_date <= ?
               AND l.warehouse_id = ? AND l.product_id = ?'
        );
        $balanceStmt->execute([$companyId, 'posted', $date, $warehouseId, $productId]);
        $bookQuantity = (float) $balanceStmt->fetchColumn();
        $countedQuantity = (float) $form['counted_quantity'];
        $difference = round($countedQuantity - $bookQuantity, 3);
        if (abs($difference) < 0.0005) {
            $errors[] = 'Loendatud kogus ja süsteemi jääk on samad. Korrigeerimist pole vaja.';
        }
        $unitCost = '0.0000';
        if ($difference > 0) {
            if (!preg_match('/^\d{1,11}(?:\.\d{1,4})?$/', $form['surplus_unit_cost'])) {
                $errors[] = 'Ülejäägi ühiku soetusmaksumus peab olema määratud (võib olla 0) ja kuni nelja komakohaga.';
            } else {
                $unitCost = stocktakeDecimal($form['surplus_unit_cost'], 4);
            }
        }
    }
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $movementStmt = $pdo->prepare('INSERT INTO inventory_movements (company_id, movement_type, movement_date, document_number, description, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $movementDescription = 'Inventuur: süsteemi jääk ' . number_format($bookQuantity, 3, '.', '') . ', loendatud ' . number_format($countedQuantity, 3, '.', '') . ($form['description'] !== '' ? '. ' . $form['description'] : '');
            $movementStmt->execute([$companyId, 'adjustment', $date, $form['document_number'] !== '' ? $form['document_number'] : null, $movementDescription, 'draft', (int) $_SESSION['user_id']]);
            $movementId = (int) $pdo->lastInsertId();
            $quantity = stocktakeDecimal(number_format(abs($difference), 3, '.', ''), 3);
            $quantityIn = $difference > 0 ? $quantity : '0.000';
            $quantityOut = $difference < 0 ? $quantity : '0.000';
            $lineStmt = $pdo->prepare('INSERT INTO inventory_movement_lines (movement_id, warehouse_id, product_id, quantity_in, quantity_out, unit_cost, total_cost) VALUES (?, ?, ?, ?, ?, ?, ROUND(? * ?, 2))');
            $lineStmt->execute([$movementId, $warehouseId, $productId, $quantityIn, $quantityOut, $unitCost, $quantityIn, $unitCost]);
            $pdo->commit();
            header('Location: movements.php?message=adjustment_draft');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Inventuuri korrigeerimise mustandi loomine ebaõnnestus: ' . $e->getMessage());
            $errors[] = 'Inventuuri korrigeerimise salvestamine ebaõnnestus.';
        }
    }
}

$balanceDate = stocktakeDateIsValid($date) ? $date : $today;
$balancesStmt = $pdo->prepare(
    'SELECT w.id AS warehouse_id, w.code AS warehouse_code, w.name AS warehouse_name,
            p.id AS product_id, p.sku, p.name AS product_name, p.unit,
            SUM(l.quantity_in - l.quantity_out) AS quantity,
            SUM(CASE WHEN l.quantity_in > 0 THEN l.total_cost ELSE -l.total_cost END) AS stock_value
     FROM inventory_movement_lines l
     JOIN inventory_movements m ON m.id = l.movement_id
     JOIN inventory_warehouses w ON w.id = l.warehouse_id
     JOIN inventory_products p ON p.id = l.product_id
     WHERE m.company_id = ? AND m.status = ? AND m.movement_date <= ?
     GROUP BY w.id, w.code, w.name, p.id, p.sku, p.name, p.unit
     HAVING SUM(l.quantity_in - l.quantity_out) <> 0 OR SUM(CASE WHEN l.quantity_in > 0 THEN l.total_cost ELSE -l.total_cost END) <> 0
     ORDER BY w.name, p.name, p.sku'
);
$balancesStmt->execute([$companyId, 'posted', $balanceDate]);
$balances = $balancesStmt->fetchAll();

$referenceCostStmt = $pdo->prepare(
    'SELECT COALESCE(SUM(CASE WHEN l.quantity_in > 0 THEN l.total_cost ELSE -l.total_cost END), 0) AS value,
            COALESCE(SUM(l.quantity_in - l.quantity_out), 0) AS quantity
     FROM inventory_movement_lines l
     JOIN inventory_movements m ON m.id = l.movement_id
     WHERE m.company_id = ? AND m.status = ? AND m.movement_date <= ?
       AND l.warehouse_id = ? AND l.product_id = ?'
);

$selectedBalance = null;
if ($form['warehouse_id'] !== '' && $form['product_id'] !== '' && stocktakeDateIsValid($balanceDate)) {
    $referenceCostStmt->execute([$companyId, 'posted', $balanceDate, (int) $form['warehouse_id'], (int) $form['product_id']]);
    $selectedBalance = $referenceCostStmt->fetch();
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inventuur ja jäägi korrigeerimine</title>
    <style>
        *{box-sizing:border-box}body{max-width:1200px;margin:32px auto;padding:0 20px;background:#f4f7fb;color:#263746;font-family:Arial,sans-serif}h1,h2{color:#17324d}.card{margin:18px 0;padding:20px;background:#fff;border:1px solid #dce3ec;border-radius:12px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:14px}.actions{display:flex;align-items:center;flex-wrap:wrap;gap:8px}.button,button{display:inline-block;min-height:40px;padding:10px 14px;border:0;border-radius:7px;background:#1769aa;color:#fff;font:inherit;font-weight:600;text-decoration:none;cursor:pointer}.secondary{background:#657789}.fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.wide{grid-column:1/-1}label{display:grid;gap:6px;font-weight:600;color:#33475b}input,select,textarea{width:100%;min-height:42px;padding:9px 11px;border:1px solid #b8c4d1;border-radius:7px;background:#fff;font:inherit}textarea{min-height:74px;resize:vertical}.muted{color:#667788;line-height:1.5}.warning{padding:12px 14px;border:1px solid #ead9a3;border-radius:8px;background:#fff8e6;color:#71520a;line-height:1.5}.message{padding:11px 14px;border-radius:7px;background:#fff0f0;color:#a32121;border:1px solid #e5a3a3}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e3e9f0;text-align:left;vertical-align:top}th{background:#f3f6fa;color:#526579;white-space:nowrap}.num{text-align:right;white-space:nowrap}.empty{color:#667788}@media(max-width:700px){body{padding:0 12px}.topbar{align-items:flex-start;flex-direction:column}.fields{grid-template-columns:1fr}.wide{grid-column:auto}}
    </style>
</head>
<body>
    <header class="topbar"><div><h1>Inventuur ja jäägi korrigeerimine</h1><p class="muted">Võrdle süsteemi kogust loendatud kogusega ja koosta vahe kohta kinnitatav laoliikumine.</p></div><div class="actions"><a class="button secondary" href="movements.php">Kauba liikumised</a><a class="button secondary" href="index.php">Lao ülevaade</a><a class="button secondary" href="../index.php">Avalehele</a></div></header>
    <?php foreach ($errors as $error): ?><p class="message"><?= h($error) ?></p><?php endforeach; ?>
    <p class="warning">Korrigeerimise kinnitamisel FIFO puudujääk eemaldatakse vanimatest soetuskihtidest. Ülejäägi väärtustamiseks sisesta selle ühiku soetusmaksumus. Laoarvestus ei loo veel pearaamatu kannet.</p>
    <section class="card">
        <h2>Loenduse tulemus</h2>
        <form method="post" class="fields">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <label>Inventuuri kuupäev *<input type="date" name="movement_date" required value="<?= h($date) ?>"></label>
            <label>Ladu *<select name="warehouse_id" required><option value="">Vali ladu</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= (int) $warehouse['id'] ?>" <?= (string) $warehouse['id'] === $form['warehouse_id'] ? 'selected' : '' ?>><?= h($warehouse['code'] . ' — ' . $warehouse['name']) ?></option><?php endforeach; ?></select></label>
            <label>Toode *<select name="product_id" required><option value="">Vali toode</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>" <?= (string) $product['id'] === $form['product_id'] ? 'selected' : '' ?>><?= h($product['sku'] . ' — ' . $product['name'] . ' (' . $product['unit'] . ')') ?></option><?php endforeach; ?></select></label>
            <label>Loendatud kogus *<input name="counted_quantity" inputmode="decimal" required value="<?= h($form['counted_quantity']) ?>" placeholder="nt 12 või 2,500"></label>
            <label>Ülejäägi ühiku soetusmaksumus (€)<input name="surplus_unit_cost" inputmode="decimal" value="<?= h($form['surplus_unit_cost']) ?>" placeholder="Täida ainult ülejäägi korral"></label>
            <label>Dokumendi number<input name="document_number" maxlength="80" value="<?= h($form['document_number']) ?>" placeholder="nt inventuuri akt nr 1"></label>
            <label class="wide">Märkused<textarea name="description" maxlength="500" placeholder="Täiendav selgitus"><?= h($form['description']) ?></textarea></label>
            <?php if ($selectedBalance): ?><p class="wide muted">Süsteemi kogus valitud kuupäeval: <strong><?= h(displayQuantity((string) $selectedBalance['quantity'])) ?></strong>. Raamatuväärtuse järgi keskmine ühikuhind: <strong><?= (float) $selectedBalance['quantity'] != 0 ? h(number_format((float) $selectedBalance['value'] / (float) $selectedBalance['quantity'], 4, ',', ' ') . ' €') : '—' ?></strong>. See on üksnes abiväärtus; ülejäägi hinna valid ise.</p><?php endif; ?>
            <div class="wide actions"><button type="submit">Koosta korrigeerimise mustand</button></div>
        </form>
    </section>
    <section class="card">
        <h2>Laojäägid kuupäeva <?= h($balanceDate) ?> seisuga</h2>
        <?php if (!$balances): ?><p class="empty">Valitud kuupäevaks kinnitatud laojääke ei ole.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Ladu</th><th>Tootekood</th><th>Toode</th><th class="num">Süsteemi kogus</th><th class="num">Raamatuväärtus</th></tr></thead><tbody><?php foreach ($balances as $balance): ?><tr><td><?= h($balance['warehouse_code'] . ' — ' . $balance['warehouse_name']) ?></td><td><?= h($balance['sku']) ?></td><td><?= h($balance['product_name']) ?></td><td class="num"><?= h(displayQuantity((string) $balance['quantity'])) ?> <?= h($balance['unit']) ?></td><td class="num"><?= h(number_format((float) $balance['stock_value'], 2, ',', ' ')) ?> €</td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>
</body>
</html>
