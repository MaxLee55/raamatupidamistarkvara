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
require_once __DIR__ . '/company_vat_status.php';
require_once __DIR__ . '/ladu/inventory_account_settings.php';
require_once __DIR__ . '/reset_work_data.php';
require_once __DIR__ . '/reset_work_period.php';
require_once __DIR__ . '/database_backup.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$companyId = (int) $_SESSION['company_id'];
loadInventoryAccountDefaults($pdo, $companyId);
$errors = [];
$saved = false;
$accountNotice = $_SESSION['settings_account_notice'] ?? null;
unset($_SESSION['settings_account_notice']);
$maintenanceNotice = $_SESSION['settings_notice'] ?? null;
unset($_SESSION['settings_notice']);

$bankCsvBatchesStmt = $pdo->prepare(
    "SELECT bsl.import_batch_id,
            MAX(bsl.source_file_name) AS source_file_name,
            a.code AS bank_code,
            a.name AS bank_name,
            MIN(bsl.created_at) AS imported_at,
            COUNT(*) AS row_count,
            SUM(bsl.matched_journal_line_id IS NOT NULL) AS matched_rows,
            SUM(bsl.draft_journal_entry_id IS NOT NULL) AS draft_rows
     FROM bank_statement_lines bsl
     JOIN accounts a ON a.id = bsl.bank_account_id AND a.company_id = bsl.company_id
     WHERE bsl.company_id = ? AND bsl.import_batch_id IS NOT NULL
     GROUP BY bsl.import_batch_id, a.id, a.code, a.name
     ORDER BY imported_at DESC, bsl.import_batch_id DESC"
);
$bankCsvBatchesStmt->execute([$companyId]);
$bankCsvBatches = $bankCsvBatchesStmt->fetchAll(PDO::FETCH_ASSOC);

$legacyBankCsvStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM bank_statement_lines WHERE company_id = ? AND import_batch_id IS NULL'
);
$legacyBankCsvStmt->execute([$companyId]);
$legacyBankCsvRows = (int) $legacyBankCsvStmt->fetchColumn();

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['action'] ?? '', ['clear_entries', 'clear_entries_from_date', 'clear_bank_csv', 'clear_bank_csv_batch', 'clear_bank_csv_legacy', 'clear_portfolio_opening'], true)
) {
    $action = (string) $_POST['action'];

    if (
        empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
    ) {
        $noticeKey = $action === 'clear_portfolio_opening'
            ? 'portfolio_opening_notice'
            : 'settings_notice';
        $_SESSION[$noticeKey] = [
            'type' => 'error',
            'text' => 'Vorm aegus. Andmeid ei kustutatud; värskenda lehte ja proovi uuesti.',
        ];
    } else {
        $deleteFromDate = null;
        if ($action === 'clear_entries_from_date') {
            $deleteFromDate = trim((string) ($_POST['delete_from'] ?? ''));
            $parsedDeleteDate = DateTimeImmutable::createFromFormat('!Y-m-d', $deleteFromDate);
            if (
                $deleteFromDate === ''
                || $parsedDeleteDate === false
                || $parsedDeleteDate->format('Y-m-d') !== $deleteFromDate
            ) {
                $_SESSION['settings_notice'] = [
                    'type' => 'error',
                    'text' => 'Vali kehtiv kustutamise alguskuupäev. Andmeid ei kustutatud.',
                ];
                header('Location: settings.php');
                exit;
            }
        }

        $backupName = 'raamatupidamine_' . date('Ymd_His')
            . '_company_' . $companyId . '_enne_kustutamist.sql';
        $backupPath = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR
            . 'varukoopiad' . DIRECTORY_SEPARATOR . 'raamatupidamine'
            . DIRECTORY_SEPARATOR . $backupName;

        try {
            createDatabaseBackup($backupPath);
            $pdo->beginTransaction();

            if ($action === 'clear_entries') {
                $noticeText=resetCompanyWorkData($pdo,$companyId);
            } elseif ($action === 'clear_entries_from_date') {
                lockWorkDataCompany($pdo,$companyId);
                $payrollRunStmt = $pdo->prepare(
                    'SELECT pr.id
                     FROM payroll_runs pr
                     WHERE pr.company_id = ?
                       AND (LAST_DAY(pr.work_month) >= ? OR pr.planned_payment_date >= ?
                            OR EXISTS (
                                SELECT 1 FROM payroll_run_item_payments pp
                                WHERE pp.company_id = pr.company_id AND pp.run_id = pr.id
                                  AND pp.actual_payment_date >= ?
                            ))'
                );
                $payrollRunStmt->execute([$companyId, $deleteFromDate, $deleteFromDate, $deleteFromDate]);
                $payrollRunIds = array_values(array_unique(array_map('intval', $payrollRunStmt->fetchAll(PDO::FETCH_COLUMN))));

                $entryIds = [];
                $cancelledPurchaseInvoiceIds = [];
                $cancelledMovementIds = [];
                $purchaseCancellationIds = [];
                $cancelledSalesInvoiceIds = [];
                $cancelledSalesMovementIds = [];
                $salesCancellationIds = [];
                $entryQueries = [
                    [
                        'SELECT id FROM journal_entries WHERE company_id = ? AND entry_date >= ?',
                        [$companyId, $deleteFromDate],
                    ],
                    [
                        'SELECT journal_entry_id FROM inventory_movements
                         WHERE company_id = ? AND movement_date >= ? AND journal_entry_id IS NOT NULL',
                        [$companyId, $deleteFromDate],
                    ],
                    [
                        'SELECT journal_entry_id FROM sales_invoices WHERE company_id = ? AND invoice_date >= ? AND journal_entry_id IS NOT NULL',
                        [$companyId, $deleteFromDate],
                    ],
                    [
                        'SELECT ip.journal_entry_id
                         FROM invoice_payments ip
                         JOIN sales_invoices si ON si.id = ip.invoice_id
                         WHERE si.company_id = ? AND (ip.payment_date >= ? OR si.invoice_date >= ?)',
                        [$companyId, $deleteFromDate, $deleteFromDate],
                    ],
                    [
                        'SELECT journal_entry_id FROM purchase_invoices WHERE company_id = ? AND invoice_date >= ? AND journal_entry_id IS NOT NULL',
                        [$companyId, $deleteFromDate],
                    ],
                    [
                        'SELECT pip.journal_entry_id
                         FROM purchase_invoice_payments pip
                         JOIN purchase_invoices pi ON pi.id = pip.invoice_id
                         WHERE pi.company_id = ? AND (pip.payment_date >= ? OR pi.invoice_date >= ?)',
                        [$companyId, $deleteFromDate, $deleteFromDate],
                    ],
                ];
                foreach ($entryQueries as [$query, $parameters]) {
                    $entryStmt = $pdo->prepare($query);
                    $entryStmt->execute($parameters);
                    foreach ($entryStmt->fetchAll(PDO::FETCH_COLUMN) as $entryId) {
                        if ($entryId !== null) {
                            $entryIds[] = (int) $entryId;
                        }
                    }
                }

                // Kui kustutatava perioodiga on seotud ostuarve tühistamine,
                // eemalda koos paranduskandega ka kogu arve/laoliikumise auditikirje.
                $cancellationTableExists = $pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
                );
                $cancellationTableExists->execute(['purchase_invoice_cancellations']);
                if ((int) $cancellationTableExists->fetchColumn() > 0) {
                    $cancellationStmt = $pdo->prepare(
                        'SELECT pc.id, pc.purchase_invoice_id, pc.inventory_movement_id,
                                pc.original_journal_entry_id, pc.reversal_journal_entry_id,
                                pi.invoice_date, im.movement_date, original_entry.entry_date AS original_date,
                                reversal_entry.entry_date AS reversal_date
                         FROM purchase_invoice_cancellations pc
                         JOIN purchase_invoices pi ON pi.id = pc.purchase_invoice_id AND pi.company_id = pc.company_id
                         JOIN inventory_movements im ON im.id = pc.inventory_movement_id AND im.company_id = pc.company_id
                         LEFT JOIN journal_entries original_entry ON original_entry.id = pc.original_journal_entry_id
                         LEFT JOIN journal_entries reversal_entry ON reversal_entry.id = pc.reversal_journal_entry_id
                         WHERE pc.company_id = ?'
                    );
                    $cancellationStmt->execute([$companyId]);
                    $initialEntryIds = array_fill_keys(array_map('intval', $entryIds), true);
                    foreach ($cancellationStmt->fetchAll(PDO::FETCH_ASSOC) as $cancelRow) {
                        $touchesPeriod = $cancelRow['invoice_date'] >= $deleteFromDate
                            || $cancelRow['movement_date'] >= $deleteFromDate
                            || ($cancelRow['original_date'] !== null && $cancelRow['original_date'] >= $deleteFromDate)
                            || ($cancelRow['reversal_date'] !== null && $cancelRow['reversal_date'] >= $deleteFromDate)
                            || isset($initialEntryIds[(int) $cancelRow['original_journal_entry_id']])
                            || isset($initialEntryIds[(int) $cancelRow['reversal_journal_entry_id']]);
                        if ($touchesPeriod) {
                            $purchaseCancellationIds[] = (int) $cancelRow['id'];
                            $cancelledPurchaseInvoiceIds[] = (int) $cancelRow['purchase_invoice_id'];
                            $cancelledMovementIds[] = (int) $cancelRow['inventory_movement_id'];
                            $entryIds[] = (int) $cancelRow['original_journal_entry_id'];
                            $entryIds[] = (int) $cancelRow['reversal_journal_entry_id'];
                        }
                    }
                }
                $salesCancellationTableExists = $pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
                );
                $salesCancellationTableExists->execute(['sales_invoice_cancellations']);
                if ((int) $salesCancellationTableExists->fetchColumn() > 0) {
                    $salesCancellationStmt = $pdo->prepare(
                        'SELECT sc.id, sc.sales_invoice_id, sc.inventory_movement_id,
                                sc.original_journal_entry_id, sc.reversal_journal_entry_id,
                                si.invoice_date, im.movement_date, original_entry.entry_date AS original_date,
                                reversal_entry.entry_date AS reversal_date
                         FROM sales_invoice_cancellations sc
                         JOIN sales_invoices si ON si.id = sc.sales_invoice_id AND si.company_id = sc.company_id
                         LEFT JOIN inventory_movements im ON im.id = sc.inventory_movement_id AND im.company_id = sc.company_id
                         LEFT JOIN journal_entries original_entry ON original_entry.id = sc.original_journal_entry_id
                         LEFT JOIN journal_entries reversal_entry ON reversal_entry.id = sc.reversal_journal_entry_id
                         WHERE sc.company_id = ?'
                    );
                    $salesCancellationStmt->execute([$companyId]);
                    $initialEntryIds = array_fill_keys(array_map('intval', $entryIds), true);
                    foreach ($salesCancellationStmt->fetchAll(PDO::FETCH_ASSOC) as $cancelRow) {
                        $touchesPeriod = $cancelRow['invoice_date'] >= $deleteFromDate
                            || ($cancelRow['movement_date'] !== null && $cancelRow['movement_date'] >= $deleteFromDate)
                            || ($cancelRow['original_date'] !== null && $cancelRow['original_date'] >= $deleteFromDate)
                            || ($cancelRow['reversal_date'] !== null && $cancelRow['reversal_date'] >= $deleteFromDate)
                            || isset($initialEntryIds[(int) $cancelRow['original_journal_entry_id']])
                            || isset($initialEntryIds[(int) $cancelRow['reversal_journal_entry_id']]);
                        if ($touchesPeriod) {
                            $salesCancellationIds[] = (int) $cancelRow['id'];
                            $cancelledSalesInvoiceIds[] = (int) $cancelRow['sales_invoice_id'];
                            if ($cancelRow['inventory_movement_id'] !== null) {
                                $cancelledSalesMovementIds[] = (int) $cancelRow['inventory_movement_id'];
                            }
                            $entryIds[] = (int) $cancelRow['original_journal_entry_id'];
                            $entryIds[] = (int) $cancelRow['reversal_journal_entry_id'];
                        }
                    }
                }

                // Põhivara proovikirjed valitakse sisestamise, soetamise,
                // kasutusvalmis kuupäeva või kustutatava ostuarve järgi.
                // Kontoseadete tabelit see puhastus ei puuduta.
                $fixedAssetTables = [];
                $fixedAssetTableCheck = $pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
                );
                foreach ([
                    'fixed_assets',
                    'fixed_asset_depreciation_runs',
                    'fixed_asset_estimate_changes',
                    'fixed_asset_disposals',
                    'fixed_asset_disposal_reversals',
                    'fixed_asset_depreciation_corrections',
                ] as $fixedAssetTable) {
                    $fixedAssetTableCheck->execute([$fixedAssetTable]);
                    $fixedAssetTables[$fixedAssetTable] = (int) $fixedAssetTableCheck->fetchColumn() > 0;
                }
                $fixedAssetIdsToDelete = [];
                $fixedAssetDisposalIdsToDelete = [];
                $fixedAssetDisposalReversalIdsToDelete = [];
                $fixedAssetDisposalIdsToReopen = [];
                $fixedAssetDisposalAssetIdsToRefresh = [];
                $deletedFixedAssets = 0;
                $deletedFixedAssetRuns = 0;
                $deletedFixedAssetDisposals = 0;
                if ($fixedAssetTables['fixed_assets']) {
                    $invoiceDateClause = '';
                    $assetParameters = [$companyId, $deleteFromDate, $deleteFromDate, $deleteFromDate];
                    if ($cancelledPurchaseInvoiceIds) {
                        $cancelledInvoicePlaceholders = implode(',', array_fill(0, count($cancelledPurchaseInvoiceIds), '?'));
                        $invoiceDateClause = " OR EXISTS (
                            SELECT 1 FROM purchase_invoices pi
                            WHERE pi.company_id = fa.company_id
                              AND pi.supplier_invoice_number COLLATE utf8mb4_unicode_ci = fa.source_document COLLATE utf8mb4_unicode_ci
                              AND (pi.invoice_date >= ? OR pi.id IN ($cancelledInvoicePlaceholders))
                        )";
                        $assetParameters[] = $deleteFromDate;
                        $assetParameters = array_merge($assetParameters, $cancelledPurchaseInvoiceIds);
                    } else {
                        $invoiceDateClause = " OR EXISTS (
                            SELECT 1 FROM purchase_invoices pi
                            WHERE pi.company_id = fa.company_id
                              AND pi.supplier_invoice_number COLLATE utf8mb4_unicode_ci = fa.source_document COLLATE utf8mb4_unicode_ci
                              AND pi.invoice_date >= ?
                        )";
                        $assetParameters[] = $deleteFromDate;
                    }
                    $assetStmt = $pdo->prepare(
                        "SELECT fa.id FROM fixed_assets fa
                         WHERE fa.company_id = ?
                           AND (DATE(fa.created_at) >= ? OR fa.acquisition_date >= ? OR fa.available_date >= ?$invoiceDateClause)"
                    );
                    $assetStmt->execute($assetParameters);
                    $fixedAssetIdsToDelete = array_values(array_unique(array_map('intval', $assetStmt->fetchAll(PDO::FETCH_COLUMN))));
                }
                $fixedAssetIdSet = array_fill_keys($fixedAssetIdsToDelete, true);
                if ($fixedAssetIdsToDelete && $fixedAssetTables['fixed_asset_depreciation_runs']) {
                    $assetPlaceholders = implode(',', array_fill(0, count($fixedAssetIdsToDelete), '?'));
                    $runEntryStmt = $pdo->prepare(
                        "SELECT journal_entry_id FROM fixed_asset_depreciation_runs
                         WHERE company_id = ? AND asset_id IN ($assetPlaceholders)"
                    );
                    $runEntryStmt->execute(array_merge([$companyId], $fixedAssetIdsToDelete));
                    foreach ($runEntryStmt->fetchAll(PDO::FETCH_COLUMN) as $entryId) $entryIds[] = (int) $entryId;
                }
                if ($fixedAssetTables['fixed_asset_disposals']) {
                    $entryIdSet = array_fill_keys(array_map('intval', $entryIds), true);
                    $disposalStmt = $pdo->prepare(
                        'SELECT id, asset_id, disposal_date, journal_entry_id
                         FROM fixed_asset_disposals WHERE company_id = ?'
                    );
                    $disposalStmt->execute([$companyId]);
                    foreach ($disposalStmt->fetchAll(PDO::FETCH_ASSOC) as $disposalRow) {
                        $disposalId = (int) $disposalRow['id'];
                        $assetId = (int) $disposalRow['asset_id'];
                        $disposalEntryId = (int) $disposalRow['journal_entry_id'];
                        if ($disposalRow['disposal_date'] >= $deleteFromDate
                            || isset($entryIdSet[$disposalEntryId])
                            || isset($fixedAssetIdSet[$assetId])) {
                            $fixedAssetDisposalIdsToDelete[] = $disposalId;
                            $fixedAssetDisposalAssetIdsToRefresh[] = $assetId;
                            $entryIds[] = $disposalEntryId;
                        }
                    }
                }
                $fixedAssetDisposalIdsToDelete = array_values(array_unique($fixedAssetDisposalIdsToDelete));
                $fixedAssetDisposalAssetIdsToRefresh = array_values(array_unique($fixedAssetDisposalAssetIdsToRefresh));
                if ($fixedAssetTables['fixed_asset_disposal_reversals'] && $fixedAssetTables['fixed_asset_disposals']) {
                    $entryIdSet = array_fill_keys(array_map('intval', $entryIds), true);
                    $disposalIdSet = array_fill_keys($fixedAssetDisposalIdsToDelete, true);
                    $disposalAssetStmt = $pdo->prepare(
                        'SELECT id, asset_id FROM fixed_asset_disposals WHERE company_id = ?'
                    );
                    $disposalAssetStmt->execute([$companyId]);
                    $disposalAssetById = [];
                    foreach ($disposalAssetStmt->fetchAll(PDO::FETCH_ASSOC) as $row) $disposalAssetById[(int) $row['id']] = (int) $row['asset_id'];
                    $reversalStmt = $pdo->prepare(
                        'SELECT id, disposal_id, reversal_journal_entry_id, reversal_date
                         FROM fixed_asset_disposal_reversals WHERE company_id = ?'
                    );
                    $reversalStmt->execute([$companyId]);
                    foreach ($reversalStmt->fetchAll(PDO::FETCH_ASSOC) as $reversalRow) {
                        $disposalId = (int) $reversalRow['disposal_id'];
                        $reversalEntryId = (int) $reversalRow['reversal_journal_entry_id'];
                        $assetId = $disposalAssetById[$disposalId] ?? 0;
                        if ($reversalRow['reversal_date'] >= $deleteFromDate
                            || isset($entryIdSet[$reversalEntryId])
                            || isset($disposalIdSet[$disposalId])
                            || isset($fixedAssetIdSet[$assetId])) {
                            $fixedAssetDisposalReversalIdsToDelete[] = (int) $reversalRow['id'];
                            $entryIds[] = $reversalEntryId;
                            if ($assetId > 0) $fixedAssetDisposalAssetIdsToRefresh[] = $assetId;
                            if (!isset($disposalIdSet[$disposalId])) $fixedAssetDisposalIdsToReopen[] = $disposalId;
                        }
                    }
                }
                $fixedAssetDisposalReversalIdsToDelete = array_values(array_unique($fixedAssetDisposalReversalIdsToDelete));
                $fixedAssetDisposalIdsToReopen = array_values(array_unique($fixedAssetDisposalIdsToReopen));
                $fixedAssetDisposalAssetIdsToRefresh = array_values(array_unique($fixedAssetDisposalAssetIdsToRefresh));
                $entryIds = array_values(array_unique(array_filter($entryIds, static fn(int $id): bool => $id > 0)));
                $cancelledPurchaseInvoiceIds = array_values(array_unique($cancelledPurchaseInvoiceIds));
                $cancelledMovementIds = array_values(array_unique(array_merge($cancelledMovementIds, $cancelledSalesMovementIds)));
                $cancelledSalesInvoiceIds = array_values(array_unique($cancelledSalesInvoiceIds));
                $deletedSecurityTrades = resetPeriodSupplementary($pdo,$companyId,$deleteFromDate,
                    $entryIds,$cancelledSalesInvoiceIds,$cancelledPurchaseInvoiceIds,$cancelledMovementIds,
                    $salesCancellationIds,$purchaseCancellationIds,$payrollRunIds,$fixedAssetIdsToDelete);

                // Kustuta liikumised alles pärast nende seotud pearaamatukannete leidmist.
                // Nii ei jää alles kanne, mille kuupäev on varasem, kuid laoliikumine kuulub
                // kustutatavasse perioodi.
                $statement = $pdo->prepare('DELETE FROM inventory_movements WHERE company_id = ? AND movement_date >= ?');
                $statement->execute([$companyId, $deleteFromDate]);
                if ($cancelledMovementIds) {
                    $movementPlaceholders = implode(',', array_fill(0, count($cancelledMovementIds), '?'));
                    $statement = $pdo->prepare("DELETE FROM inventory_movements WHERE company_id = ? AND id IN ($movementPlaceholders)");
                    $statement->execute(array_merge([$companyId], $cancelledMovementIds));
                }

                // Pangaväljavõtte read säilivad, kuid alates valitud kuupäevast
                // või kustutatava kande küljes olnud read muutuvad sidumata olekusse.
                $statement = $pdo->prepare(
                    'UPDATE bank_statement_lines
                     SET matched_journal_line_id = NULL, draft_journal_entry_id = NULL
                     WHERE company_id = ? AND transaction_date >= ?'
                );
                $statement->execute([$companyId, $deleteFromDate]);
                if ($entryIds) {
                    $entryPlaceholders = implode(',', array_fill(0, count($entryIds), '?'));
                    $statement = $pdo->prepare(
                        "UPDATE bank_statement_lines
                         SET matched_journal_line_id = NULL
                         WHERE company_id = ?
                           AND matched_journal_line_id IN (
                               SELECT jl.id FROM journal_lines jl WHERE jl.entry_id IN ($entryPlaceholders)
                           )"
                    );
                    $statement->execute(array_merge([$companyId], $entryIds));

                    $statement = $pdo->prepare(
                        "UPDATE bank_statement_lines
                         SET draft_journal_entry_id = NULL
                         WHERE company_id = ? AND draft_journal_entry_id IN ($entryPlaceholders)"
                    );
                    $statement->execute(array_merge([$companyId], $entryIds));
                }

                if ($purchaseCancellationIds) {
                    $cancelPlaceholders = implode(',', array_fill(0, count($purchaseCancellationIds), '?'));
                    $statement = $pdo->prepare("DELETE FROM purchase_invoice_cancellations WHERE company_id = ? AND id IN ($cancelPlaceholders)");
                    $statement->execute(array_merge([$companyId], $purchaseCancellationIds));
                }
                if ($salesCancellationIds) {
                    $cancelPlaceholders = implode(',', array_fill(0, count($salesCancellationIds), '?'));
                    $statement = $pdo->prepare("DELETE FROM sales_invoice_cancellations WHERE company_id = ? AND id IN ($cancelPlaceholders)");
                    $statement->execute(array_merge([$companyId], $salesCancellationIds));
                }

                $statement = $pdo->prepare(
                    'DELETE ip
                     FROM invoice_payments ip
                     JOIN sales_invoices si ON si.id = ip.invoice_id
                     WHERE si.company_id = ? AND (ip.payment_date >= ? OR si.invoice_date >= ?)'
                );
                $statement->execute([$companyId, $deleteFromDate, $deleteFromDate]);
                $deletedSalesPayments = $statement->rowCount();

                $statement = $pdo->prepare(
                    'DELETE pip
                     FROM purchase_invoice_payments pip
                     JOIN purchase_invoices pi ON pi.id = pip.invoice_id
                     WHERE pi.company_id = ? AND (pip.payment_date >= ? OR pi.invoice_date >= ?)'
                );
                $statement->execute([$companyId, $deleteFromDate, $deleteFromDate]);
                $deletedPurchasePayments = $statement->rowCount();

                if ($entryIds) {
                    $entryPlaceholders = implode(',', array_fill(0, count($entryIds), '?'));
                    $statement = $pdo->prepare(
                        "DELETE ip
                         FROM invoice_payments ip
                         JOIN sales_invoices si ON si.id = ip.invoice_id
                         WHERE si.company_id = ? AND ip.journal_entry_id IN ($entryPlaceholders)"
                    );
                    $statement->execute(array_merge([$companyId], $entryIds));
                    $deletedSalesPayments += $statement->rowCount();
                    $statement = $pdo->prepare(
                        "DELETE pip
                         FROM purchase_invoice_payments pip
                         JOIN purchase_invoices pi ON pi.id = pip.invoice_id
                         WHERE pi.company_id = ? AND pip.journal_entry_id IN ($entryPlaceholders)"
                    );
                    $statement->execute(array_merge([$companyId], $entryIds));
                    $deletedPurchasePayments += $statement->rowCount();

                    foreach (['sales_invoices', 'purchase_invoices'] as $invoiceTable) {
                        $statement = $pdo->prepare(
                            "UPDATE $invoiceTable SET journal_entry_id = NULL
                             WHERE company_id = ? AND journal_entry_id IN ($entryPlaceholders)"
                        );
                        $statement->execute(array_merge([$companyId], $entryIds));
                    }
                }

                if ($cancelledSalesInvoiceIds) {
                    $invoicePlaceholders = implode(',', array_fill(0, count($cancelledSalesInvoiceIds), '?'));
                    $statement = $pdo->prepare(
                        "DELETE sil FROM sales_invoice_lines sil
                         JOIN sales_invoices si ON si.id = sil.invoice_id
                         WHERE si.company_id = ? AND (si.invoice_date >= ? OR si.id IN ($invoicePlaceholders))"
                    );
                    $statement->execute(array_merge([$companyId, $deleteFromDate], $cancelledSalesInvoiceIds));
                } else {
                    $statement = $pdo->prepare(
                        'DELETE sil FROM sales_invoice_lines sil
                         JOIN sales_invoices si ON si.id = sil.invoice_id
                         WHERE si.company_id = ? AND si.invoice_date >= ?'
                    );
                    $statement->execute([$companyId, $deleteFromDate]);
                }
                if ($cancelledPurchaseInvoiceIds) {
                    $invoicePlaceholders = implode(',', array_fill(0, count($cancelledPurchaseInvoiceIds), '?'));
                    $statement = $pdo->prepare(
                        "DELETE pil FROM purchase_invoice_lines pil
                         JOIN purchase_invoices pi ON pi.id = pil.invoice_id
                         WHERE pi.company_id = ? AND (pi.invoice_date >= ? OR pi.id IN ($invoicePlaceholders))"
                    );
                    $statement->execute(array_merge([$companyId, $deleteFromDate], $cancelledPurchaseInvoiceIds));
                } else {
                    $statement = $pdo->prepare(
                        'DELETE pil FROM purchase_invoice_lines pil
                         JOIN purchase_invoices pi ON pi.id = pil.invoice_id
                         WHERE pi.company_id = ? AND pi.invoice_date >= ?'
                    );
                    $statement->execute([$companyId, $deleteFromDate]);
                }

                if ($cancelledSalesInvoiceIds) {
                    $invoicePlaceholders = implode(',', array_fill(0, count($cancelledSalesInvoiceIds), '?'));
                    $statement = $pdo->prepare("DELETE FROM sales_invoices WHERE company_id = ? AND (invoice_date >= ? OR id IN ($invoicePlaceholders))");
                    $statement->execute(array_merge([$companyId, $deleteFromDate], $cancelledSalesInvoiceIds));
                } else {
                    $statement = $pdo->prepare('DELETE FROM sales_invoices WHERE company_id = ? AND invoice_date >= ?');
                    $statement->execute([$companyId, $deleteFromDate]);
                }
                $deletedSalesInvoices = $statement->rowCount();
                if ($cancelledPurchaseInvoiceIds) {
                    $invoicePlaceholders = implode(',', array_fill(0, count($cancelledPurchaseInvoiceIds), '?'));
                    $statement = $pdo->prepare("DELETE FROM purchase_invoices WHERE company_id = ? AND (invoice_date >= ? OR id IN ($invoicePlaceholders))");
                    $statement->execute(array_merge([$companyId, $deleteFromDate], $cancelledPurchaseInvoiceIds));
                } else {
                    $statement = $pdo->prepare('DELETE FROM purchase_invoices WHERE company_id = ? AND invoice_date >= ?');
                    $statement->execute([$companyId, $deleteFromDate]);
                }
                $deletedPurchaseInvoices = $statement->rowCount();

                $deletedPayrollRuns = 0;
                if ($payrollRunIds) {
                    $runPlaceholders = implode(',', array_fill(0, count($payrollRunIds), '?'));
                    foreach ([
                        'payroll_run_item_payments',
                        'payroll_run_item_calculations',
                        'payroll_run_item_components',
                        'payroll_run_confirmations',
                        'payroll_run_items',
                    ] as $table) {
                        $statement = $pdo->prepare("DELETE FROM $table WHERE company_id = ? AND run_id IN ($runPlaceholders)");
                        $statement->execute(array_merge([$companyId], $payrollRunIds));
                    }
                    $statement = $pdo->prepare("DELETE FROM payroll_runs WHERE company_id = ? AND id IN ($runPlaceholders)");
                    $statement->execute(array_merge([$companyId], $payrollRunIds));
                    $deletedPayrollRuns = $statement->rowCount();
                }

                $statement = $pdo->prepare('DELETE FROM portfelli_algsaldo WHERE company_id = ? AND transaction_date >= ?');
                $statement->execute([$companyId, $deleteFromDate]);
                $deletedPortfolioOpeningRows = $statement->rowCount();

                // Eemalda põhivara perioodisündmused ja nende auditikirjed enne pearaamatukandeid.
                $deleteIdsForCompany = static function (PDO $pdo, string $table, string $idColumn, int $companyId, array $ids): int {
                    if (!$ids) return 0;
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $stmt = $pdo->prepare("DELETE FROM `$table` WHERE company_id = ? AND `$idColumn` IN ($placeholders)");
                    $stmt->execute(array_merge([$companyId], $ids));
                    return $stmt->rowCount();
                };
                $deleteLinkedFixedAssetRows = static function (PDO $pdo, string $table, string $assetColumn, string $entryColumn, int $companyId, array $assetIds, array $entryIds): int {
                    $conditions = [];
                    $parameters = [$companyId];
                    if ($assetIds) {
                        $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
                        $conditions[] = "`$assetColumn` IN ($placeholders)";
                        $parameters = array_merge($parameters, $assetIds);
                    }
                    if ($entryIds) {
                        $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
                        $conditions[] = "`$entryColumn` IN ($placeholders)";
                        $parameters = array_merge($parameters, $entryIds);
                    }
                    if (!$conditions) return 0;
                    $stmt = $pdo->prepare("DELETE FROM `$table` WHERE company_id = ? AND (" . implode(' OR ', $conditions) . ')');
                    $stmt->execute($parameters);
                    return $stmt->rowCount();
                };
                $deletedFixedAssetRuns = $fixedAssetTables['fixed_asset_depreciation_runs']
                    ? $deleteLinkedFixedAssetRows($pdo, 'fixed_asset_depreciation_runs', 'asset_id', 'journal_entry_id', $companyId, $fixedAssetIdsToDelete, $entryIds)
                    : 0;
                $deletedFixedAssetCorrections = 0;
                if ($fixedAssetTables['fixed_asset_depreciation_corrections']) {
                    $conditions = [];
                    $parameters = [$companyId];
                    if ($fixedAssetIdsToDelete) {
                        $placeholders = implode(',', array_fill(0, count($fixedAssetIdsToDelete), '?'));
                        $conditions[] = "asset_id IN ($placeholders)";
                        $parameters = array_merge($parameters, $fixedAssetIdsToDelete);
                    }
                    if ($entryIds) {
                        $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
                        $conditions[] = "original_journal_entry_id IN ($placeholders)";
                        $parameters = array_merge($parameters, $entryIds);
                        $conditions[] = "reversal_journal_entry_id IN ($placeholders)";
                        $parameters = array_merge($parameters, $entryIds);
                    }
                    if ($conditions) {
                        $stmt = $pdo->prepare('DELETE FROM fixed_asset_depreciation_corrections WHERE company_id = ? AND (' . implode(' OR ', $conditions) . ')');
                        $stmt->execute($parameters);
                        $deletedFixedAssetCorrections = $stmt->rowCount();
                    }
                }
                if ($fixedAssetTables['fixed_asset_estimate_changes']) {
                    $monthStart = substr($deleteFromDate, 0, 7) . '-01';
                    $conditions = ['effective_period >= ?'];
                    $parameters = [$companyId, $monthStart];
                    if ($fixedAssetIdsToDelete) {
                        $placeholders = implode(',', array_fill(0, count($fixedAssetIdsToDelete), '?'));
                        $conditions[] = "asset_id IN ($placeholders)";
                        $parameters = array_merge($parameters, $fixedAssetIdsToDelete);
                    }
                    $stmt = $pdo->prepare('DELETE FROM fixed_asset_estimate_changes WHERE company_id = ? AND (' . implode(' OR ', $conditions) . ')');
                    $stmt->execute($parameters);
                }
                if ($fixedAssetTables['fixed_asset_disposal_reversals']) {
                    if ($fixedAssetDisposalIdsToReopen && $fixedAssetTables['fixed_asset_disposals']) {
                        $reopenPlaceholders = implode(',', array_fill(0, count($fixedAssetDisposalIdsToReopen), '?'));
                        $stmt = $pdo->prepare("UPDATE fixed_asset_disposals SET status = 'posted' WHERE company_id = ? AND id IN ($reopenPlaceholders)");
                        $stmt->execute(array_merge([$companyId], $fixedAssetDisposalIdsToReopen));
                    }
                    $deletedFixedAssetDisposalReversals = $deleteIdsForCompany($pdo, 'fixed_asset_disposal_reversals', 'id', $companyId, $fixedAssetDisposalReversalIdsToDelete);
                } else {
                    $deletedFixedAssetDisposalReversals = 0;
                }
                $deletedFixedAssetDisposals = $fixedAssetTables['fixed_asset_disposals']
                    ? $deleteIdsForCompany($pdo, 'fixed_asset_disposals', 'id', $companyId, $fixedAssetDisposalIdsToDelete)
                    : 0;
                if ($fixedAssetDisposalAssetIdsToRefresh && $fixedAssetTables['fixed_assets'] && $fixedAssetTables['fixed_asset_disposals']) {
                    $refreshPlaceholders = implode(',', array_fill(0, count($fixedAssetDisposalAssetIdsToRefresh), '?'));
                    if ($fixedAssetTables['fixed_asset_disposal_reversals']) {
                        $stmt = $pdo->prepare(
                            "UPDATE fixed_assets fa
                             SET fa.status = CASE WHEN EXISTS (
                                 SELECT 1 FROM fixed_asset_disposals d
                                 WHERE d.company_id = fa.company_id AND d.asset_id = fa.id AND d.status = 'posted'
                             ) THEN 'disposed' ELSE 'active' END
                             WHERE fa.company_id = ? AND fa.status IN ('active', 'disposed') AND fa.id IN ($refreshPlaceholders)"
                        );
                    } else {
                        $stmt = $pdo->prepare(
                            "UPDATE fixed_assets SET status = 'active'
                             WHERE company_id = ? AND status = 'disposed' AND id IN ($refreshPlaceholders)"
                        );
                    }
                    $stmt->execute(array_merge([$companyId], $fixedAssetDisposalAssetIdsToRefresh));
                }
                $deletedFixedAssets = $fixedAssetTables['fixed_assets']
                    ? $deleteIdsForCompany($pdo, 'fixed_assets', 'id', $companyId, $fixedAssetIdsToDelete)
                    : 0;

                $deletedEntries = 0;
                if ($entryIds) {
                    $entryPlaceholders = implode(',', array_fill(0, count($entryIds), '?'));
                    $statement = $pdo->prepare(
                        "DELETE FROM journal_lines WHERE entry_id IN ($entryPlaceholders)"
                    );
                    $statement->execute($entryIds);
                    $statement = $pdo->prepare(
                        "DELETE FROM journal_entries WHERE company_id = ? AND id IN ($entryPlaceholders)"
                    );
                    $statement->execute(array_merge([$companyId], $entryIds));
                    $deletedEntries = $statement->rowCount();
                }

                $noticeText = 'Alates ' . $deleteFromDate . ' kustutati ' . $deletedEntries
                    . ' kannet, ' . $deletedSalesInvoices . ' müügiarvet, '
                    . $deletedPurchaseInvoices . ' ostuarvet ning '
                    . ($deletedSalesPayments + $deletedPurchasePayments) . ' arve makset, '
                    . $deletedPayrollRuns . ' palgaarvestust ja ' . $deletedPortfolioOpeningRows . ' portfelli algsaldo rida. '
                    . $deletedFixedAssets . ' põhivara kirjet, ' . $deletedFixedAssetRuns . ' amortisatsioonikäiku ja '
                    . $deletedFixedAssetDisposals . ' põhivara kõrvaldamist koos paranduste ajalooga kustutati. '
                    . 'Sama kuupäeva ja hilisemad laoliikumised kustutati; põhivara kontoseaded ning varasemad andmed ja töötajate ning lepingute põhiandmed säilisid. '
                    . 'Kustutati '.$deletedSecurityTrades.' väärtpaberitehingut koos seotud kannetega. '
                    . 'Pangaväljavõtte read jäid alles sidumata kujul.';
            } elseif ($action === 'clear_bank_csv') {
                $statement = $pdo->prepare('DELETE FROM bank_statement_lines WHERE company_id = ?');
                $statement->execute([$companyId]);

                $noticeText = 'Ettevõtte imporditud panga CSV-tehingute read kustutati. '
                    . 'Kandeid ega arveid ei kustutatud.';
            } elseif ($action === 'clear_bank_csv_batch') {
                $batchId = strtolower(trim((string) ($_POST['import_batch_id'] ?? '')));
                if (!preg_match('/^[a-f0-9]{32}$/', $batchId)) {
                    throw new RuntimeException('Valitud CSV impordi tunnus ei sobi. Andmeid ei kustutatud.');
                }

                $batchRowsStmt = $pdo->prepare(
                    'SELECT id, matched_journal_line_id, draft_journal_entry_id, source_file_name
                     FROM bank_statement_lines
                     WHERE company_id = ? AND import_batch_id = ?
                     FOR UPDATE'
                );
                $batchRowsStmt->execute([$companyId, $batchId]);
                $batchRows = $batchRowsStmt->fetchAll(PDO::FETCH_ASSOC);
                if (!$batchRows) {
                    throw new RuntimeException('Selle CSV impordi ridu ei leitud. Andmeid ei kustutatud.');
                }

                $linkedRows = 0;
                foreach ($batchRows as $batchRow) {
                    if ($batchRow['matched_journal_line_id'] !== null || $batchRow['draft_journal_entry_id'] !== null) {
                        $linkedRows++;
                    }
                }

                $statement = $pdo->prepare(
                    'DELETE FROM bank_statement_lines WHERE company_id = ? AND import_batch_id = ?'
                );
                $statement->execute([$companyId, $batchId]);
                $fileName = trim((string) ($batchRows[0]['source_file_name'] ?? ''));
                $noticeText = 'CSV import' . ($fileName !== '' ? ' „' . $fileName . '”' : '')
                    . ' kustutati (' . count($batchRows) . ' rida). Pearaamatu kandeid ega arveid ei kustutatud.'
                    . ($linkedRows > 0
                        ? ' ' . $linkedRows . ' seotud CSV rea kanded või mustandid jäid alles; enne sama faili uuesti importimist kontrolli neid, et vältida topeltkandeid.'
                        : '');
            } elseif ($action === 'clear_bank_csv_legacy') {
                $legacyLockStmt = $pdo->prepare(
                    'SELECT id, matched_journal_line_id, draft_journal_entry_id
                     FROM bank_statement_lines
                     WHERE company_id = ? AND import_batch_id IS NULL
                     FOR UPDATE'
                );
                $legacyLockStmt->execute([$companyId]);
                $legacyRows = $legacyLockStmt->fetchAll(PDO::FETCH_ASSOC);
                if (!$legacyRows) {
                    throw new RuntimeException('Eristamata vanu CSV ridu ei leitud. Andmeid ei kustutatud.');
                }
                foreach ($legacyRows as $legacyRow) {
                    if ($legacyRow['matched_journal_line_id'] !== null || $legacyRow['draft_journal_entry_id'] !== null) {
                        throw new RuntimeException(
                            'Vanade eristamata CSV-ridade hulgas on pearaamatu kande või mustandiga seotud ridu. '
                            . 'Neid ridu ei kustutatud.'
                        );
                    }
                }
                $statement = $pdo->prepare(
                    'DELETE FROM bank_statement_lines WHERE company_id = ? AND import_batch_id IS NULL'
                );
                $statement->execute([$companyId]);
                $noticeText = 'Eristamata vanad CSV read kustutati (' . count($legacyRows)
                    . ' rida). Pearaamatu kandeid ega arveid ei kustutatud.';
            } else {
                $statement = $pdo->prepare('DELETE FROM portfelli_algsaldo WHERE company_id = ?');
                $statement->execute([$companyId]);

                $noticeText = 'Ettevõtte portfelli algsaldo CSV-st imporditud read kustutati. '
                    . 'Pangatehinguid, pearaamatu kandeid ega arveid ei kustutatud.';
            }

            $pdo->commit();
            if ($action === 'clear_entries' || $action === 'clear_entries_from_date') {
                unset($_SESSION['bank_counter_account_ids'],$_SESSION['bank_invoice_allocations']);
            }

            if ($action === 'clear_entries') {
                try {
                    $resetTables = array_merge(supplementaryResetTables(),[
                        'accounting_period_events',
                        'accounting_year_events',
                        'bank_statement_lines',
                        'billberry_inbox',
                        'billberry_sent_invoices',
                        'email_send_log',
                        'fixed_asset_depreciation_runs',
                        'fixed_asset_depreciation_corrections',
                        'fixed_asset_estimate_changes',
                        'fixed_asset_disposal_reversals',
                        'fixed_asset_disposals',
                        'fixed_assets',
                        'inventory_movement_lines',
                        'inventory_movements',
                        'inventory_products',
                        'inventory_warehouses',
                        'invoice_payments',
                        'journal_entries',
                        'journal_lines',
                        'payroll_employee_tax_settings',
                        'payroll_employees',
                        'payroll_employment_closures',
                        'payroll_employment_events',
                        'payroll_employment_suspensions',
                        'payroll_employment_terms',
                        'payroll_employments',
                        'payroll_run_confirmations',
                        'payroll_run_item_calculations',
                        'payroll_run_item_components',
                        'payroll_run_item_payments',
                        'payroll_run_items',
                        'payroll_runs',
                        'portfelli_algsaldo',
                        'purchase_invoice_lines',
                        'purchase_invoice_payments',
                        'purchase_invoice_cancellations',
                        'purchase_invoices',
                        'sales_invoice_lines',
                        'sales_invoice_cancellations',
                        'sales_invoices',
                    ]);
                    $resetCount = 0;
                    $tableExists = $pdo->prepare(
                        "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND extra LIKE '%auto_increment%'"
                    );
                    foreach ($resetTables as $table) {
                        $tableExists->execute([$table]);
                        if ((int) $tableExists->fetchColumn() === 0) {
                            continue;
                        }
                        $remainingRows = (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
                        if ($remainingRows === 0) {
                            $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = 1");
                            $resetCount++;
                        }
                    }
                    $noticeText .= ' Tühjade tabelite ID-loendurid lähtestati (' . $resetCount
                        . ' tabelit); uued kirjed algavad seal ID-ga 1. Säilitatud seadistus- ja põhiandmete ID-sid ei muudetud. '
                        . 'Pangakannete dokumendinumbrid algavad selle ettevõtte jaoks uuesti Pank-1-st.';
                } catch (Throwable $counterException) {
                    error_log('Kande ID loenduri lähtestamine ebaõnnestus: ' . $counterException->getMessage());
                    $noticeText .= ' Kanded kustutati, kuid kandenumbriloendurit ei saanud lähtestada.';
                }
            } elseif ($action === 'clear_entries_from_date') {
                try {
                    $remainingEntries = (int) $pdo->query(
                        'SELECT COUNT(*) FROM journal_entries'
                    )->fetchColumn();
                    if ($remainingEntries === 0) {
                        $pdo->exec('ALTER TABLE journal_entries AUTO_INCREMENT = 1');
                        $noticeText .= ' Kuna kandeid enam pole, ID-loendur lähtestati.';
                    } else {
                        $noticeText .= ' Olemasolevate varasemate kannete tõttu jätkub ID-loendur järgmise vaba numbriga.';
                    }
                    $resetAssetTableCount = 0;
                    $fixedAssetResetCheck = $pdo->prepare(
                        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
                    );
                    foreach ([
                        'fixed_asset_depreciation_runs',
                        'fixed_asset_estimate_changes',
                        'fixed_asset_disposals',
                        'fixed_asset_disposal_reversals',
                        'fixed_asset_depreciation_corrections',
                        'fixed_assets',
                    ] as $assetTable) {
                        $fixedAssetResetCheck->execute([$assetTable]);
                        if ((int) $fixedAssetResetCheck->fetchColumn() === 0) continue;
                        $remainingRows = (int) $pdo->query("SELECT COUNT(*) FROM `$assetTable`")->fetchColumn();
                        if ($remainingRows === 0) {
                            $pdo->exec("ALTER TABLE `$assetTable` AUTO_INCREMENT = 1");
                            $resetAssetTableCount++;
                        }
                    }
                    if ($resetAssetTableCount > 0) {
                        $noticeText .= ' Tühjade põhivara andmetabelite ID-loendurid lähtestati (' . $resetAssetTableCount . ' tabelit).';
                    }
                } catch (Throwable $counterException) {
                    error_log('Kuupäevapõhise kustutuse ID loenduri kontroll ebaõnnestus: ' . $counterException->getMessage());
                    $noticeText .= ' Kanded kustutati, kuid ID-loendurit ei saanud kontrollida.';
                }
            }

            $noticeKey = $action === 'clear_portfolio_opening'
                ? 'portfolio_opening_notice'
                : 'settings_notice';
            $_SESSION[$noticeKey] = [
                'type' => 'success',
                'text' => $noticeText . ' Enne kustutamist loodi SQL-varukoopia: '
                    . $backupPath,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Seadete andmete kustutamine ebaõnnestus: ' . $exception->getMessage());
            $noticeKey = $action === 'clear_portfolio_opening'
                ? 'portfolio_opening_notice'
                : 'settings_notice';
            $_SESSION[$noticeKey] = [
                'type' => 'error',
                'text' => $exception instanceof RuntimeException
                    ? $exception->getMessage()
                    : 'Kustutamine ebaõnnestus. Tehing pöörati tagasi; vaata, kas varukoopia jäi alles.',
            ];
        }
    }

    header('Location: ' . ($action === 'clear_portfolio_opening' ? 'portfelli_algsaldo.php' : 'settings.php'));
    exit;
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array($_POST['action'] ?? '', ['change_login_email', 'change_login_password'], true)
) {
    $accountErrors = [];
    $action = (string) $_POST['action'];
    $userId = (int) $_SESSION['user_id'];

    if (
        empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
    ) {
        $accountErrors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    }

    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $userStmt = $pdo->prepare(
        'SELECT email, password_hash
         FROM users
         WHERE id = ? AND company_id = ?'
    );
    $userStmt->execute([$userId, $companyId]);
    $currentUser = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentUser || !password_verify($currentPassword, (string) $currentUser['password_hash'])) {
        $accountErrors[] = 'Praegune parool ei ole õige.';
    }

    if ($action === 'change_login_email') {
        $newEmail = trim((string) ($_POST['new_email'] ?? ''));
        if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $accountErrors[] = 'Sisesta korrektne uus e-posti aadress.';
        } elseif (strlen($newEmail) > 254) {
            $accountErrors[] = 'E-posti aadress võib olla kuni 254 märki.';
        }

        if (!$accountErrors) {
            $duplicateStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $duplicateStmt->execute([$newEmail, $userId]);
            if ($duplicateStmt->fetchColumn()) {
                $accountErrors[] = 'See e-posti aadress on juba teise kasutaja kontoga seotud.';
            }
        }

        if (!$accountErrors) {
            $updateUserStmt = $pdo->prepare('UPDATE users SET email = ? WHERE id = ? AND company_id = ?');
            $updateUserStmt->execute([$newEmail, $userId, $companyId]);
            $_SESSION['email'] = $newEmail;
            $_SESSION['settings_account_notice'] = [
                'type' => 'success',
                'text' => 'Sisselogimise e-posti aadress muudeti.',
            ];
            header('Location: settings.php#account-settings');
            exit;
        }
    } else {
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        if (mb_strlen($newPassword, 'UTF-8') < 6) {
            $accountErrors[] = 'Uus parool peab olema vähemalt 6 märki pikk.';
        } elseif (strlen($newPassword) > 72) {
            $accountErrors[] = 'Uus parool võib olla kuni 72 baidi pikk.';
        }
        if (!preg_match('/\p{Lu}/u', $newPassword)
            || !preg_match('/\p{Ll}/u', $newPassword)
            || !preg_match('/[0-9]/', $newPassword)) {
            $accountErrors[] = 'Uus parool peab sisaldama vähemalt ühte suurt tähte, väikest tähte ja numbrit.';
        }
        if (!hash_equals($newPassword, $confirmPassword)) {
            $accountErrors[] = 'Uue parooli kordus ei klapi.';
        }

        if (!$accountErrors) {
            $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $updateUserStmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND company_id = ?');
            $updateUserStmt->execute([$passwordHash, $userId, $companyId]);
            $_SESSION['settings_account_notice'] = [
                'type' => 'success',
                'text' => 'Parool muudeti. Järgmisel sisselogimisel kasuta uut parooli.',
            ];
            header('Location: settings.php#account-settings');
            exit;
        }
    }

    $accountNotice = ['type' => 'error', 'text' => implode(' ', $accountErrors)];
}

