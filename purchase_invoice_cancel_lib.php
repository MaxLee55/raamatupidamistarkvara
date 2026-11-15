<?php

function ensurePurchaseInvoiceCancellationTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS purchase_invoice_cancellations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            company_id INT UNSIGNED NOT NULL,
            purchase_invoice_id INT UNSIGNED NOT NULL,
            inventory_movement_id INT UNSIGNED NOT NULL,
            original_journal_entry_id INT UNSIGNED NOT NULL,
            reversal_journal_entry_id INT UNSIGNED NOT NULL,
            cancelled_by INT UNSIGNED NOT NULL,
            reason VARCHAR(500) NOT NULL DEFAULT '',
            cancelled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_purchase_cancel_invoice (purchase_invoice_id),
            UNIQUE KEY uq_purchase_cancel_movement (inventory_movement_id),
            UNIQUE KEY uq_purchase_cancel_reversal (reversal_journal_entry_id),
            KEY idx_purchase_cancel_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci"
    );
}

function ensureSalesInvoiceCancellationTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS sales_invoice_cancellations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            company_id INT UNSIGNED NOT NULL,
            sales_invoice_id INT UNSIGNED NOT NULL,
            inventory_movement_id INT UNSIGNED DEFAULT NULL,
            original_journal_entry_id INT UNSIGNED NOT NULL,
            reversal_journal_entry_id INT UNSIGNED NOT NULL,
            cancelled_by INT UNSIGNED NOT NULL,
            reason VARCHAR(500) NOT NULL DEFAULT '',
            cancelled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_sales_cancel_invoice (sales_invoice_id),
            UNIQUE KEY uq_sales_cancel_movement (inventory_movement_id),
            UNIQUE KEY uq_sales_cancel_reversal (reversal_journal_entry_id),
            KEY idx_sales_cancel_company (company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci"
    );
}

function purchaseCancellationEntryLines(PDO $pdo, int $entryId): array
{
    $stmt = $pdo->prepare(
        'SELECT account_id, SUM(debit) AS debit_total, SUM(credit) AS credit_total
         FROM journal_lines WHERE entry_id = ? GROUP BY account_id'
    );
    $stmt->execute([$entryId]);
    $lines = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $accountId = (int) $row['account_id'];
        $debit = (int) round((float) $row['debit_total'] * 100);
        $credit = (int) round((float) $row['credit_total'] * 100);
        if ($debit !== 0 || $credit !== 0) {
            $lines[$accountId] = [$debit, $credit];
        }
    }
    ksort($lines);
    return $lines;
}

function isExactPurchaseInvoiceReversal(PDO $pdo, int $originalEntryId, int $candidateEntryId): bool
{
    if ($originalEntryId <= 0 || $candidateEntryId <= 0 || $originalEntryId === $candidateEntryId) {
        return false;
    }
    $original = purchaseCancellationEntryLines($pdo, $originalEntryId);
    $candidate = purchaseCancellationEntryLines($pdo, $candidateEntryId);
    if (!$original || count($original) !== count($candidate)) {
        return false;
    }
    foreach ($original as $accountId => [$debit, $credit]) {
        if (!isset($candidate[$accountId]) || $candidate[$accountId] !== [$credit, $debit]) {
            return false;
        }
    }
    return true;
}

function isExactJournalEntryReversal(PDO $pdo, int $originalEntryId, int $candidateEntryId): bool
{
    return isExactPurchaseInvoiceReversal($pdo, $originalEntryId, $candidateEntryId);
}

