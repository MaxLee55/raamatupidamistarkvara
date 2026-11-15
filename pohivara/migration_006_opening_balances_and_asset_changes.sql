-- Põhivara algseis ja edaspidised arvestushinnangu muudatused.
-- Käivita üks kord olemasolevas andmebaasis pärast migratsioone 002–005.
ALTER TABLE fixed_assets
    ADD COLUMN legacy_opening_balance TINYINT(1) NOT NULL DEFAULT 0 AFTER notes,
    ADD COLUMN opening_accumulated_depreciation DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER legacy_opening_balance,
    ADD COLUMN opening_depreciation_months SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER opening_accumulated_depreciation;

CREATE TABLE IF NOT EXISTS fixed_asset_estimate_changes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    asset_id INT UNSIGNED NOT NULL,
    effective_period DATE NOT NULL,
    months_before_change SMALLINT UNSIGNED NOT NULL,
    carrying_value_at_change DECIMAL(15,2) NOT NULL,
    residual_value DECIMAL(15,2) NOT NULL,
    remaining_life_months SMALLINT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fixed_asset_estimate_period (company_id, asset_id, effective_period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
