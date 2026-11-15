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
function cents(string $amount): int
{
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');
    return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
}
function money(int $amount): string
{
    return number_format(intdiv($amount, 100), 0, ',', ' ')
        . ',' . str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
}
function percentCents(int $amount, float $rate): int
{
    return (int) round($amount * $rate, 0, PHP_ROUND_HALF_UP);
}

$companyId = (int) $_SESSION['company_id'];
$runId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$runId || $runId < 1) {
    http_response_code(404);
    exit('Palgaperioodi ei leitud.');
}
$runStmt = $pdo->prepare(
    'SELECT id, work_month, planned_payment_date, status FROM payroll_runs
     WHERE id = :run_id AND company_id = :company_id'
);
$runStmt->execute(['run_id' => $runId, 'company_id' => $companyId]);
$run = $runStmt->fetch();
if (!$run) {
    http_response_code(404);
    exit('Palgaperioodi ei leitud.');
}

$itemsStmt = $pdo->prepare(
    'SELECT pri.id, pri.gross_amount, payment.actual_payment_date,
            e.id AS employee_id, e.first_name, e.last_name,
            COALESCE(payment.actual_payment_date, :planned_date) AS calculation_date
     FROM payroll_run_items pri
     JOIN payroll_employments pe ON pe.id = pri.employment_id AND pe.company_id = pri.company_id
     JOIN payroll_employees e ON e.id = pe.employee_id AND e.company_id = pe.company_id
     LEFT JOIN payroll_run_item_payments payment
       ON payment.company_id = pri.company_id AND payment.run_id = pri.run_id AND payment.run_item_id = pri.id
     WHERE pri.company_id = :company_id AND pri.run_id = :run_id
     ORDER BY e.last_name, e.first_name, calculation_date'
);
$itemsStmt->execute([
    'planned_date' => $run['planned_payment_date'],
    'company_id' => $companyId,
    'run_id' => $runId,
]);
$items = $itemsStmt->fetchAll();
$errors = [];
$notice = $_SESSION['payroll_calculation_notice'] ?? '';
unset($_SESSION['payroll_calculation_notice']);
$saveRequested = $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'save_calculation';
if ($saveRequested && (empty($_SESSION['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? ''))) {
    $errors[] = 'Vorm aegus. Värskenda lehte ja arvuta uuesti.';
}
$warnings = [];
$results = [];
$seenEmployeeMonth = [];
$grossTotal = $netTotal = $employeeUnemploymentTotal = $pensionTotal = $incomeTaxTotal = 0;
$employerUnemploymentTotal = $socialTaxTotal = 0;
$employerSocialIsCertain = true;

if (!$items) {
    $errors[] = 'Palgaperioodis pole palgaridu.';
}

foreach ($items as $item) {
    $date = (string) $item['calculation_date'];
    $year = (int) substr($date, 0, 4);
    if ($year !== 2026) {
        $errors[] = $item['last_name'] . ' ' . $item['first_name'] . ': ' . $date
            . ' jaoks pole veel selle arvutuse maksumäärasid sisestatud.';
        continue;
    }
    $monthKey = $item['employee_id'] . '-' . substr($date, 0, 7);
    if (isset($seenEmployeeMonth[$monthKey])) {
        $errors[] = $item['last_name'] . ' ' . $item['first_name']
            . ': samas kuus on mitu palgarida või väljamakset. Maksuvaba tulu kuupiiri arvutamiseks koonda need üheks palgakandeks.';
        continue;
    }
    $seenEmployeeMonth[$monthKey] = true;

    $settingStmt = $pdo->prepare(
        'SELECT valid_from, tax_free_type, tax_free_amount, pension_contribution_rate,
                unemployment_exempt, social_tax_minimum_exempt
         FROM payroll_employee_tax_settings
         WHERE company_id = :company_id AND employee_id = :employee_id AND valid_from <= :calculation_date
         ORDER BY valid_from DESC LIMIT 1'
    );
    $settingStmt->execute([
        'company_id' => $companyId,
        'employee_id' => $item['employee_id'],
        'calculation_date' => $date,
    ]);
    $setting = $settingStmt->fetch();
    if (!$setting) {
        $errors[] = $item['last_name'] . ' ' . $item['first_name']
            . ': väljamakse kuupäeval kehtivat maksuseadete kirjet ei leitud.';
        continue;
    }

    $gross = cents((string) $item['gross_amount']);
    $pensionRate = (int) $setting['pension_contribution_rate'];
    $pension = percentCents($gross, $pensionRate / 100);
    // MTA's 2026 employment-tax example: income tax is calculated after
    // funded-pension and employee unemployment contributions and the allowance.
    $employeeUnemployment = (int) $setting['unemployment_exempt'] === 1
        ? 0
        : percentCents($gross, 0.016);
    $requestedAllowance = cents((string) $setting['tax_free_amount']);
    $allowance = min($requestedAllowance, max(0, $gross - $pension - $employeeUnemployment));
    $incomeTaxBase = max(0, $gross - $pension - $employeeUnemployment - $allowance);
    $incomeTax = percentCents($incomeTaxBase, 0.22);
    $net = max(0, $gross - $pension - $employeeUnemployment - $incomeTax);
    $employerUnemployment = percentCents($gross, 0.008);
    $socialTaxOnGross = percentCents($gross, 0.33);
    $socialTax = $socialTaxOnGross;
    $minimumNote = '';
    if ($gross < 88600 && (int) $setting['social_tax_minimum_exempt'] !== 1) {
        // 2026 minimum social-tax obligation may apply, with multiple statutory
        // exceptions and month/day adjustments that are not yet collected here.
        $socialTax = 29238;
        $employerSocialIsCertain = false;
        $minimumNote = 'Alammäära ja võimalike erandite kontroll vajalik';
    } elseif ($gross < 88600) {
        $minimumNote = 'Sotsiaalmaksu erand märgitud';
    }
    $calculated = [
        'employee_id' => (int) $item['employee_id'],
        'run_item_id' => (int) $item['id'],
        'name' => $item['last_name'] . ' ' . $item['first_name'],
        'date' => $date,
        'setting_from' => (string) $setting['valid_from'],
        'gross' => $gross,
        'pension_rate' => $pensionRate,
        'pension' => $pension,
        'employee_unemployment_rate' => (int) $setting['unemployment_exempt'] === 1 ? 0 : 1.6,
        'employee_unemployment' => $employeeUnemployment,
        'allowance' => $allowance,
        'income_tax_rate' => 22,
        'income_tax' => $incomeTax,
        'net' => $net,
        'employer_unemployment_rate' => 0.8,
        'employer_unemployment' => $employerUnemployment,
        'social_tax_rate' => 33,
        'social_tax' => $socialTax,
        'social_tax_minimum_applied' => $gross < 88600 && (int) $setting['social_tax_minimum_exempt'] !== 1,
        'minimum_note' => $minimumNote,
    ];
    $results[] = $calculated;
    $grossTotal += $gross;
    $pensionTotal += $pension;
    $employeeUnemploymentTotal += $employeeUnemployment;
    $incomeTaxTotal += $incomeTax;
    $netTotal += $net;
    $employerUnemploymentTotal += $employerUnemployment;
    $socialTaxTotal += $socialTax;
}

// Avoid applying the same monthly allowance twice when another recorded payment
// for this employee already exists in the same payment month.
foreach ($results as $result) {
    $duplicateStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM payroll_run_items other_item
         JOIN payroll_employments other_employment
           ON other_employment.id = other_item.employment_id AND other_employment.company_id = other_item.company_id
         LEFT JOIN payroll_run_item_payments other_payment
           ON other_payment.company_id = other_item.company_id
          AND other_payment.run_id = other_item.run_id AND other_payment.run_item_id = other_item.id
         JOIN payroll_runs other_run
           ON other_run.id = other_item.run_id AND other_run.company_id = other_item.company_id
         WHERE other_item.company_id = :company_id
           AND other_employment.employee_id = :employee_id
           AND other_item.run_id <> :run_id
           AND COALESCE(other_payment.actual_payment_date, other_run.planned_payment_date) >= :month_start
           AND COALESCE(other_payment.actual_payment_date, other_run.planned_payment_date) < :next_month'
    );
    $monthStart = substr($result['date'], 0, 7) . '-01';
    $nextMonth = (new DateTimeImmutable($monthStart))->modify('+1 month')->format('Y-m-d');
    $duplicateStmt->execute([
        'company_id' => $companyId,
        'employee_id' => $result['employee_id'],
        'run_id' => $runId,
        'month_start' => $monthStart,
        'next_month' => $nextMonth,
    ]);
    if ((int) $duplicateStmt->fetchColumn() > 0) {
        $errors[] = $result['name'] . ': samal kuul leidub teises palgaperioodis väljamakse. Kontrolli, et maksuvaba tulu kuupiir ei rakenduks kaks korda.';
    }
}

$allPaid = $run['status'] === 'paid';
foreach ($items as $item) {
    if ($item['actual_payment_date'] === null) {
        $allPaid = false;
        break;
    }
}
$employmentCost = $netTotal + $pensionTotal + $employeeUnemploymentTotal + $incomeTaxTotal
    + $employerUnemploymentTotal + $socialTaxTotal;

if ($saveRequested && !$errors && $results) {
    try {
        $pdo->beginTransaction();
        $saveStmt = $pdo->prepare(
            'INSERT INTO payroll_run_item_calculations
                (company_id, run_id, run_item_id, calculation_date, setting_valid_from,
                 gross_amount, pension_rate, pension_amount, employee_unemployment_rate,
                 employee_unemployment_amount, tax_free_amount, income_tax_rate,
                 income_tax_amount, net_amount, employer_unemployment_rate,
                 employer_unemployment_amount, social_tax_rate, social_tax_amount,
                 social_tax_minimum_applied, calculated_by)
             VALUES
                (:company_id, :run_id, :run_item_id, :calculation_date, :setting_valid_from,
                 :gross_amount, :pension_rate, :pension_amount, :employee_unemployment_rate,
                 :employee_unemployment_amount, :tax_free_amount, :income_tax_rate,
                 :income_tax_amount, :net_amount, :employer_unemployment_rate,
                 :employer_unemployment_amount, :social_tax_rate, :social_tax_amount,
                 :social_tax_minimum_applied, :calculated_by)'
        );
        $calculationIds = [];
        foreach ($results as $row) {
            $saveStmt->execute([
                'company_id' => $companyId,
                'run_id' => $runId,
                'run_item_id' => $row['run_item_id'],
                'calculation_date' => $row['date'],
                'setting_valid_from' => $row['setting_from'],
                'gross_amount' => number_format($row['gross'] / 100, 2, '.', ''),
                'pension_rate' => $row['pension_rate'],
                'pension_amount' => number_format($row['pension'] / 100, 2, '.', ''),
                'employee_unemployment_rate' => $row['employee_unemployment_rate'],
                'employee_unemployment_amount' => number_format($row['employee_unemployment'] / 100, 2, '.', ''),
                'tax_free_amount' => number_format($row['allowance'] / 100, 2, '.', ''),
                'income_tax_rate' => $row['income_tax_rate'],
                'income_tax_amount' => number_format($row['income_tax'] / 100, 2, '.', ''),
                'net_amount' => number_format($row['net'] / 100, 2, '.', ''),
                'employer_unemployment_rate' => $row['employer_unemployment_rate'],
                'employer_unemployment_amount' => number_format($row['employer_unemployment'] / 100, 2, '.', ''),
                'social_tax_rate' => $row['social_tax_rate'],
                'social_tax_amount' => number_format($row['social_tax'] / 100, 2, '.', ''),
                'social_tax_minimum_applied' => (int) $row['social_tax_minimum_applied'],
                'calculated_by' => (int) $_SESSION['user_id'],
            ]);
            $calculationIds[(int) $row['run_item_id']] = (int) $pdo->lastInsertId();
        }

        $paymentStmt = $pdo->prepare(
            'SELECT actual_payment_date, bank_account_id, journal_entry_id
             FROM payroll_run_item_payments
             WHERE company_id = ? AND run_id = ? AND run_item_id = ?'
        );
        $postedCount = 0;
        $paidWithoutBank = 0;
        foreach ($results as $row) {
            $paymentStmt->execute([$companyId, $runId, $row['run_item_id']]);
            $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$payment) {
                continue;
            }
            if (empty($payment['bank_account_id'])) {
                $paidWithoutBank++;
                continue;
            }
            $entryId = payrollCreatePaymentJournal(
                $pdo,
                $companyId,
                (int) $_SESSION['user_id'],
                (int) $runId,
                (int) $row['run_item_id'],
                $calculationIds[(int) $row['run_item_id']]
            );
            if (empty($payment['journal_entry_id'])) {
                $postedCount++;
            }
        }
        $pdo->commit();
        $message = 'Arvutuse versioon salvestati. Palgalehed on nüüd avatavad.';
        if ($postedCount > 0) {
            $message .= ' Pearaamatusse loodi ' . $postedCount . ' palgakannet.';
        }
        if ($paidWithoutBank > 0) {
            $message .= ' ' . $paidWithoutBank . ' makstud töötaja pangakonto tuleb valida palgaperioodi lehel.';
        }
        $_SESSION['payroll_calculation_notice'] = $message;
        header('Location: tax-calculation.php?id=' . $runId);
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
        error_log('Palgaarvestuse salvestamine ebaõnnestus: ' . $e->getMessage());
        $errors[] = 'Arvutuse salvestamine ebaõnnestus.';
    }
}

$savedStmt = $pdo->prepare(
    'SELECT pri.id AS run_item_id, e.id AS employee_id, e.first_name, e.last_name, e.email,
            (SELECT c.id FROM payroll_run_item_calculations c
             WHERE c.company_id = pri.company_id AND c.run_id = pri.run_id AND c.run_item_id = pri.id
             ORDER BY c.id DESC LIMIT 1) AS calculation_id
     FROM payroll_run_items pri
     JOIN payroll_employments pe ON pe.id = pri.employment_id AND pe.company_id = pri.company_id
     JOIN payroll_employees e ON e.id = pe.employee_id AND e.company_id = pe.company_id
     WHERE pri.company_id = ? AND pri.run_id = ?
     ORDER BY e.last_name, e.first_name'
);
$savedStmt->execute([$companyId, $runId]);
$savedPayslips = $savedStmt->fetchAll();
$payslipHistoryStmt = $pdo->prepare(
    'SELECT related_person_id, document_label, recipient_email, subject, status,
            error_message, attempted_at, completed_at
     FROM email_send_log
     WHERE company_id = ? AND document_type = ? AND document_id = ?
     ORDER BY id DESC'
);
$payslipHistoryStmt->execute([$companyId, 'payslip', (int) $runId]);
$payslipHistoryByEmployee = [];
foreach ($payslipHistoryStmt->fetchAll(PDO::FETCH_ASSOC) as $emailLog) {
    $payslipHistoryByEmployee[(int) $emailLog['related_person_id']][] = $emailLog;
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="../favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Netopalga arvestus | Palgamoodul</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px; background: #f4f7fb; color: #243447; font-family: Arial, sans-serif; }
        main { max-width: 1250px; margin: 0 auto; }
        header, .panel { margin-bottom: 20px; padding: 22px; background: #fff; border: 1px solid #dce3ec; border-radius: 12px; }
        header { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        h1, h2 { margin-top: 0; color: #17324d; }
        .muted { color: #68798b; line-height: 1.5; }
        .message { padding: 12px 14px; border-radius: 7px; }
        .error { background: #fdecec; color: #8d2222; }
        .warning { background: #fff4d7; color: #6d5312; }
        .ok { background: #e9f6ed; color: #205c31; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 8px; border-bottom: 1px solid #e2e8ef; text-align: right; vertical-align: top; }
        th:first-child, td:first-child { text-align: left; }
        th { color: #526579; font-size: 13px; }
        .button { display: inline-block; min-height: 40px; padding: 10px 14px; border-radius: 7px; background: #1769aa; color: #fff; font-weight: 700; text-decoration: none; }
        .total { font-size: 19px; font-weight: 700; color: #17324d; }
        button { display: inline-block; min-height: 40px; padding: 10px 14px; border: 0; border-radius: 7px; background: #1769aa; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
        @media (max-width: 900px) { body { padding: 14px; } header { align-items: flex-start; flex-direction: column; } .table-wrap { overflow-x: auto; } table { min-width: 900px; } }
    </style>
</head>
<body>
<main>
    <header>
        <div><h1>Netopalga arvestus</h1><div class="muted">Arvestuskuu <?= h(substr((string) $run['work_month'], 0, 7)) ?> · <?= $allPaid ? 'tegelike väljamakse kuupäevade järgi' : 'kavandatud väljamakse kuupäeva järgi' ?></div></div>
        <a class="button" href="run.php?id=<?= (int) $runId ?>">Tagasi palgaperioodi juurde</a>
    </header>
    <?php if ($errors): ?><div class="message error"><ul><?php foreach (array_unique($errors) as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if ($notice !== ''): ?><p class="message ok"><?= h($notice) ?></p><?php endif; ?>
    <?php if (!$errors && $results): ?><p class="message ok">Arvutus põhineb MTA 2026. aasta määradel ning väljamakse kuupäeval kehtinud töötaja maksuseadetel.</p><?php endif; ?>
    <?php if (!$employerSocialIsCertain && $results): ?><p class="message warning">Vähemalt ühe töötaja brutotasu on alla 886 €. 2026. aastal võib rakenduda 292,38 € sotsiaalmaksu miinimumkohustus, kuid töötaja erandid ja osalise kuu arvestus vajavad eraldi kontrolli. Tööandja kogukulu on esialgne.</p><?php endif; ?>
    <?php if ($results): ?>
    <section class="panel">
        <div class="table-wrap"><table>
            <thead><tr><th>Töötaja · väljamakse / maksuseade alates</th><th>Bruto</th><th>II sammas</th><th>Töötaja töötuskindlustus</th><th>Maksuvaba tulu</th><th>Tulumaks</th><th>Netosumma</th><th>Tööandja töötuskindlustus</th><th>Sotsiaalmaks</th><th>Märkus</th></tr></thead>
            <tbody><?php foreach ($results as $row): ?>
                <tr>
                    <td><?= h($row['name']) ?><br><span class="muted">Väljamakse: <?= h($row['date']) ?><br>Maksuseade alates: <?= h($row['setting_from']) ?></span></td>
                    <td><?= money($row['gross']) ?> €</td>
                    <td><?= money($row['pension']) ?> €</td>
                    <td><?= money($row['employee_unemployment']) ?> €</td>
                    <td><?= money($row['allowance']) ?> €</td>
                    <td><?= money($row['income_tax']) ?> €</td>
                    <td><strong><?= money($row['net']) ?> €</strong></td>
                    <td><?= money($row['employer_unemployment']) ?> €</td>
                    <td><?= money($row['social_tax']) ?> €</td>
                    <td><?= h($row['minimum_note'] ?: '—') ?></td>
                </tr>
            <?php endforeach; ?></tbody>
            <tfoot><tr>
                <th>Kokku</th><th><?= money($grossTotal) ?> €</th><th><?= money($pensionTotal) ?> €</th>
                <th><?= money($employeeUnemploymentTotal) ?> €</th><th>—</th><th><?= money($incomeTaxTotal) ?> €</th>
                <th><?= money($netTotal) ?> €</th><th><?= money($employerUnemploymentTotal) ?> €</th><th><?= money($socialTaxTotal) ?> €</th><th></th>
            </tr></tfoot>
        </table></div>
        <p class="total">Töötajatele väljamakstav netosumma: <?= money($netTotal) ?> €</p>
        <p class="total">Tööandja kogukulu: <?= money($employmentCost) ?> €<?= $employerSocialIsCertain ? '' : ' (vajab kontrolli)' ?></p>
        <?php if (!$errors && $employerSocialIsCertain): ?><form method="post" onsubmit="return confirm('Kas salvestad selle maksuarvestuse? Uus arvutus jääb varasemate versioonide kõrvale.');">
            <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="save_calculation">
            <button type="submit">Salvesta arvutus</button>
        </form><?php endif; ?>
    </section>
    <?php endif; ?>
    <?php if ($savedPayslips): ?><section class="panel"><h2>Salvestatud palgalehed</h2>
        <ul><?php foreach ($savedPayslips as $saved): ?>
            <?php if ($saved['calculation_id']): ?><li>
                <a href="payslip.php?id=<?= (int) $runId ?>&amp;employee_id=<?= (int) $saved['employee_id'] ?>">Palgaleht: <?= h($saved['last_name'] . ' ' . $saved['first_name']) ?></a>
                <?php if (filter_var((string) ($saved['email'] ?? ''), FILTER_VALIDATE_EMAIL)): ?>
                    · <form method="post" action="send_payslip_email.php" style="display:inline" onsubmit="return confirm('Saadan palgateatise aadressile <?= h((string) $saved['email']) ?>. Palgateatis sisaldab töötaja isikuandmeid. Kas jätkan?')">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="run_id" value="<?= (int) $runId ?>">
                        <input type="hidden" name="employee_id" value="<?= (int) $saved['employee_id'] ?>">
                        <button type="submit">Saada palgateatis</button>
                    </form>
                <?php else: ?>
                    · <span class="muted">e-post puudub (lisa töötajakaardil)</span>
                <?php endif; ?>
                <?php $employeeEmailHistory = $payslipHistoryByEmployee[(int) $saved['employee_id']] ?? []; ?>
                <?php if ($employeeEmailHistory): ?>
                    <details style="margin:8px 0 0 18px">
                        <summary>Saadetiste ajalugu (<?= count($employeeEmailHistory) ?>)</summary>
                        <ul>
                        <?php foreach ($employeeEmailHistory as $emailLog): ?>
                            <li>
                                <?= h($emailLog['completed_at'] ?: $emailLog['attempted_at']) ?> ·
                                <?= h($emailLog['recipient_email']) ?> ·
                                <?= h(['sent' => 'Saadetud', 'failed' => 'Ebaõnnestus', 'pending' => 'Pooleli'][$emailLog['status']] ?? $emailLog['status']) ?>
                                <?php if ($emailLog['status'] === 'failed' && $emailLog['error_message']): ?>
                                    · <?= h($emailLog['error_message']) ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
            </li><?php endif; ?>
        <?php endforeach; ?></ul>
    </section><?php endif; ?>
    <section class="panel"><p class="muted">Arvutus toetab praegu 2026. aasta tavalist Eesti töölepingu palka. Töötuskindlustuse ning sotsiaalmaksu miinimumi erandid lähtuvad töötaja maksuseadetest. Kui sotsiaalmaksu miinimumkohustuse erand kehtib, märgi see maksuseadetes koos kehtivuse alguskuupäevaga. See on eelvaade, mitte TSD deklaratsioon. MTA näidete järgi arvutatakse tulumaks pärast töötaja töötuskindlustus- ja II samba makseid ning maksuvaba tulu.</p></section>
</main>
</body>
</html>
