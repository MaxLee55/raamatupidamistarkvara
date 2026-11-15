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
require_once __DIR__ . '/inventory_account_settings.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formatQuantity(string $quantity): string
{
    $formatted = number_format((float) $quantity, 3, ',', ' ');
    return rtrim(rtrim($formatted, '0'), ',');
}

$companyId = (int) $_SESSION['company_id'];
$inventoryAccountDefaults = loadInventoryAccountDefaults($pdo, $companyId);
$errors = [];
$form = [
    'sku' => '',
    'barcode' => '',
    'name' => '',
    'unit' => 'tk',
    'description' => '',
    'minimum_stock' => '0',
    'inventory_account_id' => $inventoryAccountDefaults['inventory_account_id'],
    'cogs_account_id' => $inventoryAccountDefaults['cogs_account_id'],
    'inventory_gain_account_id' => $inventoryAccountDefaults['inventory_gain_account_id'],
];
$editingId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
$editingProduct = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))
    ) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'toggle') {
            $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$productId || $productId < 1) {
                $errors[] = 'Toote tunnus ei ole kehtiv.';
            } else {
                $toggle = $pdo->prepare(
                    'UPDATE inventory_products
                     SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
                     WHERE id = ? AND company_id = ?'
                );
                $toggle->execute([$productId, $companyId]);
                header('Location: products.php?message=updated');
                exit;
            }
        } elseif (in_array($action, ['add', 'update'], true)) {
            foreach (array_keys($form) as $field) {
                $form[$field] = trim((string) ($_POST[$field] ?? ''));
            }
            $form['minimum_stock'] = str_replace(',', '.', $form['minimum_stock']);
            $editingId = $action === 'update'
                ? filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT)
                : null;

            if ($action === 'update' && (!$editingId || $editingId < 1)) {
                $errors[] = 'Toote tunnus ei ole kehtiv.';
            }
            if ($form['sku'] === '' || mb_strlen($form['sku']) > 50) {
                $errors[] = 'Tootekood on kohustuslik ja võib olla kuni 50 märki.';
            }
            if ($form['barcode'] !== '' && mb_strlen($form['barcode']) > 100) {
                $errors[] = 'Ribakood võib olla kuni 100 märki.';
            }
            if ($form['name'] === '' || mb_strlen($form['name']) > 200) {
                $errors[] = 'Nimetus on kohustuslik ja võib olla kuni 200 märki.';
            }
            if ($form['unit'] === '' || mb_strlen($form['unit']) > 30) {
                $errors[] = 'Mõõtühik on kohustuslik ja võib olla kuni 30 märki.';
            }
            if (mb_strlen($form['description']) > 5000) {
                $errors[] = 'Kirjeldus võib olla kuni 5000 märki.';
            }
            if (!preg_match('/^\d{1,12}(?:\.\d{1,3})?$/', $form['minimum_stock'])) {
                $errors[] = 'Minimaalne laojääk peab olema null või positiivne arv, kuni kolme komakohaga.';
            }
            $form['inventory_account_id'] = trim($form['inventory_account_id']);
            $form['cogs_account_id'] = trim($form['cogs_account_id']);
            $form['inventory_gain_account_id'] = trim($form['inventory_gain_account_id']);
            foreach (['inventory_account_id', 'cogs_account_id', 'inventory_gain_account_id'] as $accountField) {
                if ($form[$accountField] !== '' && (!ctype_digit($form[$accountField]) || (int) $form[$accountField] < 1)) {
                    $errors[] = 'Vali kontod rippmenüüst.';
                    break;
                }
            }

            if (!$errors) {
                $accountsStmt = $pdo->prepare('SELECT id, account_type FROM accounts WHERE company_id = ? AND is_active = 1');
                $accountsStmt->execute([$companyId]);
                $accountTypes = [];
                foreach ($accountsStmt->fetchAll() as $accountRow) {
                    $accountTypes[(int) $accountRow['id']] = $accountRow['account_type'];
                }
                foreach ([
                    'inventory_account_id' => 'asset',
                    'cogs_account_id' => 'expense',
                    'inventory_gain_account_id' => 'income',
                ] as $accountField => $requiredType) {
                    if ($form[$accountField] !== '' && ($accountTypes[(int) $form[$accountField]] ?? '') !== $requiredType) {
                        $errors[] = 'Vali varude kontoks varakonto, kaubakuluks kulukonto ja ülejäägiks tulukonto.';
                        break;
                    }
                }
            }

            if (!$errors) {
                try {
                    if ($action === 'add') {
                        $save = $pdo->prepare(
                            'INSERT INTO inventory_products
                                (company_id, sku, barcode, name, unit, description, minimum_stock,
                                 inventory_account_id, cogs_account_id, inventory_gain_account_id)
                             VALUES
                                (:company_id, :sku, :barcode, :name, :unit, :description, :minimum_stock,
                                 :inventory_account_id, :cogs_account_id, :inventory_gain_account_id)'
                        );
                        $save->execute([
                            'company_id' => $companyId,
                            'sku' => $form['sku'],
                            'barcode' => $form['barcode'] !== '' ? $form['barcode'] : null,
                            'name' => $form['name'],
                            'unit' => $form['unit'],
                            'description' => $form['description'] !== '' ? $form['description'] : null,
                            'minimum_stock' => $form['minimum_stock'],
                            'inventory_account_id' => $form['inventory_account_id'] !== '' ? (int) $form['inventory_account_id'] : null,
                            'cogs_account_id' => $form['cogs_account_id'] !== '' ? (int) $form['cogs_account_id'] : null,
                            'inventory_gain_account_id' => $form['inventory_gain_account_id'] !== '' ? (int) $form['inventory_gain_account_id'] : null,
                        ]);
                        saveInventoryAccountDefaults($pdo, $companyId, $form);
                        header('Location: products.php?message=added');
                        exit;
                    }

                    $exists = $pdo->prepare('SELECT id FROM inventory_products WHERE id = ? AND company_id = ?');
                    $exists->execute([$editingId, $companyId]);
                    if (!$exists->fetchColumn()) {
                        throw new RuntimeException('Toodet ei leitud.');
                    }
                    $save = $pdo->prepare(
                        'UPDATE inventory_products
                         SET sku = :sku, barcode = :barcode, name = :name, unit = :unit,
                             description = :description, minimum_stock = :minimum_stock,
                             inventory_account_id = :inventory_account_id,
                             cogs_account_id = :cogs_account_id,
                             inventory_gain_account_id = :inventory_gain_account_id
                         WHERE id = :id AND company_id = :company_id'
                    );
                    $save->execute([
                        'sku' => $form['sku'],
                        'barcode' => $form['barcode'] !== '' ? $form['barcode'] : null,
                        'name' => $form['name'],
                        'unit' => $form['unit'],
                        'description' => $form['description'] !== '' ? $form['description'] : null,
                        'minimum_stock' => $form['minimum_stock'],
                        'inventory_account_id' => $form['inventory_account_id'] !== '' ? (int) $form['inventory_account_id'] : null,
                        'cogs_account_id' => $form['cogs_account_id'] !== '' ? (int) $form['cogs_account_id'] : null,
                        'inventory_gain_account_id' => $form['inventory_gain_account_id'] !== '' ? (int) $form['inventory_gain_account_id'] : null,
                        'id' => $editingId,
                        'company_id' => $companyId,
                    ]);
                    saveInventoryAccountDefaults($pdo, $companyId, $form);
                    header('Location: products.php?message=edited');
                    exit;
                } catch (RuntimeException $e) {
                    $errors[] = $e->getMessage();
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        $errors[] = 'Selle tootekoodi või ribakoodiga toode on juba olemas.';
                    } else {
                        error_log('Lao tootekaardi salvestamine ebaõnnestus: ' . $e->getMessage());
                        $errors[] = 'Tootekaardi salvestamine ebaõnnestus.';
                    }
                }
            }
        } else {
            $errors[] = 'Tundmatu toiming.';
        }
    }
}

