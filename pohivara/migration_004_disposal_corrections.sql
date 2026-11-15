-- Kõrvaldamise paranduste ajalugu ja korduskõrvaldamise turvaline lubamine.
-- Enne rakendamist säilitab vana kirje: status muutub vaikimisi väärtuseks posted.
ALTER TABLE fixed_asset_disposals
    DROP INDEX uq_fixed_asset_disposal_asset,
    DROP INDEX uq_fixed_asset_disposal_invoice,
    ADD COLUMN status ENUM('posted', 'reversed') NOT NULL DEFAULT 'posted' AFTER disposal_type,
    ADD COLUMN active_asset_id INT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'posted' THEN asset_id ELSE NULL END) STORED,
    ADD COLUMN active_sales_invoice_id INT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'posted' THEN sales_invoice_id ELSE NULL END) STORED,
    ADD UNIQUE KEY uq_fixed_asset_disposal_active_asset (company_id, active_asset_id),
    ADD UNIQUE KEY uq_fixed_asset_disposal_active_invoice (company_id, active_sales_invoice_id);

CREATE TABLE IF NOT EXISTS fixed_asset_disposal_reversals (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    disposal_id INT UNSIGNED NOT NULL,
    reversal_journal_entry_id INT UNSIGNED NOT NULL,
    reversal_date DATE NOT NULL,
    reason VARCHAR(500) NOT NULL,
    reversed_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fixed_asset_disposal_reversal_disposal (disposal_id),
    UNIQUE KEY uq_fixed_asset_disposal_reversal_entry (reversal_journal_entry_id),
    KEY idx_fixed_asset_disposal_reversal_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;