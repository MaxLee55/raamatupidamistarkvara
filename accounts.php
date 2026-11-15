<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/db.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function accountHasReferences(PDO $pdo, int $accountId): bool
{
    $checks = [
        ['journal_lines', 'account_id'],
        ['purchase_invoice_lines', 'account_id'],
        ['bank_statement_lines', 'bank_account_id'],
        ['invoice_payments', 'bank_account_id'],
        ['purchase_invoice_payments', 'bank_account_id'],
    ];

    foreach ($checks as [$table, $column]) {
        $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE {$column} = ? LIMIT 1");
        $stmt->execute([$accountId]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    }

    return false;
}

$companyId = (int) $_SESSION['company_id'];
$errors = [];
$typeNames = [
    'asset' => 'Vara',
    'liability' => 'Kohustus',
    'equity' => 'Omakapital',
    'income' => 'Tulu',
    'expense' => 'Kulu',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
    ) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $code = trim((string) ($_POST['code'] ?? ''));
            $name = trim((string) ($_POST['name'] ?? ''));
            $accountType = (string) ($_POST['account_type'] ?? '');

            if ($code === '' || mb_strlen($code) > 20) {
                $errors[] = 'Kontokood on kohustuslik ja võib olla kuni 20 märki.';
            }
            if ($name === '' || mb_strlen($name) > 200) {
                $errors[] = 'Konto nimi on kohustuslik ja võib olla kuni 200 märki.';
            }
            if (!isset($typeNames[$accountType])) {
                $errors[] = 'Vali konto tüüp.';
            }

            if (!$errors) {
                try {
                    $stmt = $pdo->prepare(
                        'INSERT INTO accounts (company_id, code, name, account_type, is_active)
                         VALUES (?, ?, ?, ?, 1)'
                    );
                    $stmt->execute([$companyId, $code, $name, $accountType]);
                    header('Location: accounts.php?message=added');
                    exit;
                } catch (PDOException $e) {
                    if ($e->getCode() === '23000') {
                        $errors[] = 'Selle kontokoodiga konto on juba olemas.';
                    } else {
                        error_log($e->getMessage());
                        $errors[] = 'Konto lisamine ebaõnnestus.';
                    }
                }
            }
        } elseif ($action === 'update') {
            $accountId = filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT);
            $name = trim((string) ($_POST['name'] ?? ''));

            if (!$accountId || $accountId < 1) {
                $errors[] = 'Konto ID on vigane.';
            } elseif ($name === '' || mb_strlen($name) > 200) {
                $errors[] = 'Konto nimi on kohustuslik ja võib olla kuni 200 märki.';
            } else {
                $currentStmt = $pdo->prepare(
                    'SELECT id, code, name, account_type
                     FROM accounts
                     WHERE id = ? AND company_id = ?'
                );
                $currentStmt->execute([$accountId, $companyId]);
                $current = $currentStmt->fetch();

                if (!$current) {
                    $errors[] = 'Kontot ei leitud.';
                } else {
                    $isUsed = accountHasReferences($pdo, (int) $accountId);
                    $code = trim((string) ($_POST['code'] ?? $current['code']));
                    $accountType = (string) ($_POST['account_type'] ?? $current['account_type']);

                    if ($isUsed) {
                        // Kasutatud konto kood ja tüüp jäävad ajaloo terviklikkuse nimel samaks.
                        $code = (string) $current['code'];
                        $accountType = (string) $current['account_type'];
                    } elseif ($code === '' || mb_strlen($code) > 20) {
                        $errors[] = 'Kontokood on kohustuslik ja võib olla kuni 20 märki.';
                    } elseif (!isset($typeNames[$accountType])) {
                        $errors[] = 'Vali konto tüüp.';
                    }

                    if (!$errors) {
                        try {
                            $updateStmt = $pdo->prepare(
                                'UPDATE accounts
                                 SET code = ?, name = ?, account_type = ?
                                 WHERE id = ? AND company_id = ?'
                            );
                            $updateStmt->execute([
                                $code,
                                $name,
                                $accountType,
                                $accountId,
                                $companyId,
                            ]);
                            header('Location: accounts.php?message=edited');
                            exit;
                        } catch (PDOException $e) {
                            if ($e->getCode() === '23000') {
                                $errors[] = 'Selle kontokoodiga konto on juba olemas.';
                            } else {
                                error_log($e->getMessage());
                                $errors[] = 'Konto muutmine ebaõnnestus.';
                            }
                        }
                    }
                }
            }
        } elseif ($action === 'delete') {
            $accountId = filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT);

            if (!$accountId || $accountId < 1) {
                $errors[] = 'Konto ID on vigane.';
            } else {
                try {
                    $pdo->beginTransaction();

                    $lockStmt = $pdo->prepare(
                        'SELECT id FROM accounts
                         WHERE id = ? AND company_id = ?
                         FOR UPDATE'
                    );
                    $lockStmt->execute([$accountId, $companyId]);

                    if (!$lockStmt->fetchColumn()) {
                        throw new RuntimeException('Kontot ei leitud.');
                    }

                    if (accountHasReferences($pdo, (int) $accountId)) {
                        throw new RuntimeException(
                            'Kontot on juba kasutatud kandes, arves või pangatehingus. '
                            . 'Seda ei saa kustutada; muuda konto passiivseks.'
                        );
                    }

                    $deleteStmt = $pdo->prepare(
                        'DELETE FROM accounts WHERE id = ? AND company_id = ?'
                    );
                    $deleteStmt->execute([$accountId, $companyId]);
                    $pdo->commit();

                    header('Location: accounts.php?message=deleted');
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
                    error_log($e->getMessage());
                    $errors[] = 'Konto kustutamine ebaõnnestus.';
                }
            }
        } elseif ($action === 'toggle') {
            $accountId = filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT);

            if (!$accountId || $accountId < 1) {
                $errors[] = 'Konto ID on vigane.';
            } else {
                $updateStmt = $pdo->prepare(
                    'UPDATE accounts
                     SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END
                     WHERE id = ? AND company_id = ?'
                );
                $updateStmt->execute([$accountId, $companyId]);
                header('Location: accounts.php?message=updated');
                exit;
            }
        }
    }
}