if ($editingId && $editingId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $editStmt = $pdo->prepare(
        'SELECT id, sku, barcode, name, unit, description, minimum_stock,
                inventory_account_id, cogs_account_id, inventory_gain_account_id
         FROM inventory_products
         WHERE id = ? AND company_id = ?'
    );
    $editStmt->execute([$editingId, $companyId]);
    $editingProduct = $editStmt->fetch();
    if (!$editingProduct) {
        http_response_code(404);
        exit('Toodet ei leitud.');
    }
    foreach (array_keys($form) as $field) {
        $form[$field] = (string) ($editingProduct[$field] ?? '');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $editStmt = $pdo->prepare(
        'SELECT id FROM inventory_products WHERE id = ? AND company_id = ?'
    );
    $editStmt->execute([$editingId, $companyId]);
    $editingProduct = $editStmt->fetch();
}

$search = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? 'active');
if (!in_array($status, ['active', 'inactive', 'all'], true)) {
    $status = 'active';
}
$sql = 'SELECT id, sku, barcode, name, unit, description, minimum_stock, is_active,
               inventory_account_id, cogs_account_id, inventory_gain_account_id
        FROM inventory_products
        WHERE company_id = :company_id';
$params = ['company_id' => $companyId];
if ($status !== 'all') {
    $sql .= ' AND is_active = :is_active';
    $params['is_active'] = $status === 'active' ? 1 : 0;
}
if ($search !== '') {
    $sql .= ' AND (sku LIKE :search_sku OR barcode LIKE :search_barcode OR name LIKE :search_name)';
    $like = '%' . $search . '%';
    $params['search_sku'] = $like;
    $params['search_barcode'] = $like;
    $params['search_name'] = $like;
}
$sql .= ' ORDER BY is_active DESC, name, sku';
$listStmt = $pdo->prepare($sql);
$listStmt->execute($params);
$products = $listStmt->fetchAll();
$accountStmt = $pdo->prepare('SELECT id, code, name, account_type FROM accounts WHERE company_id = ? AND is_active = 1 ORDER BY code');
$accountStmt->execute([$companyId]);
$accountsByType = ['asset' => [], 'expense' => [], 'income' => []];
foreach ($accountStmt->fetchAll() as $accountRow) {
    if (isset($accountsByType[$accountRow['account_type']])) $accountsByType[$accountRow['account_type']][] = $accountRow;
}
$message = (string) ($_GET['message'] ?? '');
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lao tootekaardid</title>
    <style>
        *{box-sizing:border-box}body{max-width:1200px;margin:32px auto;padding:0 20px;background:#f4f7fb;color:#263746;font-family:Arial,sans-serif}h1,h2{color:#17324d}.card{margin:18px 0;padding:20px;background:#fff;border:1px solid #dce3ec;border-radius:12px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:14px}.muted{color:#667788;line-height:1.5}.fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.wide{grid-column:1/-1}label{display:grid;gap:6px;font-weight:600;color:#33475b}input,textarea,select{width:100%;min-height:42px;padding:9px 11px;border:1px solid #b8c4d1;border-radius:7px;background:#fff;font:inherit}textarea{min-height:90px;resize:vertical}.button,button{display:inline-block;min-height:40px;padding:10px 14px;border:0;border-radius:7px;background:#1769aa;color:#fff;font:inherit;font-weight:600;text-decoration:none;cursor:pointer}.secondary{background:#657789}.danger{background:#a83232}.actions{display:flex;align-items:center;flex-wrap:wrap;gap:8px}.filters{display:flex;align-items:end;flex-wrap:wrap;gap:10px}.filters label{min-width:180px}.message{padding:11px 14px;border-radius:7px}.success{background:#eaf7ef;color:#176b32}.error{background:#fff0f0;color:#a32121;border:1px solid #e5a3a3}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e3e9f0;text-align:left;vertical-align:top}th{background:#f3f6fa;color:#526579;white-space:nowrap}.row-actions form{display:inline-block}.inactive{color:#7a8793}@media(max-width:700px){body{padding:0 12px}.topbar{align-items:flex-start;flex-direction:column}.fields{grid-template-columns:1fr}.wide{grid-column:auto}}
    </style>
</head>
<body>
    <header class="topbar">
        <div><h1>Lao tootekaardid</h1><p class="muted">Laoseisu ja omahinda arvutatakse hiljem kauba liikumiste põhjal. Uuele tootekaardile pakutakse vaikimisi salvestatud lao pearaamatukontosid.</p></div>
        <div class="actions"><a class="button secondary" href="movements.php">Kauba liikumised</a><a class="button secondary" href="warehouses.php">Laod ja asukohad</a><a class="button secondary" href="index.php">Lao ülevaade</a><a class="button secondary" href="../index.php">Avalehele</a></div>
    </header>

    <?php foreach ($errors as $error): ?><p class="message error"><?= h($error) ?></p><?php endforeach; ?>
    <?php if ($message === 'added'): ?><p class="message success">Toode lisati.</p>
    <?php elseif ($message === 'edited'): ?><p class="message success">Tootekaart salvestati.</p>
    <?php elseif ($message === 'updated'): ?><p class="message success">Toote olekut muudeti.</p><?php endif; ?>

    <section class="card" id="new-product">
        <h2><?= $editingProduct ? 'Muuda tootekaarti' : 'Lisa toode' ?></h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="<?= $editingProduct ? 'update' : 'add' ?>">
            <?php if ($editingProduct): ?><input type="hidden" name="product_id" value="<?= (int) $editingProduct['id'] ?>"><?php endif; ?>
            <div class="fields">
                <label>Tootekood *<input name="sku" maxlength="50" required value="<?= h($form['sku']) ?>"></label>
                <label>Ribakood<input name="barcode" maxlength="100" value="<?= h($form['barcode']) ?>"></label>
                <label>Nimetus *<input name="name" maxlength="200" required value="<?= h($form['name']) ?>"></label>
                <label>Mõõtühik *<input name="unit" maxlength="30" required placeholder="tk, kg, l, m" value="<?= h($form['unit']) ?>"></label>
                <label>Minimaalne laojääk *<input name="minimum_stock" inputmode="decimal" required value="<?= h($form['minimum_stock']) ?>"><span class="muted">Kuni kolm komakohta.</span></label>
                <label>Varude konto<select name="inventory_account_id"><option value="">Vali varakonto</option><?php foreach ($accountsByType['asset'] as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (string) $account['id'] === $form['inventory_account_id'] ? 'selected' : '' ?>><?= h($account['code'] . ' — ' . $account['name']) ?></option><?php endforeach; ?></select><span class="muted">Näiteks varud või kaubavaru.</span></label>
                <label>Müüdud kauba kulu konto<select name="cogs_account_id"><option value="">Vali kulukonto</option><?php foreach ($accountsByType['expense'] as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (string) $account['id'] === $form['cogs_account_id'] ? 'selected' : '' ?>><?= h($account['code'] . ' — ' . $account['name']) ?></option><?php endforeach; ?></select></label>
                <label>Inventuuri ülejäägi konto<select name="inventory_gain_account_id"><option value="">Vali tulukonto</option><?php foreach ($accountsByType['income'] as $account): ?><option value="<?= (int) $account['id'] ?>" <?= (string) $account['id'] === $form['inventory_gain_account_id'] ? 'selected' : '' ?>><?= h($account['code'] . ' — ' . $account['name']) ?></option><?php endforeach; ?></select><span class="muted">Vajalik ainult inventuuri ülejäägi korral.</span></label>
                <label class="wide">Kirjeldus<textarea name="description" maxlength="5000"><?= h($form['description']) ?></textarea></label>
            </div>
            <div class="actions" style="margin-top:14px">
                <button type="submit"><?= $editingProduct ? 'Salvesta muudatused' : 'Lisa toode' ?></button>
                <?php if ($editingProduct): ?><a class="button secondary" href="products.php">Katkesta muutmine</a><?php endif; ?>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>Toodete nimekiri (<?= count($products) ?>)</h2>
        <form method="get" class="filters">
            <label>Otsi koodi, ribakoodi või nimetuse järgi<input name="q" value="<?= h($search) ?>"></label>
            <label>Olek<select name="status"><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Aktiivsed</option><option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Mitteaktiivsed</option><option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Kõik</option></select></label>
            <button type="submit">Otsi</button>
            <a class="button secondary" href="products.php">Tühjenda</a>
        </form>
        <?php if (!$products): ?><p class="muted">Valitud filtrile vastavaid tooteid ei leitud.</p><?php else: ?>
        <div class="table-wrap" style="margin-top:14px"><table>
            <thead><tr><th>Tootekood</th><th>Ribakood</th><th>Nimetus</th><th>Ühik</th><th>Minimaalne jääk</th><th>Olek</th><th>Tegevused</th></tr></thead>
            <tbody>
            <?php foreach ($products as $product): ?>
                <tr class="<?= (int) $product['is_active'] === 1 ? '' : 'inactive' ?>">
                    <td><?= h($product['sku']) ?></td>
                    <td><?= h($product['barcode'] ?? '') ?: '—' ?></td>
                    <td><strong><?= h($product['name']) ?></strong><?php if ($product['description']): ?><br><span class="muted"><?= nl2br(h($product['description'])) ?></span><?php endif; ?></td>
                    <td><?= h($product['unit']) ?></td>
                    <td><?= h(formatQuantity((string) $product['minimum_stock'])) ?></td>
                    <td><?= (int) $product['is_active'] === 1 ? 'Aktiivne' : 'Mitteaktiivne' ?></td>
                    <td class="row-actions"><div class="actions"><a class="button" href="products.php?edit=<?= (int) $product['id'] ?>">Muuda</a>
                        <form method="post" onsubmit="return confirm('Kas muudad selle toote olekut?');">
                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                            <button class="<?= (int) $product['is_active'] === 1 ? 'danger' : 'secondary' ?>" type="submit"><?= (int) $product['is_active'] === 1 ? 'Muuda passiivseks' : 'Muuda aktiivseks' ?></button>
                        </form></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </section>
</body>
</html>
