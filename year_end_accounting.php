<?php

function yearEndCents(string $amount): int
{
    $amount = trim($amount);
    if (!preg_match('/^-?\d+(?:\.\d{1,2})?$/', $amount)) {
        throw new RuntimeException('Kontosaldo ei ole korrektne rahasumma.');
    }

    $negative = isset($amount[0]) && $amount[0] === '-';
    $unsigned = ltrim($amount, '-');
    [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
    $cents = ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');

    return $negative ? -$cents : $cents;
}

function yearEndAmount(int $cents): string
{
    $absolute = abs($cents);
    return intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
}

function yearEndAddLine(array &$lines, array $account, int $debitCents, int $creditCents, string $description): void
{
    if ($debitCents === 0 && $creditCents === 0) {
        return;
    }

    $lines[] = [
        'account_id' => (int) $account['id'],
        'account_label' => (string) $account['code'] . ' — ' . (string) $account['name'],
        'description' => $description,
        'debit_cents' => $debitCents,
        'credit_cents' => $creditCents,
    ];
}

function yearEndLoadBalances(PDO $pdo, int $companyId, ?string $periodStart, string $periodEnd): array
{
    $dateCondition = $periodStart === null
        ? 'je.entry_date <= ?'
        : 'je.entry_date BETWEEN ? AND ?';
    $sql = 'SELECT a.id, a.code, a.name, a.account_type,
                   COALESCE(SUM(CASE WHEN je.id IS NOT NULL THEN jl.debit ELSE 0 END), 0) AS debit_total,
                   COALESCE(SUM(CASE WHEN je.id IS NOT NULL THEN jl.credit ELSE 0 END), 0) AS credit_total
            FROM accounts a
            LEFT JOIN journal_lines jl ON jl.account_id = a.id
            LEFT JOIN journal_entries je
              ON je.id = jl.entry_id
             AND je.company_id = a.company_id
             AND je.status = \'posted\'
             AND ' . $dateCondition . '
            WHERE a.company_id = ?
            GROUP BY a.id, a.code, a.name, a.account_type
            ORDER BY a.code';
    $stmt = $pdo->prepare($sql);
    $params = $periodStart === null
        ? [$periodEnd, $companyId]
        : [$periodStart, $periodEnd, $companyId];
    $stmt->execute($params);

    $balances = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['raw_cents'] = yearEndCents((string) $row['debit_total'])
            - yearEndCents((string) $row['credit_total']);
        $balances[(int) $row['id']] = $row;
    }

    return $balances;
}

