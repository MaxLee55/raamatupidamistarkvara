<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Kasuta arve kinnitamise vormi.');
}

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
) {
    http_response_code(400);
    exit('Vorm aegus. Ava arve uuesti ja proovi siis.');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/credit_note_lib.php';
require_once __DIR__ . '/period_lock.php';
require_once __DIR__ . '/tax_code_lib.php';
ensureTaxCodeSchema($pdo);

function toCents(string $amount): int
{
    $negative = isset($amount[0]) && $amount[0] === '-';
    $amount = ltrim($amount, '-');
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

    $cents = ((int) $whole * 100)
        + (int) str_pad(substr($fraction, 0, 2), 2, '0');

    return $negative ? -$cents : $cents;
}

$invoiceId = filter_input(INPUT_POST, 'invoice_id', FILTER_VALIDATE_INT);

if (!$invoiceId) {
    http_response_code(400);
    exit('Vigane arve ID.');
}

$companyId = (int) $_SESSION['company_id'];

try {
    $pdo->beginTransaction();

    $invoiceStmt = $pdo->prepare(
        'SELECT si.id, si.invoice_number, si.invoice_date, si.status,
                si.journal_entry_id, c.name AS contact_name, c.country_code, c.vat_number
         FROM sales_invoices si
         JOIN contacts c ON c.id = si.contact_id AND c.company_id = si.company_id
         WHERE si.id = :invoice_id
           AND si.company_id = :company_id
         FOR UPDATE'
    );
    $invoiceStmt->execute([
        'invoice_id' => $invoiceId,
        'company_id' => $companyId,
    ]);
    $invoice = $invoiceStmt->fetch();

    if (
        !$invoice ||
        $invoice['status'] !== 'draft' ||
        $invoice['journal_entry_id'] !== null
    ) {
        throw new RuntimeException('Arvet ei leitud või see pole enam mustand.');
    }

    $periodMessage = closedAccountingPeriodMessage($pdo, $companyId, $invoice['invoice_date']);
    if ($periodMessage !== null) {
        throw new RuntimeException($periodMessage);
    }
    $totalsStmt = $pdo->prepare(
        'SELECT
            COALESCE(SUM(net_amount), 0) AS net_total,
            COALESCE(SUM(vat_amount), 0) AS vat_total,
            COALESCE(SUM(gross_amount), 0) AS gross_total,
            COUNT(*) AS line_count
         FROM sales_invoice_lines
         WHERE invoice_id = :invoice_id'
    );
    $totalsStmt->execute(['invoice_id' => $invoiceId]);
    $totals = $totalsStmt->fetch();

    $netTotal = toCents((string) $totals['net_total']);
    $vatTotal = toCents((string) $totals['vat_total']);
    $grossTotal = toCents((string) $totals['gross_total']);

    if ((int) $totals['line_count'] < 1 || $grossTotal <= 0) {
        throw new RuntimeException('Arvel peab olema vähemalt üks positiivse summaga rida.');
    }

    if ($netTotal + $vatTotal !== $grossTotal) {
        throw new RuntimeException('Arve summad ei klapi. Kontrolli arveridu.');
    }

    $receivableId = filter_var(
        $_POST['receivable_account_id'] ?? null,
        FILTER_VALIDATE_INT
    );
    $revenueId = filter_var(
        $_POST['revenue_account_id'] ?? null,
        FILTER_VALIDATE_INT
    );
    $vatId = filter_var(
        $_POST['vat_account_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$receivableId || !$revenueId) {
        throw new RuntimeException('Vali nõuete ja müügitulu kontod.');
    }

    if ($vatTotal > 0 && !$vatId) {
        throw new RuntimeException('Vali käibemaksukohustuse konto.');
    }

    $accountStmt = $pdo->prepare(
        'SELECT id, account_type
         FROM accounts
         WHERE company_id = :company_id
           AND is_active = 1'
    );
    $accountStmt->execute(['company_id' => $companyId]);

    $accountTypes = [];
    foreach ($accountStmt->fetchAll() as $account) {
        $accountTypes[(int) $account['id']] = $account['account_type'];
    }

    $invoiceLinesStmt = $pdo->prepare('SELECT sil.id, sil.description, sil.quantity, sil.product_id, sil.warehouse_id,
        sil.net_amount, sil.vat_amount, sil.gross_amount, sil.vat_rate, tc.code AS tax_code, tc.valid_to AS tax_valid_to
        FROM sales_invoice_lines sil JOIN sales_invoices si ON si.id = sil.invoice_id
        LEFT JOIN tax_codes tc ON tc.id = sil.tax_code_id AND tc.company_id = si.company_id
        WHERE sil.invoice_id = ? ORDER BY sil.id FOR UPDATE');
    $invoiceLinesStmt->execute([$invoiceId]);
    $invoiceLines = $invoiceLinesStmt->fetchAll();
    confirmZeroSalesInvoice($pdo,$companyId,(int)$_SESSION['user_id'],$invoiceId);
    $reverseCount=0;
    foreach ($invoiceLines as $taxLine) {
        $spec=reverseVatSpec((string)($taxLine['tax_code']??''));
        if (!$spec) continue;
        ++$reverseCount;
        $partyError=reversePartyError($spec,(string)$invoice['country_code'],(string)$invoice['vat_number']);
            $companyError=reverseCompanyError($pdo,$companyId,$spec);
            if ($companyError!==null) throw new RuntimeException($companyError);
        if ($partyError!==null) throw new RuntimeException($partyError);
        if ($spec['side']!=='sales' || $invoice['invoice_date']<$spec['valid_from']
            || ($taxLine['tax_valid_to']!==null && $invoice['invoice_date']>$taxLine['tax_valid_to'])
            || (float)$taxLine['vat_rate']!==0.0 || toCents((string)$taxLine['vat_amount'])!==0
            || toCents((string)$taxLine['net_amount'])<0
            || toCents((string)$taxLine['net_amount'])!==toCents((string)$taxLine['gross_amount'])) {
            throw new RuntimeException('Siseriikliku pöördmaksu müügi rea summad või maksukoodi kuupäev on vigased.');
        }
    }
    if ($reverseCount>0 && $reverseCount!==count($invoiceLines)) throw new RuntimeException('Pöördmaksu müük ja tavaline müük sisesta eraldi arvetena.');
    $stockGroups = [];
    foreach ($invoiceLines as $invoiceLine) {
        if ($invoiceLine['product_id'] === null && $invoiceLine['warehouse_id'] === null) continue;
        if ($invoiceLine['product_id'] === null || $invoiceLine['warehouse_id'] === null) {
            throw new RuntimeException('Laotoote arverea puhul peavad nii toode kui ka ladu olema valitud.');
        }
        $stockCheck = $pdo->prepare(
            'SELECT p.id, p.sku, p.name, p.inventory_account_id, p.cogs_account_id
             FROM inventory_products p
             JOIN inventory_warehouses w ON w.id = ?
             WHERE p.id = ? AND p.company_id = ? AND p.is_active = 1
               AND w.company_id = ? AND w.is_active = 1 FOR UPDATE'
        );
        $stockCheck->execute([(int) $invoiceLine['warehouse_id'], (int) $invoiceLine['product_id'], $companyId, $companyId]);
        $stockProduct = $stockCheck->fetch();
        if (!$stockProduct || empty($stockProduct['inventory_account_id']) || empty($stockProduct['cogs_account_id'])) {
            throw new RuntimeException('Arve laotoode või ladu pole aktiivne või tootekaardi varude ja kaubakulu kontod on määramata.');
        }
        if (($accountTypes[(int) $stockProduct['inventory_account_id']] ?? '') !== 'asset'
            || ($accountTypes[(int) $stockProduct['cogs_account_id']] ?? '') !== 'expense') {
            throw new RuntimeException('Laotoote varude või kaubakulu konto pole aktiivne või on vale kontotüübiga.');
        }
        $groupKey = (int) $invoiceLine['warehouse_id'] . ':' . (int) $invoiceLine['product_id'];
        if (!isset($stockGroups[$groupKey])) {
            $stockGroups[$groupKey] = [
                'warehouse_id' => (int) $invoiceLine['warehouse_id'],
                'product_id' => (int) $invoiceLine['product_id'],
                'quantity_milli' => 0,
                'product' => $stockProduct,
            ];
        }
        $quantity = (string) $invoiceLine['quantity'];
        [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, '0');
        $stockGroups[$groupKey]['quantity_milli'] += ((int) $whole * 1000) + (int) str_pad(substr($fraction, 0, 3), 3, '0');
    }

    if (($accountTypes[$receivableId] ?? '') !== 'asset') {
        throw new RuntimeException('Nõuete kontoks vali aktiivne varakonto.');
    }

    if (($accountTypes[$revenueId] ?? '') !== 'income') {
        throw new RuntimeException('Müügitulu kontoks vali aktiivne tulukonto.');
    }

    if ($vatTotal > 0 && ($accountTypes[$vatId] ?? '') !== 'liability') {
        throw new RuntimeException('Käibemaksukohustuse kontoks vali aktiivne kohustuste konto.');
    }

    $entryDescription = 'Müügiarve ' . $invoice['invoice_number']
        . ' — ' . $invoice['contact_name'];

    $entryStmt = $pdo->prepare(
        "INSERT INTO journal_entries
            (company_id, entry_date, description, document_number,
             status, created_by, posted_at)
         VALUES
            (:company_id, :entry_date, :description, :document_number,
             'posted', :created_by, CURRENT_TIMESTAMP)"
    );
    $entryStmt->execute([
        'company_id' => $companyId,
        'entry_date' => $invoice['invoice_date'],
        'description' => $entryDescription,
        'document_number' => $invoice['invoice_number'],
        'created_by' => (int) $_SESSION['user_id'],
    ]);

    $entryId = (int) $pdo->lastInsertId();

    $lineStmt = $pdo->prepare(
        'INSERT INTO journal_lines
            (entry_id, account_id, description, debit, credit)
         VALUES
            (:entry_id, :account_id, :description, :debit, :credit)'
    );

    // Ostja võlg: arve kogusumma deebetisse.
    $lineStmt->execute([
        'entry_id' => $entryId,
        'account_id' => $receivableId,
        'description' => 'Ostja võlg',
        'debit' => (string) ($grossTotal / 100),
        'credit' => '0.00',
    ]);

    // Müügitulu: netosumma kreeditisse.
    if ($netTotal > 0) {
        $lineStmt->execute([
            'entry_id' => $entryId,
            'account_id' => $revenueId,
            'description' => 'Müügitulu',
            'debit' => '0.00',
            'credit' => number_format($netTotal / 100, 2, '.', ''),
        ]);
    }

    // Käibemaksukohustus: maksusumma kreeditisse.
    if ($vatTotal > 0) {
        $lineStmt->execute([
            'entry_id' => $entryId,
            'account_id' => $vatId,
            'description' => 'Käibemaksukohustus',
            'debit' => '0.00',
            'credit' => number_format($vatTotal / 100, 2, '.', ''),
        ]);
    }

    if ($stockGroups) {
        foreach ($stockGroups as $group) {
            $laterStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM inventory_movement_lines l
                 JOIN inventory_movements m ON m.id = l.movement_id
                 WHERE m.company_id = ? AND m.status = ? AND m.movement_date > ?
                   AND l.warehouse_id = ? AND l.product_id = ?'
            );
            $laterStmt->execute([$companyId, 'posted', $invoice['invoice_date'], $group['warehouse_id'], $group['product_id']]);
            if ((int) $laterStmt->fetchColumn() > 0) {
                throw new RuntimeException('Selle toote laos on arve kuupäevast hilisemaid kinnitatud liikumisi. Kinnita arve enne neid või paranda hilisemad liikumised FIFO järjekorras.');
            }
        }

        $movementStmt = $pdo->prepare(
            "INSERT INTO inventory_movements
                (company_id, source_sales_invoice_id, journal_entry_id, movement_type, movement_date,
                 document_number, description, status, created_by, posted_at)
             VALUES (?, ?, ?, 'issue', ?, ?, ?, 'posted', ?, NOW())"
        );
        $movementStmt->execute([
            $companyId, $invoiceId, $entryId, $invoice['invoice_date'], $invoice['invoice_number'],
            'Müügiarve ' . $invoice['invoice_number'], (int) $_SESSION['user_id'],
        ]);
        $movementId = (int) $pdo->lastInsertId();
        $stockLineStmt = $pdo->prepare('INSERT INTO inventory_movement_lines (movement_id, warehouse_id, product_id, quantity_in, quantity_out, unit_cost, total_cost) VALUES (?, ?, ?, 0, ?, ?, ?)');
        $costsByAccount = [];
        foreach ($stockGroups as $group) {
            $quantity = intdiv($group['quantity_milli'], 1000) . '.' . str_pad((string) ($group['quantity_milli'] % 1000), 3, '0', STR_PAD_LEFT);
            $allocations = invoiceFifoAllocations($pdo, $companyId, $movementId, $invoice['invoice_date'], $group['warehouse_id'], $group['product_id'], $quantity);
            foreach ($allocations as $allocation) {
                $stockLineStmt->execute([$movementId, $group['warehouse_id'], $group['product_id'], $allocation['quantity'], $allocation['unit_cost'], $allocation['total_cost']]);
                $accountId = (int) $group['product']['cogs_account_id'];
                $inventoryAccountId = (int) $group['product']['inventory_account_id'];
                $accountKey = $accountId . ':' . $inventoryAccountId;
                if (!isset($costsByAccount[$accountKey])) {
                    $costsByAccount[$accountKey] = [
                        'cogs_account_id' => $accountId,
                        'inventory_account_id' => $inventoryAccountId,
                        'cost_cents' => 0,
                    ];
                }
                $costsByAccount[$accountKey]['cost_cents'] += toCents((string) $allocation['total_cost']);
            }
        }
        foreach ($costsByAccount as $costInfo) {
            if ($costInfo['cost_cents'] <= 0) continue;
            $cost = number_format($costInfo['cost_cents'] / 100, 2, '.', '');
            $lineStmt->execute([
                'entry_id' => $entryId, 'account_id' => $costInfo['cogs_account_id'],
                'description' => 'Müüdud kauba kulu — arve ' . $invoice['invoice_number'],
                'debit' => $cost, 'credit' => '0.00',
            ]);
            $lineStmt->execute([
                'entry_id' => $entryId, 'account_id' => $costInfo['inventory_account_id'],
                'description' => 'Varude vähenemine — arve ' . $invoice['invoice_number'],
                'debit' => '0.00', 'credit' => $cost,
            ]);
        }
    }

    $updateStmt = $pdo->prepare(
        "UPDATE sales_invoices
         SET status = 'posted',
             journal_entry_id = :entry_id
         WHERE id = :invoice_id
           AND company_id = :company_id
           AND status = 'draft'"
    );
    $updateStmt->execute([
        'entry_id' => $entryId,
        'invoice_id' => $invoiceId,
        'company_id' => $companyId,
    ]);

    if ($updateStmt->rowCount() !== 1) {
        throw new RuntimeException('Arve kinnitamine ebaõnnestus.');
    }

    $pdo->commit();

    header('Location: invoices.php?id=' . $invoiceId . '&posted=1');
    exit;
} catch (RuntimeException|InvalidArgumentException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
        . '<p><a href="invoices.php?id=' . (int) $invoiceId . '">Tagasi arve juurde</a></p>');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($e->getMessage());
    http_response_code(500);
    exit('Arve kinnitamisel tekkis viga.');
}

function invoiceFifoAllocations(PDO $pdo, int $companyId, int $movementId, string $movementDate, int $warehouseId, int $productId, string $quantity): array
{
    return purchaseVatFifoAllocations($pdo,$companyId,$movementId,$movementDate,$warehouseId,$productId,$quantity);
}
