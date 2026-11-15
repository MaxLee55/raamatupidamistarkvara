ALTER TABLE bank_statement_lines
    ADD COLUMN import_batch_id CHAR(32) NULL DEFAULT NULL,
    ADD COLUMN source_file_name VARCHAR(255) NULL DEFAULT NULL,
    ADD INDEX idx_bank_statement_company_import (company_id, import_batch_id);
