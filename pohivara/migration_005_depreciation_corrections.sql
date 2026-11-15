-- Säilitab põhivara amortisatsioonikande paranduste ajaloo.
-- Käivita olemasolevas raamatupidamise andmebaasis üks kord pärast migration_002_depreciation_runs.sql-i.
CREATE TABLE IF NOT EXISTS fixed_asset_depreciation_corrections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    asset_id INT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    original_journal_entry_id INT UNSIGNED NOT NULL,
    reversal_journal_entry_id INT UNSIGNED NOT NULL,
    reversal_date DATE NOT NULL,
    reason VARCHAR(500) NOT NULL,
    reversed_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fixed_asset_depreciation_correction_asset (company_id, asset_id, period_start),
    KEY idx_fixed_asset_depreciation_correction_original (original_journal_entry_id),
    KEY idx_fixed_asset_depreciation_correction_reversal (reversal_journal_entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
