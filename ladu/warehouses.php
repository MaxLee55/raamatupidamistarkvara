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

$companyId = (int) $_SESSION['company_id'];
$errors = [];
$form = ['code' => '', 'name' => '', 'location_description' => ''];
$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
$editingWarehouse = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))
    ) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if (in_array($action, ['add', 'update'], true)) {
            foreach (array_keys($form) as $field) {
                $form[$field] = trim((string) ($_POST[$field] ?? ''));
            }
            $editId = $action === 'update'
                ? filter_var($_POST['warehouse_id'] ?? null, FILTER_VALIDATE_INT)
                : null;
            if ($action === 'update' && (!$editId || $editId < 1)) {
                $errors[] = 'Lao tunnus ei ole kehtiv.';
            }
            if ($form['code'] === '' || mb_strlen($form['code']) > 40) {
                $errors[] = 'Lao kood on kohustuslik ja võib olla kuni 40 märki.';
            }
            if ($form['name'] === '' || mb_strlen($form['name']) > 150) {
                $errors[] = 'Lao nimi on kohustuslik ja võib olla kuni 150 märki.';
            }
            if (mb_strlen($form['location_description']) > 500) {
                $errors[] = 'Asukoha kirjeldus võib olla kuni 500 märki.';
            }

            if (!$errors) {
                try {
                    if ($action === 'add') {
                        $pdo->beginTransaction();
                        $defaultStmt = $pdo->prepare(
                            'SELECT id FROM inventory_warehouses
                             WHERE company_id = ? AND is_active = 1 AND is_default = 1
                             FOR UPDATE'
                        );
                        $defaultStmt->execute([$companyId]);
                        $isDefault = $defaultStmt->fetchColumn() ? 0 : 1;
                        $insert = $pdo->prepare(
                            'INSERT INTO inventory_warehouses
                                (company_id, code, name, location_description, is_active, is_default)
                             VALUES (?, ?, ?, ?, 1, ?)'
                        );
                        $insert->execute([
                            $companyId,
                            $form['code'],
                            $form['name'],
                            $form['location_description'] !== '' ? $form['location_description'] : null,
                            $isDefault,
                        ]);
                        $pdo->commit();
                        header('Location: warehouses.php?message=added');
                        exit;
                    }

                    $exists = $pdo->prepare('SELECT id FROM inventory_warehouses WHERE id = ? AND company_id = ?');
                    $exists->execute([$editId, $companyId]);
                    if (!$exists->fetchColumn()) {
                        throw new RuntimeException('Ladu ei leitud.');
                    }
                    $update = $pdo->prepare(
                        'UPDATE inventory_warehouses
                         SET code = ?, name = ?, location_description = ?
                         WHERE id = ? AND company_id = ?'
                    );
                    $update->execute([
                        $form['code'],
                        $form['name'],
                        $form['location_description'] !== '' ? $form['location_description'] : null,
                        $editId,
                        $companyId,
                    ]);
                    header('Location: warehouses.php?message=edited');
                    exit;
                } catch (RuntimeException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = $e->getMessage();
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($e->getCode() === '23000') {
                        $errors[] = 'Selle koodiga ladu on ettevõttes juba olemas.';
                    } else {
                        error_log('Lao salvestamine ebaõnnestus: ' . $e->getMessage());
                        $errors[] = 'Lao salvestamine ebaõnnestus.';
                    }
                }
            }
        } elseif ($action === 'set_default') {
            $warehouseId = filter_var($_POST['warehouse_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$warehouseId || $warehouseId < 1) {
                $errors[] = 'Lao tunnus ei ole kehtiv.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $lock = $pdo->prepare(
                        'SELECT id FROM inventory_warehouses
                         WHERE id = ? AND company_id = ? AND is_active = 1
                         FOR UPDATE'
                    );
                    $lock->execute([$warehouseId, $companyId]);
                    if (!$lock->fetchColumn()) {
                        throw new RuntimeException('Vaikimisi laoks saab valida ainult aktiivse lao.');
                    }
                    $clearDefault = $pdo->prepare('UPDATE inventory_warehouses SET is_default = 0 WHERE company_id = ?');
                    $clearDefault->execute([$companyId]);
                    $makeDefault = $pdo->prepare(
                        'UPDATE inventory_warehouses SET is_default = 1 WHERE id = ? AND company_id = ?'
                    );
                    $makeDefault->execute([$warehouseId, $companyId]);
                    $pdo->commit();
                    header('Location: warehouses.php?message=default');
                    exit;
                } catch (RuntimeException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = $e->getMessage();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Vaikimisi lao muutmine ebaõnnestus: ' . $e->getMessage());
                    $errors[] = 'Vaikimisi lao muutmine ebaõnnestus.';
                }
            }
        } elseif ($action === 'toggle') {
            $warehouseId = filter_var($_POST['warehouse_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$warehouseId || $warehouseId < 1) {
                $errors[] = 'Lao tunnus ei ole kehtiv.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $currentStmt = $pdo->prepare(
                        'SELECT id, is_active, is_default FROM inventory_warehouses
                         WHERE id = ? AND company_id = ? FOR UPDATE'
                    );
                    $currentStmt->execute([$warehouseId, $companyId]);
                    $current = $currentStmt->fetch();
                    if (!$current) {
                        throw new RuntimeException('Ladu ei leitud.');
                    }

                    if ((int) $current['is_active'] === 1) {
                        $nextDefault = null;
                        if ((int) $current['is_default'] === 1) {
                            $otherStmt = $pdo->prepare(
                                'SELECT id FROM inventory_warehouses
                                 WHERE company_id = ? AND is_active = 1 AND id <> ?
                                 ORDER BY id LIMIT 1 FOR UPDATE'
                            );
                            $otherStmt->execute([$companyId, $warehouseId]);
                            $nextDefault = $otherStmt->fetchColumn();
                            if (!$nextDefault) {
                                throw new RuntimeException('Ainus aktiivne ladu peab jääma vaikimisi laoks. Lisa või aktiveeri enne teine ladu.');
                            }
                        }
                        if ($nextDefault) {
                            $promote = $pdo->prepare(
                                'UPDATE inventory_warehouses SET is_default = 1 WHERE id = ? AND company_id = ?'
                            );
                            $promote->execute([$nextDefault, $companyId]);
                        }
                        $toggle = $pdo->prepare(
                            'UPDATE inventory_warehouses SET is_active = 0, is_default = 0
                             WHERE id = ? AND company_id = ?'
                        );
                        $toggle->execute([$warehouseId, $companyId]);
                    } else {
                        $toggle = $pdo->prepare(
                            'UPDATE inventory_warehouses SET is_active = 1
                             WHERE id = ? AND company_id = ?'
                        );
                        $toggle->execute([$warehouseId, $companyId]);
                    }
                    $pdo->commit();
                    header('Location: warehouses.php?message=updated');
                    exit;
                } catch (RuntimeException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = $e->getMessage();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Lao oleku muutmine ebaõnnestus: ' . $e->getMessage());
                    $errors[] = 'Lao oleku muutmine ebaõnnestus.';
                }
            }
        } else {
            $errors[] = 'Tundmatu toiming.';
        }
    }
}