function yearEndNextDocumentNumber(PDO $pdo, int $companyId, string $prefix): string
{
    for ($suffix = 1; $suffix <= 999; $suffix++) {
        $documentNumber = $prefix . '-' . str_pad((string) $suffix, 2, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare(
            'SELECT 1 FROM journal_entries WHERE company_id = ? AND document_number = ? LIMIT 1'
        );
        $stmt->execute([$companyId, $documentNumber]);
        if (!$stmt->fetchColumn()) {
            return $documentNumber;
        }
    }

    throw new RuntimeException('Aastavahetuse dokumendinumbrite järjestus sai täis.');
}

function yearEndBuildPlan(PDO $pdo, int $companyId, string $periodStart, string $periodEnd): array
{
    $periodBalances = yearEndLoadBalances($pdo, $companyId, $periodStart, $periodEnd);
    $closingLines = [];
    $profitAndLossNetDebit = 0;
    $resultAccounts = [];
    $errors = [];

    foreach ($periodBalances as $row) {
        if (!in_array($row['account_type'], ['asset', 'liability', 'equity', 'income', 'expense'], true)) {
            if ($row['raw_cents'] !== 0) {
                $errors[] = 'Kontol ' . $row['code'] . ' on tundmatu kontotüüp; sulgemiskannet ei koostatud.';
            }
            continue;
        }
        if (in_array($row['account_type'], ['income', 'expense'], true)) {
            $resultAccounts[] = $row;
            $profitAndLossNetDebit += $row['raw_cents'];
            if ($row['raw_cents'] > 0) {
                yearEndAddLine($closingLines, $row, 0, $row['raw_cents'], 'Aasta tulude ja kulude sulgemine');
            } elseif ($row['raw_cents'] < 0) {
                yearEndAddLine($closingLines, $row, -$row['raw_cents'], 0, 'Aasta tulude ja kulude sulgemine');
            }
        }
    }

    $profitAccounts = array_values(array_filter($periodBalances, function ($row) {
        return (string) $row['code'] === '3100';
    }));
    if ($profitAndLossNetDebit !== 0) {
        if (count($profitAccounts) !== 1 || $profitAccounts[0]['account_type'] !== 'equity') {
            $errors[] = 'Tulemi sulgemiseks peab kontoplaanis olema üks omakapitali konto koodiga 3100.';
        } elseif ($profitAndLossNetDebit > 0) {
            yearEndAddLine($closingLines, $profitAccounts[0], $profitAndLossNetDebit, 0, 'Aruandeaasta kahjum');
        } else {
            yearEndAddLine($closingLines, $profitAccounts[0], 0, -$profitAndLossNetDebit, 'Aruandeaasta kasum');
        }
    }

    $closingDebit = array_sum(array_column($closingLines, 'debit_cents'));
    $closingCredit = array_sum(array_column($closingLines, 'credit_cents'));
    if ($closingLines && $closingDebit !== $closingCredit) {
        $errors[] = 'Arvutatud sulgemiskande deebet ja kreedit ei klapi.';
    }

    $lifetimeBalances = yearEndLoadBalances($pdo, $companyId, null, $periodEnd);
    $openingLines = [];
    foreach ($lifetimeBalances as $row) {
        if (in_array($row['account_type'], ['income', 'expense'], true)) {
            continue;
        }
        if (!in_array($row['account_type'], ['asset', 'liability', 'equity'], true)) {
            if ($row['raw_cents'] !== 0) {
                $errors[] = 'Kontol ' . $row['code'] . ' on tundmatu kontotüüp; algkannet ei koostatud.';
            }
            continue;
        }
        $raw = $row['raw_cents'];
        if ((string) $row['code'] === '3100') {
            $raw += $profitAndLossNetDebit;
        }
        if ($raw > 0) {
            yearEndAddLine($openingLines, $row, $raw, 0, 'Uue majandusaasta algsaldo');
        } elseif ($raw < 0) {
            yearEndAddLine($openingLines, $row, 0, -$raw, 'Uue majandusaasta algsaldo');
        }
    }
    $openingDebit = array_sum(array_column($openingLines, 'debit_cents'));
    $openingCredit = array_sum(array_column($openingLines, 'credit_cents'));
    if ($openingLines && $openingDebit !== $openingCredit) {
        $errors[] = 'Lõppbilansi algsaldod ei ole tasakaalus; algkannet ei koostatud.';
    }

    $nextStart = (new DateTimeImmutable($periodEnd))->modify('+1 day')->format('Y-m-d');
    $futureStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM journal_entries
         WHERE company_id = ? AND status = 'posted' AND entry_date >= ?
           AND COALESCE(document_number, '') NOT LIKE 'AA-ALG-%'
           AND COALESCE(document_number, '') NOT LIKE 'AA-TAGASI-%'"
    );
    $futureStmt->execute([$companyId, $nextStart]);
    if ((int) $futureStmt->fetchColumn() > 0) {
        $errors[] = 'Järgmise majandusaasta kohta on juba pearaamatukandeid. Kontrolli nende algsaldosid käsitsi enne aastavahetuse kinnitamist.';
    }

    $futureInvoicesStmt = $pdo->prepare(
        'SELECT
           (SELECT COUNT(*) FROM sales_invoices WHERE company_id = ? AND invoice_date >= ?)
         + (SELECT COUNT(*) FROM purchase_invoices WHERE company_id = ? AND invoice_date >= ?)'
    );
    $futureInvoicesStmt->execute([$companyId, $nextStart, $companyId, $nextStart]);
    if ((int) $futureInvoicesStmt->fetchColumn() > 0) {
        $errors[] = 'Järgmise majandusaasta kohta on juba arveid. Automaatset algkannet ei koostatud.';
    }

    $year = (int) substr($periodStart, 0, 4);
    $closingDocument = yearEndNextDocumentNumber($pdo, $companyId, 'AA-SULG-' . $year);
    $openingDocument = yearEndNextDocumentNumber($pdo, $companyId, 'AA-ALG-' . ($year + 1));

    return [
        'closing_lines' => $closingLines,
        'opening_lines' => $openingLines,
        'closing_debit' => $closingDebit,
        'closing_credit' => $closingCredit,
        'opening_debit' => $openingDebit,
        'opening_credit' => $openingCredit,
        'result_net_debit' => $profitAndLossNetDebit,
        'closing_document' => $closingDocument,
        'opening_document' => $openingDocument,
        'opening_date' => $nextStart,
        'errors' => $errors,
    ];
}

function yearEndInsertPostedEntry(
    PDO $pdo,
    int $companyId,
    int $userId,
    string $date,
    string $description,
    string $documentNumber,
    array $lines
): ?int {
    if (!$lines) {
        return null;
    }
    $stmt = $pdo->prepare(
        "INSERT INTO journal_entries
            (company_id, entry_date, description, document_number, status, created_by, posted_at)
         VALUES (?, ?, ?, ?, 'posted', ?, CURRENT_TIMESTAMP)"
    );
    $stmt->execute([$companyId, $date, $description, $documentNumber, $userId]);
    $entryId = (int) $pdo->lastInsertId();
    $lineStmt = $pdo->prepare(
        'INSERT INTO journal_lines (entry_id, account_id, description, debit, credit)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($lines as $line) {
        $lineStmt->execute([
            $entryId,
            (int) $line['account_id'],
            $line['description'],
            yearEndAmount((int) $line['debit_cents']),
            yearEndAmount((int) $line['credit_cents']),
        ]);
    }

    return $entryId;
}
