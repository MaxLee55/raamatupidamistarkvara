<?php

/**
 * Kontrollib, et 1000–1099 koodiga kassa- ja pangakonto ning 1100 väärtpaberikonto
 * väljaminek ei viiks kontot rohkem miinusesse.
 * $deltasByAccountId sisaldab kontode muutust sentides (deebet miinus kreedit).
 */
function assertCashAccountsNotFurtherNegative(PDO $pdo, int $companyId, array $deltasByAccountId): void
{
    $deltas = [];
    foreach ($deltasByAccountId as $accountId => $delta) {
        $accountId = (int) $accountId;
        $delta = (int) $delta;
        if ($accountId > 0 && $delta !== 0) {
            $deltas[$accountId] = ($deltas[$accountId] ?? 0) + $delta;
        }
    }

    if (!$deltas) {
        return;
    }

    $accountIds = array_keys($deltas);
    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
    $accountsStmt = $pdo->prepare(
        "SELECT id, code, name
         FROM accounts
         WHERE company_id = ?
           AND account_type = 'asset'
           AND (code REGEXP '^10[0-9]{2}$' OR code = '1100')
           AND id IN ($placeholders)
         ORDER BY id
         FOR UPDATE"
    );
    $accountsStmt->execute(array_merge([$companyId], $accountIds));

    $balanceStmt = $pdo->prepare(
        "SELECT COALESCE(SUM(jl.debit - jl.credit), 0)
         FROM journal_lines jl
         JOIN journal_entries je ON je.id = jl.entry_id
         WHERE jl.account_id = :account_id
           AND je.company_id = :company_id
           AND je.status = 'posted'"
    );

    foreach ($accountsStmt->fetchAll(PDO::FETCH_ASSOC) as $account) {
        $accountId = (int) $account['id'];
        $delta = $deltas[$accountId] ?? 0;

        // Sissetulek on lubatud ka siis, kui see vähendab olemasolevat miinust.
        if ($delta >= 0) {
            continue;
        }

        $balanceStmt->execute([
            'account_id' => $accountId,
            'company_id' => $companyId,
        ]);
        $balanceText = (string) $balanceStmt->fetchColumn();
        $negative = isset($balanceText[0]) && $balanceText[0] === '-';
        $balanceText = ltrim($balanceText, '-');
        [$whole, $fraction] = array_pad(explode('.', $balanceText, 2), 2, '0');
        $balanceCents = ((int) $whole * 100)
            + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        if ($negative) {
            $balanceCents = -$balanceCents;
        }

        $projectedCents = $balanceCents + $delta;
        if ($projectedCents < 0) {
            throw new RuntimeException(
                'Toiming peatati: konto '
                . $account['code'] . ' — ' . $account['name']
                . ' jääks pärast väljaminekut '
                . number_format(abs($projectedCents) / 100, 2, ',', ' ')
                . ' € miinusesse.'
            );
        }
    }
}