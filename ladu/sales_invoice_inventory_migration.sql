-- Käivita üks kord olemasolevas raamatupidamise andmebaasis.
-- Seob müügiarvete read laotoote ja laoga ning lao väljamineku müügiarvega.

ALTER TABLE `sales_invoice_lines`
    ADD COLUMN `product_id` INT UNSIGNED DEFAULT NULL AFTER `gross_amount`,
    ADD COLUMN `warehouse_id` INT UNSIGNED DEFAULT NULL AFTER `product_id`,
    ADD KEY `idx_sales_invoice_lines_product` (`product_id`),
    ADD KEY `idx_sales_invoice_lines_warehouse` (`warehouse_id`),
    ADD CONSTRAINT `fk_sales_invoice_lines_inventory_product`
        FOREIGN KEY (`product_id`) REFERENCES `inventory_products` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_sales_invoice_lines_inventory_warehouse`
        FOREIGN KEY (`warehouse_id`) REFERENCES `inventory_warehouses` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `inventory_movements`
    ADD COLUMN `source_sales_invoice_id` INT UNSIGNED DEFAULT NULL AFTER `source_purchase_invoice_id`,
    ADD UNIQUE KEY `uq_inventory_movements_source_sales_invoice` (`source_sales_invoice_id`),
    ADD CONSTRAINT `fk_inventory_movements_sales_invoice`
        FOREIGN KEY (`source_sales_invoice_id`) REFERENCES `sales_invoices` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE;
