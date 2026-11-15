<?php

/**
 * Generates the next company-specific bank document number.
 * Call only inside an active transaction.
 */
function nextBankDocumentNumber(PDO $pdo, int $companyId): string
{
    // Serialize number allocation for the company so simultaneous imports
    // cannot receive the same number.
    $companyLock = $pdo->prepare('SELECT id FROM companies WHERE id = ? FOR UPDATE');
    $companyLock->execute([$companyId]);
    if (!$companyLock->fetchColumn()) {
        throw new RuntimeException('Ettevõtet ei leitud pangakande numbri loomiseks.');
    }

    $numberStmt = $pdo->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING(document_number, 6) AS UNSIGNED)), 0)
         FROM journal_entries
         WHERE company_id = ?
           AND document_number REGEXP '^Pank-[0-9]+$'"
    );
    $numberStmt->execute([$companyId]);

    return 'Pank-' . ((int) $numberStmt->fetchColumn() + 1);
}
