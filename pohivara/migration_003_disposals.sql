-- Põhivara müügi ja kasutusest kõrvaldamise jälgimine.
CREATE TABLE IF NOT EXISTS fixed_asset_disposals (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    asset_id INT UNSIGNED NOT NULL,
    disposal_date DATE NOT NULL,
    disposal_type ENUM('sale', 'writeoff') NOT NULL,
    sales_invoice_id INT UNSIGNED DEFAULT NULL,
    acquisition_cost DECIMAL(15,2) NOT NULL,
    accumulated_depreciation DECIMAL(15,2) NOT NULL,
    carrying_value DECIMAL(15,2) NOT NULL,
    disposal_expense_account_id INT UNSIGNED NOT NULL,
    journal_entry_id INT UNSIGNED NOT NULL,
    notes VARCHAR(500) DEFAULT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fixed_asset_disposal_asset (company_id, asset_id),
    UNIQUE KEY uq_fixed_asset_disposal_invoice (company_id, sales_invoice_id),
    KEY idx_fixed_asset_disposal_journal (journal_entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