$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $editId = filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT);
}

$editAccount = null;
$editAccountUsed = false;
if ($editId && $editId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT id, code, name, account_type
         FROM accounts
         WHERE id = ? AND company_id = ?'
    );
    $editStmt->execute([$editId, $companyId]);
    $editAccount = $editStmt->fetch();

    if ($editAccount) {
        $editAccountUsed = accountHasReferences($pdo, (int) $editAccount['id']);
    }
}

$listStmt = $pdo->prepare(
    'SELECT id, code, name, account_type, is_active
     FROM accounts
     WHERE company_id = ?
     ORDER BY code'
);
$listStmt->execute([$companyId]);
$accounts = $listStmt->fetchAll();
$message = $_GET['message'] ?? '';
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kontoplaan</title>
    <style>
        body {
            max-width: 1150px;
            margin: 36px auto;
            padding: 0 24px;
            background: #f4f7fb;
            color: #243447;
            font-family: Arial, sans-serif;
        }
        h1, h2 { color: #17324d; }
        .account-card {
            margin: 20px 0 32px;
            padding: 22px 24px;
            background: #f7f9fc;
            border: 1px solid #dce3ec;
            border-radius: 12px;
            max-width: 900px;
        }
        .account-card h2 { margin-top: 0; }
        .account-grid {
            display: grid;
            grid-template-columns: 1fr 1.5fr 1fr;
            gap: 16px;
            align-items: end;
        }
        .account-field label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
            color: #33475b;
        }
        .account-field input, .account-field select {
            box-sizing: border-box;
            width: 100%;
            min-height: 42px;
            padding: 10px 12px;
            border: 1px solid #b8c4d1;
            border-radius: 7px;
            background: #fff;
            font: inherit;
        }
        .account-actions { margin-top: 18px; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            background: #fff;
        }
        th, td {
            padding: 10px 12px;
            border: 1px solid #dce3ec;
            text-align: left;
        }
        th { background: #eaf0f6; color: #17324d; }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            align-items: center;
        }
        .button, button {
            display: inline-block;
            padding: 9px 13px;
            background: #1769aa;
            color: #fff;
            text-decoration: none;
            border: 0;
            border-radius: 6px;
            cursor: pointer;
            font: inherit;
            font-weight: 600;
        }
        .button:hover, button:hover { background: #0f4f83; }
        .button-secondary { background: #657789; }
        .button-secondary:hover { background: #4d5e6e; }
        .button-danger { background: #b4232d; }
        .button-danger:hover { background: #8f1821; }
        .inactive { color: #777; }
        .error {
            color: #a32121;
            background: #fff0f0;
            border: 1px solid #e5a1a1;
            padding: 10px 12px;
            border-radius: 7px;
        }
        .success {
            color: #176b32;
            background: #eaf7ef;
            border: 1px solid #b8dfc7;
            padding: 10px 12px;
            border-radius: 7px;
        }
        .muted { color: #667788; }
        @media (max-width: 700px) {
            body { margin: 22px auto; padding: 0 14px; }
            .account-grid { grid-template-columns: 1fr; }
            .account-card { padding: 16px; }
        }
    </style>
</head>
<body>
    <h1>Kontoplaan</h1>

    <?php foreach ($errors as $error): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endforeach; ?>

    <?php if ($message === 'added'): ?>
        <p class="success">Konto lisati.</p>
    <?php elseif ($message === 'updated'): ?>
        <p class="success">Konto olekut muudeti.</p>
    <?php elseif ($message === 'edited'): ?>
        <p class="success">Konto andmed salvestati.</p>
    <?php elseif ($message === 'deleted'): ?>
        <p class="success">Konto kustutati.</p>
    <?php endif; ?>

    <section class="account-card">
        <h2>Lisa konto</h2>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="add">
            <div class="account-grid">
                <div class="account-field">
                    <label for="account-code">Kontokood</label>
                    <input id="account-code" type="text" name="code" maxlength="20" required>
                </div>
                <div class="account-field">
                    <label for="account-name">Konto nimi</label>
                    <input id="account-name" type="text" name="name" maxlength="200" required>
                </div>
                <div class="account-field">
                    <label for="account-type">Konto tüüp</label>
                    <select id="account-type" name="account_type" required>
                        <option value="">Vali tüüp</option>
                        <?php foreach ($typeNames as $type => $typeName): ?>
                            <option value="<?= h($type) ?>"><?= h($typeName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="account-actions">
                <button type="submit">Lisa konto</button>
            </div>
        </form>
    </section>

    <?php if ($editAccount): ?>
        <section class="account-card">
            <h2>Muuda kontot <?= h($editAccount['code']) ?></h2>
            <?php if ($editAccountUsed): ?>
                <p class="muted">
                    Kontot on juba kasutatud. Koodi ja tüüpi ei saa muuta, kuid nime saab parandada.
                </p>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="account_id" value="<?= (int) $editAccount['id'] ?>">
                <div class="account-grid">
                    <div class="account-field">
                        <label for="edit-account-code">Kontokood</label>
                        <input
                            id="edit-account-code"
                            type="text"
                            name="code"
                            maxlength="20"
                            value="<?= h($editAccount['code']) ?>"
                            <?= $editAccountUsed ? 'readonly' : 'required' ?>
                        >
                    </div>
                    <div class="account-field">
                        <label for="edit-account-name">Konto nimi</label>
                        <input
                            id="edit-account-name"
                            type="text"
                            name="name"
                            maxlength="200"
                            value="<?= h($editAccount['name']) ?>"
                            required
                        >
                    </div>
                    <div class="account-field">
                        <label for="edit-account-type">Konto tüüp</label>
                        <select
                            id="edit-account-type"
                            name="account_type"
                            <?= $editAccountUsed ? 'disabled' : 'required' ?>
                        >
                            <?php foreach ($typeNames as $type => $typeName): ?>
                                <option
                                    value="<?= h($type) ?>"
                                    <?= $editAccount['account_type'] === $type ? 'selected' : '' ?>
                                >
                                    <?= h($typeName) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($editAccountUsed): ?>
                            <input type="hidden" name="account_type" value="<?= h($editAccount['account_type']) ?>">
                        <?php endif; ?>
                    </div>
                </div>
                <div class="account-actions">
                    <button type="submit">Salvesta muudatused</button>
                    <a class="button button-secondary" href="accounts.php">Katkesta</a>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <h2>Olemasolevad kontod</h2>
    <p class="muted">
        Kasutuses olevat kontot kustutada ei saa; selle saab muuta passiivseks.
    </p>

    <table>
        <thead>
            <tr>
                <th>Kood</th>
                <th>Nimi</th>
                <th>Tüüp</th>
                <th>Olek</th>
                <th>Toimingud</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($accounts as $account): ?>
                <tr class="<?= (int) $account['is_active'] ? '' : 'inactive' ?>">
                    <td><?= h($account['code']) ?></td>
                    <td><?= h($account['name']) ?></td>
                    <td><?= h($typeNames[$account['account_type']] ?? $account['account_type']) ?></td>
                    <td><?= (int) $account['is_active'] ? 'Aktiivne' : 'Passiivne' ?></td>
                    <td>
                        <div class="actions">
                            <a class="button" href="accounts.php?edit=<?= (int) $account['id'] ?>">Muuda</a>
                            <form method="post" onsubmit="return confirm('Kas kustutad selle konto? Seda saab teha ainult kasutamata kontoga.');">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                                <button class="button-danger" type="submit">Kustuta</button>
                            </form>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
                                <button class="button-secondary" type="submit">
                                    <?= (int) $account['is_active'] ? 'Muuda passiivseks' : 'Muuda aktiivseks' ?>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p><a class="button" href="index.php">Tagasi avalehele</a></p>
</body>
</html>