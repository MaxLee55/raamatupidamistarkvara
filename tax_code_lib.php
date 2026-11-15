<?php
require_once __DIR__ . '/eu_reverse_vat.php';
require_once __DIR__ . '/zero_sales_vat.php';

/**
 * Install the first, domestic VAT-code catalogue without changing existing
 * invoice amounts or journal entries. Existing rows retain a NULL code and
 * continue to work through their stored vat_rate.
 */
function ensureTaxCodeSchema(PDO $pdo): void
{
    ensureForeignPurchaseContactSchema($pdo);
    ensureZeroSalesSchema($pdo);
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS tax_codes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT UNSIGNED NOT NULL,
            code VARCHAR(40) NOT NULL,
            label VARCHAR(160) NOT NULL,
            invoice_type ENUM('sales','purchase') NOT NULL,
            rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            tax_category ENUM('standard','reduced','zero_rated','exempt','reverse_charge') NOT NULL,
            valid_from DATE NULL,
            valid_to DATE NULL,
            kmd_box VARCHAR(20) NULL,
            kmd_inf_code VARCHAR(20) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_tax_codes_company_type_code (company_id, invoice_type, code),
            KEY idx_tax_codes_company_active (company_id, invoice_type, is_active),
            CONSTRAINT fk_tax_codes_company FOREIGN KEY (company_id)
                REFERENCES companies (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $categoryColumn = $pdo->query("SHOW COLUMNS FROM tax_codes LIKE 'tax_category'")->fetch(PDO::FETCH_ASSOC);
    if (!str_contains((string) ($categoryColumn['Type'] ?? ''), 'reverse_charge')) {
        $pdo->exec("ALTER TABLE tax_codes MODIFY tax_category ENUM('standard','reduced','zero_rated','exempt','reverse_charge') NOT NULL");
    }

    foreach (['sales_invoice_lines', 'purchase_invoice_lines'] as $table) {
        $column = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $column->execute([$table, 'tax_code_id']);
        if ((int) $column->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN tax_code_id BIGINT UNSIGNED NULL");
            $pdo->exec("ALTER TABLE `{$table}` ADD KEY idx_{$table}_tax_code (tax_code_id)");
        }
    }

    $catalogue = [
        ['EE24', 'Eesti standardmäär — 24%', 24.00, 'standard', '2025-07-01', null, '1', 1],
        ['EE13', 'Majutus — 13%', 13.00, 'reduced', '2025-01-01', null, '2²', 2],
        ['EE9', 'Ajakirjandusväljaanne — 9%', 9.00, 'reduced', '2025-01-01', null, '2', 3],
        ['EE9-OLD', 'Majutus — ajalooline 9%', 9.00, 'reduced', null, '2024-12-31', '2', 4],
        ['EE5-OLD', 'Ajakirjandusväljaanne — ajalooline 5%', 5.00, 'reduced', null, '2024-12-31', '2¹', 5],
        ['EE9-TRANSITION', 'Majutus — 9% üleminekusäte (ainult tingimuste täitmisel)', 9.00, 'reduced', '2025-01-01', '2026-12-31', '2', 6],
        ['EE5-TRANSITION', 'Ajakirjandusväljaanne — 5% üleminekusäte (ainult tingimuste täitmisel)', 5.00, 'reduced', '2025-01-01', '2026-12-31', '2¹', 7],
        ['EE0', 'Eesti — 0% määraga maksustatav (käibe liik tuleb täpsustada)', 0.00, 'zero_rated', null, null, null, 8],
        ['EXEMPT', 'Maksuvaba käive / ost (vajab tehingu liigi kontrolli)', 0.00, 'exempt', null, null, null, 9],
        ['EE22-OLD', 'Eesti standardmäär — ajalooline 22%', 22.00, 'standard', '2024-01-01', '2025-06-30', '1', 10],
        ['EE20-OLD', 'Eesti standardmäär — ajalooline 20%', 20.00, 'standard', null, '2023-12-31', '1¹', 11],
    ];
    $companies = $pdo->query('SELECT id FROM companies')->fetchAll(PDO::FETCH_COLUMN);
    $oldCodeStmt = $pdo->prepare('SELECT id FROM tax_codes WHERE company_id = ? AND invoice_type = ? AND code = ?');
    $newCodeStmt = $pdo->prepare('SELECT id FROM tax_codes WHERE company_id = ? AND invoice_type = ? AND code = ?');
    $renameCodeStmt = $pdo->prepare('UPDATE tax_codes SET code = ? WHERE id = ? AND company_id = ?');
    $deleteDuplicateCodeStmt = $pdo->prepare('DELETE FROM tax_codes WHERE id = ? AND company_id = ?');
    foreach ($companies as $companyId) {
        foreach (['sales', 'purchase'] as $invoiceType) {
            $prefix = $invoiceType === 'sales' ? 'M_' : 'O_';
            $lineTable = $invoiceType === 'sales' ? 'sales_invoice_lines' : 'purchase_invoice_lines';
            foreach ($catalogue as [$baseCode]) {
                $prefixedCode = $prefix . $baseCode;
                $oldCodeStmt->execute([(int) $companyId, $invoiceType, $baseCode]);
                $oldId = $oldCodeStmt->fetchColumn();
                if ($oldId === false) {
                    continue;
                }
                $newCodeStmt->execute([(int) $companyId, $invoiceType, $prefixedCode]);
                $newId = $newCodeStmt->fetchColumn();
                if ($newId !== false) {
                    $pdo->prepare("UPDATE `{$lineTable}` SET tax_code_id = ? WHERE tax_code_id = ?")
                        ->execute([(int) $newId, (int) $oldId]);
                    $deleteDuplicateCodeStmt->execute([(int) $oldId, (int) $companyId]);
                } else {
                    $renameCodeStmt->execute([$prefixedCode, (int) $oldId, (int) $companyId]);
                }
            }
        }
    }
    $insert = $pdo->prepare(
        'INSERT INTO tax_codes
            (company_id, code, label, invoice_type, rate, tax_category, valid_from, valid_to, kmd_box, kmd_inf_code, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE label = VALUES(label), rate = VALUES(rate),
             tax_category = VALUES(tax_category), valid_from = VALUES(valid_from),
             valid_to = VALUES(valid_to), kmd_box = VALUES(kmd_box), is_active = 1'
    );
    foreach ($companies as $companyId) {
        foreach (['sales', 'purchase'] as $invoiceType) {
            $prefix = $invoiceType === 'sales' ? 'M_' : 'O_';
            foreach ($catalogue as [$code, $label, $rate, $category, $from, $to, $kmdBox, $sort]) {
                $insert->execute([(int) $companyId, $prefix . $code, $label, $invoiceType, $rate, $category, $from, $to, $invoiceType === 'sales' ? $kmdBox : null, null]);
            }
        }
    }
    foreach ($companies as $companyId) {
        $insert->execute([(int)$companyId,'M_CASH24','Kassapõhine arvestus — 24%','sales',24,'standard','2025-07-01',null,'1',null]);
        $insert->execute([(int)$companyId,'M_CASH13','Kassapõhine arvestus — 13%','sales',13,'reduced','2025-01-01',null,'2²',null]);
        $insert->execute([(int)$companyId,'M_CASH9','Kassapõhine arvestus — 9%','sales',9,'reduced','2025-01-01',null,'2',null]);
        $insert->execute([(int)$companyId,'O_IMPORT0','Kauba import — tarnija KM 0; KM tollidokumendilt','purchase',0,'zero_rated',null,null,null,null]);
        $insert->execute([(int)$companyId,'O_EU_GOODS_EXEMPT','EL kauba maksuvaba ühendusesisene soetamine','purchase',0,'reverse_charge','2004-05-01',null,null,null]);
        $insert->execute([(int)$companyId,'O_OUTSIDE_EE','Väljaspool Eesti KMD-d — summa koos võimaliku välisriigi KM-iga','purchase',0,'zero_rated',null,null,null,null]);
        foreach(zeroSalesSpecs() as $code=>$spec) $insert->execute([(int)$companyId,$code,$spec['label'],'sales',0,$spec['category'],null,null,$spec['box'],null]);
        foreach (['GOODS' => 'kauba', 'SERVICES' => 'teenuse'] as $kind => $label) {
            foreach ([24, 13, 9] as $rate) {
                $code = 'O_EU_' . $kind . $rate;
                $spec = euReverseSpec($code);
                $insert->execute([(int) $companyId, $code, 'EL ' . $label . ' ost — pöördmaks ' . $rate . '% (tarnija KM 0)',
                    'purchase', 0, 'reverse_charge', $spec['valid_from'], null, null, null]);
            }
        }
    }

    foreach ($companies as $companyId) {
        foreach ([
            ['O_EE_REVERSE24','Siseriiklik kaubaost — pöördmaks 24% (KMS § 41¹)','purchase', '2025-07-01'],
            ['M_EE_REVERSE24','Siseriiklik kaubamüük — pöördmaks 24% (KMS § 41¹)','sales', '2025-07-01'],
            ['O_NON_EU_SERVICES24','EL-välise teenuse ost — pöördmaks 24%','purchase', '2025-07-01'],
            ['O_NON_EU_SERVICES9','EL-välise teenuse ost — pöördmaks 9% (ainult vastava teenuse korral)','purchase', '2025-01-01'],
        ] as [$code,$label,$side,$validFrom]) {
            $insert->execute([(int)$companyId,$code,$label,$side,0,'reverse_charge',$validFrom,null,$code==='M_EE_REVERSE24'?'9':null,null]);
        }
    }

}

function getTaxCodes(PDO $pdo, int $companyId, string $invoiceType, string $invoiceDate = ''): array
{
    if (!in_array($invoiceType, ['sales', 'purchase'], true)) {
        throw new InvalidArgumentException('Vigane arve liik.');
    }
    $stmt = $pdo->prepare(
        'SELECT id, code, label, rate, tax_category, valid_from, valid_to
         FROM tax_codes
         WHERE company_id = ? AND invoice_type = ? AND is_active = 1
         ORDER BY CASE tax_category WHEN "standard" THEN 0 WHEN "reduced" THEN 1 WHEN "zero_rated" THEN 2 ELSE 3 END,
                  rate DESC, code'
    );
    $stmt->execute([$companyId, $invoiceType]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getTaxCodeById(PDO $pdo, int $companyId, string $invoiceType, int $taxCodeId, string $invoiceDate): ?array
{
    $stmt = $pdo->prepare(
        'SELECT id, code, label, rate, tax_category
         FROM tax_codes
         WHERE id = ? AND company_id = ? AND invoice_type = ? AND is_active = 1
           AND (valid_from IS NULL OR valid_from <= ?)
           AND (valid_to IS NULL OR valid_to >= ?)'
    );
    $stmt->execute([$taxCodeId, $companyId, $invoiceType, $invoiceDate, $invoiceDate]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
