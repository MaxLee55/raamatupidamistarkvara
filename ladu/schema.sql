CREATE TABLE IF NOT EXISTS `inventory_products` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT UNSIGNED NOT NULL,
    `sku` VARCHAR(50) NOT NULL,
    `barcode` VARCHAR(100) DEFAULT NULL,
    `name` VARCHAR(200) NOT NULL,
    `unit` VARCHAR(30) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `minimum_stock` DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    `inventory_account_id` INT UNSIGNED DEFAULT NULL,
    `cogs_account_id` INT UNSIGNED DEFAULT NULL,
    `inventory_gain_account_id` INT UNSIGNED DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_inventory_products_company_sku` (`company_id`, `sku`),
    UNIQUE KEY `uq_inventory_products_company_barcode` (`company_id`, `barcode`),
    KEY `idx_inventory_products_company_active_name` (`company_id`, `is_active`, `name`),
    CONSTRAINT `fk_inventory_products_company`
        FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_inventory_products_inventory_account`
        FOREIGN KEY (`inventory_account_id`) REFERENCES `accounts` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_inventory_products_cogs_account`
        FOREIGN KEY (`cogs_account_id`) REFERENCES `accounts` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_inventory_products_gain_account`
        FOREIGN KEY (`inventory_gain_account_id`) REFERENCES `accounts` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

CREATE TABLE IF NOT EXISTS `inventory_account_settings` (
    `company_id` INT UNSIGNED NOT NULL,
    `inventory_account_id` INT UNSIGNED DEFAULT NULL,
    `cogs_account_id` INT UNSIGNED DEFAULT NULL,
    `inventory_gain_account_id` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`company_id`),
    KEY `idx_inventory_account_settings_inventory_account` (`inventory_account_id`),
    KEY `idx_inventory_account_settings_cogs_account` (`cogs_account_id`),
    KEY `idx_inventory_account_settings_gain_account` (`inventory_gain_account_id`),
    CONSTRAINT `fk_inventory_account_settings_company`
        FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_inventory_account_settings_inventory_account`
        FOREIGN KEY (`inventory_account_id`) REFERENCES `accounts` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_inventory_account_settings_cogs_account`
        FOREIGN KEY (`cogs_account_id`) REFERENCES `accounts` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_inventory_account_settings_gain_account`
        FOREIGN KEY (`inventory_gain_account_id`) REFERENCES `accounts` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

CREATE TABLE IF NOT EXISTS `inventory_warehouses` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT UNSIGNED NOT NULL,
    `code` VARCHAR(40) NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `location_description` VARCHAR(500) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_inventory_warehouses_company_code` (`company_id`, `code`),
    KEY `idx_inventory_warehouses_company_active` (`company_id`, `is_active`, `name`),
    CONSTRAINT `fk_inventory_warehouses_company`
        FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

CREATE TABLE IF NOT EXISTS `inventory_movements` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `company_id` INT UNSIGNED NOT NULL,
    `source_purchase_invoice_id` INT UNSIGNED DEFAULT NULL,
    `source_sales_invoice_id` INT UNSIGNED DEFAULT NULL,
    `journal_entry_id` INT UNSIGNED DEFAULT NULL,
    `movement_type` VARCHAR(20) NOT NULL,
    `movement_date` DATE NOT NULL,
    `document_number` VARCHAR(80) DEFAULT NULL,
    `description` VARCHAR(500) DEFAULT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `posted_at` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_inventory_movements_source_purchase_invoice` (`source_purchase_invoice_id`),
    UNIQUE KEY `uq_inventory_movements_source_sales_invoice` (`source_sales_invoice_id`),
    KEY `idx_inventory_movements_company_status_date` (`company_id`, `status`, `movement_date`, `id`),
    CONSTRAINT `fk_inventory_movements_purchase_invoice`
        FOREIGN KEY (`source_purchase_invoice_id`) REFERENCES `purchase_invoices` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_inventory_movements_sales_invoice`
        FOREIGN KEY (`source_sales_invoice_id`) REFERENCES `sales_invoices` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_inventory_movements_company`
        FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_inventory_movements_journal_entry`
        FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

CREATE TABLE IF NOT EXISTS `inventory_movement_lines` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `movement_id` INT UNSIGNED NOT NULL,
    `warehouse_id` INT UNSIGNED NOT NULL,
    `product_id` INT UNSIGNED NOT NULL,
    `quantity_in` DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    `quantity_out` DECIMAL(15,3) NOT NULL DEFAULT 0.000,
    `unit_cost` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
    `total_cost` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (`id`),
    KEY `idx_inventory_movement_lines_product_warehouse` (`product_id`, `warehouse_id`, `movement_id`),
    CONSTRAINT `fk_inventory_movement_lines_movement`
        FOREIGN KEY (`movement_id`) REFERENCES `inventory_movements` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_inventory_movement_lines_warehouse`
        FOREIGN KEY (`warehouse_id`) REFERENCES `inventory_warehouses` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_inventory_movement_lines_product`
        FOREIGN KEY (`product_id`) REFERENCES `inventory_products` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

-- Müügiarve read võivad viidata müüdud laotootele ja laole.
-- Need veerud lisatakse olemasolevale sales_invoice_lines tabelile migratsiooniga.
