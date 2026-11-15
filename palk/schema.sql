-- Käivita see skript raamatupidamine andmebaasis palgamooduli tabelite uuendamisel.
-- Kõik päringud peavad piirama tulemused sisselogitud company_id järgi.
CREATE TABLE IF NOT EXISTS payroll_employees (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(254) NULL,
    bank_iban VARCHAR(34) NULL,
    bank_name VARCHAR(120) NULL,
    bank_bic VARCHAR(11) NULL,
    personal_code CHAR(11) NULL,
    birth_date DATE NULL,
    is_test_data TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_employee_company_id (company_id, id),
    UNIQUE KEY uq_payroll_employee_personal_code (company_id, personal_code),
    KEY ix_payroll_employee_name (company_id, last_name, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE payroll_employees
    ADD COLUMN IF NOT EXISTS email VARCHAR(254) NULL AFTER last_name;

ALTER TABLE payroll_employees
    ADD COLUMN IF NOT EXISTS is_test_data TINYINT(1) NOT NULL DEFAULT 0;

-- Kehtivuskuupäevadega töötaja maksuseaded. Maksuvaba tulu rakendamise aluseks
-- on töötaja avaldus; II samba määr tuleb kontrollida Pensionikeskuse registrist.
CREATE TABLE IF NOT EXISTS payroll_employee_tax_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    valid_from DATE NOT NULL,
    tax_free_type VARCHAR(16) NOT NULL DEFAULT 'none',
    tax_free_amount DECIMAL(7,2) NOT NULL DEFAULT 0.00,
    tax_free_application_date DATE NULL,
    pension_contribution_rate TINYINT UNSIGNED NOT NULL DEFAULT 0,
    pension_rate_checked_on DATE NULL,
    unemployment_exempt TINYINT(1) NOT NULL DEFAULT 0,
    social_tax_minimum_exempt TINYINT(1) NOT NULL DEFAULT 0,
    recorded_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_tax_setting_start (company_id, employee_id, valid_from),
    KEY ix_payroll_tax_setting_current (company_id, employee_id, valid_from),
    CONSTRAINT fk_payroll_tax_setting_employee
        FOREIGN KEY (company_id, employee_id)
        REFERENCES payroll_employees (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotent update for installations that already created the tax-settings table.
ALTER TABLE payroll_employee_tax_settings
    ADD COLUMN IF NOT EXISTS unemployment_exempt TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS social_tax_minimum_exempt TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS payroll_employments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    employment_type_code SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    started_on DATE NOT NULL,
    ended_on DATE NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_employment_company_id (company_id, id),
    KEY ix_payroll_employment_employee (company_id, employee_id, started_on),
    CONSTRAINT fk_payroll_employment_employee
        FOREIGN KEY (company_id, employee_id)
        REFERENCES payroll_employees (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_employment_terms (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    employment_id BIGINT UNSIGNED NOT NULL,
    valid_from DATE NOT NULL,
    valid_until DATE NULL,
    work_time_rate DECIMAL(3,2) NOT NULL,
    job_title VARCHAR(250) NOT NULL,
    workplace_address VARCHAR(500) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_terms_valid_from (company_id, employment_id, valid_from),
    KEY ix_payroll_terms_current (company_id, employment_id, valid_until),
    CONSTRAINT fk_payroll_terms_employment
        FOREIGN KEY (company_id, employment_id)
        REFERENCES payroll_employments (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_employment_suspensions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    employment_id BIGINT UNSIGNED NOT NULL,
    started_on DATE NOT NULL,
    ended_on DATE NULL,
    basis_code VARCHAR(16) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_payroll_suspensions_open (company_id, employment_id, ended_on),
    CONSTRAINT fk_payroll_suspension_employment
        FOREIGN KEY (company_id, employment_id)
        REFERENCES payroll_employments (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_employment_closures (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    employment_id BIGINT UNSIGNED NOT NULL,
    ended_on DATE NOT NULL,
    basis_code VARCHAR(16) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_employment_closure (company_id, employment_id),
    CONSTRAINT fk_payroll_closure_employment
        FOREIGN KEY (company_id, employment_id)
        REFERENCES payroll_employments (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Säilitab lõpetamise ja selle tühistamise ajaloo; kirjeid ei kustutata jäljetult.
CREATE TABLE IF NOT EXISTS payroll_employment_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    employment_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(16) NOT NULL,
    event_date DATE NOT NULL,
    basis_code VARCHAR(16) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_payroll_employment_events (company_id, employment_id, event_date),
    CONSTRAINT fk_payroll_employment_event_employment
        FOREIGN KEY (company_id, employment_id)
        REFERENCES payroll_employments (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    work_month DATE NOT NULL,
    planned_payment_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_run_company_id (company_id, id),
    KEY ix_payroll_run_month (company_id, work_month, planned_payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_run_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    run_id BIGINT UNSIGNED NOT NULL,
    employment_id BIGINT UNSIGNED NOT NULL,
    gross_amount DECIMAL(13,2) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_run_item (company_id, run_id, employment_id),
    KEY ix_payroll_run_item_employment (company_id, employment_id),
    CONSTRAINT fk_payroll_run_item_run
        FOREIGN KEY (company_id, run_id)
        REFERENCES payroll_runs (company_id, id),
    CONSTRAINT fk_payroll_run_item_employment
        FOREIGN KEY (company_id, employment_id)
        REFERENCES payroll_employments (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Brutotasu detail: ühe töötaja arvestusrea alla saab lisada mitu palgaliiki.
CREATE TABLE IF NOT EXISTS payroll_run_item_components (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    run_id BIGINT UNSIGNED NOT NULL,
    run_item_id BIGINT UNSIGNED NOT NULL,
    component_type VARCHAR(24) NOT NULL,
    amount DECIMAL(13,2) NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_payroll_component_item (company_id, run_id, run_item_id),
    CONSTRAINT fk_payroll_component_run FOREIGN KEY (company_id, run_id)
        REFERENCES payroll_runs (company_id, id),
    CONSTRAINT fk_payroll_component_item FOREIGN KEY (run_item_id)
        REFERENCES payroll_run_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vanad ühe summaga palgaread säilivad ühe „Põhipalk“ reana.
INSERT INTO payroll_run_item_components
    (company_id, run_id, run_item_id, component_type, amount, created_by)
SELECT pri.company_id, pri.run_id, pri.id, 'salary', pri.gross_amount, pri.created_by
FROM payroll_run_items pri
WHERE NOT EXISTS (
    SELECT 1 FROM payroll_run_item_components c
    WHERE c.company_id = pri.company_id AND c.run_id = pri.run_id AND c.run_item_id = pri.id
);

CREATE TABLE IF NOT EXISTS payroll_run_confirmations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    run_id BIGINT UNSIGNED NOT NULL,
    confirmed_by BIGINT UNSIGNED NOT NULL,
    confirmed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_run_confirmation (company_id, run_id),
    CONSTRAINT fk_payroll_run_confirmation_run
        FOREIGN KEY (company_id, run_id)
        REFERENCES payroll_runs (company_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_run_item_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    run_id BIGINT UNSIGNED NOT NULL,
    run_item_id BIGINT UNSIGNED NOT NULL,
    actual_payment_date DATE NOT NULL,
    bank_account_id INT UNSIGNED NULL,
    journal_entry_id INT UNSIGNED NULL,
    journal_calculation_id BIGINT UNSIGNED NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_run_item_payment (company_id, run_item_id),
    UNIQUE KEY uq_payroll_payment_journal_entry (journal_entry_id),
    KEY ix_payroll_run_payment_date (company_id, actual_payment_date),
    KEY ix_payroll_payment_bank_account (company_id, bank_account_id),
    CONSTRAINT fk_payroll_run_payment_run
        FOREIGN KEY (company_id, run_id)
        REFERENCES payroll_runs (company_id, id),
    CONSTRAINT fk_payroll_run_payment_item
        FOREIGN KEY (run_item_id)
        REFERENCES payroll_run_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payroll_account_settings (
    company_id BIGINT UNSIGNED NOT NULL,
    wages_expense_account_id INT UNSIGNED NOT NULL,
    employer_tax_expense_account_id INT UNSIGNED NOT NULL,
    employer_social_tax_expense_account_id INT UNSIGNED NULL,
    employer_unemployment_expense_account_id INT UNSIGNED NULL,
    income_tax_liability_account_id INT UNSIGNED NOT NULL,
    pension_liability_account_id INT UNSIGNED NOT NULL,
    employee_unemployment_liability_account_id INT UNSIGNED NOT NULL,
    employer_unemployment_liability_account_id INT UNSIGNED NOT NULL,
    social_tax_liability_account_id INT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Maksuarvestuse muutumatu salvestatud versioon; kordusarvutus lisab uue versiooni.
CREATE TABLE IF NOT EXISTS payroll_run_item_calculations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NOT NULL,
    run_id BIGINT UNSIGNED NOT NULL,
    run_item_id BIGINT UNSIGNED NOT NULL,
    calculation_date DATE NOT NULL,
    setting_valid_from DATE NOT NULL,
    gross_amount DECIMAL(13,2) NOT NULL,
    pension_rate DECIMAL(4,2) NOT NULL,
    pension_amount DECIMAL(13,2) NOT NULL,
    employee_unemployment_rate DECIMAL(4,2) NOT NULL,
    employee_unemployment_amount DECIMAL(13,2) NOT NULL,
    tax_free_amount DECIMAL(13,2) NOT NULL,
    income_tax_rate DECIMAL(4,2) NOT NULL,
    income_tax_amount DECIMAL(13,2) NOT NULL,
    net_amount DECIMAL(13,2) NOT NULL,
    employer_unemployment_rate DECIMAL(4,2) NOT NULL,
    employer_unemployment_amount DECIMAL(13,2) NOT NULL,
    social_tax_rate DECIMAL(4,2) NOT NULL,
    social_tax_amount DECIMAL(13,2) NOT NULL,
    social_tax_minimum_applied TINYINT(1) NOT NULL DEFAULT 0,
    calculated_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_payroll_calculation_latest (company_id, run_id, run_item_id, id),
    CONSTRAINT fk_payroll_calculation_run
        FOREIGN KEY (company_id, run_id)
        REFERENCES payroll_runs (company_id, id),
    CONSTRAINT fk_payroll_calculation_item
        FOREIGN KEY (run_item_id)
        REFERENCES payroll_run_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
