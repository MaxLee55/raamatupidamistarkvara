CREATE TABLE IF NOT EXISTS annual_report_account_mappings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    account_id INT UNSIGNED NOT NULL,
    income_scheme TINYINT UNSIGNED NOT NULL DEFAULT 2,
    report_line_code VARCHAR(60) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_annual_report_mapping_company_account_scheme (company_id, account_id, income_scheme),
    KEY idx_annual_report_mapping_account (account_id),
    CONSTRAINT fk_annual_report_mapping_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT fk_annual_report_mapping_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
