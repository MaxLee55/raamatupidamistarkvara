-- KMD tehinguandmete ettevalmistus. Veerud on valikulised ja ei muuda olemasolevaid kandeid.
ALTER TABLE accounts
    ADD COLUMN kmd_type_code VARCHAR(50) NULL;

ALTER TABLE contacts
    ADD COLUMN kmd_identifier_type VARCHAR(30) NULL,
    ADD COLUMN kmd_party_category TINYINT UNSIGNED NULL,
    ADD COLUMN country_code CHAR(2) NOT NULL DEFAULT 'EE';