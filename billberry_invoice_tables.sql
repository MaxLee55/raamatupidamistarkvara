CREATE TABLE IF NOT EXISTS billberry_sent_invoices (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    sales_invoice_id INT UNSIGNED NOT NULL,
    billberry_invoice_id BIGINT UNSIGNED DEFAULT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    response_json LONGTEXT DEFAULT NULL,
    UNIQUE KEY uq_billberry_sent_invoice (company_id, sales_invoice_id),
    CONSTRAINT fk_billberry_sent_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE,
    CONSTRAINT fk_billberry_sent_invoice FOREIGN KEY (sales_invoice_id) REFERENCES sales_invoices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

CREATE TABLE IF NOT EXISTS billberry_inbox (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    billberry_invoice_id BIGINT UNSIGNED NOT NULL,
    invoice_number VARCHAR(100) DEFAULT NULL,
    invoice_date DATE DEFAULT NULL,
    due_date DATE DEFAULT NULL,
    sender_name VARCHAR(200) DEFAULT NULL,
    sender_registry_code VARCHAR(20) DEFAULT NULL,
    received_at DATETIME DEFAULT NULL,
    purchase_invoice_id INT UNSIGNED DEFAULT NULL,
    metadata_json LONGTEXT NOT NULL,
    invoice_xml LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_billberry_inbox_remote (company_id, billberry_invoice_id),
    KEY ix_billberry_inbox_date (company_id, invoice_date),
    CONSTRAINT fk_billberry_inbox_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci;

ALTER TABLE billberry_inbox
    ADD CONSTRAINT fk_billberry_inbox_purchase_invoice
    FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices (id) ON DELETE SET NULL;
