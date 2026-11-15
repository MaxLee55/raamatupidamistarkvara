<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: ../login.php');
    exit;
}
if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('Palgamoodulile on ligipääs ainult administraatoril.');
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/accounting.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function validDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function normalizedGrossAmount(string $value): ?string
{
    $value = str_replace([' ', "\xc2\xa0", "\xe2\x80\xaf"], '', trim($value));
    $value = str_replace(',', '.', $value);
    if (!preg_match('/^\d{1,11}(?:\.\d{1,2})?$/', $value)) {
        return null;
    }
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    $fraction = str_pad($fraction, 2, '0');
    $whole = ltrim($whole, '0');
    $whole = $whole === '' ? '0' : $whole;
    if ((int) $whole === 0 && (int) $fraction === 0) {
        return null;
    }
    return $whole . '.' . $fraction;
}

function amountToCents(string $value): int
{
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');
    return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
}

function formatCents(int $cents): string
{
    return number_format(intdiv($cents, 100), 0, ',', ' ')
        . ',' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

$companyId = (int) $_SESSION['company_id'];
$runId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$runId || $runId < 1) {
    http_response_code(404);
    exit('Palgaperioodi ei leitud.');
}

$flash = $_SESSION['payroll_item_notice'] ?? '';
unset($_SESSION['payroll_item_notice']);
$errors = [];

$runStmt = $pdo->prepare(
    'SELECT id, work_month, planned_payment_date, status
     FROM payroll_runs
     WHERE id = :run_id AND company_id = :company_id'
);
$runStmt->execute(['run_id' => $runId, 'company_id' => $companyId]);
$run = $runStmt->fetch();
if (!$run) {
    http_response_code(404);
    exit('Palgaperioodi ei leitud.');
}
$bankAccountsStmt = $pdo->prepare(
    "SELECT id, code, name FROM accounts
     WHERE company_id = ? AND is_active = 1 AND account_type = 'asset'
       AND code REGEXP '^10[0-9]{2}$'
     ORDER BY code"
);
$bankAccountsStmt->execute([$companyId]);
$bankAccounts = $bankAccountsStmt->fetchAll(PDO::FETCH_ASSOC);
$bankAccountsById = [];
foreach ($bankAccounts as $bankAccount) {
    $bankAccountsById[(int) $bankAccount['id']] = $bankAccount['code'] . ' — ' . $bankAccount['name'];
}
$confirmationStmt = $pdo->prepare(
    'SELECT rc.confirmed_at, u.email AS confirmed_by_email
     FROM payroll_run_confirmations rc
     LEFT JOIN users u ON u.id = rc.confirmed_by AND u.company_id = rc.company_id
     WHERE rc.company_id = ? AND rc.run_id = ?'
);
$confirmationStmt->execute([$companyId, $runId]);
$confirmation = $confirmationStmt->fetch() ?: null;