if ($editId && $editId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $editStmt = $pdo->prepare(
        'SELECT id, code, name, location_description
         FROM inventory_warehouses WHERE id = ? AND company_id = ?'
    );
    $editStmt->execute([$editId, $companyId]);
    $editingWarehouse = $editStmt->fetch();
    if (!$editingWarehouse) {
        http_response_code(404);
        exit('Ladu ei leitud.');
    }
    foreach (array_keys($form) as $field) {
        $form[$field] = (string) ($editingWarehouse[$field] ?? '');
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update' && $editId) {
    $editStmt = $pdo->prepare('SELECT id FROM inventory_warehouses WHERE id = ? AND company_id = ?');
    $editStmt->execute([$editId, $companyId]);
    $editingWarehouse = $editStmt->fetch();
}

$listStmt = $pdo->prepare(
    'SELECT id, code, name, location_description, is_active, is_default
     FROM inventory_warehouses WHERE company_id = ?
     ORDER BY is_default DESC, is_active DESC, name, code'
);
$listStmt->execute([$companyId]);
$warehouses = $listStmt->fetchAll();
$message = (string) ($_GET['message'] ?? '');
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laod ja asukohad</title>
    <style>
        *{box-sizing:border-box}body{max-width:1120px;margin:32px auto;padding:0 20px;background:#f4f7fb;color:#263746;font-family:Arial,sans-serif}h1,h2{color:#17324d}.card{margin:18px 0;padding:20px;background:#fff;border:1px solid #dce3ec;border-radius:12px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:14px}.muted{color:#667788;line-height:1.5}.fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.wide{grid-column:1/-1}label{display:grid;gap:6px;font-weight:600;color:#33475b}input,textarea{width:100%;min-height:42px;padding:9px 11px;border:1px solid #b8c4d1;border-radius:7px;background:#fff;font:inherit}textarea{min-height:82px;resize:vertical}.button,button{display:inline-block;min-height:40px;padding:10px 14px;border:0;border-radius:7px;background:#1769aa;color:#fff;font:inherit;font-weight:600;text-decoration:none;cursor:pointer}.secondary{background:#657789}.danger{background:#a83232}.actions{display:flex;align-items:center;flex-wrap:wrap;gap:8px}.message{padding:11px 14px;border-radius:7px}.success{background:#eaf7ef;color:#176b32}.error{background:#fff0f0;color:#a32121;border:1px solid #e5a3a3}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{padding:10px;border-bottom:1px solid #e3e9f0;text-align:left;vertical-align:top}th{background:#f3f6fa;color:#526579;white-space:nowrap}.badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#eaf7ef;color:#176b32;font-size:13px;font-weight:700}.muted-badge{background:#eef1f4;color:#687786}.row-actions form{display:inline-block}.inactive{color:#7a8793}@media(max-width:650px){body{padding:0 12px}.topbar{align-items:flex-start;flex-direction:column}.fields{grid-template-columns:1fr}.wide{grid-column:auto}}
    </style>
</head>
<body>
    <header class="topbar">
        <div><h1>Laod ja asukohad</h1><p class="muted">Lao koodi ja vaikimisi lao määrangut kasutatakse järgmistes laoetappides.</p></div>
        <div class="actions"><a class="button secondary" href="movements.php">Kauba liikumised</a><a class="button secondary" href="index.php">Lao ülevaade</a><a class="button secondary" href="../index.php">Avalehele</a></div>
    </header>
    <?php foreach ($errors as $error): ?><p class="message error"><?= h($error) ?></p><?php endforeach; ?>
    <?php if ($message === 'added'): ?><p class="message success">Ladu lisati.</p>
    <?php elseif ($message === 'edited'): ?><p class="message success">Lao andmed salvestati.</p>
    <?php elseif ($message === 'updated'): ?><p class="message success">Lao olekut muudeti.</p>
    <?php elseif ($message === 'default'): ?><p class="message success">Vaikimisi ladu muudeti.</p><?php endif; ?>

    <section class="card" id="new-warehouse">
        <h2><?= $editingWarehouse ? 'Muuda ladu' : 'Lisa ladu' ?></h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="<?= $editingWarehouse ? 'update' : 'add' ?>">
            <?php if ($editingWarehouse): ?><input type="hidden" name="warehouse_id" value="<?= (int) $editingWarehouse['id'] ?>"><?php endif; ?>
            <div class="fields">
                <label>Lao kood *<input name="code" maxlength="40" required placeholder="nt PÕHI või POOD" value="<?= h($form['code']) ?>"></label>
                <label>Lao nimi *<input name="name" maxlength="150" required placeholder="nt Põhiladu" value="<?= h($form['name']) ?>"></label>
                <label class="wide">Asukoht või kirjeldus<textarea name="location_description" maxlength="500" placeholder="nt aadress, ruum või selgitus"><?= h($form['location_description']) ?></textarea></label>
            </div>
            <div class="actions" style="margin-top:14px">
                <button type="submit"><?= $editingWarehouse ? 'Salvesta muudatused' : 'Lisa ladu' ?></button>
                <?php if ($editingWarehouse): ?><a class="button secondary" href="warehouses.php">Katkesta muutmine</a><?php endif; ?>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>Laod (<?= count($warehouses) ?>)</h2>
        <?php if (!$warehouses): ?><p class="muted">Laod puuduvad. Esimesena lisatud aktiivne ladu määratakse automaatselt vaikimisi laoks.</p><?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Kood</th><th>Nimi</th><th>Asukoht või kirjeldus</th><th>Vaikimisi</th><th>Olek</th><th>Tegevused</th></tr></thead>
            <tbody>
            <?php foreach ($warehouses as $warehouse): ?>
                <tr class="<?= (int) $warehouse['is_active'] === 1 ? '' : 'inactive' ?>">
                    <td><?= h($warehouse['code']) ?></td>
                    <td><strong><?= h($warehouse['name']) ?></strong></td>
                    <td><?= nl2br(h((string) ($warehouse['location_description'] ?? ''))) ?: '—' ?></td>
                    <td><?= (int) $warehouse['is_default'] === 1 ? '<span class="badge">Jah</span>' : '—' ?></td>
                    <td><?= (int) $warehouse['is_active'] === 1 ? 'Aktiivne' : 'Mitteaktiivne' ?></td>
                    <td><div class="actions">
                        <a class="button" href="warehouses.php?edit=<?= (int) $warehouse['id'] ?>">Muuda</a>
                        <?php if ((int) $warehouse['is_active'] === 1 && (int) $warehouse['is_default'] !== 1): ?>
                        <form method="post"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="set_default"><input type="hidden" name="warehouse_id" value="<?= (int) $warehouse['id'] ?>"><button class="secondary" type="submit">Määra vaikimisi laoks</button></form>
                        <?php endif; ?>
                        <form method="post" onsubmit="return confirm('Kas muudad lao olekut? Lao andmeid ei kustutata.');"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="warehouse_id" value="<?= (int) $warehouse['id'] ?>"><button class="<?= (int) $warehouse['is_active'] === 1 ? 'danger' : 'secondary' ?>" type="submit"><?= (int) $warehouse['is_active'] === 1 ? 'Muuda passiivseks' : 'Muuda aktiivseks' ?></button></form>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div><?php endif; ?>
    </section>
</body>
</html>
