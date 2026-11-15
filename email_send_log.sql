CREATE TABLE IF NOT EXISTS email_send_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id INT UNSIGNED NOT NULL,
    document_type VARCHAR(24) NOT NULL,
    document_id INT UNSIGNED NOT NULL,
    related_person_id INT UNSIGNED DEFAULT NULL,
    document_label VARCHAR(150) NOT NULL,
    recipient_email VARCHAR(254) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    status ENUM('pending', 'sent', 'failed') NOT NULL DEFAULT 'pending',
    error_message TEXT DEFAULT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    sent_by INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_email_log_document (company_id, document_type, document_id, related_person_id, attempted_at),
    CONSTRAINT fk_email_log_company FOREIGN KEY (company_id) REFERENCES companies (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_email_log_user FOREIGN KEY (sent_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
