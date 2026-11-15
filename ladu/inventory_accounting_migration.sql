-- Käivita üks kord olemasolevas raamatupidamise andmebaasis.
-- Seob laotoote kontod ning laoliikumise automaatse pearaamatukandega.

ALTER TABLE `inventory_products`
    ADD COLUMN `inventory_account_id` INT UNSIGNED DEFAULT NULL AFTER `minimum_stock`,
    ADD COLUMN `cogs_account_id` INT UNSIGNED DEFAULT NULL AFTER `inventory_account_id`,
    ADD COLUMN `inventory_gain_account_id` INT UNSIGNED DEFAULT NULL AFTER `cogs_account_id`,
    ADD CONSTRAINT `fk_inventory_products_inventory_account`
        FOREIGN KEY (`inventory_account_id`) REFERENCES `accounts` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_inventory_products_cogs_account`
        FOREIGN KEY (`cogs_account_id`) REFERENCES `accounts` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_inventory_products_gain_account`
        FOREIGN KEY (`inventory_gain_account_id`) REFERENCES `accounts` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `inventory_movements`
    ADD COLUMN `journal_entry_id` INT UNSIGNED DEFAULT NULL AFTER `source_purchase_invoice_id`,
    ADD KEY `idx_inventory_movements_journal_entry` (`journal_entry_id`),
    ADD CONSTRAINT `fk_inventory_movements_journal_entry`
        FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;