$workMonthStart = (string) $run['work_month'];
$workMonthEnd = (new DateTimeImmutable($workMonthStart))->modify('last day of this month')->format('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
    ) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'confirm_run') {
            try {
                $pdo->beginTransaction();
                $statusStmt = $pdo->prepare(
                    'SELECT status FROM payroll_runs WHERE id = ? AND company_id = ? FOR UPDATE'
                );
                $statusStmt->execute([$runId, $companyId]);
                if ($statusStmt->fetchColumn() !== 'draft') {
                    throw new RuntimeException('Palgaperiood ei ole enam mustandina kinnitatav.');
                }
                $countStmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM payroll_run_items WHERE company_id = ? AND run_id = ?'
                );
                $countStmt->execute([$companyId, $runId]);
                if ((int) $countStmt->fetchColumn() === 0) {
                    throw new RuntimeException('Tühja palgaperioodi ei saa kinnitada.');
                }
                $confirmStmt = $pdo->prepare(
                    'INSERT INTO payroll_run_confirmations (company_id, run_id, confirmed_by)
                     VALUES (?, ?, ?)'
                );
                $confirmStmt->execute([$companyId, $runId, (int) $_SESSION['user_id']]);
                $updateRunStmt = $pdo->prepare(
                    'UPDATE payroll_runs SET status = \'confirmed\'
                     WHERE id = ? AND company_id = ? AND status = \'draft\''
                );
                $updateRunStmt->execute([$runId, $companyId]);
                $pdo->commit();
                $_SESSION['payroll_item_notice'] = 'Palgaperiood kinnitati. Brutotasusid enam muuta ei saa.';
                header('Location: run.php?id=' . $runId);
                exit;
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = $e->getMessage();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Palgaperioodi kinnitamine ebaõnnestus: ' . $e->getMessage());
                $errors[] = 'Palgaperioodi kinnitamine ebaõnnestus.';
            }
        } elseif ($action === 'mark_paid') {
            $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
            $paymentDate = trim((string) ($_POST['actual_payment_date'] ?? ''));
            $bankAccountId = filter_var($_POST['bank_account_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$itemId || $itemId < 1) {
                $errors[] = 'Palgarea tunnus ei ole kehtiv.';
            }
            if (!$bankAccountId || !array_filter($bankAccounts, static function ($account) use ($bankAccountId) {
                return (int) $account['id'] === (int) $bankAccountId;
            })) {
                $errors[] = 'Vali pangakonto, millelt netopalk tegelikult maksti.';
            }
            if (!validDate($paymentDate) || $paymentDate > date('Y-m-d')) {
                $errors[] = 'Tegelik väljamakse kuupäev peab olema kehtiv tänane või varasem kuupäev.';
            }
            if (!$errors) {
                try {
                    $pdo->beginTransaction();
                    $statusStmt = $pdo->prepare(
                        'SELECT status FROM payroll_runs WHERE id = ? AND company_id = ? FOR UPDATE'
                    );
                    $statusStmt->execute([$runId, $companyId]);
                    if ($statusStmt->fetchColumn() !== 'confirmed') {
                        throw new RuntimeException('Väljamakse saab märkida ainult kinnitatud palgaperioodis.');
                    }
                    $itemStmt = $pdo->prepare(
                        'SELECT id FROM payroll_run_items
                         WHERE id = ? AND company_id = ? AND run_id = ?'
                    );
                    $itemStmt->execute([$itemId, $companyId, $runId]);
                    if (!$itemStmt->fetchColumn()) {
                        throw new RuntimeException('Palgarea tunnust ei leitud.');
                    }
                    $paymentStmt = $pdo->prepare(
                        'INSERT INTO payroll_run_item_payments
                            (company_id, run_id, run_item_id, actual_payment_date, bank_account_id, recorded_by)
                         VALUES (?, ?, ?, ?, ?, ?)'
                    );
                    $paymentStmt->execute([
                        $companyId, $runId, $itemId, $paymentDate, $bankAccountId, (int) $_SESSION['user_id'],
                    ]);
                    $journalEntryId = null;
                    $latestCalculationStmt = $pdo->prepare(
                        'SELECT id, calculation_date FROM payroll_run_item_calculations
                         WHERE company_id = ? AND run_id = ? AND run_item_id = ?
                         ORDER BY id DESC LIMIT 1'
                    );
                    $latestCalculationStmt->execute([$companyId, $runId, $itemId]);
                    $latestCalculation = $latestCalculationStmt->fetch(PDO::FETCH_ASSOC);
                    if ($latestCalculation && (string) $latestCalculation['calculation_date'] === $paymentDate) {
                        $journalEntryId = payrollCreatePaymentJournal(
                            $pdo, $companyId, (int) $_SESSION['user_id'], $runId, $itemId,
                            (int) $latestCalculation['id']
                        );
                    }
                    $countsStmt = $pdo->prepare(
                        'SELECT COUNT(*) AS item_count,
                                (SELECT COUNT(*) FROM payroll_run_item_payments
                                 WHERE company_id = ? AND run_id = ?) AS paid_count
                         FROM payroll_run_items WHERE company_id = ? AND run_id = ?'
                    );
                    $countsStmt->execute([$companyId, $runId, $companyId, $runId]);
                    $counts = $countsStmt->fetch();
                    if ((int) $counts['item_count'] === (int) $counts['paid_count']) {
                        $paidStmt = $pdo->prepare(
                            'UPDATE payroll_runs SET status = \'paid\'
                             WHERE id = ? AND company_id = ? AND status = \'confirmed\''
                        );
                        $paidStmt->execute([$runId, $companyId]);
                    }
                    $pdo->commit();
                    $_SESSION['payroll_item_notice'] = $journalEntryId
                        ? 'Väljamakse salvestati ja pearaamatu kanne #' . $journalEntryId . ' loodi.'
                        : 'Väljamakse salvestati. Ava „Arvuta netopalk” ja salvesta tegeliku väljamakse kuupäevaga arvutus; seejärel loob programm pearaamatu kande.';
                    header('Location: run.php?id=' . $runId);
                    exit;
                } catch (RuntimeException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $errors[] = $e->getMessage();
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('Tegelik väljamakse kuupäev ebaõnnestus: ' . $e->getMessage());
                    $errors[] = 'Väljamakse kuupäeva salvestamine ebaõnnestus.';
                }
            }
        } elseif ($action === 'save_paid_bank_account') {
            $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
            $bankAccountId = filter_var($_POST['bank_account_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$itemId || $itemId < 1 || !$bankAccountId) {
                $errors[] = 'Vali palgarida ja väljamakse pangakonto.';
            } elseif (!array_filter($bankAccounts, static function ($account) use ($bankAccountId) {
                return (int) $account['id'] === (int) $bankAccountId;
            })) {
                $errors[] = 'Valitud pangakonto ei kuulu ettevõtte aktiivsete pangakontode hulka.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $paymentLookup = $pdo->prepare(
                        'SELECT id, actual_payment_date, journal_entry_id, bank_account_id
                         FROM payroll_run_item_payments
                         WHERE company_id = ? AND run_id = ? AND run_item_id = ? FOR UPDATE'
                    );
                    $paymentLookup->execute([$companyId, $runId, $itemId]);
                    $payment = $paymentLookup->fetch(PDO::FETCH_ASSOC);
                    if (!$payment || $payment['journal_entry_id'] !== null || $payment['bank_account_id'] !== null) {
                        throw new RuntimeException('Pangakontot saab lisada ainult makstuks märgitud palgale, millel konto ja pearaamatukanne veel puuduvad.');
                    }
                    $setBankStmt = $pdo->prepare(
                        'UPDATE payroll_run_item_payments SET bank_account_id = ?
                         WHERE id = ? AND company_id = ? AND journal_entry_id IS NULL AND bank_account_id IS NULL'
                    );
                    $setBankStmt->execute([$bankAccountId, (int) $payment['id'], $companyId]);
                    $latestCalculationStmt = $pdo->prepare(
                        'SELECT id, calculation_date FROM payroll_run_item_calculations
                         WHERE company_id = ? AND run_id = ? AND run_item_id = ?
                         ORDER BY id DESC LIMIT 1'
                    );
                    $latestCalculationStmt->execute([$companyId, $runId, $itemId]);
                    $latestCalculation = $latestCalculationStmt->fetch(PDO::FETCH_ASSOC);
                    $journalEntryId = null;
                    if ($latestCalculation && (string) $latestCalculation['calculation_date'] === (string) $payment['actual_payment_date']) {
                        $journalEntryId = payrollCreatePaymentJournal(
                            $pdo, $companyId, (int) $_SESSION['user_id'], $runId, $itemId,
                            (int) $latestCalculation['id']
                        );
                    }
                    $pdo->commit();
                    $_SESSION['payroll_item_notice'] = $journalEntryId
                        ? 'Pangakonto salvestati ja pearaamatu kanne #' . $journalEntryId . ' loodi.'
                        : 'Pangakonto salvestati. Ava „Arvuta netopalk” ja salvesta tegeliku väljamakse kuupäevaga arvutus, et palgakanne tekiks.';
                    header('Location: run.php?id=' . $runId);
                    exit;
                } catch (RuntimeException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $errors[] = $e->getMessage();
                } catch (PDOException $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Palgamakse pangakonto salvestamine ebaõnnestus: ' . $e->getMessage());
                    $errors[] = 'Palgamakse pangakonto salvestamine ebaõnnestus.';
                }
            }
        } elseif ($run['status'] !== 'draft') {
            $errors[] = 'Kinnitatud palgaperioodi brutotasusid enam muuta ei saa.';
        } elseif ($action === 'update_component' || $action === 'remove_component') {
            $componentId = filter_var($_POST['component_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$componentId || $componentId < 1) {
                $errors[] = 'Palgaliigi tunnus ei ole kehtiv.';
            } else {
                $componentLookup = $pdo->prepare(
                    'SELECT c.id, c.run_item_id FROM payroll_run_item_components c
                     JOIN payroll_run_items i ON i.id = c.run_item_id
                     WHERE c.id = ? AND c.company_id = ? AND c.run_id = ?
                       AND i.company_id = ? AND i.run_id = ?'
                );
                $componentLookup->execute([$componentId, $companyId, $runId, $companyId, $runId]);
                $componentRow = $componentLookup->fetch();
                if (!$componentRow) {
                    $errors[] = 'Palgaliiki ei leitud.';
                } elseif ($action === 'update_component') {
                    $componentType = (string) ($_POST['component_type'] ?? '');
                    $componentAmount = normalizedGrossAmount((string) ($_POST['component_amount'] ?? ''));
                    $validTypes = ['salary','overtime','night_work','holiday_work','vacation_pay','bonus','other'];
                    if (!in_array($componentType, $validTypes, true)) $errors[] = 'Vali palgaliik.';
                    if ($componentAmount === null) $errors[] = 'Brutosumma peab olema positiivne summa, kuni kahe komakohaga.';
                    if (!$errors) {
                        try {
                            $pdo->beginTransaction();
                            $updateComponent = $pdo->prepare('UPDATE payroll_run_item_components SET component_type = ?, amount = ? WHERE id = ? AND company_id = ? AND run_id = ?');
                            $updateComponent->execute([$componentType, $componentAmount, $componentId, $companyId, $runId]);
                            $sumComponents = $pdo->prepare('UPDATE payroll_run_items SET gross_amount = (SELECT COALESCE(SUM(amount), 0) FROM payroll_run_item_components WHERE company_id = ? AND run_id = ? AND run_item_id = ?), updated_by = ? WHERE id = ? AND company_id = ? AND run_id = ?');
                            $sumComponents->execute([$companyId, $runId, $componentRow['run_item_id'], (int) $_SESSION['user_id'], $componentRow['run_item_id'], $companyId, $runId]);
                            $pdo->commit();
                            $_SESSION['payroll_item_notice'] = 'Palgaliik uuendati ja brutotasu arvutati uuesti.';
                            header('Location: run.php?id=' . $runId);
                            exit;
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            error_log('Palgaliigi muutmine ebaõnnestus: ' . $e->getMessage());
                            $errors[] = 'Palgaliigi muutmine ebaõnnestus.';
                        }
                    }
                } else {
                    $countComponents = $pdo->prepare('SELECT COUNT(*) FROM payroll_run_item_components WHERE company_id = ? AND run_id = ? AND run_item_id = ?');
                    $countComponents->execute([$companyId, $runId, $componentRow['run_item_id']]);
                    if ((int) $countComponents->fetchColumn() <= 1) {
                        $errors[] = 'Töötaja viimast palgaliiki ei saa eraldi eemaldada. Kasuta töötaja real „Eemalda kõik“.';
                    } else {
                        try {
                            $pdo->beginTransaction();
                            $deleteComponent = $pdo->prepare('DELETE FROM payroll_run_item_components WHERE id = ? AND company_id = ? AND run_id = ?');
                            $deleteComponent->execute([$componentId, $companyId, $runId]);
                            $sumComponents = $pdo->prepare('UPDATE payroll_run_items SET gross_amount = (SELECT COALESCE(SUM(amount), 0) FROM payroll_run_item_components WHERE company_id = ? AND run_id = ? AND run_item_id = ?), updated_by = ? WHERE id = ? AND company_id = ? AND run_id = ?');
                            $sumComponents->execute([$companyId, $runId, $componentRow['run_item_id'], (int) $_SESSION['user_id'], $componentRow['run_item_id'], $companyId, $runId]);
                            $pdo->commit();
                            $_SESSION['payroll_item_notice'] = 'Palgaliik eemaldati ja brutotasu arvutati uuesti.';
                            header('Location: run.php?id=' . $runId);
                            exit;
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            error_log('Palgaliigi eemaldamine ebaõnnestus: ' . $e->getMessage());
                            $errors[] = 'Palgaliigi eemaldamine ebaõnnestus.';
                        }
                    }
                }
            }
        } elseif ($action === 'save_item') {
            $employmentId = filter_var($_POST['employment_id'] ?? null, FILTER_VALIDATE_INT);
            $grossAmount = normalizedGrossAmount((string) ($_POST['gross_amount'] ?? ''));
            $componentType = (string) ($_POST['component_type'] ?? 'salary');
            $componentLabels = [
                'salary' => 'Põhipalk', 'overtime' => 'Ületunnitöö',
                'night_work' => 'Öötöö', 'holiday_work' => 'Riigipühal töötamine',
                'vacation_pay' => 'Puhkusetasu', 'bonus' => 'Lisatasu / preemia',
                'other' => 'Muu töötasu',
            ];
            if (!$employmentId || $employmentId < 1) {
                $errors[] = 'Vali töösuhe.';
            }
            if ($grossAmount === null) {
                $errors[] = 'Brutotasu peab olema positiivne summa, kuni kahe komakohaga.';
            }
            if (!isset($componentLabels[$componentType])) {
                $errors[] = 'Vali palgaliik.';
            }
            if (!$errors) {
                $employmentStmt = $pdo->prepare(
                    'SELECT id FROM payroll_employments
                     WHERE id = :employment_id
                       AND company_id = :company_id
                       AND started_on <= :month_end
                       AND (ended_on IS NULL OR ended_on >= :month_start)'
                );
                $employmentStmt->execute([
                    'employment_id' => $employmentId,
                    'company_id' => $companyId,
                    'month_end' => $workMonthEnd,
                    'month_start' => $workMonthStart,
                ]);
                if (!$employmentStmt->fetchColumn()) {
                    $errors[] = 'Valitud töösuhe ei kehtinud selles arvestuskuus.';
                }
            }
            if (!$errors) {
                try {
                    $pdo->beginTransaction();
                    $itemStmt = $pdo->prepare('SELECT id FROM payroll_run_items WHERE company_id = ? AND run_id = ? AND employment_id = ? FOR UPDATE');
                    $itemStmt->execute([$companyId, $runId, $employmentId]);
                    $itemId = $itemStmt->fetchColumn();
                    if (!$itemId) {
                        $newItem = $pdo->prepare('INSERT INTO payroll_run_items (company_id, run_id, employment_id, gross_amount, created_by, updated_by) VALUES (?, ?, ?, 0.00, ?, ?)');
                        $newItem->execute([$companyId, $runId, $employmentId, (int) $_SESSION['user_id'], (int) $_SESSION['user_id']]);
                        $itemId = (int) $pdo->lastInsertId();
                    }
                    $componentStmt = $pdo->prepare('INSERT INTO payroll_run_item_components (company_id, run_id, run_item_id, component_type, amount, created_by) VALUES (?, ?, ?, ?, ?, ?)');
                    $componentStmt->execute([$companyId, $runId, $itemId, $componentType, $grossAmount, (int) $_SESSION['user_id']]);
                    $sumStmt = $pdo->prepare('UPDATE payroll_run_items SET gross_amount = (SELECT COALESCE(SUM(amount), 0) FROM payroll_run_item_components WHERE company_id = ? AND run_id = ? AND run_item_id = ?), updated_by = ? WHERE id = ? AND company_id = ? AND run_id = ?');
                    $sumStmt->execute([$companyId, $runId, $itemId, (int) $_SESSION['user_id'], $itemId, $companyId, $runId]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('Palgakomponendi salvestamine ebaõnnestus: ' . $e->getMessage());
                    $errors[] = 'Palgaliigi salvestamine ebaõnnestus.';
                }
                if (!$errors) $_SESSION['payroll_item_notice'] = 'Palgaliik lisati töötaja brutotasule.';
                if (!$errors) {
                header('Location: run.php?id=' . $runId);
                exit;
                }
            }
        } elseif ($action === 'remove_item') {
            $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$itemId || $itemId < 1) {
                $errors[] = 'Palgarea tunnus ei ole kehtiv.';
            } else {
                $deleteComponents = $pdo->prepare('DELETE FROM payroll_run_item_components WHERE company_id = ? AND run_id = ? AND run_item_id = ?');
                $deleteComponents->execute([$companyId, $runId, $itemId]);
                $removeStmt = $pdo->prepare(
                    'DELETE FROM payroll_run_items
                     WHERE id = :item_id AND company_id = :company_id AND run_id = :run_id'
                );
                $removeStmt->execute(['item_id' => $itemId, 'company_id' => $companyId, 'run_id' => $runId]);
                if ($removeStmt->rowCount() !== 1) {
                    $errors[] = 'Palgarea kustutamine ebaõnnestus.';
                } else {
                    $_SESSION['payroll_item_notice'] = 'Palgarea mustand eemaldati.';
                    header('Location: run.php?id=' . $runId);
                    exit;
                }
            }
        } else {
            $errors[] = 'Tundmatu toiming.';
        }
    }
}

