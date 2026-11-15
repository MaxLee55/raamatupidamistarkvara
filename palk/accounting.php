<?php
require_once dirname(__DIR__) . '/period_lock.php';
require_once dirname(__DIR__) . '/cash_balance_guard.php';

function payrollAccountingCents(string $amount): int
{
    if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $amount)) {
        throw new RuntimeException('Palgaarvestuses leidus vigane rahasumma.');
    }
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
    return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
}

function payrollCalculationAmounts(array $row): array
{
    $fields = [
        'gross_amount', 'pension_amount', 'employee_unemployment_amount',
        'income_tax_amount', 'net_amount', 'employer_unemployment_amount', 'social_tax_amount',
    ];
    $amounts = [];
    foreach ($fields as $field) {
        $amounts[$field] = payrollAccountingCents((string) $row[$field]);
    }
    return $amounts;
}

/**
 * Loob väljamakstud palgale tasakaalus kinnitatud kande või kontrollib, et olemasoleva kande arvutus ei muutunud.
 * Kutsuja peab enne selle funktsiooni käivitamist kasutama andmebaasitransaktsiooni.
 */
function payrollCreatePaymentJournal(PDO $pdo, int $companyId, int $userId, int $runId, int $runItemId, int $calculationId): int
{
    $paymentStmt = $pdo->prepare(
        'SELECT id, actual_payment_date, bank_account_id, journal_entry_id, journal_calculation_id
         FROM payroll_run_item_payments
         WHERE company_id = ? AND run_id = ? AND run_item_id = ?
         FOR UPDATE'
    );
    $paymentStmt->execute([$companyId, $runId, $runItemId]);
    $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
    if (!$payment) {
        throw new RuntimeException('Enne palgakande loomist märgi tegelik väljamakse makstuks.');
    }
    if (empty($payment['bank_account_id'])) {
        throw new RuntimeException('Vali sellele väljamaksele pangakonto.');
    }

    $calcStmt = $pdo->prepare(
        'SELECT c.id, c.calculation_date, c.gross_amount, c.pension_amount,
                c.employee_unemployment_amount, c.income_tax_amount, c.net_amount,
                c.employer_unemployment_amount, c.social_tax_amount,
                e.first_name, e.last_name, r.work_month, pri.gross_amount AS item_gross
         FROM payroll_run_item_calculations c
         JOIN payroll_run_items pri
           ON pri.id = c.run_item_id AND pri.company_id = c.company_id AND pri.run_id = c.run_id
         JOIN payroll_employments pe
           ON pe.id = pri.employment_id AND pe.company_id = pri.company_id
         JOIN payroll_employees e
           ON e.id = pe.employee_id AND e.company_id = pe.company_id
         JOIN payroll_runs r ON r.id = c.run_id AND r.company_id = c.company_id
         WHERE c.id = ? AND c.company_id = ? AND c.run_id = ? AND c.run_item_id = ?'
    );
    $calcStmt->execute([$calculationId, $companyId, $runId, $runItemId]);
    $calc = $calcStmt->fetch(PDO::FETCH_ASSOC);
    if (!$calc) {
        throw new RuntimeException('Salvestatud palgaarvutust ei leitud. Arvuta palk uuesti.');
    }
    if ((string) $calc['calculation_date'] !== (string) $payment['actual_payment_date']) {
        throw new RuntimeException('Arvutuse kuupäev ja tegelik väljamakse kuupäev ei klapi. Arvuta netopalk uuesti pärast maksekuupäeva märkimist.');
    }

    $amounts = payrollCalculationAmounts($calc);
    $itemGross = payrollAccountingCents((string) $calc['item_gross']);
    if ($amounts['gross_amount'] !== $itemGross) {
        throw new RuntimeException('Palgaarvutuse bruto ei klapi kinnitatud palgarea summaga. Kannet ei loodud.');
    }
    $employeeDeductions = $amounts['pension_amount']
        + $amounts['employee_unemployment_amount'] + $amounts['income_tax_amount'];
    if ($amounts['gross_amount'] <= 0 || $amounts['net_amount'] <= 0
        || $amounts['net_amount'] + $employeeDeductions !== $amounts['gross_amount']) {
        throw new RuntimeException('Palgaarvestuse bruto, kinnipidamised ja netosumma ei klapi. Kannet ei loodud.');
    }

    if (!empty($payment['journal_entry_id'])) {
        if (empty($payment['journal_calculation_id'])) {
            throw new RuntimeException('Olemasoleva palgakande arvutusviide puudub; kontrolli kanne käsitsi.');
        }
        $originalCalcStmt = $pdo->prepare(
            'SELECT gross_amount, pension_amount, employee_unemployment_amount, income_tax_amount,
                    net_amount, employer_unemployment_amount, social_tax_amount
             FROM payroll_run_item_calculations WHERE id = ? AND company_id = ? AND run_id = ? AND run_item_id = ?'
        );
        $originalCalcStmt->execute([
            (int) $payment['journal_calculation_id'], $companyId, $runId, $runItemId,
        ]);
        $originalCalc = $originalCalcStmt->fetch(PDO::FETCH_ASSOC);
        if (!$originalCalc || payrollCalculationAmounts($originalCalc) !== $amounts) {
            throw new RuntimeException('Selle palga pearaamatukanne on juba loodud, kuid uus arvutus erineb. Kinnitamiseks koosta paranduskanded eraldi.');
        }
        return (int) $payment['journal_entry_id'];
    }

    $settingsStmt = $pdo->prepare('SELECT * FROM payroll_account_settings WHERE company_id = ?');
    $settingsStmt->execute([$companyId]);
    $settings = $settingsStmt->fetch(PDO::FETCH_ASSOC);
    if (!$settings) {
        throw new RuntimeException('Enne palgakande loomist määra kontod Palgakontode seadistuses.');
    }

    $accountRoles = [
        'wages_expense_account_id' => 'expense',
        'employer_social_tax_expense_account_id' => 'expense',
        'employer_unemployment_expense_account_id' => 'expense',
        'income_tax_liability_account_id' => 'liability',
        'pension_liability_account_id' => 'liability',
        'employee_unemployment_liability_account_id' => 'liability',
        'employer_unemployment_liability_account_id' => 'liability',
        'social_tax_liability_account_id' => 'liability',
    ];
    $accountIds = [(int) $payment['bank_account_id']];
    foreach ($accountRoles as $field => $_type) {
        $accountIds[] = (int) $settings[$field];
    }
    $accountIds = array_values(array_unique($accountIds));
    $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
    $accountStmt = $pdo->prepare(
        "SELECT id, code, name, account_type, is_active FROM accounts
         WHERE company_id = ? AND id IN ($placeholders) FOR UPDATE"
    );
    $accountStmt->execute(array_merge([$companyId], $accountIds));
    $accounts = [];
    foreach ($accountStmt->fetchAll(PDO::FETCH_ASSOC) as $account) {
        $accounts[(int) $account['id']] = $account;
    }

    $bankId = (int) $payment['bank_account_id'];
    $bank = $accounts[$bankId] ?? null;
    if (!$bank || (int) $bank['is_active'] !== 1 || $bank['account_type'] !== 'asset'
        || !preg_match('/^10\d{2}$/', (string) $bank['code'])) {
        throw new RuntimeException('Valitud väljamakse konto peab olema ettevõtte aktiivne panga- või kassakonto (1000–1099).');
    }
    foreach ($accountRoles as $field => $expectedType) {
        $account = $accounts[(int) $settings[$field]] ?? null;
        if (!$account || (int) $account['is_active'] !== 1 || $account['account_type'] !== $expectedType) {
            throw new RuntimeException('Palgakontode seadistuses on passiivne või vale tüüpi konto. Kontrolli seadistust.');
        }
    }

    $entryDate = (string) $payment['actual_payment_date'];
    $periodMessage = closedAccountingPeriodMessage($pdo, $companyId, $entryDate);
    if ($periodMessage !== null) {
        throw new RuntimeException($periodMessage);
    }
    assertCashAccountsNotFurtherNegative($pdo, $companyId, [$bankId => -$amounts['net_amount']]);

    $debits = [];
    $credits = [];
    $addLine = static function (array &$lines, int $accountId, int $cents, string $description): void {
        if ($cents <= 0) {
            return;
        }
        if (!isset($lines[$accountId])) {
            $lines[$accountId] = ['amount' => 0, 'descriptions' => []];
        }
        $lines[$accountId]['amount'] += $cents;
        $lines[$accountId]['descriptions'][] = $description;
    };
    $addLine($debits, (int) $settings['wages_expense_account_id'], $amounts['gross_amount'], 'Töötasu brutokulu');
    $addLine($debits, (int) $settings['employer_social_tax_expense_account_id'], $amounts['social_tax_amount'], 'Tööandja sotsiaalmaksukulu');
    $addLine($debits, (int) $settings['employer_unemployment_expense_account_id'], $amounts['employer_unemployment_amount'], 'Tööandja töötuskindlustusmakse kulu');
    $addLine($credits, $bankId, $amounts['net_amount'], 'Töötajale tegelikult makstud netopalk');
    $addLine($credits, (int) $settings['income_tax_liability_account_id'], $amounts['income_tax_amount'], 'Kinnipeetud tulumaks');
    $addLine($credits, (int) $settings['pension_liability_account_id'], $amounts['pension_amount'], 'II samba makse');
    $addLine($credits, (int) $settings['employee_unemployment_liability_account_id'], $amounts['employee_unemployment_amount'], 'Töötaja töötuskindlustusmakse');
    $addLine($credits, (int) $settings['employer_unemployment_liability_account_id'], $amounts['employer_unemployment_amount'], 'Tööandja töötuskindlustusmakse');
    $addLine($credits, (int) $settings['social_tax_liability_account_id'], $amounts['social_tax_amount'], 'Sotsiaalmaks');

    $debitTotal = array_sum(array_column($debits, 'amount'));
    $creditTotal = array_sum(array_column($credits, 'amount'));
    if ($debitTotal <= 0 || $debitTotal !== $creditTotal || count($debits) + count($credits) < 2) {
        throw new RuntimeException('Palgakande deebet ja kreedit ei klapi; kannet ei loodud.');
    }

    $employeeName = trim((string) $calc['first_name'] . ' ' . (string) $calc['last_name']);
    $workMonth = substr((string) $calc['work_month'], 0, 7);
    $entryStmt = $pdo->prepare(
        "INSERT INTO journal_entries
            (company_id, entry_date, description, document_number, status, created_by, posted_at)
         VALUES (?, ?, ?, ?, 'posted', ?, NOW())"
    );
    $entryStmt->execute([
        $companyId,
        $entryDate,
        'Palgakulu ja maksud — ' . $employeeName . ' — ' . $workMonth,
        'PALG-' . $runId . '-' . $runItemId,
        $userId,
    ]);
    $entryId = (int) $pdo->lastInsertId();
    $lineStmt = $pdo->prepare(
        'INSERT INTO journal_lines (entry_id, account_id, description, debit, credit)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($debits as $accountId => $line) {
        $lineStmt->execute([$entryId, $accountId, implode(' + ', $line['descriptions']), number_format($line['amount'] / 100, 2, '.', ''), '0.00']);
    }
    foreach ($credits as $accountId => $line) {
        $lineStmt->execute([$entryId, $accountId, implode(' + ', $line['descriptions']), '0.00', number_format($line['amount'] / 100, 2, '.', '')]);
    }
    $linkStmt = $pdo->prepare(
        'UPDATE payroll_run_item_payments
         SET journal_entry_id = ?, journal_calculation_id = ?
         WHERE company_id = ? AND run_id = ? AND run_item_id = ? AND journal_entry_id IS NULL'
    );
    $linkStmt->execute([$entryId, $calculationId, $companyId, $runId, $runItemId]);
    if ($linkStmt->rowCount() !== 1) {
        throw new RuntimeException('Palgakande seose salvestamine ebaõnnestus. Tehing tühistati.');
    }
    return $entryId;
}
