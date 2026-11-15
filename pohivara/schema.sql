-- Põhivara mooduli baas: ettevõtte kontoseosed ja põhivara register.
-- Impordi see fail programmi andmebaasi enne mooduli esmakordset kasutamist.
CREATE TABLE IF NOT EXISTS fixed_asset_settings (
    company_id INT UNSIGNED NOT NULL,
    asset_account_id INT UNSIGNED DEFAULT NULL,
    accumulated_depreciation_account_id INT UNSIGNED DEFAULT NULL,
    depreciation_expense_account_id INT UNSIGNED DEFAULT NULL,
    updated_by INT UNSIGNED DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id),
    KEY idx_fixed_asset_settings_asset_account (asset_account_id),
    KEY idx_fixed_asset_settings_accumulated_account (accumulated_depreciation_account_id),
    KEY idx_fixed_asset_settings_expense_account (depreciation_expense_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fixed_assets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    asset_number VARCHAR(50) NOT NULL,
    name VARCHAR(200) NOT NULL,
    category VARCHAR(100) DEFAULT NULL,
    acquisition_date DATE NOT NULL,
    available_date DATE NOT NULL,
    acquisition_cost DECIMAL(15,2) NOT NULL,
    residual_value DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    useful_life_months SMALLINT UNSIGNED NOT NULL,
    depreciation_method VARCHAR(30) NOT NULL DEFAULT 'straight_line',
    asset_account_id INT UNSIGNED NOT NULL,
    accumulated_depreciation_account_id INT UNSIGNED NOT NULL,
    depreciation_expense_account_id INT UNSIGNED NOT NULL,
    source_document VARCHAR(100) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    legacy_opening_balance TINYINT(1) NOT NULL DEFAULT 0,
    opening_accumulated_depreciation DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    opening_depreciation_months SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fixed_assets_company_number (company_id, asset_number),
    KEY idx_fixed_assets_company_status (company_id, status),
    KEY idx_fixed_assets_company_available (company_id, available_date),
    KEY idx_fixed_assets_asset_account (asset_account_id),
    KEY idx_fixed_assets_accumulated_account (accumulated_depreciation_account_id),
    KEY idx_fixed_assets_expense_account (depreciation_expense_account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