$eligibleStmt = $pdo->prepare(
    'SELECT pe.id, pe.started_on, e.first_name, e.last_name,
            (SELECT pet.job_title
             FROM payroll_employment_terms pet
             WHERE pet.company_id = pe.company_id
               AND pet.employment_id = pe.id
               AND pet.valid_from <= :month_end
               AND (pet.valid_until IS NULL OR pet.valid_until >= :month_start)
             ORDER BY pet.valid_from DESC LIMIT 1) AS job_title
     FROM payroll_employments pe
     JOIN payroll_employees e
       ON e.id = pe.employee_id AND e.company_id = pe.company_id
     WHERE pe.company_id = :company_id
       AND pe.started_on <= :month_end_outer
       AND (pe.ended_on IS NULL OR pe.ended_on >= :month_start_outer)
     ORDER BY e.last_name, e.first_name, pe.started_on'
);
$eligibleStmt->execute([
    'month_end' => $workMonthEnd,
    'month_start' => $workMonthStart,
    'company_id' => $companyId,
    'month_end_outer' => $workMonthEnd,
    'month_start_outer' => $workMonthStart,
]);
$eligibleEmployments = $eligibleStmt->fetchAll();

$itemsStmt = $pdo->prepare(
    'SELECT pri.id, pri.employment_id, pri.gross_amount, pri.updated_at,
            payment.actual_payment_date, payment.bank_account_id, payment.journal_entry_id,
            e.first_name, e.last_name, pe.started_on,
            (SELECT pet.job_title FROM payroll_employment_terms pet
             WHERE pet.company_id = pe.company_id AND pet.employment_id = pe.id
               AND pet.valid_from <= :month_end
               AND (pet.valid_until IS NULL OR pet.valid_until >= :month_start)
             ORDER BY pet.valid_from DESC LIMIT 1) AS job_title
     FROM payroll_run_items pri
     JOIN payroll_employments pe
       ON pe.id = pri.employment_id AND pe.company_id = pri.company_id
     JOIN payroll_employees e
       ON e.id = pe.employee_id AND e.company_id = pe.company_id
     LEFT JOIN payroll_run_item_payments payment
       ON payment.run_item_id = pri.id AND payment.company_id = pri.company_id
      AND payment.run_id = pri.run_id
     WHERE pri.company_id = :company_id AND pri.run_id = :run_id
     ORDER BY e.last_name, e.first_name, pe.started_on'
);
$itemsStmt->execute([
    'month_end' => $workMonthEnd,
    'month_start' => $workMonthStart,
    'company_id' => $companyId,
    'run_id' => $runId,
]);
$items = $itemsStmt->fetchAll();
$componentsByItem = [];
if ($items) {
    $componentStmt = $pdo->prepare('SELECT id, run_item_id, component_type, amount FROM payroll_run_item_components WHERE company_id = ? AND run_id = ? ORDER BY id');
    $componentStmt->execute([$companyId, $runId]);
    $componentLabels = ['salary'=>'Põhipalk','overtime'=>'Ületunnitöö','night_work'=>'Öötöö','holiday_work'=>'Riigipühal töötamine','vacation_pay'=>'Puhkusetasu','bonus'=>'Lisatasu / preemia','other'=>'Muu töötasu'];
    foreach ($componentStmt->fetchAll() as $component) $componentsByItem[(int)$component['run_item_id']][] = $component;
}
$totalGrossCents = 0;
foreach ($items as $item) {
    $totalGrossCents += amountToCents((string) $item['gross_amount']);
}
$statusLabels = ['draft' => 'Mustand', 'confirmed' => 'Kinnitatud', 'paid' => 'Makstud'];
$runStatusLabel = $statusLabels[$run['status']] ?? (string) $run['status'];
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Palgaperioodi mustand | Palgamoodul</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px; background: #f4f7fb; color: #243447; font-family: Arial, sans-serif; }
        main { max-width: 1200px; margin: 0 auto; }
        header, .panel { margin-bottom: 20px; padding: 22px; background: #fff; border: 1px solid #dce3ec; border-radius: 12px; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        h1, h2 { margin-top: 0; color: #17324d; }
        .muted { color: #68798b; line-height: 1.5; }
        .fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        label { display: block; color: #33475b; font-size: 14px; font-weight: 600; }
        input, select { display: block; width: 100%; min-height: 42px; margin-top: 6px; padding: 9px 11px; border: 1px solid #b8c4d1; border-radius: 7px; font: inherit; }
        button, .button { display: inline-block; min-height: 38px; padding: 9px 12px; border: 0; border-radius: 7px; background: #1769aa; color: #fff; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .remove { background: #9e3434; }
        .actions { margin-top: 14px; }
        .message { padding: 12px 14px; border-radius: 7px; }
        .success { background: #e9f6ed; color: #205c31; }
        .error { background: #fdecec; color: #8d2222; }
        ul { margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 8px; border-bottom: 1px solid #e2e8ef; text-align: left; vertical-align: middle; }
        th { color: #526579; font-size: 13px; }
        .amount-input { min-width: 130px; max-width: 180px; }
        .total { margin: 16px 0 0; font-size: 20px; font-weight: 700; color: #17324d; }
        @media (max-width: 760px) { body { padding: 14px; } header { align-items: flex-start; flex-direction: column; } .fields { grid-template-columns: 1fr; } table { font-size: 13px; } }
    </style>
</head>
<body>
<main>
    <header>
        <div><h1>Palgaperiood · <?= h(substr($run['work_month'], 0, 7)) ?></h1><div class="muted">Kavandatud väljamakse: <?= h($run['planned_payment_date']) ?> · Staatus: <?= h($runStatusLabel) ?></div>
            <?php if ($confirmation): ?><div class="muted">Kinnitas <?= h($confirmation['confirmed_by_email'] ?? 'administraator') ?> · <?= h($confirmation['confirmed_at']) ?></div><?php endif; ?>
        </div>
        <div><a class="button" href="tax-calculation.php?id=<?= (int) $runId ?>">Arvuta netopalk</a> <a class="button" href="runs.php">Tagasi palgaperioodide juurde</a></div>
    </header>
    <?php if ($flash !== ''): ?><p class="message success"><?= h($flash) ?></p><?php endif; ?>
    <?php if ($errors): ?><div class="message error"><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <?php if ($run['status'] === 'draft'): ?>
    <section class="panel">
        <h2>Lisa töötasu liik</h2>
        <?php if (!$eligibleEmployments): ?>
            <p class="muted">Selles arvestuskuus kehtinud töösuhteid ei leitud.</p>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="save_item">
                <div class="fields">
                    <label>Töötaja ja töösuhe<select name="employment_id" required><option value="">Vali töösuhe</option>
                        <?php foreach ($eligibleEmployments as $eligible): ?>
                            <option value="<?= (int) $eligible['id'] ?>"><?= h($eligible['last_name'] . ' ' . $eligible['first_name'] . ' · ' . ($eligible['job_title'] ?: 'töösuhe alates ' . $eligible['started_on'])) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label>Palgaliik<select name="component_type" required><option value="salary">Põhipalk</option><option value="overtime">Ületunnitöö</option><option value="night_work">Öötöö</option><option value="holiday_work">Riigipühal töötamine</option><option value="vacation_pay">Puhkusetasu</option><option value="bonus">Lisatasu / preemia</option><option value="other">Muu töötasu</option></select></label>
                    <label>Brutosumma (€)<input class="amount-input" name="gross_amount" inputmode="decimal" placeholder="nt 1800,00" required></label>
                </div>
                <div class="actions"><button type="submit">Lisa palgaliik</button></div>
            </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="panel">
        <h2>Brutotasud</h2>
        <?php if (!$items): ?><p class="muted">Mustandis pole veel töötajate brutotasusid.</p><?php else: ?>
            <table><thead><tr><th>Töötaja</th><th>Ametinimetus</th><th>Brutotasu (€)</th><?php if ($run['status'] === 'draft'): ?><th>Muudetud</th><th></th><?php else: ?><th>Tegelik väljamakse ja pangakonto</th><th>Pearaamatukanne</th><?php endif; ?></tr></thead><tbody>
            <?php foreach ($items as $item): ?><tr>
                <td><?= h($item['last_name'] . ' ' . $item['first_name']) ?></td>
                <td><?= h($item['job_title'] ?? '') ?></td>
                <td><strong><?= h(formatCents(amountToCents((string) $item['gross_amount']))) ?> €</strong><?php foreach (($componentsByItem[(int)$item['id']] ?? []) as $component): ?><?php if ($run['status'] === 'draft'): ?><form method="post" style="display:flex;gap:6px;align-items:center;margin-top:6px;flex-wrap:wrap"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="component_id" value="<?= (int)$component['id'] ?>"><input type="hidden" name="action" value="update_component"><select name="component_type" aria-label="Palgaliik"><?php foreach ($componentLabels as $type => $label): ?><option value="<?= h($type) ?>" <?= $type === $component['component_type'] ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select><input class="amount-input" name="component_amount" aria-label="Brutosumma" inputmode="decimal" value="<?= h((string)$component['amount']) ?>" required><button type="submit">Uuenda</button></form><form method="post" style="margin-top:4px" onsubmit="return confirm('Kas eemaldan selle palgaliigi?')"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="component_id" value="<?= (int)$component['id'] ?>"><input type="hidden" name="action" value="remove_component"><button class="remove" type="submit">Eemalda liik</button></form><?php else: ?><br><small class="muted"><?= h($componentLabels[$component['component_type']] ?? 'Muu töötasu') ?>: <?= h(formatCents(amountToCents((string)$component['amount']))) ?> €</small><?php endif; ?><?php endforeach; ?></td>
                <?php if ($run['status'] === 'draft'): ?>
                    <td><?= h(substr((string) $item['updated_at'], 0, 16)) ?></td>
                    <td><form method="post" onsubmit="return confirm('Kas eemaldan selle töötaja kõik palgaliigid mustandist?')"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="remove_item"><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>"><button class="remove" type="submit">Eemalda kõik</button></form></td>
                <?php else: ?>
                    <td>
                        <?php if ($item['actual_payment_date'] !== null): ?>
                            <?= h($item['actual_payment_date']) ?>
                            <?php if (empty($item['bank_account_id'])): ?>
                                <form method="post" style="display:grid;gap:7px;margin-top:8px">
                                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="save_paid_bank_account"><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                    <select name="bank_account_id" required aria-label="Tegelik pangakonto"><option value="">Vali pangakonto</option><?php foreach ($bankAccounts as $bankAccount): ?><option value="<?= (int) $bankAccount['id'] ?>"><?= h($bankAccount['code'] . ' — ' . $bankAccount['name']) ?></option><?php endforeach; ?></select>
                                    <button type="submit">Salvesta pangakonto</button>
                                </form>
                            <?php else: ?>
                                <br><small class="muted"><?= h($bankAccountsById[(int) $item['bank_account_id']] ?? 'Pangakonto') ?></small>
                            <?php endif; ?>
                        <?php elseif ($run['status'] === 'confirmed'): ?>
                            <form method="post" style="display:grid;gap:7px" onsubmit="return confirm('Kas selle töötaja väljamakse on päriselt tehtud?');">
                                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="mark_paid"><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                <input type="date" name="actual_payment_date" max="<?= h(date('Y-m-d')) ?>" required>
                                <select name="bank_account_id" required aria-label="Pangakonto, kust palk maksti"><option value="">Vali pangakonto</option><?php foreach ($bankAccounts as $bankAccount): ?><option value="<?= (int) $bankAccount['id'] ?>"><?= h($bankAccount['code'] . ' — ' . $bankAccount['name']) ?></option><?php endforeach; ?></select>
                                <button type="submit">Märgi makstuks</button>
                            </form>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($item['journal_entry_id'])): ?>
                            <a href="../entry.php?id=<?= (int) $item['journal_entry_id'] ?>">Ava kanne #<?= (int) $item['journal_entry_id'] ?></a>
                        <?php elseif ($item['actual_payment_date'] !== null): ?>
                            <span class="muted">Kanne tekib, kui salvestad tegeliku maksekuupäevaga netopalga arvutuse.</span>
                            <br><a href="tax-calculation.php?id=<?= (int) $runId ?>">Arvuta netopalk</a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                <?php endif; ?>
            </tr><?php endforeach; ?>
            </tbody></table>
        <?php endif; ?>
        <p class="total">Brutotasud kokku: <?= h(formatCents($totalGrossCents)) ?> €</p>
        <p class="muted">Netosumma ja maksude eelvaate avamiseks kasuta lehe ülaosas nuppu „Arvuta netopalk“.</p>
    </section>
    <?php if ($run['status'] === 'draft' && $items): ?>
        <section class="panel">
            <h2>Kinnita palgaarvestus</h2>
            <p class="muted">Kinnitamine lukustab brutotasud ja eemaldamise. Pärast kinnitamist märgi iga töötaja päris väljamakse kuupäev ja pangakonto; sobiva salvestatud arvutuse korral loob programm pearaamatukande automaatselt.</p>
            <form method="post" onsubmit="return confirm('Kas kinnitad selle palgaperioodi? Brutotasusid ei saa pärast kinnitamist muuta.');">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="confirm_run">
                <button type="submit">Kinnita palgaarvestus</button>
            </form>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
