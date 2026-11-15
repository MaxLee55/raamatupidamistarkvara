<?php
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: ../login.php');
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Palgamooduli maksude tasumise lehele on ligipääs ainult administraatoril.');
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/period_lock.php';
require_once dirname(__DIR__) . '/cash_balance_guard.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function payrollTaxCents(string $value): ?int
{
    $value = str_replace([' ', "\xc2\xa0", "\xe2\x80\xaf"], '', trim($value));
    $value = str_replace(',', '.', $value);
    if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $value)) {
        return null;
    }
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
    return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
}

function payrollTaxMoney(int $cents): string
{
    return number_format(intdiv($cents, 100), 0, ',', ' ')
        . ',' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT) . ' €';
}

function payrollTaxDecimal(int $cents): string
{
    return number_format($cents / 100, 2, '.', '');
}

$companyId = (int) $_SESSION['company_id'];
$userId = (int) $_SESSION['user_id'];
$roles = [
    'income_tax_liability_account_id' => 'Tulumaks',
    'pension_liability_account_id' => 'Kogumispension (II sammas)',
    'employee_unemployment_liability_account_id' => 'Töötaja töötuskindlustusmakse',
    'employer_unemployment_liability_account_id' => 'Tööandja töötuskindlustusmakse',
    'social_tax_liability_account_id' => 'Sotsiaalmaks',
];
$errors = [];
$notice = $_SESSION['payroll_tax_payment_notice'] ?? '';
unset($_SESSION['payroll_tax_payment_notice']);
$selectedId = filter_input(INPUT_GET, 'bank_line_id', FILTER_VALIDATE_INT) ?: 0;
$formAmounts = array_fill_keys(array_keys($roles), '0,00');

$settingsStmt = $pdo->prepare('SELECT * FROM payroll_account_settings WHERE company_id = ?');
$settingsStmt->execute([$companyId]);
$settings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: null;
$configuredAccounts = [];
if ($settings) {
    $ids = array_values(array_unique(array_map(static fn($key) => (int) $settings[$key], array_keys($roles))));
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $accountStmt = $pdo->prepare(
        "SELECT id, code, name, account_type, is_active FROM accounts
         WHERE company_id = ? AND id IN ($marks)"
    );
    $accountStmt->execute(array_merge([$companyId], $ids));
    foreach ($accountStmt->fetchAll(PDO::FETCH_ASSOC) as $account) {
        $configuredAccounts[(int) $account['id']] = $account;
    }
}

