<?php
declare(strict_types=1);

function emailLogStart(
    PDO $pdo,
    int $companyId,
    string $documentType,
    int $documentId,
    ?int $relatedPersonId,
    string $documentLabel,
    string $recipientEmail,
    string $subject,
    int $userId
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO email_send_log
            (company_id, document_type, document_id, related_person_id, document_label,
             recipient_email, subject, status, attempted_at, sent_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\', NOW(), ?)'
    );
    $stmt->execute([
        $companyId,
        $documentType,
        $documentId,
        $relatedPersonId,
        $documentLabel,
        $recipientEmail,
        $subject,
        $userId,
    ]);

    return (int) $pdo->lastInsertId();
}

function emailLogFinish(PDO $pdo, int $logId, string $status, ?string $error = null): void
{
    if (!in_array($status, ['sent', 'failed'], true)) {
        throw new InvalidArgumentException('Vigane e-kirja saatmise olek.');
    }

    $stmt = $pdo->prepare(
        'UPDATE email_send_log
         SET status = ?, error_message = ?, completed_at = NOW()
         WHERE id = ?'
    );
    $stmt->execute([$status, $error, $logId]);
}
