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
require_once dirname(__DIR__) . '/credit_note_lib.php';
require_once dirname(__DIR__) . '/period_lock.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function validMovementDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function normalizeDecimal(string $value, int $scale): string
{
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $whole = ltrim($whole, '0');
    if ($whole === '') {
        $whole = '0';
    }
    return $whole . '.' . str_pad(substr($fraction, 0, $scale), $scale, '0');
}

function decimalToCents(string $amount): int
{
    $negative = isset($amount[0]) && $amount[0] === '-';
    $amount = ltrim($amount, '-');
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
    $cents = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    return $negative ? -$cents : $cents;
}

function money(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

function formatQuantity(string $quantity): string
{
    $formatted = number_format((float) $quantity, 3, ',', ' ');
    return rtrim(rtrim($formatted, '0'), ',');
}

function getFifoAllocations(PDO $pdo, int $companyId, int $movementId, string $movementDate, int $warehouseId, int $productId, string $quantity): array
{
    return purchaseVatFifoAllocations($pdo,$companyId,$movementId,$movementDate,$warehouseId,$productId,$quantity);
}
function createInventoryJournalEntry(PDO $pdo, int $companyId, int $movementId, array $movement, int $productId, string $kind): int
{
    $productStmt = $pdo->prepare(
        'SELECT p.sku, p.name, p.inventory_account_id, p.cogs_account_id, p.inventory_gain_account_id
         FROM inventory_products p WHERE p.id = ? AND p.company_id = ? FOR UPDATE'
    );
    $productStmt->execute([$productId, $companyId]);
    $product = $productStmt->fetch();
    if (!$product) throw new RuntimeException('Toodet ei leitud. Raamatupidamiskannet ei loodud.');

    $amountColumn = $kind === 'surplus' ? 'quantity_in' : 'quantity_out';
    $amountStmt = $pdo->prepare('SELECT COALESCE(SUM(total_cost), 0) FROM inventory_movement_lines WHERE movement_id = ? AND product_id = ? AND ' . $amountColumn . ' > 0');
    $amountStmt->execute([$movementId, $productId]);
    $amountCents = decimalToCents((string) $amountStmt->fetchColumn());
    if ($amountCents <= 0) throw new RuntimeException('FIFO omahind on null või puudub. Raamatupidamiskannet ei loodud.');

    $debitAccountId = $kind === 'surplus'
        ? (int) ($product['inventory_account_id'] ?? 0)
        : (int) ($product['cogs_account_id'] ?? 0);
    $creditAccountId = $kind === 'surplus'
        ? (int) ($product['inventory_gain_account_id'] ?? 0)
        : (int) ($product['inventory_account_id'] ?? 0);
    if (!$debitAccountId || !$creditAccountId) {
        throw new RuntimeException('Vali tootekaardil varude konto ja müüdud kauba kulu konto. Inventuuri ülejäägi korral vali ka ülejäägi tulukonto.');
    }
    $accountStmt = $pdo->prepare('SELECT id, account_type FROM accounts WHERE company_id = ? AND is_active = 1 AND id IN (?, ?) FOR UPDATE');
    $accountStmt->execute([$companyId, $debitAccountId, $creditAccountId]);
    $accountTypes = [];
    foreach ($accountStmt->fetchAll() as $account) $accountTypes[(int) $account['id']] = $account['account_type'];
    $expectedDebitType = $kind === 'surplus' ? 'asset' : 'expense';
    $expectedCreditType = $kind === 'surplus' ? 'income' : 'asset';
    if (($accountTypes[$debitAccountId] ?? '') !== $expectedDebitType || ($accountTypes[$creditAccountId] ?? '') !== $expectedCreditType) {
        throw new RuntimeException('Tootekaardi kontod on valed või mitteaktiivsed. Kontrolli kontoplaani ja tootekaarti.');
    }

    $description = ($kind === 'issue' ? 'Kauba väljaminek' : ($kind === 'shortage' ? 'Inventuuri puudujääk' : 'Inventuuri ülejääk'))
        . ' — ' . $product['sku'] . ' ' . $product['name'];
    $entryStmt = $pdo->prepare("INSERT INTO journal_entries (company_id, entry_date, description, document_number, status, created_by, posted_at) VALUES (?, ?, ?, ?, 'posted', ?, NOW())");
    $entryStmt->execute([
        $companyId,
        $movement['movement_date'],
        mb_substr($description, 0, 500),
        $movement['document_number'] !== null ? mb_substr((string) $movement['document_number'], 0, 50) : null,
        (int) $_SESSION['user_id'],
    ]);
    $entryId = (int) $pdo->lastInsertId();
    $amount = intdiv($amountCents, 100) . '.' . str_pad((string) ($amountCents % 100), 2, '0', STR_PAD_LEFT);
    $lineStmt = $pdo->prepare('INSERT INTO journal_lines (entry_id, account_id, description, debit, credit) VALUES (?, ?, ?, ?, ?)');
    $lineStmt->execute([$entryId, $debitAccountId, $description, $amount, '0.00']);
    $lineStmt->execute([$entryId, $creditAccountId, $description, '0.00', $amount]);
    return $entryId;
}

$companyId = (int) $_SESSION['company_id'];
$errors = [];
$form = [
    'movement_date' => date('Y-m-d'),
    'warehouse_id' => '',
    'document_number' => '',
    'description' => '',
    'lines' => [['product_id' => '', 'quantity' => '', 'unit_cost' => '']],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))
    ) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_receipt') {
            foreach (['movement_date', 'warehouse_id', 'document_number', 'description'] as $field) {
                $form[$field] = trim((string) ($_POST[$field] ?? ''));
            }
            $postedLines = $_POST['lines'] ?? [];
            $form['lines'] = [];
            if (is_array($postedLines)) {
                foreach (array_slice($postedLines, 0, 100) as $line) {
                    if (!is_array($line)) {
                        continue;
                    }
                    $form['lines'][] = [
                        'product_id' => trim((string) ($line['product_id'] ?? '')),
                        'quantity' => str_replace(',', '.', trim((string) ($line['quantity'] ?? ''))),
                        'unit_cost' => str_replace(',', '.', trim((string) ($line['unit_cost'] ?? ''))),
                    ];
                }
            }
            if (!$form['lines']) {
                $form['lines'][] = ['product_id' => '', 'quantity' => '', 'unit_cost' => ''];
            }
            $warehouseId = filter_var($form['warehouse_id'], FILTER_VALIDATE_INT);

            if (!validMovementDate($form['movement_date'])) {
                $errors[] = 'Sisesta kehtiv kauba vastuvõtu kuupäev.';
            }
            if (!$warehouseId || $warehouseId < 1) {
                $errors[] = 'Vali ladu.';
            }
            if ($form['document_number'] !== '' && mb_strlen($form['document_number']) > 80) {
                $errors[] = 'Dokumendi number võib olla kuni 80 märki.';
            }
            if (mb_strlen($form['description']) > 500) {
                $errors[] = 'Kirjeldus võib olla kuni 500 märki.';
            }
            if (count($form['lines']) > 100) {
                $errors[] = 'Ühel vastuvõtul saab olla kuni 100 arverida.';
            }
            $validLines = [];
            foreach ($form['lines'] as $index => $line) {
                $lineNumber = $index + 1;
                $productId = filter_var($line['product_id'], FILTER_VALIDATE_INT);
                if (!$productId || $productId < 1) {
                    $errors[] = 'Vali toode real ' . $lineNumber . '.';
                }
                if (!preg_match('/^\d{1,12}(?:\.\d{1,3})?$/', $line['quantity']) || (float) $line['quantity'] <= 0) {
                    $errors[] = 'Kogus peab olema nullist suurem ja kuni kolme komakohaga (rida ' . $lineNumber . ').';
                }
                if (!preg_match('/^\d{1,11}(?:\.\d{1,4})?$/', $line['unit_cost']) || (float) $line['unit_cost'] < 0) {
                    $errors[] = 'Ühiku soetusmaksumus peab olema null või positiivne ja kuni nelja komakohaga (rida ' . $lineNumber . ').';
                }
                if ($productId && $productId > 0) {
                    $validLines[] = [$productId, $line];
                }
            }

            if (!$errors) {
                $warehouseStmt = $pdo->prepare(
                    'SELECT id FROM inventory_warehouses
                     WHERE id = ? AND company_id = ? AND is_active = 1'
                );
                $warehouseStmt->execute([$warehouseId, $companyId]);
                if (!$warehouseStmt->fetchColumn()) {
                    $errors[] = 'Valitud ladu puudub või ei ole aktiivne.';
                }
                $productStmt = $pdo->prepare(
                    'SELECT id FROM inventory_products
                     WHERE id = ? AND company_id = ? AND is_active = 1'
                );
                foreach ($validLines as $index => [$productId, $line]) {
                    $productStmt->execute([$productId, $companyId]);
                    if (!$productStmt->fetchColumn()) {
                        $errors[] = 'Toode real ' . ($index + 1) . ' puudub või ei ole aktiivne.';
                    }
                }
            }

            if (!$errors) {
                try {
                    $pdo->beginTransaction();
                    $movementStmt = $pdo->prepare(
                        'INSERT INTO inventory_movements
                            (company_id, movement_type, movement_date, document_number,
                             description, status, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $movementStmt->execute([
                        $companyId,
                        'receipt',
                        $form['movement_date'],
                        $form['document_number'] !== '' ? $form['document_number'] : null,
                        $form['description'] !== '' ? $form['description'] : null,
                        'draft',
                        (int) $_SESSION['user_id'],
                    ]);
                    $movementId = (int) $pdo->lastInsertId();
                    $lineStmt = $pdo->prepare(
                        'INSERT INTO inventory_movement_lines
                            (movement_id, warehouse_id, product_id, quantity_in,
                             quantity_out, unit_cost, total_cost)
                         VALUES (?, ?, ?, ?, 0, ?, ROUND(? * ?, 2))'
                    );
                    foreach ($validLines as [$productId, $line]) {
                        $quantity = normalizeDecimal($line['quantity'], 3);
                        $unitCost = normalizeDecimal($line['unit_cost'], 4);
                        $lineStmt->execute([$movementId, $warehouseId, $productId, $quantity, $unitCost, $quantity, $unitCost]);
                    }
                    $pdo->commit();
                    header('Location: movements.php?message=draft');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Kauba vastuvõtu mustandi loomine ebaõnnestus: ' . $e->getMessage());
                    $errors[] = 'Kauba vastuvõtu mustandi salvestamine ebaõnnestus.';
                }
            }
        } elseif ($action === 'save_issue') {
            $issueDate = trim((string) ($_POST['movement_date'] ?? ''));
            $warehouseId = filter_var($_POST['warehouse_id'] ?? null, FILTER_VALIDATE_INT);
            $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
            $quantityInput = str_replace(',', '.', trim((string) ($_POST['quantity'] ?? '')));
            $documentNumber = trim((string) ($_POST['document_number'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            if (!validMovementDate($issueDate)) $errors[] = 'Sisesta kehtiv väljamineku kuupäev.';
            if (!$warehouseId || $warehouseId < 1) $errors[] = 'Vali ladu.';
            if (!$productId || $productId < 1) $errors[] = 'Vali toode.';
            if (!preg_match('/^\d{1,12}(?:\.\d{1,3})?$/', $quantityInput) || (float) $quantityInput <= 0) $errors[] = 'Kogus peab olema nullist suurem ja kuni kolme komakohaga.';
            if (mb_strlen($documentNumber) > 80) $errors[] = 'Dokumendi number võib olla kuni 80 märki.';
            if (mb_strlen($description) > 500) $errors[] = 'Kirjeldus võib olla kuni 500 märki.';
            if (!$errors) {
                $check = $pdo->prepare('SELECT id FROM inventory_warehouses WHERE id = ? AND company_id = ? AND is_active = 1');
                $check->execute([$warehouseId, $companyId]);
                if (!$check->fetchColumn()) $errors[] = 'Valitud ladu puudub või ei ole aktiivne.';
                $check = $pdo->prepare('SELECT id FROM inventory_products WHERE id = ? AND company_id = ? AND is_active = 1');
                $check->execute([$productId, $companyId]);
                if (!$check->fetchColumn()) $errors[] = 'Valitud toode puudub või ei ole aktiivne.';
            }
            if (!$errors) {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare('INSERT INTO inventory_movements (company_id, movement_type, movement_date, document_number, description, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$companyId, 'issue', $issueDate, $documentNumber !== '' ? $documentNumber : null, $description !== '' ? $description : null, 'draft', (int) $_SESSION['user_id']]);
                    $movementId = (int) $pdo->lastInsertId();
                    $stmt = $pdo->prepare('INSERT INTO inventory_movement_lines (movement_id, warehouse_id, product_id, quantity_in, quantity_out, unit_cost, total_cost) VALUES (?, ?, ?, 0, ?, 0, 0)');
                    $stmt->execute([$movementId, $warehouseId, $productId, normalizeDecimal($quantityInput, 3)]);
                    $pdo->commit();
                    header('Location: movements.php?message=issue_draft');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Kauba väljamineku mustandi loomine ebaõnnestus: ' . $e->getMessage());
                    $errors[] = 'Kauba väljamineku mustandi salvestamine ebaõnnestus.';
                }
            }
        } elseif ($action === 'save_transfer') {
            $transferDate = trim((string) ($_POST['movement_date'] ?? ''));
            $sourceWarehouseId = filter_var($_POST['source_warehouse_id'] ?? null, FILTER_VALIDATE_INT);
            $targetWarehouseId = filter_var($_POST['target_warehouse_id'] ?? null, FILTER_VALIDATE_INT);
            $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
            $quantityInput = str_replace(',', '.', trim((string) ($_POST['quantity'] ?? '')));
            $documentNumber = trim((string) ($_POST['document_number'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            if (!validMovementDate($transferDate)) $errors[] = 'Sisesta kehtiv siirde kuupäev.';
            if (!$sourceWarehouseId || $sourceWarehouseId < 1) $errors[] = 'Vali lähtekoha ladu.';
            if (!$targetWarehouseId || $targetWarehouseId < 1) $errors[] = 'Vali sihtkoha ladu.';
            if ($sourceWarehouseId && $sourceWarehouseId === $targetWarehouseId) $errors[] = 'Lähte- ja sihtkoha ladu peavad olema erinevad.';
            if (!$productId || $productId < 1) $errors[] = 'Vali toode.';
            if (!preg_match('/^\d{1,12}(?:\.\d{1,3})?$/', $quantityInput) || (float) $quantityInput <= 0) $errors[] = 'Kogus peab olema nullist suurem ja kuni kolme komakohaga.';
            if (mb_strlen($documentNumber) > 80) $errors[] = 'Dokumendi number võib olla kuni 80 märki.';
            if (mb_strlen($description) > 500) $errors[] = 'Kirjeldus võib olla kuni 500 märki.';
            if (!$errors) {
                $warehouseCheck = $pdo->prepare('SELECT id FROM inventory_warehouses WHERE id = ? AND company_id = ? AND is_active = 1');
                foreach ([$sourceWarehouseId, $targetWarehouseId] as $warehouseId) {
                    $warehouseCheck->execute([$warehouseId, $companyId]);
                    if (!$warehouseCheck->fetchColumn()) $errors[] = 'Valitud ladu puudub või ei ole aktiivne.';
                }
                $productCheck = $pdo->prepare('SELECT id FROM inventory_products WHERE id = ? AND company_id = ? AND is_active = 1');
                $productCheck->execute([$productId, $companyId]);
                if (!$productCheck->fetchColumn()) $errors[] = 'Valitud toode puudub või ei ole aktiivne.';
            }
            if (!$errors) {
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare('INSERT INTO inventory_movements (company_id, movement_type, movement_date, document_number, description, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$companyId, 'transfer', $transferDate, $documentNumber !== '' ? $documentNumber : null, $description !== '' ? $description : null, 'draft', (int) $_SESSION['user_id']]);
                    $movementId = (int) $pdo->lastInsertId();
                    $stmt = $pdo->prepare('INSERT INTO inventory_movement_lines (movement_id, warehouse_id, product_id, quantity_in, quantity_out, unit_cost, total_cost) VALUES (?, ?, ?, 0, ?, 0, 0), (?, ?, ?, ?, 0, 0, 0)');
                    $quantity = normalizeDecimal($quantityInput, 3);
                    $stmt->execute([$movementId, $sourceWarehouseId, $productId, $quantity, $movementId, $targetWarehouseId, $productId, $quantity]);
                    $pdo->commit();
                    header('Location: movements.php?message=transfer_draft');
                    exit;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Laosiirde mustandi loomine ebaõnnestus: ' . $e->getMessage());
                    $errors[] = 'Laosiirde mustandi salvestamine ebaõnnestus.';
                }
            }
        } elseif (in_array($action, ['post', 'cancel', 'create_journal'], true)) {
            $movementId = filter_var($_POST['movement_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$movementId || $movementId < 1) {
                $errors[] = 'Liikumise tunnus ei ole kehtiv.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $movementStmt = $pdo->prepare(
                        'SELECT id, status, movement_type, movement_date, document_number, journal_entry_id
                         FROM inventory_movements
                         WHERE id = ? AND company_id = ?
                         FOR UPDATE'
                    );
                    $movementStmt->execute([$movementId, $companyId]);
                    $movement = $movementStmt->fetch();
                    if ($action === 'create_journal') {
                        if (!$movement || $movement['status'] !== 'posted' || !in_array($movement['movement_type'], ['issue', 'adjustment'], true) || $movement['journal_entry_id'] !== null) {
                            throw new RuntimeException('Pearaamatukande saab luua ainult kinnitatud väljaminekule või inventuurikorrigeerimisele, millel kannet veel pole.');
                        }
                        $periodMessage = closedAccountingPeriodMessage($pdo, $companyId, $movement['movement_date']);
                        if ($periodMessage !== null) throw new RuntimeException($periodMessage);
                        $lineStmt = $pdo->prepare('SELECT product_id, SUM(quantity_in) AS quantity_in, SUM(quantity_out) AS quantity_out FROM inventory_movement_lines WHERE movement_id = ? GROUP BY product_id');
                        $lineStmt->execute([$movementId]);
                        $movementLines = $lineStmt->fetchAll();
                        if (count($movementLines) !== 1) throw new RuntimeException('Liikumisel peab olema ühe toote read. Pearaamatukannet ei loodud.');
                        $movementLine = $movementLines[0];
                        $journalKind = $movement['movement_type'] === 'issue'
                            ? 'issue'
                            : ((float) $movementLine['quantity_in'] > 0 ? 'surplus' : 'shortage');
                        $journalId = createInventoryJournalEntry($pdo, $companyId, $movementId, $movement, (int) $movementLine['product_id'], $journalKind);
                        $pdo->prepare('UPDATE inventory_movements SET journal_entry_id = ? WHERE id = ? AND company_id = ? AND journal_entry_id IS NULL')
                            ->execute([$journalId, $movementId, $companyId]);
                    } elseif (!$movement || $movement['status'] !== 'draft' || !in_array($movement['movement_type'], ['receipt', 'issue', 'transfer', 'adjustment'], true)) {
                        throw new RuntimeException('Ainult olemasolevat lao mustandit saab kinnitada või tühistada.');
                    }

                    if ($action === 'post') {
                        if (in_array($movement['movement_type'], ['issue', 'adjustment'], true)) {
                            $periodMessage = closedAccountingPeriodMessage($pdo, $companyId, $movement['movement_date']);
                            if ($periodMessage !== null) throw new RuntimeException($periodMessage);
                        }
                        $lineCheck = $pdo->prepare(
                            'SELECT l.warehouse_id, l.product_id
                             FROM inventory_movement_lines l
                             JOIN inventory_warehouses w ON w.id = l.warehouse_id
                             JOIN inventory_products p ON p.id = l.product_id
                             WHERE l.movement_id = ? AND w.company_id = ? AND p.company_id = ?
                               AND w.is_active = 1 AND p.is_active = 1'
                        );
                        $lineCheck->execute([$movementId, $companyId, $companyId]);
                        $checkedLines = $lineCheck->fetchAll();
                        if (count($checkedLines) === 0) {
                            throw new RuntimeException('Mustandi toode või ladu ei ole enam aktiivne. Mustandit ei kinnitatud.');
                        }
                        if (in_array($movement['movement_type'], ['issue', 'adjustment'], true)) {
                            $issueLinesStmt = $pdo->prepare('SELECT id, warehouse_id, product_id, quantity_out FROM inventory_movement_lines WHERE movement_id = ? AND quantity_out > 0');
                            $issueLinesStmt->execute([$movementId]);
                            $issueLines = $issueLinesStmt->fetchAll();
                            if ($movement['movement_type'] === 'issue' && !$issueLines) {
                                throw new RuntimeException('Väljamineku mustandis puudub kogus. Seda ei kinnitatud.');
                            }
                            $deleteAllocations = $pdo->prepare('DELETE FROM inventory_movement_lines WHERE id = ?');
                            $insertAllocation = $pdo->prepare('INSERT INTO inventory_movement_lines (movement_id, warehouse_id, product_id, quantity_in, quantity_out, unit_cost, total_cost) VALUES (?, ?, ?, 0, ?, ?, ?)');
                            foreach ($issueLines as $issueLine) {
                                $allocations = getFifoAllocations($pdo, $companyId, $movementId, $movement['movement_date'], (int) $issueLine['warehouse_id'], (int) $issueLine['product_id'], (string) $issueLine['quantity_out']);
                                $deleteAllocations->execute([$issueLine['id']]);
                                foreach ($allocations as $allocation) {
                                    $insertAllocation->execute([$movementId, $issueLine['warehouse_id'], $issueLine['product_id'], $allocation['quantity'], $allocation['unit_cost'], $allocation['total_cost']]);
                                }
                            }
                            $journalKind = 'issue';
                            if ($movement['movement_type'] === 'adjustment') {
                                $directionStmt = $pdo->prepare('SELECT quantity_in, quantity_out FROM inventory_movement_lines WHERE movement_id = ? LIMIT 1');
                                $directionStmt->execute([$movementId]);
                                $direction = $directionStmt->fetch();
                                $journalKind = $direction && (float) $direction['quantity_in'] > 0 ? 'surplus' : 'shortage';
                            }
                            $journalId = createInventoryJournalEntry($pdo, $companyId, $movementId, $movement, (int) $checkedLines[0]['product_id'], $journalKind);
                        } elseif ($movement['movement_type'] === 'transfer') {
                            $transferLinesStmt = $pdo->prepare('SELECT id, warehouse_id, product_id, quantity_in, quantity_out FROM inventory_movement_lines WHERE movement_id = ? ORDER BY id');
                            $transferLinesStmt->execute([$movementId]);
                            $transferLines = $transferLinesStmt->fetchAll();
                            $sourceLine = null;
                            $targetLine = null;
                            foreach ($transferLines as $transferLine) {
                                if ((float) $transferLine['quantity_out'] > 0) $sourceLine = $transferLine;
                                if ((float) $transferLine['quantity_in'] > 0) $targetLine = $transferLine;
                            }
                            if (!$sourceLine || !$targetLine || (int) $sourceLine['warehouse_id'] === (int) $targetLine['warehouse_id'] || (int) $sourceLine['product_id'] !== (int) $targetLine['product_id']) {
                                throw new RuntimeException('Siirde lähte- ja sihtkoha andmed ei klapi. Siiret ei kinnitatud.');
                            }
                            $quantity = (string) $sourceLine['quantity_out'];
                            $allocations = getFifoAllocations($pdo, $companyId, $movementId, $movement['movement_date'], (int) $sourceLine['warehouse_id'], (int) $sourceLine['product_id'], $quantity);
                            $pdo->prepare('DELETE FROM inventory_movement_lines WHERE movement_id = ?')->execute([$movementId]);
                            $insertPair = $pdo->prepare('INSERT INTO inventory_movement_lines (movement_id, warehouse_id, product_id, quantity_in, quantity_out, unit_cost, total_cost) VALUES (?, ?, ?, 0, ?, ?, ?), (?, ?, ?, ?, 0, ?, ?)');
                            foreach ($allocations as $allocation) {
                                $insertPair->execute([$movementId, $sourceLine['warehouse_id'], $sourceLine['product_id'], $allocation['quantity'], $allocation['unit_cost'], $allocation['total_cost'], $movementId, $targetLine['warehouse_id'], $targetLine['product_id'], $allocation['quantity'], $allocation['unit_cost'], $allocation['total_cost']]);
                            }
                        }
                        $update = $pdo->prepare(
                            'UPDATE inventory_movements
                             SET status = ?, posted_at = NOW()
                             WHERE id = ? AND company_id = ? AND status = ?'
                        );
                        $update->execute(['posted', $movementId, $companyId, 'draft']);
                        if (!empty($journalId)) {
                            $pdo->prepare('UPDATE inventory_movements SET journal_entry_id = ? WHERE id = ? AND company_id = ?')
                                ->execute([$journalId, $movementId, $companyId]);
                        }
                    } elseif ($action === 'cancel') {
                        $update = $pdo->prepare(
                            'UPDATE inventory_movements
                             SET status = ?
                             WHERE id = ? AND company_id = ? AND status = ?'
                        );
                        $update->execute(['cancelled', $movementId, $companyId, 'draft']);
                    }
                    $pdo->commit();
                    $messageKey = $action === 'post'
                        ? 'posted_' . $movement['movement_type']
                        : ($action === 'create_journal' ? 'journal_created' : 'cancelled');
                    header('Location: movements.php?message=' . rawurlencode($messageKey));
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
                    error_log('Kauba vastuvõtu muutmine ebaõnnestus: ' . $e->getMessage());
                    $errors[] = 'Kauba vastuvõtu muutmine ebaõnnestus.';
                }
            }
        } else {
            $errors[] = 'Tundmatu toiming.';
        }
    }
}

$warehousesStmt = $pdo->prepare(
    'SELECT id, code, name, is_default FROM inventory_warehouses
     WHERE company_id = ? AND is_active = 1
     ORDER BY is_default DESC, name, code'
);
$warehousesStmt->execute([$companyId]);
$warehouses = $warehousesStmt->fetchAll();
if ($form['warehouse_id'] === '' && $warehouses) {
    $form['warehouse_id'] = (string) $warehouses[0]['id'];
}
$productsStmt = $pdo->prepare(
    'SELECT id, sku, name, unit FROM inventory_products
     WHERE company_id = ? AND is_active = 1
     ORDER BY name, sku'
);
$productsStmt->execute([$companyId]);
$products = $productsStmt->fetchAll();

$balancesStmt = $pdo->prepare(
    'SELECT w.id AS warehouse_id, w.code AS warehouse_code, w.name AS warehouse_name,
            p.id AS product_id, p.sku, p.name AS product_name, p.unit,
            SUM(l.quantity_in - l.quantity_out) AS quantity,
            SUM(CASE WHEN l.quantity_in > 0 THEN l.total_cost ELSE -l.total_cost END) AS stock_value
     FROM inventory_movement_lines l
     JOIN inventory_movements m ON m.id = l.movement_id
     JOIN inventory_warehouses w ON w.id = l.warehouse_id
     JOIN inventory_products p ON p.id = l.product_id
     WHERE m.company_id = ? AND m.status = ?
     GROUP BY w.id, w.code, w.name, p.id, p.sku, p.name, p.unit
     HAVING SUM(l.quantity_in - l.quantity_out) <> 0
         OR SUM(CASE WHEN l.quantity_in > 0 THEN l.total_cost ELSE -l.total_cost END) <> 0
     ORDER BY w.name, p.name, p.sku'
);
$balancesStmt->execute([$companyId, 'posted']);
$balances = $balancesStmt->fetchAll();

$historyStmt = $pdo->prepare(
    'SELECT m.id, m.movement_type, m.movement_date, m.document_number, m.description, m.status, m.journal_entry_id,
            l.quantity_in, l.quantity_out, l.unit_cost, l.total_cost,
            p.sku, p.name AS product_name, p.unit,
            CASE WHEN m.movement_type = ? THEN CONCAT(w.code, " — ", w.name, " → ",
                (SELECT CONCAT(t.code, " — ", t.name)
                 FROM inventory_movement_lines tl
                 JOIN inventory_warehouses t ON t.id = tl.warehouse_id
                 WHERE tl.movement_id = m.id AND tl.quantity_in > 0 LIMIT 1))
                ELSE CONCAT(w.code, " — ", w.name) END AS warehouse_label
     FROM inventory_movements m
     JOIN inventory_movement_lines l ON l.movement_id = m.id
     JOIN inventory_products p ON p.id = l.product_id
     JOIN inventory_warehouses w ON w.id = l.warehouse_id
     WHERE m.company_id = ? AND m.movement_type IN (?, ?, ?, ?)
       AND (m.movement_type <> ? OR l.quantity_out > 0)
     ORDER BY m.movement_date DESC, m.id DESC
     LIMIT 100'
);
$historyStmt->execute(['transfer', $companyId, 'receipt', 'issue', 'transfer', 'adjustment', 'transfer']);
$history = $historyStmt->fetchAll();
$messages = [
    'draft' => 'Kauba vastuvõtu mustand salvestati. Ladu muutub pärast kinnitamist.',
    'issue_draft' => 'Kauba väljamineku mustand salvestati. FIFO omahind leitakse kinnitamisel.',
    'transfer_draft' => 'Laosiirde mustand salvestati. FIFO soetusmaksumus kantakse sihtkohta kinnitamisel üle.',
    'adjustment_draft' => 'Inventuuri korrigeerimise mustand salvestati. Vaata see üle ja kinnita laoliikumiste lehel.',
    'posted_receipt' => 'Kauba vastuvõtt kinnitati ja laojääk uuenes.',
    'posted_issue' => 'Kauba väljaminek kinnitati FIFO soetusmaksumusega.',
    'posted_transfer' => 'Laosiire kinnitati ning kogus ja FIFO väärtus kanti sihtkoha lattu üle.',
    'posted_adjustment' => 'Inventuuri korrigeerimine kinnitati ja laojääk uuenes.',
    'journal_created' => 'Pearaamatukanne loodi olemasolevale laoliikumisele.',
    'cancelled' => 'Kauba vastuvõtu mustand tühistati.',
];
$message = $messages[(string) ($_GET['message'] ?? '')] ?? '';
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kauba liikumised</title>
    <style>
        *{box-sizing:border-box}body{max-width:1600px;margin:32px auto;padding:0 20px;background:#f4f7fb;color:#263746;font-family:Arial,sans-serif}h1,h2,h3{color:#17324d}.card{margin:18px 0;padding:20px;background:#fff;border:1px solid #dce3ec;border-radius:12px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:14px}.muted{color:#667788;line-height:1.5}.warning{padding:12px 14px;border:1px solid #ead9a3;border-radius:8px;background:#fff8e6;color:#71520a;line-height:1.5}.fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.wide{grid-column:1/-1}label{display:grid;gap:6px;font-weight:600;color:#33475b}input,select,textarea{width:100%;min-height:42px;padding:9px 11px;border:1px solid #b8c4d1;border-radius:7px;background:#fff;font:inherit}textarea{min-height:74px;resize:vertical}.button,button{display:inline-block;min-height:40px;padding:10px 14px;border:0;border-radius:7px;background:#1769aa;color:#fff;font:inherit;font-weight:600;text-decoration:none;cursor:pointer}.secondary{background:#657789}.danger{background:#a83232}.success-button{background:#1b7d46}.actions{display:flex;align-items:center;flex-wrap:wrap;gap:8px}.message{padding:11px 14px;border-radius:7px}.notice{background:#eaf7ef;color:#176b32}.error{background:#fff0f0;color:#a32121;border:1px solid #e5a3a3}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse}.movement-history-table{min-width:1500px}th,td{padding:10px;border-bottom:1px solid #e3e9f0;text-align:left;vertical-align:top}th{background:#f3f6fa;color:#526579;white-space:nowrap}.num{text-align:right;white-space:nowrap}.status{font-weight:700}.posted{color:#176b32}.draft{color:#946200}.cancelled{color:#718096}.row-actions{width:210px;min-width:210px;white-space:normal}.row-actions form{display:block;width:100%;max-width:100%}.row-actions .actions{align-items:stretch;flex-direction:column}.row-actions button{width:100%;min-width:0;white-space:normal;line-height:1.25}.empty{color:#667788}@media(max-width:700px){body{padding:0 12px}.topbar{align-items:flex-start;flex-direction:column}.fields{grid-template-columns:1fr}.wide{grid-column:auto}}
    </style>
</head>
<body>
    <header class="topbar">
        <div><h1>Kauba liikumised</h1><p class="muted">Võta kaupa lattu vastu, vormista väljaminekuid ja liiguta kaupa ladude vahel.</p></div>
        <div class="actions"><a class="button secondary" href="warehouses.php">Laod</a><a class="button secondary" href="products.php">Tootekaardid</a><a class="button secondary" href="index.php">Lao ülevaade</a><a class="button secondary" href="../index.php">Avalehele</a></div>
    </header>

    <?php if ($message !== ''): ?><p class="message notice"><?= h($message) ?></p><?php endif; ?>
    <?php foreach ($errors as $error): ?><p class="message error"><?= h($error) ?></p><?php endforeach; ?>
    <p class="warning">Ostuarve sisesta eraldi ostuarvete lehel ja seo arverida tootekaardi varude kontoga. Kinnitatud kauba väljaminek ning inventuuri korrigeerimine loovad nüüd FIFO omahinnaga automaatse pearaamatukande. Lao vastuvõtu mustand käsitsi lisamisel muudab ainult laoseisu; ostuarvega seotud vastuvõtu loob ostuarve kinnitamine.</p>

    <section class="card">
        <h2>Kauba vastuvõtt</h2>
        <?php if (!$warehouses): ?><p class="warning">Enne kauba vastuvõttu lisa ja aktiveeri vähemalt üks ladu lehel <a href="warehouses.php">Laod ja asukohad</a>.</p>
        <?php elseif (!$products): ?><p class="warning">Enne kauba vastuvõttu lisa vähemalt üks aktiivne toode lehel <a href="products.php">Tootekaardid</a>.</p>
        <?php else: ?>
        <form method="post" id="receipt-form">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="save_receipt">
            <div class="fields">
                <label>Vastuvõtu kuupäev *<input type="date" name="movement_date" required value="<?= h($form['movement_date']) ?>"></label>
                <label>Ladu *<select name="warehouse_id" required><option value="">Vali ladu</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= (int) $warehouse['id'] ?>" <?= (string) $warehouse['id'] === $form['warehouse_id'] ? 'selected' : '' ?>><?= h($warehouse['code'] . ' — ' . $warehouse['name'] . ((int) $warehouse['is_default'] === 1 ? ' (vaikimisi)' : '')) ?></option><?php endforeach; ?></select></label>
                <label>Dokumendi number<input name="document_number" maxlength="80" value="<?= h($form['document_number']) ?>" placeholder="nt saatelehe või ostuarve nr"></label>
                <label class="wide">Kirjeldus<textarea name="description" maxlength="500" placeholder="Täiendav selgitus"><?= h($form['description']) ?></textarea></label>
            </div>
            <h3>Vastuvõetavad tooted</h3>
            <div id="receipt-lines">
                <?php foreach ($form['lines'] as $lineIndex => $line): ?>
                <div class="fields receipt-line" style="margin:12px 0;padding:12px;background:#f8fafc;border:1px solid #e3e9f0;border-radius:8px">
                    <label>Toode *<select name="lines[<?= (int) $lineIndex ?>][product_id]" required><option value="">Vali toode</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>" <?= (string) $product['id'] === $line['product_id'] ? 'selected' : '' ?>><?= h($product['sku'] . ' — ' . $product['name'] . ' (' . $product['unit'] . ')') ?></option><?php endforeach; ?></select></label>
                    <label>Kogus *<input name="lines[<?= (int) $lineIndex ?>][quantity]" inputmode="decimal" required value="<?= h($line['quantity']) ?>" placeholder="nt 12 või 2,500"></label>
                    <label>Ühiku soetusmaksumus (€) *<input name="lines[<?= (int) $lineIndex ?>][unit_cost]" inputmode="decimal" required value="<?= h($line['unit_cost']) ?>" placeholder="nt 4,25"></label>
                    <div class="actions" style="align-self:end"><button type="button" class="danger remove-line">Eemalda rida</button></div>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="muted">Sisesta ühe ühiku soetusmaksumus: mahaarvatav käibemaks jäta välja, mahaarvamatu käibemaks ja kaubale jaotatud otsesed soetuskulud arvesta sisse.</p>
            <div class="actions"><button type="button" class="secondary" id="add-line">Lisa toode</button><button type="submit">Salvesta mustand</button></div>
        </form><?php endif; ?>
    </section>

    <section class="card">
        <h2>Kauba väljaminek</h2>
        <?php if (!$warehouses || !$products): ?><p class="warning">Väljamineku loomiseks lisa aktiivne ladu ja toode.</p>
        <?php else: ?>
        <form method="post" class="fields">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="save_issue">
            <label>Väljamineku kuupäev *<input type="date" name="movement_date" required value="<?= h(date('Y-m-d')) ?>"></label>
            <label>Ladu *<select name="warehouse_id" required><option value="">Vali ladu</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= (int) $warehouse['id'] ?>"><?= h($warehouse['code'] . ' — ' . $warehouse['name']) ?></option><?php endforeach; ?></select></label>
            <label>Toode *<select name="product_id" required><option value="">Vali toode</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>"><?= h($product['sku'] . ' — ' . $product['name'] . ' (' . $product['unit'] . ')') ?></option><?php endforeach; ?></select></label>
            <label>Kogus *<input name="quantity" inputmode="decimal" required placeholder="nt 2 või 1,500"></label>
            <label>Dokumendi number<input name="document_number" maxlength="80" placeholder="nt mahakandmise akt"></label>
            <label class="wide">Põhjus / kirjeldus<textarea name="description" maxlength="500" placeholder="nt müük, omatarve, praak või kadu"></textarea></label>
            <div class="wide actions"><button type="submit">Salvesta väljamineku mustand</button></div>
        </form>
        <p class="muted">Väljamineku kinnitamisel arvestab süsteem soetused FIFO järjekorras ja takistab negatiivse laojäägi tekkimist.</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Laosiire</h2>
        <?php if (count($warehouses) < 2): ?><p class="warning">Laosiirdeks peab olema vähemalt kaks aktiivset ladu.</p>
        <?php else: ?>
        <form method="post" class="fields">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="save_transfer">
            <label>Siirde kuupäev *<input type="date" name="movement_date" required value="<?= h(date('Y-m-d')) ?>"></label>
            <label>Toode *<select name="product_id" required><option value="">Vali toode</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>"><?= h($product['sku'] . ' — ' . $product['name'] . ' (' . $product['unit'] . ')') ?></option><?php endforeach; ?></select></label>
            <label>Lähtekoha ladu *<select name="source_warehouse_id" required><option value="">Vali ladu</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= (int) $warehouse['id'] ?>"><?= h($warehouse['code'] . ' — ' . $warehouse['name']) ?></option><?php endforeach; ?></select></label>
            <label>Sihtkoha ladu *<select name="target_warehouse_id" required><option value="">Vali ladu</option><?php foreach ($warehouses as $warehouse): ?><option value="<?= (int) $warehouse['id'] ?>"><?= h($warehouse['code'] . ' — ' . $warehouse['name']) ?></option><?php endforeach; ?></select></label>
            <label>Kogus *<input name="quantity" inputmode="decimal" required placeholder="nt 2 või 1,500"></label>
            <label>Dokumendi number<input name="document_number" maxlength="80" placeholder="nt laosiirde akt"></label>
            <label class="wide">Kirjeldus<textarea name="description" maxlength="500" placeholder="Täiendav selgitus"></textarea></label>
            <div class="wide actions"><button type="submit">Salvesta siirde mustand</button></div>
        </form>
        <p class="muted">Kinnitamisel kontrollitakse lähtekoha jääki ja kantakse FIFO soetusmaksumus sihtkoha lattu üle.</p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Laojääkide ülevaade</h2>
        <?php if (!$balances): ?><p class="empty">Kinnitatud laokoguseid veel pole.</p><?php else: ?>
        <div class="table-wrap"><table><thead><tr><th>Ladu</th><th>Tootekood</th><th>Toode</th><th class="num">Kogus</th><th class="num">Arvestuslik väärtus</th></tr></thead><tbody>
        <?php foreach ($balances as $balance): ?><tr><td><?= h($balance['warehouse_code'] . ' — ' . $balance['warehouse_name']) ?></td><td><?= h($balance['sku']) ?></td><td><?= h($balance['product_name']) ?></td><td class="num"><?= h(formatQuantity((string) $balance['quantity'])) ?> <?= h($balance['unit']) ?></td><td class="num"><?= h(money(decimalToCents((string) $balance['stock_value']))) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </section>

    <section class="card">
        <h2>Laoliikumised</h2>
        <?php if (!$history): ?><p class="empty">Laoliikumisi pole veel loodud.</p><?php else: ?>
        <div class="table-wrap"><table class="movement-history-table"><thead><tr><th>Kuupäev</th><th>Liik</th><th>Ladu</th><th>Toode</th><th>Dokument</th><th class="num">Kogus</th><th class="num">FIFO ühikuhind</th><th class="num">Omahind kokku</th><th>Olek</th><th class="row-actions">Tegevused</th><th>Raamatupidamine</th></tr></thead><tbody>
        <?php foreach ($history as $row): ?>
            <?php $statusNames = ['draft' => 'Mustand', 'posted' => 'Kinnitatud', 'cancelled' => 'Tühistatud']; ?>
            <tr>
                <td><?= h($row['movement_date']) ?></td>
                <td><?= h(['receipt' => 'Vastuvõtt', 'issue' => 'Väljaminek', 'transfer' => 'Laosiire', 'adjustment' => 'Inventuur'][$row['movement_type']] ?? $row['movement_type']) ?></td>
                <td><?= h($row['warehouse_label']) ?></td>
                <td><?= h($row['sku'] . ' — ' . $row['product_name']) ?></td>
                <td><?= h($row['document_number'] ?? '—') ?><?= $row['description'] ? '<br><span class="muted">' . h($row['description']) . '</span>' : '' ?></td>
                <td class="num"><?= h(formatQuantity((string) ((float) $row['quantity_in'] > 0 ? $row['quantity_in'] : $row['quantity_out']))) ?> <?= h($row['unit']) ?></td>
                <td class="num"><?= $row['status'] === 'draft' && ((float) $row['quantity_out'] > 0 && in_array($row['movement_type'], ['issue', 'adjustment'], true)) ? 'leitakse kinnitamisel' : h(number_format((float) $row['unit_cost'], 4, ',', ' ') . ' €') ?></td>
                <td class="num"><?= $row['status'] === 'draft' && ((float) $row['quantity_out'] > 0 && in_array($row['movement_type'], ['issue', 'adjustment'], true)) ? '—' : h(money(decimalToCents((string) $row['total_cost']))) ?></td>
                <td class="status <?= h($row['status']) ?>"><?= h($statusNames[$row['status']] ?? $row['status']) ?></td>
                <td class="row-actions">
                    <?php if ($row['status'] === 'cancelled'): ?>—
                    <?php elseif ($row['status'] === 'draft'): ?>
                    <div class="actions">
                        <form method="post" onsubmit="return confirm('Kas kinnitad selle laoliikumise? Väljaminekul arvutatakse FIFO omahind.');"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="post"><input type="hidden" name="movement_id" value="<?= (int) $row['id'] ?>"><button class="success-button" type="submit">Kinnita</button></form>
                        <form method="post" onsubmit="return confirm('Kas tühistad selle lao mustandi?');"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="movement_id" value="<?= (int) $row['id'] ?>"><button class="danger" type="submit">Tühista</button></form>
                    </div>
                    <?php elseif ($row['status'] === 'posted' && in_array($row['movement_type'], ['issue', 'adjustment'], true) && empty($row['journal_entry_id'])): ?>
                    <form method="post" onsubmit="return confirm('Kas lood sellele juba kinnitatud laoliikumisele FIFO omahinnaga pearaamatukande?');"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="create_journal"><input type="hidden" name="movement_id" value="<?= (int) $row['id'] ?>"><button class="success-button" type="submit">Loo pearaamatukanne</button></form>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><?= $row['journal_entry_id'] ? '<a href="../entry.php?id=' . (int) $row['journal_entry_id'] . '">Ava kanne #' . (int) $row['journal_entry_id'] . '</a>' : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var container = document.getElementById('receipt-lines');
    var addButton = document.getElementById('add-line');
    if (!container || !addButton) return;

    function updateNames() {
        Array.prototype.forEach.call(container.querySelectorAll('.receipt-line'), function (row, index) {
            row.querySelectorAll('select, input').forEach(function (field) {
                field.name = field.name.replace(/lines\[\d+\]/, 'lines[' + index + ']');
            });
            var remove = row.querySelector('.remove-line');
            remove.disabled = container.querySelectorAll('.receipt-line').length === 1;
        });
    }

    addButton.addEventListener('click', function () {
        var rows = container.querySelectorAll('.receipt-line');
        if (rows.length >= 100) return;
        var row = rows[0].cloneNode(true);
        row.querySelectorAll('select').forEach(function (field) { field.selectedIndex = 0; });
        row.querySelectorAll('input').forEach(function (field) { field.value = ''; });
        container.appendChild(row);
        updateNames();
    });

    container.addEventListener('click', function (event) {
        if (event.target.classList.contains('remove-line') && container.querySelectorAll('.receipt-line').length > 1) {
            event.target.closest('.receipt-line').remove();
            updateNames();
        }
    });
    updateNames();
});
</script>
</body>
</html>
