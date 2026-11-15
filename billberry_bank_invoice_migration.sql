-- Run once in phpMyAdmin on each accounting database before using bank e-invoices.
ALTER TABLE companies
    ADD COLUMN bank_bic VARCHAR(11) DEFAULT NULL AFTER bank_name;

ALTER TABLE contacts
    ADD COLUMN bank_einvoice_bic VARCHAR(11) DEFAULT NULL AFTER country_code,
    ADD COLUMN bank_einvoice_account VARCHAR(34) DEFAULT NULL AFTER bank_einvoice_bic;

-- Fill the sender BIC where the existing bank name is recognizable.
UPDATE companies
SET bank_bic = CASE
    WHEN bank_name LIKE '%Swedbank%' THEN 'HABAEE2X'
    WHEN bank_name LIKE '%SEB%' THEN 'EEUHEE2X'
    WHEN bank_name LIKE '%LHV%' THEN 'LHVBEE22'
    WHEN bank_name LIKE '%Coop%' THEN 'EKRDEE22'
    WHEN bank_name LIKE '%Luminor%' THEN 'RIKOEE22'
    ELSE bank_bic
END
WHERE bank_bic IS NULL;
