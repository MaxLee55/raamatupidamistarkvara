<?php

function findClosedAccountingPeriod(PDO $pdo, int $companyId, string $date): ?array
{
    $stmt = $pdo->prepare(
        'SELECT action, period_start, period_end
         FROM accounting_period_events
         WHERE company_id = ?
           AND period_start <= ?
           AND period_end >= ?
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([$companyId, $date, $date]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    return $event && $event['action'] === 'closed' ? $event : null;
}

function closedAccountingPeriodMessage(PDO $pdo, int $companyId, string $date): ?string
{
    $period = findClosedAccountingPeriod($pdo, $companyId, $date);

    if (!$period) {
        return null;
    }

    $start = (new DateTimeImmutable($period['period_start']))->format('d.m.Y');
    $end = (new DateTimeImmutable($period['period_end']))->format('d.m.Y');

    return 'Periood ' . $start . ' kuni ' . $end
        . ' on suletud. Ava periood uuesti, kui pead sinna muudatuse tegema.';
}