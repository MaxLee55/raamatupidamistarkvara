-- Lisa palgakontode seadistusse eraldi kulukontod sotsiaalmaksule
-- ja tööandja töötuskindlustusmaksele.
ALTER TABLE payroll_account_settings
    ADD COLUMN IF NOT EXISTS employer_social_tax_expense_account_id INT UNSIGNED NULL AFTER employer_tax_expense_account_id,
    ADD COLUMN IF NOT EXISTS employer_unemployment_expense_account_id INT UNSIGNED NULL AFTER employer_social_tax_expense_account_id;

-- Olemasolev ühendatud maksukulu konto jääb ajutise vaikimisi valikuna
-- mõlema uue välja väärtuseks, kuni kasutaja need seadistuses üle valib.
UPDATE payroll_account_settings
SET employer_social_tax_expense_account_id = COALESCE(employer_social_tax_expense_account_id, employer_tax_expense_account_id),
    employer_unemployment_expense_account_id = COALESCE(employer_unemployment_expense_account_id, employer_tax_expense_account_id);
