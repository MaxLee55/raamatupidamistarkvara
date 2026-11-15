<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Kasuta ostuarve kinnitamise vormi.');
}

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
) {
    http_response_code(400);
    exit('Vorm aegus. Ava arve uuesti ja proovi siis.');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/period_lock.php';
require_once __DIR__ . '/tax_code_lib.php';
ensureTaxCodeSchema($pdo);

function toCents(string $amount): int
{
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
    return ((int) $whole * 100)
        + (int) str_pad(substr($fraction, 0, 2), 2, '0');
}

function centsToDecimal(int $cents): string
{
    return intdiv($cents, 100) . '.' .
        str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

$invoiceId = filter_input(INPUT_POST, 'invoice_id', FILTER_VALIDATE_INT);

if (!$invoiceId) {
    http_response_code(400);
    exit('Vigane ostuarve ID.');
}

$companyId = (int) $_SESSION['company_id'];

try {
    $pdo->beginTransaction();

    $invoiceStmt = $pdo->prepare(
        'SELECT pi.id, pi.supplier_invoice_number, pi.invoice_date,
                pi.status, pi.journal_entry_id, c.name AS supplier_name, c.country_code, c.vat_number, c.estonia_vat_number
         FROM purchase_invoices pi
         JOIN contacts c ON c.id = pi.contact_id AND c.company_id = pi.company_id
         WHERE pi.id = :invoice_id
           AND pi.company_id = :company_id
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
        throw new RuntimeException('Ostuarvet ei leitud või see pole enam mustand.');
    }

    $periodMessage = closedAccountingPeriodMessage($pdo, $companyId, $invoice['invoice_date']);
    if ($periodMessage !== null) {
        throw new RuntimeException($periodMessage);
    }
    $vatAccountId = filter_var(
        $_POST['vat_account_id'] ?? null,
        FILTER_VALIDATE_INT
    );
    $payableAccountId = filter_var(
        $_POST['payable_account_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!$payableAccountId) {
        throw new RuntimeException('Vali tarnijavõla konto.');
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

    if (($accountTypes[$payableAccountId] ?? '') !== 'liability') {
        throw new RuntimeException('Tarnijavõla kontoks vali aktiivne kohustuste konto.');
    }

    $linesStmt = $pdo->prepare(
        'SELECT pil.account_id, pil.product_id, pil.warehouse_id,
                pil.description, pil.quantity, pil.unit_price, pil.net_amount,
                pil.vat_amount, pil.gross_amount, pil.vat_rate, tc.code AS tax_code, tc.valid_from AS tax_valid_from, tc.valid_to AS tax_valid_to, a.account_type
         FROM purchase_invoice_lines pil
         JOIN accounts a ON a.id = pil.account_id
         LEFT JOIN purchase_invoices pi ON pi.id = pil.invoice_id
         LEFT JOIN tax_codes tc ON tc.id = pil.tax_code_id AND tc.company_id = pi.company_id
         WHERE pil.invoice_id = :invoice_id
           AND a.company_id = :company_id
           AND a.is_active = 1'
    );
    $linesStmt->execute([
        'invoice_id' => $invoiceId,
        'company_id' => $companyId,
    ]);
    $lines = $linesStmt->fetchAll();

    if (!$lines) {
        throw new RuntimeException('Ostuarvel pole arveridu.');
    }
    $importRows=count(array_filter($lines,static fn(array $line):bool=>($line['tax_code']??'')==='O_IMPORT0'));
    if($importRows && $importRows!==count($lines)) throw new RuntimeException('Impordi kaubaarve peab sisaldama ainult O_IMPORT0 ridu; muud tehingud sisesta eraldi arvele.');
    if($importRows) foreach($lines as $line) if((float)$line['vat_rate']!==0.0 || toCents((string)$line['vat_amount'])!==0) throw new RuntimeException('Impordi kaubaarvel ei tohi olla tarnija KM-i; impordi käibemaks sisesta tollidokumendi juurde.');

    $netTotal = 0;
    $vatTotal = 0;
    $grossTotal = 0;
    $stockLines = [];
    $reverseVatTotal = 0;

    foreach ($lines as $line) {
        if (!in_array($line['account_type'], ['expense', 'asset'], true)) {
            throw new RuntimeException('Arverea konto peab olema kulu- või varakonto.');
        }

        $reverseSpec = reverseVatSpec((string) ($line['tax_code'] ?? ''));
        if ($reverseSpec) {
            $supplierError = reversePartyError($reverseSpec, (string) $invoice['country_code'], (string) $invoice['vat_number']);
            $companyError=reverseCompanyError($pdo,$companyId,$reverseSpec);
            if ($companyError!==null) throw new RuntimeException($companyError);
            if ($supplierError !== null) throw new RuntimeException($supplierError);
            if ($reverseSpec['side'] !== 'purchase' || $invoice['invoice_date'] < $reverseSpec['valid_from']
                || ($line['tax_valid_to'] !== null && $invoice['invoice_date'] > $line['tax_valid_to'])
                || toCents((string) $line['vat_amount']) !== 0
                || (float) $line['vat_rate'] !== 0.0
                || toCents((string) $line['net_amount']) !== toCents((string) $line['gross_amount'])) {
                throw new RuntimeException('Pöördmaksu real peab tarnija käibemaks olema 0 ja maksukood arve kuupäeval kehtima.');
            }
            $reverseVatTotal += euReverseVat(toCents((string) $line['net_amount']), $reverseSpec['rate']);
        }
        $netTotal += toCents((string) $line['net_amount']);
        $vatTotal += toCents((string) $line['vat_amount']);
        $grossTotal += toCents((string) $line['gross_amount']);

        if ($line['product_id'] === null && $line['warehouse_id'] !== null) {
            throw new RuntimeException('Ostuarve real on ladu valitud ilma laotoote valikuta. Paranda mustand enne kinnitamist.');
        }
        if ($line['product_id'] !== null) {
            if ($line['warehouse_id'] === null || $line['account_type'] !== 'asset') {
                throw new RuntimeException('Laotoote real peab olema ladu ja varakonto. Ostuarvet ei kinnitatud.');
            }
            $productCheck = $pdo->prepare('SELECT id, inventory_account_id FROM inventory_products WHERE id = ? AND company_id = ? AND is_active = 1 FOR UPDATE');
            $productCheck->execute([(int) $line['product_id'], $companyId]);
            $warehouseCheck = $pdo->prepare('SELECT id FROM inventory_warehouses WHERE id = ? AND company_id = ? AND is_active = 1 FOR UPDATE');
            $warehouseCheck->execute([(int) $line['warehouse_id'], $companyId]);
            $stockProduct = $productCheck->fetch();
            if (!$stockProduct || !$warehouseCheck->fetchColumn()) {
                throw new RuntimeException('Ostuarve laotoode või ladu puudub või pole aktiivne.');
            }
            if (empty($stockProduct['inventory_account_id']) || (int) $stockProduct['inventory_account_id'] !== (int) $line['account_id']) {
                throw new RuntimeException('Ostuarve rea konto peab olema sama, mis tootekaardil määratud varude konto. Ava tootekaart ja vali varude konto enne arve kinnitamist.');
            }
            $stockLines[] = $line;
        }
    }

    if ($issue = foreignPurchaseSupplierVatError($invoice, $vatTotal)) throw new RuntimeException($issue);
    $reverseVatAccountId = (int) ($_POST['reverse_vat_account_id'] ?? 0);
    $reversePosting = euReversePostingLines($reverseVatTotal, (int) $vatAccountId, $reverseVatAccountId, (int) $payableAccountId, $accountTypes);

    if ($grossTotal <= 0 || $netTotal + $vatTotal !== $grossTotal) {
        throw new RuntimeException('Ostuarve summad on vigased või ei klapi.');
    }

    if ($vatTotal > 0 && ($accountTypes[$vatAccountId] ?? '') !== 'asset') {
        throw new RuntimeException('Sisendkäibemaksu kontoks vali aktiivne varakonto.');
    }

    $description = 'Ostuarve ' . $invoice['supplier_invoice_number']
        . ' — ' . $invoice['supplier_name'];

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
        'description' => $description,
        'document_number' => $invoice['supplier_invoice_number'],
        'created_by' => (int) $_SESSION['user_id'],
    ]);

    $entryId = (int) $pdo->lastInsertId();

    $lineStmt = $pdo->prepare(
        'INSERT INTO journal_lines
            (entry_id, account_id, description, debit, credit)
         VALUES
            (:entry_id, :account_id, :description, :debit, :credit)'
    );

    // Iga arverea netosumma oma kulu- või varakonto deebetisse.
    foreach ($lines as $line) {
        $lineNet = toCents((string) $line['net_amount']);

        if ($lineNet > 0) {
            $lineStmt->execute([
                'entry_id' => $entryId,
                'account_id' => (int) $line['account_id'],
                'description' => $line['description'],
                'debit' => centsToDecimal($lineNet),
                'credit' => '0.00',
            ]);
        }
    }

    // Sisendkäibemaksu summa varakonto deebetisse.
    if ($vatTotal > 0) {
        if (!$vatAccountId || !isset($accountTypes[$vatAccountId])) {
            throw new RuntimeException('Vali sisendkäibemaksu varakonto.');
        }

        $lineStmt->execute([
            'entry_id' => $entryId,
            'account_id' => $vatAccountId,
            'description' => 'Sisendkäibemaks',
            'debit' => centsToDecimal($vatTotal),
            'credit' => '0.00',
        ]);
    }

    foreach ($reversePosting as $reverseLine) {
        $lineStmt->execute([
            'entry_id' => $entryId,
            'account_id' => $reverseLine['account_id'],
            'description' => $reverseLine['description'],
            'debit' => centsToDecimal($reverseLine['debit_cents']),
            'credit' => centsToDecimal($reverseLine['credit_cents']),
        ]);
    }

    // Tarnijavõlg arve kogusummas kreeditisse.
    $lineStmt->execute([
        'entry_id' => $entryId,
        'account_id' => $payableAccountId,
        'description' => 'Tarnijavõlg',
        'debit' => '0.00',
        'credit' => centsToDecimal($grossTotal),
    ]);

    if ($stockLines) {
        $stockMovementStmt = $pdo->prepare(
            'INSERT INTO inventory_movements
                (company_id, source_purchase_invoice_id, movement_type, movement_date,
                 document_number, description, status, created_by, posted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stockMovementStmt->execute([
            $companyId,
            $invoiceId,
            'receipt',
            $invoice['invoice_date'],
            $invoice['supplier_invoice_number'],
            'Ostuarve ' . $invoice['supplier_invoice_number'] . ' — ' . $invoice['supplier_name'],
            'posted',
            (int) $_SESSION['user_id'],
        ]);
        $stockMovementId = (int) $pdo->lastInsertId();
        $stockLineStmt = $pdo->prepare(
            'INSERT INTO inventory_movement_lines
                (movement_id, warehouse_id, product_id, quantity_in, quantity_out, unit_cost, total_cost)
             VALUES (?, ?, ?, ?, 0, ?, ?)'
        );
        foreach ($stockLines as $stockLine) {
            $stockLineStmt->execute([
                $stockMovementId,
                (int) $stockLine['warehouse_id'],
                (int) $stockLine['product_id'],
                (string) $stockLine['quantity'],
                (string) $stockLine['unit_price'],
                (string) $stockLine['net_amount'],
            ]);
        }
    }

    $updateStmt = $pdo->prepare(
        "UPDATE purchase_invoices
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
        throw new RuntimeException('Ostuarve kinnitamine ebaõnnestus.');
    }

    $pdo->commit();

    header('Location: purchase_invoices.php?id=' . $invoiceId);
    exit;
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
        . '<p><a href="purchase_invoices.php?id=' . (int) $invoiceId . '">Tagasi ostuarve juurde</a></p>');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($e->getMessage());
    http_response_code(500);
    exit('Ostuarve kinnitamisel tekkis viga.');
}
