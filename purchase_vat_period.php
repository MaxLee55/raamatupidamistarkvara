<?php
require_once __DIR__.'/period_lock.php';
require_once __DIR__.'/cash_vat_preview.php';
require_once __DIR__.'/cash_vat_events.php';
require_once __DIR__.'/company_vat_status.php';
require_once __DIR__.'/cash_purchase_rows.php';

// JSON null represents a payment-driven plan. Manual plans always store a month-to-cents map.
const CASH_SUPPLIER_PLAN = 'null';
const CASH_COMPANY_PLAN = '"cash_company"';

function isCashVatPlan(?string $allocation): bool
{
    return in_array($allocation,[CASH_SUPPLIER_PLAN,CASH_COMPANY_PLAN],true);
}

function purchaseCashSupplierPayments(PDO $pdo, int $company, int $id, string $end = '9999-12-31'): array
{
    $query = $pdo->prepare("SELECT p.payment_date,p.amount FROM purchase_invoice_payments p
        JOIN journal_entries j ON j.id=p.journal_entry_id AND j.company_id=? AND j.status='posted'
        WHERE p.invoice_id=? AND p.payment_date<=? ORDER BY p.payment_date,p.id");
    $query->execute([$company, $id, $end]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function purchaseCashSupplierAllocations(array $source, array $payments, string $received): array
{
    if (!$source['deduction_confirmed'] || $source['country_code'] !== 'EE'
        || ($source['reverse_line_count'] && $source['reverse_scope']!=='domestic')) {
        throw new InvalidArgumentException('Kassapõhine jaotus sobib kinnitatud Eesti ostule; EL soetus ja välisriigi teenused kasutavad üldkorda.');
    }
    cashPurchaseLines($source);
    $allocations = cashVatPaymentDates($payments, $source['gross'], $source['deductible_cents']);
    if ($payments && !$allocations) {
        throw new InvalidArgumentException('Registreeritud maksed ja arve summa vajavad kontrolli.');
    }
    return $allocations;
}

function ensurePurchaseVatPeriodSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_vat_periods (
        company_id INT NOT NULL, invoice_id INT NOT NULL, received_date DATE NOT NULL,
        basis VARCHAR(500) NOT NULL, filed_date DATE NULL, tax_event_date DATE NULL, allocation_json TEXT NOT NULL,
        updated_by INT NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(company_id,invoice_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!$pdo->query("SHOW COLUMNS FROM purchase_vat_periods LIKE 'tax_event_date'")->fetch()) {
        $pdo->exec('ALTER TABLE purchase_vat_periods ADD COLUMN tax_event_date DATE NULL');
    }
}

function purchaseVatPeriodPlan(PDO $pdo, int $company, int $invoice): ?array
{
    $query = $pdo->prepare('SELECT * FROM purchase_vat_periods WHERE company_id=? AND invoice_id=?');
    $query->execute([$company, $invoice]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: null;
}

function purchaseVatPeriodSource(PDO $pdo, int $company, array $invoice, bool $allowUnconfirmed = false): array
{
    $period = substr($invoice['document_date'], 0, 7);
    $confirmed = applyInfConfirmations($pdo, $company, $period, [$invoice])[0];
    if (($confirmed['country_code'] !== 'EE' && !$confirmed['reverse_line_count'] && foreignPurchaseEstonianVat($confirmed) === null)
        || $confirmed['party']['kind'] !== 'business' || !empty($confirmed['credit_link']) || $confirmed['net'] <= 0) {
        throw new InvalidArgumentException('Kuude jaotuseks vali ettevõttelt saadud Eesti ostuarve või toetatud pöördmaksuarve.');
    }
    if ($confirmed['deduction_vat'] <= 0 || ($confirmed['deduction_confirmed'] && $confirmed['deductible_cents'] <= 0)
        || (!$allowUnconfirmed && !$confirmed['deduction_confirmed'])) {
        throw new InvalidArgumentException('Kuude jaotuseks on vaja positiivset kinnitatud mahaarvatavat käibemaksu.');
    }
    return $confirmed;
}

function validatePurchaseVatPeriodAllocations(array $allocations, array $source): void
{
    $lineVat = $source['vat_line_allocations'] ?? [];
    $totals = [];
    $total = 0;
    foreach ($allocations as $allocation) {
        if (!$lineVat) {
            if (!is_int($allocation) || $allocation <= 0) throw new InvalidArgumentException('Kuude jaotus on vigane.');
            $total += $allocation;
            continue;
        }
        if (!is_array($allocation) || array_diff_key($lineVat,$allocation) || array_diff_key($allocation,$lineVat)) {
            throw new InvalidArgumentException('Iga kuu jaotus peab sisaldama kõiki kinnitatud arveridu.');
        }
        foreach ($allocation as $id=>$amount) {
            if (!is_int($amount) || $amount < 0) throw new InvalidArgumentException('Arverea kuusumma on vigane.');
            $totals[$id] = ($totals[$id] ?? 0) + $amount;
            $total += $amount;
        }
    }
    foreach ($lineVat as $id=>$line) {
        if (($totals[$id] ?? 0) !== $line['deductible_cents']) {
            throw new InvalidArgumentException('Iga arverea kuude summad peavad kokku võrduma rea kinnitatud mahaarvatava summaga.');
        }
    }
    if ($total !== $source['deductible_cents']) throw new InvalidArgumentException('Kuude summad peavad kokku võrduma arve kinnitatud mahaarvatava summaga.');
}

function savePurchaseVatPeriodPlan(PDO $pdo, int $company, int $user, int $id, array $form): void
{
    $companyCashPeriod = ($form['action'] ?? '') === 'cash_company' ? companyCashVatPeriod($pdo,$company) : [];
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare("SELECT * FROM purchase_invoices WHERE company_id=? AND id=? AND status='posted' FOR UPDATE");
        $query->execute([$company, $id]);
        $invoice = $query->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) throw new InvalidArgumentException('Kinnitatud ostuarvet ei leitud.');
        $date = $invoice['invoice_date'];
        $origin = substr($date, 0, 7);
        $previous = purchaseVatPeriodPlan($pdo, $company, $id);
        if (!hash_equals(hash('sha256', json_encode($previous, JSON_THROW_ON_ERROR)), (string)($form['snapshot'] ?? ''))) {
            throw new InvalidArgumentException('Jaotus on vahepeal muutunud. Ava arve uuesti.');
        }
        $action = $form['action'] ?? 'save';
        $wasCash = isCashVatPlan($previous['allocation_json'] ?? null);
        $cashAction = in_array($action,['cash_supplier','cash_company'],true);
        if (($cashAction && $previous) || ($wasCash && $action !== 'remove')) {
            throw new InvalidArgumentException('Eemalda olemasolev perioodijaotus enne uue jaotuse valimist.');
        }
        $old = $previous && !$wasCash ? json_decode($previous['allocation_json'], true, 512, JSON_THROW_ON_ERROR) : [];
        foreach (loadCreditLinks($pdo, $company, 'purchase') as $link) {
            if ((int)$link['original_invoice_id'] === $id && $link['credit_status'] === 'posted') {
                throw new InvalidArgumentException('Selle arvega on seotud aktiivne kreeditarve.');
            }
        }
        $allocations = [];
        $received = trim((string)($form['received_date'] ?? ''));
        $filed = trim((string)($form['filed_date'] ?? ''));
        $basis = trim((string)($form['basis'] ?? ''));
        $event = trim((string)($form['tax_event_date'] ?? '')) ?: $date;
        if (($invoice['invoice_currency'] ?? 'EUR') !== 'EUR' && $action !== 'remove') {
            if ($cashAction) throw new InvalidArgumentException('Kassapõhise käibemaksuga välisvaluutaarve vajab eraldi arvestust.');
            if ($event !== $invoice['fx_event_date']) throw new InvalidArgumentException('Maksusündmuse kuupäev peab vastama arve valuutakursi aluseks olevale kuupäevale.');
        }
        $eventMonth = substr($event, 0, 7);
        $removing = $action === 'remove';
        if ($cashAction || $wasCash) {
            $payments = purchaseCashSupplierPayments($pdo, $company, $id);
            $affectedDates = array_filter(array_merge([$date,$previous['received_date']??null,$previous['tax_event_date']??null,
                $received?:null,($form['tax_event_date']??'')?:null], array_column($payments, 'payment_date')));
            foreach (array_unique(array_map(static fn(string $day): string => substr($day, 0, 7), $affectedDates)) as $month) {
                $day = new DateTimeImmutable($month.'-01');
                $end = $day->modify('first day of next month');
                for (; $day < $end; $day = $day->modify('+1 day')) {
                    if ($issue = closedAccountingPeriodMessage($pdo, $company, $day->format('Y-m-d'))) {
                        throw new InvalidArgumentException($issue);
                    }
                }
            }
            if ($wasCash) {
                $pdo->prepare('DELETE FROM purchase_vat_periods WHERE company_id=? AND invoice_id=?')->execute([$company, $id]);
            } else {
                $receivedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $received);
                if (!$receivedDate || $receivedDate->format('Y-m-d') !== $received || $received < $date || $received > date('Y-m-d')
                    || $basis === '' || zeroLength($basis) > 500 || empty($form['confirmed'])) {
                    throw new InvalidArgumentException('Sisesta arve saamise kuupäev ja tarnija kassapõhise arvestuse alus ning kinnita andmed.');
                }
                if($filed!=='') {
                    $filing=DateTimeImmutable::createFromFormat('!Y-m-d',$filed);
                    if(!$filing || $filing->format('Y-m-d')!==$filed || $filed<$received || $filed>date('Y-m-d')) throw new InvalidArgumentException('Kontrolli deklaratsiooni tegelikku esitamise kuupäeva.');
                }
                $eventDate=trim((string)($form['tax_event_date']??''))?:$date;
                $parsedEvent=DateTimeImmutable::createFromFormat('!Y-m-d',$eventDate);
                if(!$parsedEvent || $parsedEvent->format('Y-m-d')!==$eventDate) throw new InvalidArgumentException('Kontrolli kauba või teenuse saamise kuupäeva.');
                if ($action === 'cash_company' && !companyUsesCashVat($companyCashPeriod,$eventDate) && !companyUsesCashVat($companyCashPeriod,$date)) {
                    throw new InvalidArgumentException('Kauba või teenuse saamise kuupäev peab jääma ettevõtte kassapõhise arvestuse perioodi.');
                }
                $loaded = loadInfRows($pdo, $company, $date, $date, 'purchase', [$id]);
                if (!$loaded['invoices'] || $loaded['errors']) throw new InvalidArgumentException('Kontrolli ostuarve andmeid.');
                $source = purchaseVatPeriodSource($pdo, $company, $loaded['invoices'][0]);
                if (!hash_equals($source['confirmation_hash'], (string) ($form['invoice_snapshot'] ?? ''))) {
                    throw new InvalidArgumentException('Arve andmed on vahepeal muutunud. Ava arve uuesti.');
                }
                purchaseCashSupplierAllocations($source, $payments, $received);
                $pdo->prepare('INSERT INTO purchase_vat_periods
                    (company_id,invoice_id,received_date,basis,allocation_json,updated_by,tax_event_date,filed_date) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$company,$id,$received,$basis,$action === 'cash_company' ? CASH_COMPANY_PLAN : CASH_SUPPLIER_PLAN,$user,$eventDate,$filed?:null]);
            }
            $pdo->commit();
            return;
        }
        if (!$removing) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $received);
            $eventDate = DateTimeImmutable::createFromFormat('!Y-m-d', $event);
            if (!$parsed || $parsed->format('Y-m-d') !== $received || $received < $date || $received > date('Y-m-d')
                || !$eventDate || $eventDate->format('Y-m-d') !== $event || $event > date('Y-m-d')
                || $basis === '' || zeroLength($basis) > 500 || empty($form['confirmed'])) {
                throw new InvalidArgumentException('Sisesta tegelik arve saamise kuupäev ja jaotuse alus ning kinnita mahaarvamise õiguse tekkimine valitud kuudel.');
            }
            $loaded = loadInfRows($pdo, $company, $date, $date, 'purchase', [$id]);
            if (count($loaded['invoices']) !== 1) throw new InvalidArgumentException('Arve read puuduvad.');
            $source = purchaseVatPeriodSource($pdo, $company, $loaded['invoices'][0], !$previous);
            foreach ($source['codes'] as $code) {
                $specification = reverseVatSpec($code);
                if ($specification && $event < $specification['valid_from']) {
                    throw new InvalidArgumentException('Pöördmaksu kood ei kehti valitud maksustatava sündmuse kuupäeval.');
                }
            }
            if (!hash_equals($source['confirmation_hash'], (string)($form['invoice_snapshot'] ?? ''))) {
                throw new InvalidArgumentException('Arve andmed on vahepeal muutunud. Ava arve uuesti.');
            }
            $total = $source['deduction_confirmed']
                ? $source['deductible_cents']
                : parseDeductibleCents((string)($form['deductible_total'] ?? ''), $source['deduction_vat']);
            $months = $form['month'] ?? [];
            $amounts = $form['amount'] ?? [];
            $lineVat = $source['vat_line_allocations'] ?? [];
            if ($lineVat) {
                $amounts = $form['line_amount'] ?? [];
                if (!is_array($months) || !is_array($amounts) || array_diff_key($lineVat,$amounts) || array_diff_key($amounts,$lineVat)) {
                    throw new InvalidArgumentException('Sisesta kuusummad kõigi arveridade jaoks.');
                }
                $byMonth = array_fill_keys(array_keys($months),[]);
                foreach ($amounts as $lineId=>$lineAmounts) {
                    if (!is_array($lineAmounts) || array_keys($lineAmounts) !== array_keys($months)) {
                        throw new InvalidArgumentException('Arverea summad ei vasta kuude arvule.');
                    }
                    foreach ($lineAmounts as $key=>$amount) {
                        $amount = trim((string)$amount);
                        $byMonth[$key][$lineId] = $amount === '' ? 0 : parseDeductibleCents($amount,$lineVat[$lineId]['deductible_cents']);
                    }
                }
                $amounts = $byMonth;
            }
            if (!is_array($months) || !is_array($amounts) || count($months) > 24 || array_keys($months) !== array_keys($amounts)) {
                throw new InvalidArgumentException('Sisesta kuni 24 jaotusrida.');
            }
            foreach ($months as $key => $month) {
                $amount = $lineVat ? $amounts[$key] : trim((string)$amounts[$key]);
                if ($month === '' && ($lineVat ? array_sum($amount) === 0 : $amount === '')) continue;
                if (!is_string($month) || !preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])$/D', $month)
                    || $month < $eventMonth || isset($allocations[$month])) {
                    throw new InvalidArgumentException('Vali iga mahaarvamise kuu üks kord. Kuu ei või eelneda maksustatava sündmuse kuule.');
                }
                if ($month < substr($received, 0, 7)) {
                    $filingDate = DateTimeImmutable::createFromFormat('!Y-m-d', $filed);
                    if ($month !== $eventMonth || !$filingDate || $filingDate->format('Y-m-d') !== $filed
                        || $filed < $received || $filed > date('Y-m-d') || substr($filed, 0, 7) <= $eventMonth) {
                        throw new InvalidArgumentException('Varasema kuu jaoks märgi deklaratsiooni esitamise kuupäev. Arve peab selleks päevaks olema käes.');
                    }
                }
                $cents = $lineVat ? $amount : parseDeductibleCents($amount, $total);
                if (($lineVat ? array_sum($cents) : $cents) <= 0) throw new InvalidArgumentException('Iga kuu jaotatud summa peab olema positiivne.');
                $allocations[$month] = $cents;
            }
            validatePurchaseVatPeriodAllocations($allocations,array_replace($source,['deductible_cents'=>$total]));
            if (isset($allocations[$origin]) && count($allocations) === 1 && $eventMonth === $origin) {
                throw new InvalidArgumentException('Kogu summa kajastub juba arve kuus. Eraldi jaotust on vaja teise kuu kasutamiseks.');
            }
            ksort($allocations);
            if (!isset($allocations[$eventMonth]) || $eventMonth >= substr($received, 0, 7)) $filed = '';
        }
        $previousEventMonth = substr($previous['tax_event_date'] ?? $date, 0, 7);
        foreach (array_unique(array_merge([$origin, $previousEventMonth, $removing ? $origin : $eventMonth], array_keys($old), array_keys($allocations))) as $month) {
            $day = new DateTimeImmutable($month.'-01');
            $end = $day->modify('first day of next month');
            for (; $day < $end; $day = $day->modify('+1 day')) {
                if ($issue = closedAccountingPeriodMessage($pdo, $company, $day->format('Y-m-d'))) {
                    throw new InvalidArgumentException($issue);
                }
            }
        }
        if ($removing) {
            $pdo->prepare('DELETE FROM purchase_vat_periods WHERE company_id=? AND invoice_id=?')->execute([$company, $id]);
        } else {
            if (!$source['deduction_confirmed']) {
                $source['deductible_cents'] = $total;
                $source['vat_purpose'] = (string)($form['vat_purpose'] ?? 'ordinary');
                $source['car_ids'] = vatCarIds((string)($form['car_ids'] ?? ''));
                saveInfConfirmations($pdo, $company, $origin, $user, [$source]);
            }
            $query = $pdo->prepare('INSERT INTO purchase_vat_periods
                (company_id, invoice_id, received_date, basis, filed_date, tax_event_date, allocation_json, updated_by)
                VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE received_date=VALUES(received_date),
                basis=VALUES(basis), filed_date=VALUES(filed_date), tax_event_date=VALUES(tax_event_date),
                allocation_json=VALUES(allocation_json), updated_by=VALUES(updated_by)');
            $query->execute([$company, $id, $received, $basis, $filed ?: null, $event, json_encode($allocations, JSON_THROW_ON_ERROR), $user]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

/** Original invoice data stays intact for INF columns and threshold calculation. */
function loadPurchasePeriodRows(PDO $pdo, int $company, string $start, string $end): array
{
    $loaded = loadInfRows($pdo, $company, $start, $end, 'purchase');
    $query = $pdo->prepare('SELECT p.invoice_id,p.allocation_json,p.tax_event_date,p.received_date,p.filed_date,i.invoice_date
        FROM purchase_vat_periods p LEFT JOIN purchase_invoices i ON i.id=p.invoice_id AND i.company_id=p.company_id WHERE p.company_id=?');
    $query->execute([$company]);
    $plans = $query->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    if (!$plans) return $loaded;

    $extraIds = [];
    foreach ($plans as $id => &$plan) {
        if (isCashVatPlan($plan['allocation_json'])) {
            if ($plan['invoice_date'] === null || $plan['invoice_date'] <= $end || !empty($plan['filed_date'])) $extraIds[$id] = (int)$id;
            continue;
        }
        $plan['allocations'] = json_decode($plan['allocation_json'], true, 512, JSON_THROW_ON_ERROR);
        $months = array_keys($plan['allocations']);
        if ($plan['tax_event_date']) $months[] = substr($plan['tax_event_date'], 0, 7);
        foreach ($months as $month) {
            if ($month >= substr($start, 0, 7) && $month <= substr($end, 0, 7)) $extraIds[$id] = (int)$id;
        }
    }
    unset($plan);
    $cashCreditParents=[];
    foreach(loadCreditLinks($pdo,$company,'purchase') as $creditId=>$link) {
        $parent=(int)$link['original_invoice_id'];
        if($link['credit_status']==='posted' && isset($plans[$parent]) && isCashVatPlan($plans[$parent]['allocation_json'])) {
            $cashCreditParents[$creditId]=$parent; $extraIds[$creditId]=(int)$creditId;
        }
    }
    $sources = [];
    foreach ($loaded['invoices'] as $invoice) $sources[$invoice['id']] = $invoice;
    if ($extraIds) {
        foreach (loadInfRows($pdo, $company, $start, $end, 'purchase', array_values($extraIds))['invoices'] as $invoice) {
            $sources[$invoice['id']] = $invoice;
        }
    }
    $loaded['invoices'] = [];
    $loaded['scheduled'] = [];
    foreach ($sources as $id => $invoice) {
        if(isset($cashCreditParents[$id])) continue;
        if (!isset($plans[$id])) {
            $loaded['invoices'][] = $invoice;
            continue;
        }
        try {
            $loaded['has_period_plan'] = true;
            $source = purchaseVatPeriodSource($pdo, $company, $invoice);
            if (isCashVatPlan($plans[$id]['allocation_json'])) {
                $loaded['planned_invoice_ids'][$id] = true;
                foreach($cashCreditParents as $creditId=>$parent) if($parent===$id) $loaded['planned_invoice_ids'][$creditId]=true;
                foreach(cashPurchasePeriodRows($pdo,$company,$source,$plans[$id],$sources,$cashCreditParents,$start,$end) as $row) {
                    $loaded['invoices'][]=$row;
                    if($row['deductible_cents']!==0) $loaded['scheduled'][]=$row;
                }
                continue;
            }
            $allocations = $plans[$id]['allocations'];
            validatePurchaseVatPeriodAllocations($allocations,$source);
            $loaded['planned_invoice_ids'][$id] = true;
            $eventDate = $plans[$id]['tax_event_date'] ?? $invoice['document_date'];
            $eventMonth = substr($eventDate, 0, 7);
            if ($source['reverse_line_count']) {
                $allocations[$eventMonth] ??= !empty($source['vat_line_allocations'])
                    ? array_fill_keys(array_keys($source['vat_line_allocations']),0) : 0;
            }
            foreach ($allocations as $month => $amount) {
                $reportDate = $month === $eventMonth ? $eventDate : $month.'-01';
                if ($reportDate < $start || $reportDate > $end) continue;
                $row = $source;
                if (is_array($amount)) {
                    foreach ($row['vat_line_allocations'] as $lineId => &$line) {
                        $line['period_plan_total'] = $line['deductible_cents'];
                        $line['deductible_cents'] = $amount[$lineId];
                        if ($line['deductible_cents'] === 0) $line['car_ids'] = [];
                    }
                    unset($line);
                    $amount = array_sum($amount);
                }
                $row['report_date'] = $reportDate;
                $row['deductible_cents'] = $amount;
                $row['period_plan_total'] = $source['deductible_cents'];
                if ($month !== $eventMonth) {
                    foreach (['reverse_vat','eu_goods_net','eu_services_net','domestic_purchase_net','non_eu_services_net'] as $field) {
                        $row[$field] = 0;
                    }
                    $row['reverse_base_by_rate'] = [];
                    $row['foreign_reverse_bases'] = [];
                }
                if ($amount === 0) $row['car_ids'] = [];
                $loaded['invoices'][] = $row;
                if ($amount !== 0) $loaded['scheduled'][] = $row;
            }
        } catch (InvalidArgumentException $error) {
            $loaded['errors'][] = 'Ostuarve '.$invoice['document_number'].': '.$error->getMessage();
        }
    }
    foreach (array_diff_key($extraIds, $sources) as $id) {
        $loaded['errors'][] = 'Mahaarvamise jaotuse kinnitatud ostuarve #'.$id.' puudub.';
    }
    return $loaded;
}
