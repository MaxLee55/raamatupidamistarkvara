<?php

/** Persist company-wide fallback ledger accounts for inventory accounting. */
function ensureInventoryAccountSettings(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS inventory_account_settings (
            company_id INT UNSIGNED NOT NULL,
            inventory_account_id INT UNSIGNED DEFAULT NULL,
            cogs_account_id INT UNSIGNED DEFAULT NULL,
            inventory_gain_account_id INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (company_id),
            KEY idx_inventory_account_settings_inventory_account (inventory_account_id),
            KEY idx_inventory_account_settings_cogs_account (cogs_account_id),
            KEY idx_inventory_account_settings_gain_account (inventory_gain_account_id),
            CONSTRAINT fk_inventory_account_settings_company
                FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_inventory_account_settings_inventory_account
                FOREIGN KEY (inventory_account_id) REFERENCES accounts (id) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_inventory_account_settings_cogs_account
                FOREIGN KEY (cogs_account_id) REFERENCES accounts (id) ON DELETE RESTRICT ON UPDATE CASCADE,
            CONSTRAINT fk_inventory_account_settings_gain_account
                FOREIGN KEY (inventory_gain_account_id) REFERENCES accounts (id) ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_estonian_ci'
    );
}

function loadInventoryAccountDefaults(PDO $pdo, int $companyId): array
{
    ensureInventoryAccountSettings($pdo);
    $stmt = $pdo->prepare(
        'SELECT inventory_account_id, cogs_account_id, inventory_gain_account_id
         FROM inventory_account_settings WHERE company_id = ?'
    );
    $stmt->execute([$companyId]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        $settings = [];
        foreach (['inventory_account_id', 'cogs_account_id', 'inventory_gain_account_id'] as $field) {
            $legacyStmt = $pdo->prepare(
                "SELECT `$field` FROM inventory_products
                 WHERE company_id = ? AND `$field` IS NOT NULL
                 GROUP BY `$field` ORDER BY COUNT(*) DESC LIMIT 1"
            );
            $legacyStmt->execute([$companyId]);
            $settings[$field] = $legacyStmt->fetchColumn() ?: null;
        }
        $saveStmt = $pdo->prepare(
            'INSERT INTO inventory_account_settings
                (company_id, inventory_account_id, cogs_account_id, inventory_gain_account_id)
             VALUES (?, ?, ?, ?)'
        );
        $saveStmt->execute([
            $companyId,
            $settings['inventory_account_id'],
            $settings['cogs_account_id'],
            $settings['inventory_gain_account_id'],
        ]);
    }

    $accountStmt = $pdo->prepare(
        'SELECT id, account_type FROM accounts WHERE company_id = ? AND is_active = 1'
    );
    $accountStmt->execute([$companyId]);
    $accountTypes = [];
    foreach ($accountStmt->fetchAll(PDO::FETCH_ASSOC) as $account) {
        $accountTypes[(int) $account['id']] = (string) $account['account_type'];
    }
    foreach ([
        'inventory_account_id' => 'asset',
        'cogs_account_id' => 'expense',
        'inventory_gain_account_id' => 'income',
    ] as $field => $expectedType) {
        $accountId = (int) ($settings[$field] ?? 0);
        $settings[$field] = $accountId > 0 && ($accountTypes[$accountId] ?? '') === $expectedType
            ? (string) $accountId
            : '';
    }

    return $settings;
}

function saveInventoryAccountDefaults(PDO $pdo, int $companyId, array $accounts): void
{
    ensureInventoryAccountSettings($pdo);
    $values = [];
    foreach (['inventory_account_id', 'cogs_account_id', 'inventory_gain_account_id'] as $field) {
        $value = trim((string) ($accounts[$field] ?? ''));
        $values[] = $value !== '' ? (int) $value : null;
    }
    if (!array_filter($values, static fn($value): bool => $value !== null)) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO inventory_account_settings
            (company_id, inventory_account_id, cogs_account_id, inventory_gain_account_id)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            inventory_account_id = COALESCE(VALUES(inventory_account_id), inventory_account_id),
            cogs_account_id = COALESCE(VALUES(cogs_account_id), cogs_account_id),
            inventory_gain_account_id = COALESCE(VALUES(inventory_gain_account_id), inventory_gain_account_id),
            updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute(array_merge([$companyId], $values));
}

/** Explicitly migrate account links off product cards before those cards are cleared. */
function preserveInventoryAccountDefaultsBeforeClear(PDO $pdo, int $companyId): void
{
    // The caller ensures this table before opening the transaction. Do not run
    // DDL here: CREATE TABLE can implicitly commit the caller's transaction.
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO inventory_account_settings (company_id) VALUES (?)'
    );
    $insert->execute([$companyId]);

    foreach (['inventory_account_id', 'cogs_account_id', 'inventory_gain_account_id'] as $field) {
        $source = $pdo->prepare(
            "SELECT `$field` FROM inventory_products
             WHERE company_id = ? AND `$field` IS NOT NULL
             GROUP BY `$field` ORDER BY COUNT(*) DESC LIMIT 1"
        );
        $source->execute([$companyId]);
        $accountId = $source->fetchColumn();
        if ($accountId === false || (int) $accountId < 1) {
            continue;
        }

        $save = $pdo->prepare(
            "UPDATE inventory_account_settings
             SET `$field` = COALESCE(`$field`, ?), updated_at = CURRENT_TIMESTAMP
             WHERE company_id = ?"
        );
        $save->execute([(int) $accountId, $companyId]);
    }
}