function journalEntryUsedForInvoiceCancellation(PDO $pdo, int $companyId, int $entryId): bool
{
    $tables = ['purchase_invoice_cancellations', 'sales_invoice_cancellations'];
    $existsStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?'
    );
    foreach ($tables as $table) {
        $existsStmt->execute([$table]);
        if ((int) $existsStmt->fetchColumn() === 0) {
            continue;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE company_id = ? AND reversal_journal_entry_id = ?");
        $stmt->execute([$companyId, $entryId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return true;
        }
    }
    return false;
}

function purchaseInvoiceLaterStockOutflow(PDO $pdo, int $companyId, int $movementId): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM inventory_movement_lines receipt_line
         JOIN inventory_movement_lines later_line
           ON later_line.warehouse_id = receipt_line.warehouse_id
          AND later_line.product_id = receipt_line.product_id
          AND later_line.quantity_out > 0
         JOIN inventory_movements receipt
           ON receipt.id = receipt_line.movement_id
          AND receipt.company_id = ? AND receipt.status = \'posted\'
         JOIN inventory_movements later
           ON later.id = later_line.movement_id
          AND later.company_id = receipt.company_id AND later.status = \'posted\'
         WHERE receipt_line.movement_id = ? AND receipt_line.quantity_in > 0
           AND (later.movement_date > receipt.movement_date
                OR (later.movement_date = receipt.movement_date AND later.id > receipt.id))'
    );
    $stmt->execute([$companyId, $movementId]);
    return (int) $stmt->fetchColumn() > 0;
}

function purchaseInvoiceReversalCandidates(PDO $pdo, int $companyId, int $originalEntryId): array
{
    $originalStmt = $pdo->prepare(
        'SELECT entry_date FROM journal_entries WHERE id = ? AND company_id = ? AND status = \'posted\''
    );
    $originalStmt->execute([$originalEntryId, $companyId]);
    $originalDate = $originalStmt->fetchColumn();
    if (!$originalDate) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT id, entry_date, description, document_number
         FROM journal_entries
         WHERE company_id = ? AND status = \'posted\' AND id > ? AND entry_date >= ?
         ORDER BY entry_date, id'
    );
    $stmt->execute([$companyId, $originalEntryId, $originalDate]);
    $candidates = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $entry) {
        if (isExactPurchaseInvoiceReversal($pdo, $originalEntryId, (int) $entry['id'])) {
            $candidates[] = $entry;
        }
    }
    return $candidates;
}

function salesInvoiceReversalCandidates(PDO $pdo, int $companyId, int $originalEntryId): array
{
    $originalStmt = $pdo->prepare(
        'SELECT entry_date FROM journal_entries WHERE id = ? AND company_id = ? AND status = \'posted\''
    );
    $originalStmt->execute([$originalEntryId, $companyId]);
    $originalDate = $originalStmt->fetchColumn();
    if (!$originalDate) {
        return [];
    }
    $stmt = $pdo->prepare(
        'SELECT id, entry_date, description, document_number
         FROM journal_entries
         WHERE company_id = ? AND status = \'posted\' AND id > ? AND entry_date >= ?
         ORDER BY entry_date, id'
    );
    $stmt->execute([$companyId, $originalEntryId, $originalDate]);
    $candidates = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $entry) {
        if (isExactJournalEntryReversal($pdo, $originalEntryId, (int) $entry['id'])) {
            $candidates[] = $entry;
        }
    }
    return $candidates;
}

function salesInvoiceLaterStockOutflow(PDO $pdo, int $companyId, int $movementId): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM inventory_movement_lines source_line
         JOIN inventory_movement_lines later_line
           ON later_line.warehouse_id = source_line.warehouse_id
          AND later_line.product_id = source_line.product_id
          AND later_line.quantity_out > 0
         JOIN inventory_movements source
           ON source.id = source_line.movement_id
          AND source.company_id = ? AND source.status = \'posted\'
         JOIN inventory_movements later
           ON later.id = later_line.movement_id
          AND later.company_id = source.company_id AND later.status = \'posted\'
         WHERE source_line.movement_id = ? AND source_line.quantity_out > 0
           AND (later.movement_date > source.movement_date
                OR (later.movement_date = source.movement_date AND later.id > source.id))'
    );
    $stmt->execute([$companyId, $movementId]);
    return (int) $stmt->fetchColumn() > 0;
}
