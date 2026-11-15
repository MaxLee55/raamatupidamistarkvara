-- Amortisatsiooni pearaamatukannete jälgimine.
-- Lisa see migratsioon olemasolevasse andmebaasi; ära impordi algset schema.sql faili uuesti.
CREATE TABLE IF NOT EXISTS fixed_asset_depreciation_runs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    asset_id INT UNSIGNED NOT NULL,
    period_start DATE NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    journal_entry_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fixed_asset_depreciation_period (company_id, asset_id, period_start),
    KEY idx_fixed_asset_depreciation_journal (journal_entry_id),
    KEY idx_fixed_asset_depreciation_period (company_id, period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
