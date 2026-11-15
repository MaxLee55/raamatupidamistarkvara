<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: ../login.php');
    exit;
}

require_once dirname(__DIR__) . '/db.php';
$companyId = (int) $_SESSION['company_id'];
$lowStockStmt = $pdo->prepare(
    'SELECT p.id, p.sku, p.name, p.unit, p.minimum_stock,
            COALESCE(SUM(CASE WHEN m.id IS NOT NULL THEN l.quantity_in - l.quantity_out ELSE 0 END), 0) AS quantity
     FROM inventory_products p
     LEFT JOIN inventory_movement_lines l ON l.product_id = p.id
     LEFT JOIN inventory_movements m ON m.id = l.movement_id
        AND m.company_id = p.company_id AND m.status = ?
     WHERE p.company_id = ? AND p.is_active = 1 AND p.minimum_stock > 0
     GROUP BY p.id, p.sku, p.name, p.unit, p.minimum_stock
     HAVING quantity <= p.minimum_stock
     ORDER BY quantity, p.name, p.sku'
);
$lowStockStmt->execute(['posted', $companyId]);
$lowStockProducts = $lowStockStmt->fetchAll();
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ladu</title>
    <style>
        * { box-sizing: border-box; }
        body { max-width: 1120px; margin: 34px auto; padding: 0 22px; background: #f4f7fb; color: #263746; font-family: Arial, sans-serif; }
        h1, h2, h3 { color: #17324d; }
        .topbar, .panel { margin-bottom: 18px; padding: 20px 22px; background: #fff; border: 1px solid #dce3ec; border-radius: 12px; }
        .topbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        h1 { margin: 0; }
        .muted { color: #667788; line-height: 1.55; }
        .button { display: inline-block; min-height: 42px; padding: 11px 15px; border-radius: 7px; background: #1769aa; color: #fff; font-weight: 600; text-decoration: none; }
        .actions { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
        .roadmap { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .stage { padding: 18px; border: 1px solid #e1e7ef; border-radius: 10px; background: #f8fafc; }
        .stage h3 { margin: 0 0 8px; }
        .stage p { margin: 0; line-height: 1.5; }
        .status { display: inline-block; margin-bottom: 8px; padding: 4px 9px; border-radius: 999px; background: #eaf3fb; color: #1769aa; font-size: 13px; font-weight: 700; }
        .next { border-color: #9dc5e4; background: #f0f7fc; }
        @media (max-width: 650px) { body { margin: 18px auto; padding: 0 14px; } .topbar { align-items: flex-start; flex-direction: column; } .roadmap { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <header class="topbar">
        <div>
            <h1>Ladu</h1>
            <p class="muted">Tootekaardid, laod, ostude vastuvõtt, müük, FIFO arvestus ja laoseisu kontroll.</p>
        </div>
        <div class="actions"><a class="button" href="products.php">Tootekaardid</a> <a class="button" href="warehouses.php">Laod ja asukohad</a> <a class="button" href="movements.php">Kauba liikumised</a> <a class="button" href="stock_card.php">Laokaart</a> <a class="button" href="stocktake.php">Inventuur</a> <a class="button" href="reconciliation.php">Laovaru võrdlus</a> <a class="button" href="../index.php">Avalehele</a></div>
    </header>

    <section class="panel">
        <h2>Lao põhifunktsioonid</h2>
        <p class="muted">Ostud, müük, laoseis ja FIFO omahind on seotud raamatupidamisega. Laovaru võrdluse lehel saad kontrollida FIFO väärtust varude pearaamatukontode vastu.</p>
        <div class="roadmap">
            <article class="stage">
                <span class="status">Valmis</span>
                <h3>Tootekaardid</h3>
                <p>Tootekood või ribakood, nimetus, ühik, kirjeldus ja minimaalne laojääk. Laoväärtuse aluseks jääb kaupade tegelik soetusmaksumus.</p>
                <p style="margin-top:12px"><a href="products.php">Ava tootekaardid</a></p>
            </article>
            <article class="stage">
                <span class="status">Valmis</span>
                <h3>Laod ja asukohad</h3>
                <p>Üks või mitu füüsilist või virtuaalset ladu ning nendevahelised liikumised.</p>
                <p style="margin-top:12px"><a href="warehouses.php">Ava ladude haldus</a></p>
            </article>
            <article class="stage">
                <span class="status">Valmis</span>
                <h3>Kauba liikumised</h3>
                <p>Kauba vastuvõtt, FIFO järgi hinnastatud väljaminek ning ladudevaheline siire; laoseisu ei saa kinnitamisega negatiivseks muuta.</p>
                <p style="margin-top:12px"><a href="movements.php">Ava kauba liikumised</a></p>
            </article>
            <article class="stage">
                <span class="status">Valmis</span>
                <h3>FIFO ja raamatupidamine</h3>
                <p>Ostuarvega seotud vastuvõtt suurendab laovaru. Müügiarve ja väljaminek vähendavad seda FIFO järgi ning kannavad omahinna kuluks. Inventuurikorrigeerimised loovad samuti pearaamatukande.</p>
                <p style="margin-top:12px"><a href="reconciliation.php">Ava laovaru võrdlus</a></p>
            </article>
            <article class="stage">
                <span class="status">Valmis</span>
                <h3>Inventuur</h3>
                <p>Süsteemi jäägi võrdlus loendatud kogusega ja korrigeerimise mustandi kinnitamine. Puudujääk arvestatakse FIFO järgi.</p>
                <p style="margin-top:12px"><a href="stocktake.php">Ava inventuur</a></p>
            </article>
        </div>
    </section>

    <section class="panel">
        <h2>Vajab täiendamist</h2>
        <p class="muted">Siin kuvatakse aktiivsed tooted, mille kogus kõigis ladudes kokku on jõudnud tootekaardil määratud miinimumini või sellest allapoole.</p>
        <?php if (!$lowStockProducts): ?>
            <p class="status">Miinimumkogusest allapoole jõudnud tooteid ei ole.</p>
        <?php else: ?>
            <div style="overflow-x:auto"><table style="width:100%;border-collapse:collapse"><thead><tr><th style="text-align:left;padding:10px;background:#f3f6fa">Tootekood</th><th style="text-align:left;padding:10px;background:#f3f6fa">Toode</th><th style="text-align:right;padding:10px;background:#f3f6fa">Jääk kokku</th><th style="text-align:right;padding:10px;background:#f3f6fa">Miinimum</th></tr></thead><tbody>
            <?php foreach ($lowStockProducts as $product): ?>
                <tr><td style="padding:10px;border-bottom:1px solid #e3e9f0"><?= htmlspecialchars($product['sku'], ENT_QUOTES, 'UTF-8') ?></td><td style="padding:10px;border-bottom:1px solid #e3e9f0"><?= htmlspecialchars($product['name'], ENT_QUOTES, 'UTF-8') ?></td><td style="padding:10px;border-bottom:1px solid #e3e9f0;text-align:right"><?= htmlspecialchars(rtrim(rtrim(number_format((float) $product['quantity'], 3, ',', ' '), '0'), ','), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($product['unit'], ENT_QUOTES, 'UTF-8') ?></td><td style="padding:10px;border-bottom:1px solid #e3e9f0;text-align:right"><?= htmlspecialchars(rtrim(rtrim(number_format((float) $product['minimum_stock'], 3, ',', ' '), '0'), ','), ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($product['unit'], ENT_QUOTES, 'UTF-8') ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
        <p style="margin-top:14px"><a href="products.php">Muuda toodete miinimumkoguseid</a></p>
    </section>
</body>
</html>
