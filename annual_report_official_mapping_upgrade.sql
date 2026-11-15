-- Updates the current company's existing scheme 2 suggestions to the official
-- statutory balance-sheet and RTJ 2 scheme 2 line labels used by the app.
-- Review loan maturity after running: account 2200 defaults to current liabilities.

START TRANSACTION;

SET @company_id := 1;

UPDATE annual_report_account_mappings m
JOIN accounts a ON a.id = m.account_id AND a.company_id = m.company_id
SET m.report_line_code = CASE
    WHEN a.account_type = 'asset' AND a.code IN ('1000','1010','1020','1030') THEN 'bs_cash'
    WHEN a.account_type = 'asset' AND a.code = '1100' THEN 'bs_financial_assets'
    WHEN a.account_type = 'asset' AND a.code = '1200' THEN 'bs_receivables'
    WHEN a.account_type = 'asset' AND a.code = '1250' THEN 'bs_inventory'
    WHEN a.account_type = 'asset' AND a.code = '1500' THEN 'bs_fixed_assets'
    WHEN a.account_type = 'liability' AND a.code = '2000' THEN 'bs_payables'
    WHEN a.account_type = 'liability' AND a.code = '2010' THEN 'bs_employee_payables'
    WHEN a.account_type = 'liability' AND a.code IN ('2100','2110','2120','2130','2140','2150','2160','2170') THEN 'bs_tax_liabilities'
    WHEN a.account_type = 'liability' AND a.code = '2200' THEN 'bs_loans'
    WHEN a.account_type = 'liability' AND a.code = '2300' THEN 'bs_dividend_liabilities'
    WHEN a.account_type = 'equity' AND a.code = '3010' THEN 'bs_share_capital'
    WHEN a.account_type = 'equity' AND a.code = '3020' THEN 'bs_retained_earnings'
    WHEN a.account_type = 'equity' AND a.code = '3100' THEN 'bs_current_result'
    WHEN a.account_type = 'income' AND a.code = '4000' THEN 'is_sales'
    WHEN a.account_type = 'income' AND a.code IN ('4100','4250') THEN 'is_financial_income'
    WHEN a.account_type = 'income' AND a.code = '4200' THEN 'is_interest_income'
    WHEN a.account_type = 'income' AND a.code = '4150' THEN 'is_other_financial_income'
    WHEN a.account_type = 'income' AND a.code = '4300' THEN 'is_other_income'
    WHEN a.account_type = 'expense' AND a.code = '5000' THEN 'is_goods'
    WHEN a.account_type = 'expense' AND a.code = '5050' THEN 'is_financial_income'
    WHEN a.account_type = 'expense' AND a.code = '6500' THEN 'is_income_tax'
    WHEN a.account_type = 'expense' AND a.code IN ('6000','6010','6020','6100','6200','6300','6400','6510') THEN 'is_admin'
    ELSE m.report_line_code
END,
m.updated_at = CURRENT_TIMESTAMP
WHERE m.company_id = @company_id AND m.income_scheme = 2;

COMMIT;
