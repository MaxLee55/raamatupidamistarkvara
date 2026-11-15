<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Kasuta laekumise vormi.');
}

if (
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
) {
    http_response_code(400);
    exit('Vorm aegus. Ava arve uuesti ja proovi siis.');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/invoice_fx.php';
require_once __DIR__.'/credit_note_lib.php';
ensureCreditNoteSchema($pdo);
require_once __DIR__ . '/period_lock.php';
require_once __DIR__ . '/cash_balance_guard.php';

function toCents(string $amount): int
{
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
    return ((int) $whole * 100)
        + (int) str_pad(substr($fraction, 0, 2), 2, '0');
}

function centsToDecimal(int $cents): string
{
    return intdiv($cents, 100) . '.' .
        str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

$invoiceId = filter_input(INPUT_POST, 'invoice_id', FILTER_VALIDATE_INT);

if (!$invoiceId) {
    http_response_code(400);
    exit('Vigane arve ID.');
}

$companyId = (int) $_SESSION['company_id'];

try {
    $pdo->beginTransaction();

    $invoiceStmt = $pdo->prepare(
        'SELECT id, invoice_number, status, journal_entry_id, invoice_currency, fx_event_date
         FROM sales_invoices
         WHERE id = :invoice_id
           AND company_id = :company_id
         FOR UPDATE'
    );
    $invoiceStmt->execute([
        'invoice_id' => $invoiceId,
        'company_id' => $companyId,
    ]);
    $invoice = $invoiceStmt->fetch();

    if (
        !$invoice ||
        $invoice['status'] !== 'posted' ||
        !$invoice['journal_entry_id']
    ) {
        throw new RuntimeException('Arve peab enne laekumise lisamist olema kinnitatud.');
    }

    $bankAccountId = filter_var(
        $_POST['bank_account_id'] ?? null,
        FILTER_VALIDATE_INT
    );
    $paymentDate = trim($_POST['payment_date'] ?? '');
    $amountText = trim($_POST['amount'] ?? '');
    $foreignInvoice = invoiceFxCurrency($invoice) !== 'EUR';
    $notes = trim($_POST['notes'] ?? '');

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $paymentDate);
    if (!$date || $date->format('Y-m-d') !== $paymentDate) {
        throw new RuntimeException('Sisesta kehtiv laekumise kuupäev.');
    }

    $periodMessage = closedAccountingPeriodMessage($pdo, $companyId, $paymentDate);
    if ($periodMessage !== null) {
        throw new RuntimeException($periodMessage);
    }
    if (!$foreignInvoice && !preg_match('/^\d{1,13}(\.\d{1,2})?$/', $amountText)) {
        throw new RuntimeException('Sisesta kehtiv summa, näiteks 100.00.');
    }

    if (mb_strlen($notes) > 500) {
        throw new RuntimeException('Märkus võib olla kuni 500 märki.');
    }

    $amount = toCents($amountText);
    if (!$foreignInvoice && $amount <= 0) {
        throw new RuntimeException('Laekumise summa peab olema suurem kui null.');
    }

    $bankStmt = $pdo->prepare(
        "SELECT id
         FROM accounts
         WHERE id = :account_id
           AND company_id = :company_id
           AND is_active = 1
           AND account_type = 'asset'"
    );
    $bankStmt->execute([
        'account_id' => $bankAccountId,
        'company_id' => $companyId,
    ]);

    if (!$bankAccountId || !$bankStmt->fetch()) {
        throw new RuntimeException('Vali aktiivne panga- või kassakonto.');
    }


    $invoiceTotalStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(gross_amount), 0)
         FROM sales_invoice_lines
         WHERE invoice_id = :invoice_id'
    );
    $invoiceTotalStmt->execute(['invoice_id' => $invoiceId]);
    $invoiceTotal = toCents((string) $invoiceTotalStmt->fetchColumn());

    $paidStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0)
         FROM invoice_payments
         WHERE invoice_id = :invoice_id'
    );
    $paidStmt->execute(['invoice_id' => $invoiceId]);
    $paidTotal = toCents((string) $paidStmt->fetchColumn());

    $credited=creditAppliedTotals($pdo,$companyId,'sales');
    $refunds=creditRefundTotals($pdo,$companyId,'sales');
    $outstanding = $invoiceTotal - ($credited[$invoiceId]??0) - $paidTotal + ($refunds[$invoiceId]??0);
    $fxPayment = $foreignInvoice ? invoiceFxPayment($pdo,'sales',$invoice,$_POST,$outstanding) : null;
    $amount = $fxPayment['basis'] ?? $amount;
    $cashAmount = $fxPayment['cash'] ?? $amount;
    assertCashAccountsNotFurtherNegative($pdo, $companyId, [$bankAccountId => $cashAmount]);

    if ($amount > $outstanding) {
        throw new RuntimeException(
            'Laekumine ületab arve tasumata jääki (' .
            number_format($outstanding / 100, 2, ',', ' ') . ' €).'
        );
    }

    // Leia sama nõuete konto, mida kasutati arve kinnitamisel.
    $receivableStmt = $pdo->prepare(
        "SELECT jl.account_id
         FROM journal_lines jl
         JOIN accounts a
           ON a.id = jl.account_id
          AND a.company_id = :company_id
         WHERE jl.entry_id = :entry_id
           AND a.account_type = 'asset'
           AND jl.debit > 0
           AND jl.credit = 0
         ORDER BY jl.id
         LIMIT 1"
    );
    $receivableStmt->execute([
        'company_id' => $companyId,
        'entry_id' => $invoice['journal_entry_id'],
    ]);
    $receivableAccountId = $receivableStmt->fetchColumn();

    if (!$receivableAccountId) {
        throw new RuntimeException('Arve algsest kandest ei leitud nõuete kontot.');
    }
    if ($fxPayment && (int)$receivableAccountId === (int)$bankAccountId) throw new RuntimeException('Rahakonto peab nõuete kontost erinema.');

    $description = 'Laekumine arvele ' . $invoice['invoice_number'];

    $entryStmt = $pdo->prepare(
        "INSERT INTO journal_entries
            (company_id, entry_date, description, document_number,
             status, created_by, posted_at)
         VALUES
            (:company_id, :entry_date, :description, :document_number,
             'posted', :created_by, CURRENT_TIMESTAMP)"
    );
    $entryStmt->execute([
        'company_id' => $companyId,
        'entry_date' => $paymentDate,
        'description' => $description,
        'document_number' => 'LAEK-' . $invoice['invoice_number'],
        'created_by' => (int) $_SESSION['user_id'],
    ]);

    $entryId = (int) $pdo->lastInsertId();

    $lineStmt = $pdo->prepare(
        'INSERT INTO journal_lines
            (entry_id, account_id, description, debit, credit)
         VALUES
            (:entry_id, :account_id, :description, :debit, :credit)'
    );

    $lineStmt->execute([
        'entry_id' => $entryId,
        'account_id' => $bankAccountId,
        'description' => 'Laekumine pangakontole',
        'debit' => centsToDecimal($cashAmount),
        'credit' => '0.00',
    ]);

    $lineStmt->execute([
        'entry_id' => $entryId,
        'account_id' => (int) $receivableAccountId,
        'description' => 'Ostja võla vähenemine',
        'debit' => '0.00',
        'credit' => centsToDecimal($amount),
    ]);

    $paymentStmt = $pdo->prepare(
        'INSERT INTO invoice_payments
            (invoice_id, payment_date, amount, bank_account_id,
             journal_entry_id, created_by, notes)
         VALUES
            (:invoice_id, :payment_date, :amount, :bank_account_id,
             :journal_entry_id, :created_by, :notes)'
    );
    $paymentStmt->execute([
        'invoice_id' => $invoiceId,
        'payment_date' => $paymentDate,
        'amount' => centsToDecimal($amount),
        'bank_account_id' => $bankAccountId,
        'journal_entry_id' => $entryId,
        'created_by' => (int) $_SESSION['user_id'],
        'notes' => $notes !== '' ? $notes : null,
    ]);

    if ($fxPayment) {
        $paymentId = (int)$pdo->lastInsertId();
        invoiceFxPostPaymentDifference($pdo,$lineStmt,$companyId,$entryId,$cashAmount-$amount);
        invoiceFxSavePayment($pdo,$paymentId,$fxPayment);
    }
    $pdo->commit();

    header('Location: invoices.php?id=' . $invoiceId . '&payment=1');
    exit;
} catch (RuntimeException|InvalidArgumentException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(400);
    exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
        . '<p><a href="invoices.php?id=' . (int) $invoiceId . '">Tagasi arve juurde</a></p>');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($e->getMessage());
    http_response_code(500);
    exit('Laekumise salvestamisel tekkis viga.');
}