$selectedBankRow = null;
$balancesByAccount = [];
$lineIdsByRole = [];
if ($selectedId > 0) {
    $selectedStmt = $pdo->prepare(
        "SELECT bsl.id, bsl.transaction_date, bsl.debit_credit, bsl.amount,
                bsl.service_fee, bsl.currency, bsl.description, bsl.counterparty_name,
                bsl.matched_journal_line_id, bsl.draft_journal_entry_id,
                bsl.bank_account_id, a.code AS bank_code, a.name AS bank_name
         FROM bank_statement_lines bsl
         JOIN accounts a ON a.id = bsl.bank_account_id AND a.company_id = bsl.company_id
         WHERE bsl.id = ? AND bsl.company_id = ?"
    );
    $selectedStmt->execute([$selectedId, $companyId]);
    $selectedBankRow = $selectedStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$selectedBankRow) {
        $errors[] = 'Valitud pangatehingut ei leitud.';
    } elseif ($selectedBankRow['debit_credit'] !== 'D'
        || !empty($selectedBankRow['matched_journal_line_id'])
        || !empty($selectedBankRow['draft_journal_entry_id'])) {
        $errors[] = 'Vali sidumata pangast väljamineku rida, millel pole veel kande mustandit.';
    } elseif ((int) $selectedBankRow['bank_account_id'] < 1
        || !preg_match('/^10\d{2}$/', (string) $selectedBankRow['bank_code'])) {
        $errors[] = 'Valitud rida ei ole aktiivselt pangakontolt 1000–1099.';
    } elseif (strtoupper((string) $selectedBankRow['currency']) !== 'EUR') {
        $errors[] = 'Selle vormiga saab registreerida ainult eurodes tasutud makse.';
    } elseif ((int) round((float) $selectedBankRow['service_fee'] * 100) !== 0) {
        $errors[] = 'Pangatehingul on teenustasu. Registreeri teenustasu eraldi pangatehinguna.';
    }

    if ($settings && $selectedBankRow && !$errors) {
        foreach ($roles as $field => $_label) {
            $accountId = (int) $settings[$field];
            $account = $configuredAccounts[$accountId] ?? null;
            if (!$account || $account['account_type'] !== 'liability' || (int) $account['is_active'] !== 1) {
                $errors[] = 'Palgakontode seadistuses on passiivne või vale tüüpi kohustuste konto.';
                break;
            }
            $lineIdsByRole[$field] = $accountId;
        }
        if (!$errors) {
            foreach (array_unique(array_values($lineIdsByRole)) as $accountId) {
                $balanceStmt = $pdo->prepare(
                    'SELECT COALESCE(SUM(jl.credit - jl.debit), 0)
                     FROM journal_lines jl
                     JOIN journal_entries je ON je.id = jl.entry_id
                     WHERE jl.account_id = ? AND je.company_id = ?
                       AND je.status = \'posted\' AND je.entry_date <= ?'
                );
                $balanceStmt->execute([$accountId, $companyId, $selectedBankRow['transaction_date']]);
                $balance = payrollTaxCents((string) number_format((float) $balanceStmt->fetchColumn(), 2, '.', ''));
                $balancesByAccount[(int) $accountId] = max(0, $balance ?? 0);
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedId = filter_var($_POST['bank_line_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
    foreach ($roles as $field => $_label) {
        $formAmounts[$field] = trim((string) ($_POST[$field] ?? '0,00'));
    }
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    }
    if (!$selectedId) {
        $errors[] = 'Vali pangatehing.';
    }
    if (!$settings) {
        $errors[] = 'Palgakontode seadistus puudub. Määra esmalt palgamaksude kohustuste kontod.';
    }
    $amountsByRole = [];
    foreach ($roles as $field => $label) {
        $amountCents = payrollTaxCents($formAmounts[$field]);
        if ($amountCents === null) {
            $errors[] = $label . ': sisesta kehtiv summa.';
        } else {
            $amountsByRole[$field] = $amountCents;
        }
    }
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $bankStmt = $pdo->prepare(
                'SELECT bsl.*, a.code AS bank_code, a.name AS bank_name
                 FROM bank_statement_lines bsl
                 JOIN accounts a ON a.id = bsl.bank_account_id AND a.company_id = bsl.company_id
                 WHERE bsl.id = ? AND bsl.company_id = ? FOR UPDATE'
            );
            $bankStmt->execute([$selectedId, $companyId]);
            $bankRow = $bankStmt->fetch(PDO::FETCH_ASSOC);
            if (!$bankRow || $bankRow['debit_credit'] !== 'D'
                || $bankRow['matched_journal_line_id'] !== null
                || $bankRow['draft_journal_entry_id'] !== null) {
                throw new RuntimeException('Pangatehing on puudu, juba seotud või sellel on kande mustand. Värskenda lehte.');
            }
            if (!preg_match('/^10\d{2}$/', (string) $bankRow['bank_code'])
                || strtoupper((string) $bankRow['currency']) !== 'EUR'
                || (int) round((float) $bankRow['service_fee'] * 100) !== 0) {
                throw new RuntimeException('Valitud rida ei sobi palgamaksude tasumiseks. Kontrolli pangakontot, valuutat ja teenustasu.');
            }

            $totalCents = payrollTaxCents((string) $bankRow['amount']);
            if ($totalCents === null || $totalCents <= 0) {
                throw new RuntimeException('Pangarea summa on vigane.');
            }
            if (array_sum($amountsByRole) !== $totalCents) {
                throw new RuntimeException('Maksuliikide summad peavad kokku võrduma pangatehingu summaga ' . payrollTaxMoney($totalCents) . '.');
            }

            $groupedAmounts = [];
            foreach ($amountsByRole as $field => $amountCents) {
                $accountId = (int) $settings[$field];
                $account = $configuredAccounts[$accountId] ?? null;
                if (!$account || $account['account_type'] !== 'liability' || (int) $account['is_active'] !== 1) {
                    throw new RuntimeException('Palgakontode seadistuses on vale või passiivne maksukohustuse konto.');
                }
                if ($amountCents > 0) {
                    $groupedAmounts[$accountId]['amount'] = ($groupedAmounts[$accountId]['amount'] ?? 0) + $amountCents;
                    $groupedAmounts[$accountId]['labels'][] = $roles[$field];
                }
            }
            foreach ($groupedAmounts as $accountId => $group) {
                $balanceStmt = $pdo->prepare(
                    'SELECT COALESCE(SUM(jl.credit - jl.debit), 0)
                     FROM journal_lines jl
                     JOIN journal_entries je ON je.id = jl.entry_id
                     WHERE jl.account_id = ? AND je.company_id = ?
                       AND je.status = \'posted\' AND je.entry_date <= ?'
                );
                $balanceStmt->execute([$accountId, $companyId, $bankRow['transaction_date']]);
                $balanceCents = payrollTaxCents((string) number_format((float) $balanceStmt->fetchColumn(), 2, '.', '')) ?? 0;
                if ($group['amount'] > max(0, $balanceCents)) {
                    $account = $configuredAccounts[(int) $accountId];
                    throw new RuntimeException(
                        'Kontol ' . $account['code'] . ' — ' . $account['name']
                        . ' pole seisuga ' . $bankRow['transaction_date'] . ' piisavalt tasumata saldot.'
                    );
                }
            }

            $periodMessage = closedAccountingPeriodMessage($pdo, $companyId, (string) $bankRow['transaction_date']);
            if ($periodMessage !== null) {
                throw new RuntimeException($periodMessage);
            }
            $bankAccountId = (int) $bankRow['bank_account_id'];
            assertCashAccountsNotFurtherNegative($pdo, $companyId, [$bankAccountId => -$totalCents]);

            $counterparty = trim((string) ($bankRow['counterparty_name'] ?? ''));
            $description = 'Palgamaksude tasumine' . ($counterparty !== '' ? ' — ' . $counterparty : '');
            if (!empty($bankRow['description'])) {
                $description .= ' — ' . (string) $bankRow['description'];
            }
            $entryStmt = $pdo->prepare(
                "INSERT INTO journal_entries
                    (company_id, entry_date, description, document_number, status, created_by, posted_at)
                 VALUES (?, ?, ?, ?, 'posted', ?, NOW())"
            );
            $entryStmt->execute([
                $companyId,
                $bankRow['transaction_date'],
                mb_substr($description, 0, 500),
                'MTA-' . $selectedId,
                $userId,
            ]);
            $entryId = (int) $pdo->lastInsertId();
            $lineStmt = $pdo->prepare(
                'INSERT INTO journal_lines (entry_id, account_id, description, debit, credit)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($groupedAmounts as $accountId => $group) {
                $lineStmt->execute([
                    $entryId,
                    (int) $accountId,
                    'Tasuti: ' . implode(' + ', array_unique($group['labels'])),
                    payrollTaxDecimal($group['amount']),
                    '0.00',
                ]);
            }
            $lineStmt->execute([
                $entryId,
                $bankAccountId,
                'MTA-le tasutud palgamaksud',
                '0.00',
                payrollTaxDecimal($totalCents),
            ]);
            $bankLineStmt = $pdo->prepare(
                'SELECT id FROM journal_lines WHERE entry_id = ? AND account_id = ? AND debit = 0 AND credit = ?'
            );
            $bankLineStmt->execute([$entryId, $bankAccountId, payrollTaxDecimal($totalCents)]);
            $bankJournalLineId = (int) $bankLineStmt->fetchColumn();
            if (!$bankJournalLineId) {
                throw new RuntimeException('Pangakonto pearaamatu rea leidmine ebaõnnestus.');
            }
            $linkStmt = $pdo->prepare(
                'UPDATE bank_statement_lines
                 SET matched_journal_line_id = ?
                 WHERE id = ? AND company_id = ?
                   AND matched_journal_line_id IS NULL AND draft_journal_entry_id IS NULL'
            );
            $linkStmt->execute([$bankJournalLineId, $selectedId, $companyId]);
            if ($linkStmt->rowCount() !== 1) {
                throw new RuntimeException('Pangarea sidumine ebaõnnestus. Tehing tühistati.');
            }
            $pdo->commit();
            $_SESSION['payroll_tax_payment_notice'] = 'MTA makse registreeriti pearaamatus kande nr ' . $entryId . ' all ning seoti pangareaga.';
            header('Location: tax-payment.php?posted=' . $entryId);
            exit;
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Palgamaksude tasumise registreerimine ebaõnnestus: ' . $e->getMessage());
            $errors[] = 'Maksude tasumise kanne ebaõnnestus. Andmeid ei salvestatud.';
        }
    }
}

$unmatchedStmt = $pdo->prepare(
    "SELECT bsl.id, bsl.transaction_date, bsl.amount, bsl.currency,
            bsl.counterparty_name, bsl.description, a.code AS bank_code, a.name AS bank_name
     FROM bank_statement_lines bsl
     JOIN accounts a ON a.id = bsl.bank_account_id AND a.company_id = bsl.company_id
     WHERE bsl.company_id = ? AND bsl.debit_credit = 'D'
       AND bsl.matched_journal_line_id IS NULL AND bsl.draft_journal_entry_id IS NULL
       AND a.account_type = 'asset' AND a.code REGEXP '^10[0-9]{2}$'
     ORDER BY bsl.transaction_date DESC, bsl.id DESC
     LIMIT 200"
);
$unmatchedStmt->execute([$companyId]);
$unmatchedRows = $unmatchedStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Palgamaksude tasumine | Palgamoodul</title>
    <style>
        *{box-sizing:border-box}body{margin:0;padding:28px;background:#f4f7fb;color:#243447;font-family:Arial,sans-serif}main{max-width:1150px;margin:0 auto}header,.panel{margin-bottom:20px;padding:22px;background:#fff;border:1px solid #dce3ec;border-radius:12px}header{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}h1,h2{margin-top:0;color:#17324d}.muted{color:#68798b;line-height:1.5}.message{padding:12px 14px;border-radius:8px}.error{background:#fdecec;color:#8d2222}.success{background:#e9f6ed;color:#205c31}.warning{background:#fff5dc;color:#72530b}.button,button{display:inline-block;min-height:40px;padding:10px 14px;border:0;border-radius:7px;background:#1769aa;color:#fff;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}.secondary{background:#657789}.fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}label{display:grid;gap:6px;font-weight:600}input{width:100%;min-height:42px;padding:9px 11px;border:1px solid #b8c4d1;border-radius:7px;font:inherit;text-align:right}table{width:100%;border-collapse:collapse}th,td{padding:10px 8px;border-bottom:1px solid #e2e8ef;text-align:left;vertical-align:top}th{color:#526579;font-size:13px}.right{text-align:right}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.select-link{white-space:nowrap}@media(max-width:760px){body{padding:14px}.fields{grid-template-columns:1fr}.table-wrap{overflow-x:auto}table{min-width:760px}}
    </style>
</head>
<body>
<main>
    <header><div><h1>Palgamaksude tasumine</h1><p class="muted">Vali pangaväljavõttelt MTA-le tehtud makse ja jaota see palgamaksude kohustuste vahel. Programm loob tasakaalus kande ning seob selle pangareaga.</p></div><div class="actions"><a class="button secondary" href="runs.php">Tagasi palgaperioodide juurde</a><a class="button secondary" href="../bank_statement.php">Pangaväljavõte</a></div></header>
    <?php if ($notice !== ''): ?><p class="message success"><?= h($notice) ?></p><?php endif; ?>
    <?php if ($errors): ?><div class="message error"><ul><?php foreach (array_unique($errors) as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if (!$settings): ?><p class="message warning">Palgakontode seadistus puudub. <a href="accounting-settings.php">Ava palgakontode seadistus</a> ja määra kohustuste kontod.</p><?php endif; ?>
    <section class="panel"><h2>Sidumata pangast väljaminekud</h2>
        <?php if (!$unmatchedRows): ?><p class="muted">Sidumata pangast väljaminekuid ei leitud. Impordi esmalt pangaväljavõte või kontrolli sidumata ridade filtrit.</p>
        <?php else: ?><div class="table-wrap"><table><thead><tr><th>Kuupäev</th><th>Pangakonto</th><th>Saaja</th><th>Selgitus</th><th class="right">Summa</th><th></th></tr></thead><tbody>
            <?php foreach ($unmatchedRows as $row): ?><tr><td><?= h($row['transaction_date']) ?></td><td><?= h($row['bank_code'] . ' — ' . $row['bank_name']) ?></td><td><?= h($row['counterparty_name'] ?? '') ?></td><td><?= h($row['description'] ?? '') ?></td><td class="right"><?= h(number_format((float) $row['amount'], 2, ',', ' ')) ?> <?= h($row['currency']) ?></td><td><a class="button select-link" href="tax-payment.php?bank_line_id=<?= (int) $row['id'] ?>">Vali see makse</a></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </section>
    <?php if ($selectedBankRow && !$errors && $settings): ?>
    <section class="panel">
        <h2>Jaota MTA makse maksuliikide vahel</h2>
        <p><strong><?= h($selectedBankRow['transaction_date']) ?> · <?= h($selectedBankRow['bank_code'] . ' — ' . $selectedBankRow['bank_name']) ?> · <?= h($selectedBankRow['counterparty_name'] ?? 'Maksesaaja') ?></strong></p>
        <p class="muted"><?= h($selectedBankRow['description'] ?? '') ?><br>Pangast välja: <strong><?= h(number_format((float) $selectedBankRow['amount'], 2, ',', ' ')) ?> €</strong></p>
        <p class="message warning">Sisesta iga maksuliigi tegelikult tasutud osa. Jaotuse summa peab võrduma pangatehinguga ning ei tohi ületada vastava kohustuse pearaamatus olevat saldot. Kui MTA makse sisaldab muid makse või tasaarvestusi, kasuta tavapärast kande koostamist.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="bank_line_id" value="<?= (int) $selectedBankRow['id'] ?>">
            <div class="fields">
                <?php foreach ($roles as $field => $label): $accountId = (int) $settings[$field]; $account = $configuredAccounts[$accountId] ?? null; ?>
                    <label><?= h($label . ' · ' . ($account ? $account['code'] . ' — ' . $account['name'] : 'konto puudub')) ?>
                        <input name="<?= h($field) ?>" inputmode="decimal" value="<?= h($formAmounts[$field]) ?>" required>
                        <span class="muted">Pearaamatu kohustuse saldo: <?= h(payrollTaxMoney($balancesByAccount[$accountId] ?? 0)) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="actions"><button type="submit" onclick="return confirm('Kas registreerid selle pangamakse kinnitatud pearaamatukandena ja seod pangareaga?')">Registreeri maksude tasumine</button></div>
        </form>
    </section>
    <?php endif; ?>
</main>
</body>
</html>
