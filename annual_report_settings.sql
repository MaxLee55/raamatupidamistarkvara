CREATE TABLE IF NOT EXISTS annual_report_settings (
    company_id INT UNSIGNED NOT NULL,
    report_category VARCHAR(20) NOT NULL DEFAULT 'small',
    income_statement_scheme TINYINT UNSIGNED NOT NULL DEFAULT 2,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id),
    CONSTRAINT fk_annual_report_settings_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT chk_annual_report_category CHECK (report_category IN ('micro','small','medium','large')),
    CONSTRAINT chk_annual_report_scheme CHECK (income_statement_scheme IN (1,2))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