ensureCompanyVatStatusSchema($pdo);
ensureCompanyCashVatSchema($pdo);
$companyStmt = $pdo->prepare(
    'SELECT
        name,
        registry_code,
        fiscal_year_start,
        address,
        postal_code,
        city,
        country,
        phone,
        invoice_email,
        vat_number,
        non_vat_registered,
        cash_vat_from,
        cash_vat_until,
        cash_vat_history,
        bank_name,
        bank_bic,
        bank_account
     FROM companies
     WHERE id = ?'
);
$companyStmt->execute([$companyId]);
$company = $companyStmt->fetch(PDO::FETCH_ASSOC);

if (!$company) {
    exit('Ettevõtte andmeid ei leitud.');
}

$form = $company;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
    ) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    }

    $fields = [
        'name',
        'registry_code',
        'address',
        'postal_code',
        'city',
        'country',
        'phone',
        'invoice_email',
        'vat_number',
        'cash_vat_from',
        'cash_vat_until',
        'bank_name',
        'bank_bic',
        'bank_account',
    ];

    foreach ($fields as $field) {
        $form[$field] = trim((string) ($_POST[$field] ?? ''));
    }
    $form['non_vat_registered'] = (int) (($_POST['non_vat_registered'] ?? '') === '1');
    foreach (['cash_vat_from','cash_vat_until'] as $field) {
        if ($form[$field] !== '' && !preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D',$form[$field])) {
            $errors[] = 'Vali kassapõhise arvestuse korrektne kuu.';
        }
    }
    if ($form['cash_vat_until'] !== '' && ($form['cash_vat_from'] === '' || $form['cash_vat_until'] < $form['cash_vat_from'])) {
        $errors[] = 'Kassapõhise arvestuse viimane kuu peab olema alguskuu või sellest hilisem.';
    }

    if ($form['name'] === '') {
        $errors[] = 'Ettevõtte nimi on kohustuslik.';
    }

    if (mb_strlen($form['name']) > 200) {
        $errors[] = 'Ettevõtte nimi võib olla kuni 200 märki.';
    }

    if (mb_strlen($form['registry_code']) > 20) {
        $errors[] = 'Registrikood võib olla kuni 20 märki.';
    }

    if (mb_strlen($form['address']) > 255) {
        $errors[] = 'Aadress võib olla kuni 255 märki.';
    }

    if (mb_strlen($form['postal_code']) > 20) {
        $errors[] = 'Postiindeks võib olla kuni 20 märki.';
    }

    if (mb_strlen($form['city']) > 100) {
        $errors[] = 'Linn või asula võib olla kuni 100 märki.';
    }

    if ($form['country'] === '') {
        $errors[] = 'Riik on kohustuslik.';
    } elseif (mb_strlen($form['country']) > 100) {
        $errors[] = 'Riik võib olla kuni 100 märki.';
    }

    if (mb_strlen($form['phone']) > 50) {
        $errors[] = 'Telefon võib olla kuni 50 märki.';
    }

    if (
        $form['invoice_email'] !== ''
        && !filter_var($form['invoice_email'], FILTER_VALIDATE_EMAIL)
    ) {
        $errors[] = 'Sisesta korrektne e-posti aadress.';
    } elseif (mb_strlen($form['invoice_email']) > 190) {
        $errors[] = 'E-posti aadress võib olla kuni 190 märki.';
    }

    if (mb_strlen($form['vat_number']) > 30) {
        $errors[] = 'KMKR number võib olla kuni 30 märki.';
    }

    if (mb_strlen($form['bank_name']) > 120) {
        $errors[] = 'Panga nimi võib olla kuni 120 märki.';
    }

    if (mb_strlen($form['bank_account']) > 34) {
        $errors[] = 'Pangakonto number võib olla kuni 34 märki.';
    }

    $allowedBankBics = ['' => true, 'EKRDEE22' => true, 'LHVBEE22' => true, 'RIKOEE22' => true, 'EEUHEE2X' => true, 'HABAEE2X' => true];
    if (!isset($allowedBankBics[$form['bank_bic']])) {
        $errors[] = 'Vali Eesti panga e-arvete jaoks toetatud BIC.';
    }

    if (!$errors) {
        try { $form['cash_vat_history']=companyCashVatChangeHistory($pdo,$companyId,$form['cash_vat_from'],$form['cash_vat_until']); }
        catch(InvalidArgumentException $error) { $errors[]=$error->getMessage(); }
    }
    if (!$errors) {
        $updateStmt = $pdo->prepare(
            'UPDATE companies
             SET
                name = ?,
                registry_code = ?,
                address = ?,
                postal_code = ?,
                city = ?,
                country = ?,
                phone = ?,
                invoice_email = ?,
                vat_number = ?,
                non_vat_registered = ?,
                cash_vat_from = ?,
                cash_vat_until = ?,
                cash_vat_history = ?,
                bank_name = ?,
                bank_bic = ?,
                bank_account = ?
             WHERE id = ?'
        );

        $updateStmt->execute([
            $form['name'],
            $form['registry_code'] !== '' ? $form['registry_code'] : null,
            $form['address'] !== '' ? $form['address'] : null,
            $form['postal_code'] !== '' ? $form['postal_code'] : null,
            $form['city'] !== '' ? $form['city'] : null,
            $form['country'],
            $form['phone'] !== '' ? $form['phone'] : null,
            $form['invoice_email'] !== '' ? $form['invoice_email'] : null,
            $form['vat_number'] !== '' ? $form['vat_number'] : null,
            $form['non_vat_registered'],
            $form['cash_vat_from'] ?: null,
            $form['cash_vat_until'] ?: null,
            $form['cash_vat_history'],
            $form['bank_name'] !== '' ? $form['bank_name'] : null,
            $form['bank_bic'] !== '' ? $form['bank_bic'] : null,
            $form['bank_account'] !== '' ? $form['bank_account'] : null,
            $companyId,
        ]);

        $saved = true;
        $companyStmt->execute([$companyId]);
        $company = $companyStmt->fetch(PDO::FETCH_ASSOC);
        $form = $company;
    }
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Seaded</title>
    <style>
        body {
            max-width: 950px;
            margin: 36px auto;
            padding: 0 22px;
            background: #f4f7fb;
            color: #263746;
            font-family: Arial, sans-serif;
        }

        h1, h2 {
            color: #17324d;
        }

        .card {
            margin: 20px 0;
            padding: 22px;
            border: 1px solid #dce3ec;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 4px 14px rgba(25, 45, 70, 0.05);
        }

        .settings-section {
            margin: 12px 0;
            border: 1px solid #dce3ec;
            border-radius: 10px;
            background: #f7f9fc;
            overflow: hidden;
        }

        .settings-section > summary {
            padding: 16px 18px;
            color: #17324d;
            font-size: 18px;
            font-weight: 700;
            cursor: pointer;
        }

        .settings-section > summary:hover {
            background: #edf3f9;
        }

        .settings-section[open] > summary {
            border-bottom: 1px solid #dce3ec;
            background: #edf3f9;
        }

        .settings-section > .card {
            margin: 0;
            border: 0;
            border-radius: 0;
            box-shadow: none;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 14px;
        }

        label {
            display: grid;
            gap: 7px;
            color: #33475b;
            font-weight: 600;
        }

        input {
            box-sizing: border-box;
            width: 100%;
            min-height: 42px;
            padding: 10px 12px;
            border: 1px solid #b8c4d1;
            border-radius: 7px;
            background: #fff;
            color: #243447;
            font: inherit;
        }

        .company-details {
            padding: 16px 18px;
        }

        .company-details .form-grid {
            gap: 10px 12px;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 230px), 1fr));
        }

        .company-details .form-grid label {
            gap: 4px;
        }

        .company-details .form-grid input,
        .company-details select {
            min-height: 36px;
            padding: 6px 10px;
        }

        .company-details select {
            box-sizing: border-box;
            width: 100%;
            border: 1px solid #b8c4d1;
            border-radius: 7px;
            background: #fff;
            color: #243447;
            font: inherit;
            cursor: pointer;
        }

        .company-details select:focus-visible {
            outline: 2px solid #1769aa;
            outline-offset: 2px;
        }

        .company-details-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px 18px;
            margin-top: 12px;
        }

        .company-details-actions .button {
            margin-top: 0;
        }

        .company-details-actions .checkbox-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .checkbox-label input[type="checkbox"] {
            width: 16px;
            height: 16px;
            min-height: 0;
            padding: 0;
            margin: 0;
            flex: none;
            accent-color: #1769aa;
        }

        .button {
            display: inline-block;
            margin-top: 18px;
            padding: 11px 17px;
            border: 0;
            border-radius: 7px;
            background: #1769aa;
            color: #fff;
            font: inherit;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .button:hover {
            background: #0f4f83;
        }

        .success, .error {
            padding: 12px 15px;
            border-radius: 8px;
        }

        .success {
            border: 1px solid #b8dfc7;
            background: #eaf7ef;
            color: #176b3a;
        }

        .error {
            border: 1px solid #e5a3a3;
            background: #fff0f0;
            color: #a32121;
        }

        .muted {
            color: #667788;
        }

        .maintenance-card {
            margin-top: 42px;
            border-color: #e5d8d8;
        }

        .maintenance-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .import-batch-list {
            max-height: 420px;
            overflow-y: auto;
            align-content: start;
            scrollbar-gutter: stable;
            display: grid;
            gap: 10px;
            margin: 16px 0 22px;
        }

        .import-batch {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 13px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 9px;
            background: #fbfcfe;
        }

        .import-batch p {
            margin: 4px 0 0;
        }

        .import-batch form {
            flex: 0 0 auto;
        }

        @media (max-width: 650px) {
            .import-batch {
                align-items: flex-start;
                flex-direction: column;
            }
        }

        .button-small {
            margin-top: 8px;
            padding: 7px 11px;
            font-size: 0.88rem;
        }

        .button-danger {
            background: #a83232;
        }

        .button-danger:hover {
            background: #812424;
        }
    </style>
</head>
<body>
    <h1>Seaded</h1>
    <p class="muted">Ettevõtte andmed, mida saab kasutada müügiarvetel.</p>
    <details class="settings-section">
        <summary>E-posti saatmise seaded</summary>
        <div class="card"><p>Gmaili ühendus ja kirjade saatmise seaded.</p><a class="button" href="email_settings.php">Ava e-posti seaded</a></div>
    </details>
    <details class="settings-section">
        <summary>E-arvete seaded</summary>
        <div class="card"><p>Billberry API ühenduse seaded e-arvete saatmiseks ja vastuvõtmiseks.</p><a class="button" href="billberry_settings.php">Ava Billberry seaded</a></div>
    </details>

    <?php if ($saved): ?>
        <p class="success">Ettevõtte andmed salvestati.</p>
    <?php endif; ?>

    <?php foreach ($errors as $error): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endforeach; ?>

    <?php if ($accountNotice): ?>
        <p class="<?= $accountNotice['type'] === 'success' ? 'success' : 'error' ?>">
            <?= h($accountNotice['text']) ?>
        </p>
    <?php endif; ?>

    <?php if ($maintenanceNotice): ?>
        <p class="<?= $maintenanceNotice['type'] === 'success' ? 'success' : 'error' ?>">
            <?= h($maintenanceNotice['text']) ?>
        </p>
    <?php endif; ?>

    <details class="settings-section" <?= ($saved || $errors) ? 'open' : '' ?>>
    <summary>Ettevõtte andmed</summary>
    <form method="post" class="card company-details">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= h($_SESSION['csrf_token']) ?>"
        >

        <div class="form-grid">
            <label>
                Ettevõtte nimi
                <input
                    type="text"
                    name="name"
                    maxlength="200"
                    value="<?= h((string) ($form['name'] ?? '')) ?>"
                    required
                >
            </label>

            <label>
                Registrikood
                <input
                    type="text"
                    name="registry_code"
                    maxlength="20"
                    value="<?= h((string) ($form['registry_code'] ?? '')) ?>"
                >
            </label>

            <label>
                Aadress
                <input
                    type="text"
                    name="address"
                    maxlength="255"
                    value="<?= h((string) ($form['address'] ?? '')) ?>"
                >
            </label>

            <label>
                Postiindeks
                <input
                    type="text"
                    name="postal_code"
                    maxlength="20"
                    value="<?= h((string) ($form['postal_code'] ?? '')) ?>"
                >
            </label>

            <label>
                Linn või asula
                <input
                    type="text"
                    name="city"
                    maxlength="100"
                    value="<?= h((string) ($form['city'] ?? '')) ?>"
                >
            </label>

            <label>
                Riik
                <input
                    type="text"
                    name="country"
                    maxlength="100"
                    value="<?= h((string) ($form['country'] ?? 'Eesti')) ?>"
                    required
                >
            </label>

            <label>
                Telefon
                <input
                    type="text"
                    name="phone"
                    maxlength="50"
                    value="<?= h((string) ($form['phone'] ?? '')) ?>"
                >
            </label>

            <label>
                Arvetel kuvatav e-post
                <input
                    type="email"
                    name="invoice_email"
                    maxlength="190"
                    value="<?= h((string) ($form['invoice_email'] ?? '')) ?>"
                >
            </label>

            <label>
                KMKR number
                <input
                    type="text"
                    name="vat_number"
                    maxlength="30"
                    value="<?= h((string) ($form['vat_number'] ?? '')) ?>"
                >
            </label>

            <label>
                Panga nimi
                <input
                    type="text"
                    name="bank_name"
                    maxlength="120"
                    value="<?= h((string) ($form['bank_name'] ?? '')) ?>"
                >
            </label>

            <label>
                Pangakonto / IBAN
                <input
                    type="text"
                    name="bank_account"
                    maxlength="34"
                    value="<?= h((string) ($form['bank_account'] ?? '')) ?>"
                >
            </label>

            <label>
                Panga BIC (e-arvete jaoks)
                <select name="bank_bic">
                    <option value="">Vali pank / BIC</option>
                    <?php foreach (['EKRDEE22' => 'Coop Pank', 'LHVBEE22' => 'LHV', 'RIKOEE22' => 'Luminor', 'EEUHEE2X' => 'SEB', 'HABAEE2X' => 'Swedbank'] as $bic => $bankLabel): ?>
                        <option value="<?= h($bic) ?>" <?= (string) ($form['bank_bic'] ?? '') === $bic ? 'selected' : '' ?>><?= h($bankLabel) ?> — <?= h($bic) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div>

        <details>
            <summary>Kassapõhise käibemaksuarvestuse periood</summary>
            <div class="form-grid">
                <label>Esimene kuu <input type="month" name="cash_vat_from" value="<?= h((string) ($form['cash_vat_from'] ?? '')) ?>"></label>
                <label>Viimane kuu <input type="month" name="cash_vat_until" value="<?= h((string) ($form['cash_vat_until'] ?? '')) ?>"></label>
            </div>
            <p class="muted">Kui erikord jätkub, jäta viimane kuu tühjaks.</p>
            <p class="muted">Üleminekul säilitavad vanad arved oma arvestuskorra: varasem kassapõhine arve jääb maksepõhiseks ka pärast viimast kuud. Uue erikorra algus pärast varasema perioodi lõppu säilitab varasema perioodi ajaloos. MTA teavitus esitatakse eraldi.</p>
            <?php $cashHistory=json_decode($form['cash_vat_history']??'[]',true)??[]; if($cashHistory): ?><p>Varasemad kassapõhised perioodid:</p><ul><?php foreach($cashHistory as $interval): ?><li><?= h($interval['cash_vat_from'].' — '.$interval['cash_vat_until']) ?></li><?php endforeach; ?></ul><?php endif; ?>
        </details>
        <div class="company-details-actions">
            <label class="checkbox-label">
                <input type="checkbox" name="non_vat_registered" value="1"
                    <?= !empty($form['non_vat_registered']) ? 'checked' : '' ?>>
                Mittekäibemaksukohustuslane
            </label>
            <button class="button" type="submit">Salvesta ettevõtte andmed</button>
        </div>
    </form>
    </details>

    <details class="settings-section" id="account-settings" <?= $accountNotice ? 'open' : '' ?>>
    <summary>Sisselogimise andmed</summary>
    <div class="card">
        <p class="muted">Praegune sisselogimise e-post: <strong><?= h((string) ($_SESSION['email'] ?? '')) ?></strong></p>

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="change_login_email">
            <div class="form-grid">
                <label>
                    Uus e-posti aadress
                    <input type="email" name="new_email" maxlength="254" autocomplete="email" required>
                </label>
                <label>
                    Kinnita praeguse parooliga
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </label>
            </div>
            <button class="button" type="submit">Muuda e-posti aadress</button>
        </form>

        <hr style="margin:24px 0;border:0;border-top:1px solid #e5eaf0">

        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="change_login_password">
            <div class="form-grid">
                <label>
                    Praegune parool
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </label>
                <label>
                    Uus parool (vähemalt 6 märki, suur ja väike täht ning number)
                    <input type="password" name="new_password" minlength="6" maxlength="72" autocomplete="new-password" required>
                </label>
                <label>
                    Korda uut parooli
                    <input type="password" name="confirm_password" minlength="6" maxlength="72" autocomplete="new-password" required>
                </label>
            </div>
            <button class="button" type="submit">Muuda parool</button>
        </form>
    </div>
    </details>

    <details class="settings-section">
    <summary>Kontoplaan</summary>
    <div class="card">
        <p>Vaata ja halda ettevõtte raamatupidamiskontosid.</p>
        <a class="button" href="accounts.php">Ava kontoplaan</a>
    </div>
    </details>
    <details class="settings-section">
    <summary>Aastavahetus</summary>
    <div class="card">
        <p>Kontrolli aasta aruandeid ja kuude sulgemist ning salvesta majandusaasta lõpetamise toiming.</p>
        <a class="button" href="year_end.php">Ava aastavahetus</a>
    </div>
    </details>

    <details class="settings-section">
    <summary>Perioodi sulgemine</summary>
    <div class="card">
        <p>Sulge kontrollitud kuud, et sinna ei saaks kogemata uusi kandeid lisada.</p>
        <a class="button" href="closed_periods.php">Ava perioodi sulgemine</a>
    </div>
    </details>
    
    <details class="settings-section" <?= $maintenanceNotice ? 'open' : '' ?>>
    <summary>Andmete tühjendamine</summary>
    <section class="card maintenance-card">
        <p class="muted">
            Need toimingud puudutavad ainult sinu aktiivset ettevõtet.
            Enne kustutamist tehakse kogu andmebaasist SQL-varukoopia.
            Täielik lähtestamine eemaldab arvestuse, töötajate andmed, lao liikumised, prooviks lisatud tootekaardid ja laod ning põhivara kaardid koos amortisatsiooni ja toimingute ajalooga. Kustuvad ka käibemaksu kinnitused ja jaotused, sõiduautode kuunimekirjad ning kreeditarvete ja algsete arvete viited.
            Alles jäävad põhivara ja palga kontoseaded, kontoplaan, ettevõte, kasutajad, majandusaasta aruande seosed/seaded,
            tarnija vaikeseaded ning nendega seotud toote- ja laokaardid. Kontaktid ja eraldi salvestatud lao pearaamatukontode vaikeseosed säilivad.
            Tühjendatud tabelite ID-loendurid lähtestatakse, kui tabelis pole ühegi ettevõtte ridu alles.
        </p>

        <h3>Kustuta kanded alates kuupäevast</h3>
        <p class="muted">
            Valitud kuupäev kaasa arvatud kustutatakse pearaamatu kanded, müügi- ja ostuarved, arvetega seotud maksed, sellesse perioodi jäävad palgaarvestused, portfelli algsaldo read ning sama kuupäeva ja hilisemad laoliikumised koos nendega seotud kannetega.
            Kustutatakse ka selle ajavahemiku väärtpaberitehingud, käibemaksu tööandmed ning e-arvete ja e-kirjade logid. Vajadusel eemaldatakse samasse tehingusse kuuluv varasem dokument või kanne. Väärtpaberimüügi eemaldamisel taastub alles jääva ostupartii FIFO jääk.
            Samuti kustutatakse põhivara kirjed, mis lisati, soetati või võeti kasutusele valitud kuupäeval või hiljem, ning nendega seotud amortisatsiooni- ja kõrvaldamiskirjed. Pangaväljavõtte read säilivad, kuid kustutatud kannetega seosed eemaldatakse. Põhivara kontoseaded, töötajate, lepingute, kontaktide, lao toodete ja ladude põhiandmed ning kontoplaani, palga ja aruannete seaded säilivad.
            Suletud perioodide lukud jäävad alles ning need tuleb vajadusel perioodi sulgemise lehel avada.
        </p>
        <form method="post" class="form-grid" onsubmit="const d=this.elements.delete_from.value; return d !== '' && confirm('Hoiatus 1/2: kustutad kanded, arved, maksed, palgaarvestused, portfelli algsaldo read, väärtpaberitehingud, käibemaksu tööandmed, lao liikumised ning valitud kuupäeval või hiljem lisatud/soetatud põhivarad koos seotud kannetega alates ' + d + ' (kaasa arvatud). Põhivara kontoseaded ja muud seaded säilivad. Pangaväljavõtte read jäävad alles sidumata. Kas jätkad?') && confirm('Hoiatus 2/2: enne kustutamist tehakse SQL-varukoopia. Kas kinnitad kustutamise alates ' + d + '?');">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="clear_entries_from_date">
            <label>
                Kustuta alates kuupäevast
                <input type="date" name="delete_from" required>
            </label>
            <div>
                <button class="button button-danger" type="submit">Kustuta alates valitud kuupäevast</button>
            </div>
        </form>

        <hr style="margin:24px 0;border:0;border-top:1px solid #e5eaf0">

        <h3>Panga CSV impordid</h3>
        <p class="muted">
            Uued impordid kuvatakse siin faili nime järgi. Kustutamine eemaldab valitud CSV pangaread, kuid jätab pearaamatu kanded ja arved alles. Seotud ridadega faili saab samuti kustutada; enne sama faili uuesti importimist kontrolli alles jäänud kandeid, et vältida topeltkandeid.
        </p>

        <?php if ($bankCsvBatches): ?>
            <div class="import-batch-list" tabindex="0" role="region" aria-label="Panga CSV impordid, keritav loend">
                <?php foreach ($bankCsvBatches as $batch): ?>
                    <?php $batchLinked = (int) $batch['matched_rows'] > 0 || (int) $batch['draft_rows'] > 0; ?>
                    <div class="import-batch">
                        <div>
                            <strong><?= h((string) ($batch['source_file_name'] ?: 'Nimeta CSV-fail')) ?></strong>
                            <p class="muted">
                                <?= h($batch['bank_code'] . ' — ' . $batch['bank_name']) ?> ·
                                <?= h((string) $batch['imported_at']) ?> ·
                                <?= (int) $batch['row_count'] ?> rida
                                <?php if ($batchLinked): ?>
                                    · seotud pearaamatuga või mustandiga (kustutamisel jääb kanne alles)
                                <?php endif; ?>
                            </p>
                        </div>
                        <form method="post" onsubmit="return confirm('Hoiatus 1/2: kustutad ainult selle CSV impordi pangaread. Seotud pearaamatu kanded ja arved jäävad alles. Kui impordid sama faili uuesti, võib tekkida topeltkanne. Kas jätkad?') && confirm('Hoiatus 2/2: enne kustutamist luuakse SQL-varukoopia. Kas kinnitad valitud CSV impordi kustutamise?');">
                            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="clear_bank_csv_batch">
                            <input type="hidden" name="import_batch_id" value="<?= h((string) $batch['import_batch_id']) ?>">
                            <button class="button button-small button-danger" type="submit">Kustuta see import</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="muted">Faili järgi jälgitavaid CSV-importide rühmi veel pole.</p>
        <?php endif; ?>

        <?php if ($legacyBankCsvRows > 0): ?>
            <div class="import-batch">
                <div>
                    <strong>Varasemad CSV-read, mille faili nimi pole salvestatud</strong>
                    <p class="muted"><?= $legacyBankCsvRows ?> rida · vanu faile ei saa üksteisest eristada</p>
                </div>
                <form method="post" onsubmit="return confirm('Hoiatus 1/2: kustutad kõik eristamata vanad CSV read koos, kokku <?= $legacyBankCsvRows ?> rida. Üksikut faili nende hulgast valida ei saa. Kas jätkad?') && confirm('Hoiatus 2/2: enne kustutamist luuakse SQL-varukoopia. Kas kinnitad kõigi eristamata vanade ridade kustutamise?');">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="clear_bank_csv_legacy">
                    <button class="button button-small button-danger" type="submit">Kustuta vanad eristamata read</button>
                </form>
            </div>
        <?php endif; ?>

        <div class="maintenance-actions">
            <form method="post" onsubmit="return confirm('Hoiatus 1/2: aktiivne ettevõte lähtestatakse. Kustutatakse kanded, arved ja maksed, töötajad ja palgaarvestused, laoliikumised, prooviks lisatud tootekaardid ja laod, põhivara kaardid ning nende amortisatsiooni- ja võõrandamiste ajalugu, pangaväljavõtte read, portfelli algsaldo, sulgemisajalugu, e-arvete ajalugu ja e-kirjade saatmislogi, käibemaksu kinnitused ja jaotused, sõiduautode kuunimekirjad ning kreeditarvete viited. Kontoplaan, ettevõte, kasutajad, põhivara ja palga kontoseaded, aruandeseosed ja -seaded, tarnija vaikimisi kontoseosed, lao pearaamatukontode vaikeseosed ning kontaktid säilivad. Kas jätkad?') && confirm('Hoiatus 2/2: enne kustutamist tehakse kogu andmebaasist SQL-varukoopia. Tühjade tabelite ID-loendurid lähtestatakse. Kas kinnitad ettevõtte lähtestamise?');">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="clear_entries">
                <button class="button button-small button-danger" type="submit">Lähtesta andmed, säilita seaded</button>
            </form>

            <form method="post" onsubmit="return confirm('Hoiatus 1/2: see kustutab aktiivse ettevõtte kõik panga CSV read, ka nimega jälgitavad impordid. Pearaamatu kandeid ega arveid ei kustutata. Kas soovid jätkata?') && confirm('Hoiatus 2/2: enne kustutamist luuakse SQL-varukoopia. Kas kinnitad kõigi CSV-ridade kustutamise?');">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="clear_bank_csv">
                <button class="button button-small button-danger" type="submit">Kustuta kõik panga CSV-read</button>
            </form>
            <form method="post" onsubmit="return confirm('Hoiatus 1/2: see kustutab aktiivse ettevõtte portfelli algsaldo CSV-st imporditud read. Pangatehinguid ega raamatupidamiskandeid ei kustutata. Kas jätkame?') && confirm('Hoiatus 2/2: kustutamist ei saa rakenduses tagasi võtta. Enne kustutamist luuakse SQL-varukoopia. Kas kinnitad algsaldo ridade kustutamise?');">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="clear_portfolio_opening">
                <button class="button button-small button-danger" type="submit">Kustuta algsaldo CSV-read</button>
            </form>
        </div>
    </section>
    </details>

    <p><a class="button" href="index.php">Tagasi avalehele</a></p>
<script>
(() => {
    const list = document.querySelector('.import-batch-list');
    if (!list) return;
    const rows = Array.from(list.children).slice(0, 4);
    function sizeImportList() {
        if (!list.getClientRects().length || !rows.length) return;
        const first = rows[0].getBoundingClientRect();
        const last = rows[rows.length - 1].getBoundingClientRect();
        list.style.maxHeight = Math.ceil(last.bottom - first.top) + 'px';
    }
    const observer = new ResizeObserver(sizeImportList);
    for (const row of rows) observer.observe(row);
    list.closest('details')?.addEventListener('toggle', sizeImportList);
    window.addEventListener('resize', sizeImportList);
    sizeImportList();
})();
</script>
</body>
</html>
