-- Käivita raamatupidamine andmebaasis üks kord enne palgakannete kasutamist.
-- Seadistustabel hoiab iga ettevõtte kontoplaanist valitud kontosid.
CREATE TABLE IF NOT EXISTS payroll_account_settings (
    company_id BIGINT UNSIGNED NOT NULL,
    wages_expense_account_id INT UNSIGNED NOT NULL,
    employer_tax_expense_account_id INT UNSIGNED NOT NULL,
    income_tax_liability_account_id INT UNSIGNED NOT NULL,
    pension_liability_account_id INT UNSIGNED NOT NULL,
    employee_unemployment_liability_account_id INT UNSIGNED NOT NULL,
    employer_unemployment_liability_account_id INT UNSIGNED NOT NULL,
    social_tax_liability_account_id INT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (company_id),
    KEY ix_payroll_account_settings_user (updated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pangakonto ja pearaamatukande viited salvestatakse väljamakse juurde.
ALTER TABLE payroll_run_item_payments
    ADD COLUMN IF NOT EXISTS bank_account_id INT UNSIGNED NULL AFTER actual_payment_date,
    ADD COLUMN IF NOT EXISTS journal_entry_id INT UNSIGNED NULL AFTER bank_account_id,
    ADD COLUMN IF NOT EXISTS journal_calculation_id BIGINT UNSIGNED NULL AFTER journal_entry_id;

ALTER TABLE payroll_run_item_payments
    ADD UNIQUE KEY uq_payroll_payment_journal_entry (journal_entry_id),
    ADD KEY ix_payroll_payment_bank_account (company_id, bank_account_id);
