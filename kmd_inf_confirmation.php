<?php
require_once __DIR__ . '/kmd_asset_car_vat.php';
require_once __DIR__ . '/credit_note_lib.php';
require_once __DIR__ . '/import_vat.php';
require_once __DIR__ . '/purchase_vat_period.php';
/** Persistent, company-scoped confirmation of the exact invoice snapshot. */
function ensureInfConfirmationSchema(PDO $pdo): void
{
    if($pdo->inTransaction()) return;
    ensureCompanyCashVatSchema($pdo);
    ensurePurchaseVatAdjustmentSchema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS kmd_inf_purchase_confirmations (
        company_id INT NOT NULL,
        invoice_id INT NOT NULL,
        period CHAR(7) NOT NULL,
        invoice_hash CHAR(64) NOT NULL,
        deductible_cents BIGINT NULL,
        vat_purpose VARCHAR(32) NOT NULL DEFAULT 'ordinary',
        car_ids TEXT NULL,
        confirmed_by INT NOT NULL,
        confirmed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (company_id, invoice_id, period)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $columns = $pdo->query('SHOW COLUMNS FROM kmd_inf_purchase_confirmations')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('deductible_cents', $columns, true)) {
        $pdo->exec('ALTER TABLE kmd_inf_purchase_confirmations ADD COLUMN deductible_cents BIGINT NULL');
    }
    foreach ([
        'vat_purpose' => "VARCHAR(32) NOT NULL DEFAULT 'ordinary'",
        'car_ids' => 'TEXT NULL',
    ] as $column => $definition) {
        if (!in_array($column,$columns,true)) $pdo->exec("ALTER TABLE kmd_inf_purchase_confirmations ADD COLUMN " . $column . " " . $definition);
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS kmd_vat_period_cars (
        company_id INT NOT NULL, period CHAR(7) NOT NULL, car_identifier VARCHAR(20) NOT NULL,
        usage_kind VARCHAR(10) NOT NULL, PRIMARY KEY (company_id, period, car_identifier)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function applyInfConfirmations(PDO $pdo, int $companyId, string $period, array $invoices): array
{
    $stmt = $pdo->prepare('SELECT c.invoice_id,c.invoice_hash,c.deductible_cents,c.vat_purpose,c.car_ids,
        a.allocations_json FROM kmd_inf_purchase_confirmations c
        LEFT JOIN purchase_vat_adjustments a ON a.company_id=c.company_id AND a.invoice_id=c.invoice_id AND a.source_hash=c.invoice_hash
        WHERE c.company_id=? AND c.period=?');
    $stmt->execute([$companyId, $period]);
    $records = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    foreach ($invoices as &$invoice) {
        if (isset($invoice['period_plan_total'])) continue;
        $deductionVat = (int) ($invoice['deduction_vat'] ?? $invoice['vat']);
        $invoice['deduction_confirmed'] = isset($records[$invoice['id']])
            && hash_equals($records[$invoice['id']]['invoice_hash'], $invoice['confirmation_hash']);
        $invoice['deductible_cents'] = $invoice['deduction_confirmed']
            ? (int) ($records[$invoice['id']]['deductible_cents'] ?? $deductionVat) : null;
        if ($invoice['deduction_confirmed'] && ($invoice['deductible_cents'] < min(0,$deductionVat) || $invoice['deductible_cents'] > max(0,$deductionVat))) {
            $invoice['deduction_confirmed'] = false;
            $invoice['deductible_cents'] = null;
        }
        $invoice['vat_purpose']=(string)($records[$invoice['id']]['vat_purpose']??'ordinary');
        $savedIds=json_decode((string)($records[$invoice['id']]['car_ids']??'[]'),true);
        $invoice['car_ids']=is_array($savedIds)?$savedIds:[];
        if(!empty($invoice['import_goods']) && $deductionVat===0 && !$invoice['qualifying_rates']) {
            $invoice['deduction_confirmed']=true; $invoice['deductible_cents']=0;
            $invoice['vat_purpose']='ordinary'; $invoice['car_ids']=[];
        }
        if(!empty($invoice['credit_link']) && $invoice['net']<0) {
            $invoice['deduction_confirmed']=true;
            $invoice['deductible_cents']=(int)$invoice['credit_link']['deduction_cents'];
            $invoice['vat_purpose']=$invoice['credit_link']['vat_purpose'];
            $invoice['car_ids']=json_decode((string)$invoice['credit_link']['car_ids'],true)??[];
        }
        if ($invoice['deduction_confirmed']) {
            try {
                $invoice=validateVatPurpose($invoice,$invoice['vat_purpose'],implode(',',$invoice['car_ids']));
                $saved = json_decode($records[$invoice['id']]['allocations_json'] ?? '[]',true,512,JSON_THROW_ON_ERROR);
                if (!empty($invoice['credit_link'])) {
                    $creditState = purchaseVatState($pdo,$companyId,(int)$invoice['id']);
                    $explicit = array_column($creditState['allocations'] ?? [],'vat_line','credit_line_id');
                } else {
                    $explicit = array_column($saved,'vat_line','original_line_id');
                }
                if ($explicit) {
                    $lines = validatePurchaseVatLines($invoice,$explicit,!empty($invoice['credit_link']));
                    if (array_sum(array_column($lines,'deductible_cents')) !== $invoice['deductible_cents']) {
                        throw new InvalidArgumentException('Arveridade mahaarvamine ei klapi arve kinnitatud summaga.');
                    }
                    $invoice['vat_line_allocations'] = $lines;
                } elseif (!empty($invoice['requires_line_vat'])) {
                    throw new InvalidArgumentException('Pöördmaksuga segaarve mahaarvamine vajab kinnitatud reajaotust.');
                }
            } catch (InvalidArgumentException $exception) {
                $invoice['deduction_confirmed']=false;
                $invoice['deductible_cents']=null;
                $invoice['classification_error']=$exception->getMessage();
            }
        }
        if ($invoice['deduction_confirmed']) {
            $invoice['review'] = array_values(array_filter($invoice['review'],
                static fn(string $note): bool => $note !== 'Kontrolli, kas arve sisendkäibemaks kuulub KMD lahtris 5 mahaarvamisele.'));
        }
    }
    unset($invoice);
    return $invoices;
}

function saveInfConfirmations(PDO $pdo, int $companyId, string $period, int $userId, array $invoices): void
{
    ensureInfConfirmationSchema($pdo); ensureCreditNoteSchema($pdo);
    require_once __DIR__.'/kmd_inf_data.php';
    usort($invoices,static fn(array $a,array $b):int=>(int)$a['id']<=>(int)$b['id']);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO kmd_inf_purchase_confirmations
            (company_id, invoice_id, period, invoice_hash, confirmed_by, deductible_cents, vat_purpose, car_ids)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE invoice_hash = VALUES(invoice_hash),
                vat_purpose = VALUES(vat_purpose), car_ids = VALUES(car_ids), deductible_cents = VALUES(deductible_cents), confirmed_by = VALUES(confirmed_by), confirmed_at = CURRENT_TIMESTAMP');
        foreach ($invoices as $invoice) {
            $lock=$pdo->prepare('SELECT id,invoice_date FROM purchase_invoices WHERE id=? AND company_id=? FOR UPDATE');
            $lock->execute([$invoice['id'],$companyId]);
            $locked=$lock->fetch(PDO::FETCH_ASSOC);
            if(!$locked || substr($locked['invoice_date'],0,7)!==$period) throw new InvalidArgumentException('Arve ei kuulu valitud ettevõtte ja perioodi alla.');
            if (purchaseVatPeriodPlan($pdo, $companyId, (int)$invoice['id'])) {
                throw new InvalidArgumentException('Muuda esmalt arve mahaarvamise kuude jaotust ostuarve juures.');
            }
            if($issue=closedAccountingPeriodMessage($pdo,$companyId,$locked['invoice_date'])) throw new InvalidArgumentException($issue);
            $freshRows=loadInfRows($pdo,$companyId,$locked['invoice_date'],$locked['invoice_date'],'purchase'); $fresh=null;
            foreach($freshRows['invoices'] as $row) if((int)$row['id']===(int)$invoice['id']) $fresh=$row;
            if(!$fresh || !empty($fresh['credit_link']) || !hash_equals($fresh['confirmation_hash'],$invoice['confirmation_hash'])) throw new InvalidArgumentException('Arve andmed on vahepeal muutunud. Värskenda eelvaadet.');
            $issues=array_values(array_filter($fresh['review'],static fn(string $note):bool=>$note!=='Kontrolli, kas arve sisendkäibemaks kuulub KMD lahtris 5 mahaarvamisele.'));
            if($issues) throw new InvalidArgumentException(implode(' ',$issues));
            if (!empty($invoice['vat_line_allocations'])) {
                $fresh['vat_line_allocations'] = validatePurchaseVatLines($fresh,$invoice['vat_line_allocations']);
                $invoice['deductible_cents'] = array_sum(array_column($fresh['vat_line_allocations'],'deductible_cents'));
                $invoice['vat_purpose'] = 'ordinary';
                $invoice['car_ids'] = [];
            } elseif (!empty($fresh['requires_line_vat'])) {
                throw new InvalidArgumentException('Pöördmaksuga segaarvel kinnita mahaarvatav käibemaks arveridade kaupa.');
            }
            $fresh['deductible_cents']=$invoice['deductible_cents']??$fresh['deduction_vat'];
            $fresh['vat_purpose']=$invoice['vat_purpose']??'ordinary'; $fresh['car_ids']=$invoice['car_ids']??[]; $invoice=$fresh;
            $invoice=validateVatPurpose($invoice,$invoice['vat_purpose']??'ordinary',implode(',',$invoice['car_ids']??[]));
            foreach(loadCreditLinks($pdo,$companyId,'purchase') as $link) {
                if(($link['credit_status']??'posted')!=='posted' || (int)$link['original_invoice_id']!==(int)$invoice['id']) continue;
                $saved=$pdo->prepare('SELECT deductible_cents,vat_purpose,car_ids FROM kmd_inf_purchase_confirmations WHERE company_id=? AND invoice_id=? AND period=?');
                $saved->execute([$companyId,$invoice['id'],$period]); $old=$saved->fetch(PDO::FETCH_ASSOC);
                if(!$old || $old['vat_purpose']!==$invoice['vat_purpose']
                    || vatCarIds(implode(',',json_decode((string)$old['car_ids'],true)??[]))!==$invoice['car_ids']) {
                    throw new InvalidArgumentException('Algsel ostuarvel on kreeditarve. Selle põhivara/sõiduauto jaotust ei saa enam eraldi muuta.');
                }
            }
            $source=syncPurchaseVatOriginal($pdo,$companyId,$userId,$invoice);
            syncPurchaseVatCredits($pdo,$companyId,$userId,(int)$invoice['id'],$source);
            $stmt->execute([$companyId, $invoice['id'], $period, $invoice['confirmation_hash'], $userId, $invoice['deductible_cents'] ?? $invoice['deduction_vat'] ?? $invoice['vat'], $invoice['vat_purpose'], json_encode($invoice['car_ids'],JSON_THROW_ON_ERROR)]);
        }
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function canAllocatePurchaseVatLines(array $invoice, bool $credit = false): bool
{
    return count($invoice['vat_input_lines'] ?? []) >= ($credit ? 1 : 2)
        && ($credit || $invoice['country_code'] === 'EE' || !empty($invoice['reverse_line_count'])
            || foreignPurchaseEstonianVat($invoice) !== null);
}

function validatePurchaseVatLines(array $invoice, array $allocation, bool $credit = false): array
{
    $source = $invoice['vat_input_lines'] ?? [];
    if (!canAllocatePurchaseVatLines($invoice, $credit)
        || $credit !== !empty($invoice['credit_link']) || array_diff_key($source,$allocation) || array_diff_key($allocation,$source)) {
        throw new InvalidArgumentException('Reajaotus peab sisaldama kõiki arveridu ja sobima arve maksukäsitlusega.');
    }
    $result = [];
    foreach ($source as $id => $line) {
        $data = $allocation[$id];
        $spec = $line['reverse_spec'] ?? null;
        if ($spec && $spec['scope'] === 'domestic' && $data['vat_purpose'] !== 'ordinary') {
            throw new InvalidArgumentException('Segaarve pöördmaksurea ostuliigiks vali tavaline ost.');
        }
        if ($spec && $spec['scope'] !== 'domestic' && $data['deductible_cents'] !== 0
            && foreignReverseDeductionCode($spec, $data['vat_purpose']) === null) {
            throw new InvalidArgumentException('Selle välisostu rea ostuliigile puudub vastav XBRL-i mahaarvamise liik. Kontrolli rea liigitust.');
        }
        $result[$id] = validateVatPurpose(['vat'=>$line['vat'],'deductible_cents'=>$data['deductible_cents']],
            $data['vat_purpose'],implode(',',$data['car_ids']));
    }
    return $result;
}

function infPurchaseDeduction(array $invoice, bool $allPeriods = false): int
{
    if (empty($invoice['reverse_line_count']) || ($invoice['reverse_scope'] ?? '') === 'domestic') {
        if ($allPeriods && isset($invoice['period_plan_total'])) return $invoice['period_plan_total'];
        return $invoice['deductible_cents'] ?? 0;
    }
    $deduction = 0;
    foreach ($invoice['vat_line_allocations'] ?? [] as $id => $line) {
        if (empty($invoice['vat_input_lines'][$id]['reverse'])) {
            $deduction += $allPeriods ? ($line['period_plan_total'] ?? $line['deductible_cents']) : $line['deductible_cents'];
        }
    }
    return $deduction;
}

/** Same snapshots and saved amounts for the report and both KMD exports. */
function loadPurchaseDeductions(PDO $pdo, int $companyId, string $startDate, string $endDate): array
{
    require_once __DIR__ . '/kmd_inf_data.php';
    ensureInfConfirmationSchema($pdo);
    $loaded = loadPurchasePeriodRows($pdo, $companyId, $startDate, $endDate);
    $byMonth = [];
    foreach ($loaded['invoices'] as $invoice) {
        $byMonth[substr($invoice['report_date'] ?? $invoice['document_date'], 0, 7)][] = $invoice;
    }
    $result = ['invoices' => [], 'total' => 0, 'pending' => [], 'errors' => $loaded['errors'], 'reverse_vat' => 0, 'reverse_base_by_rate' => [], 'eu_goods_net' => 0, 'eu_services_net' => 0, 'domestic_purchase_net' => 0, 'non_eu_services_net' => 0, 'fixed_assets_vat' => 0, 'cars_vat' => 0, 'cars_partial_vat' => 0, 'number_of_cars' => 0, 'number_of_cars_partial' => 0];
    $cursor=new DateTimeImmutable(substr($startDate,0,7).'-01');
    $last=new DateTimeImmutable(substr($endDate,0,7).'-01');
    for(; $cursor<=$last; $cursor=$cursor->modify('+1 month')) $byMonth[$cursor->format('Y-m')] ??= [];
    foreach ($byMonth as $period => $invoices) {
        $invoices=applyInfConfirmations($pdo,$companyId,$period,$invoices);
        $carTotals=vatCarTotals($invoices,loadVatCars($pdo,$companyId,$period));
        foreach(['fixed_assets_vat','cars_vat','cars_partial_vat','number_of_cars','number_of_cars_partial'] as $field) $result[$field]+=$carTotals[$field];
        $result['errors']=array_merge($result['errors'],$carTotals['errors']);
        foreach ($invoices as $invoice) {
            if (($invoice['deduction_vat'] ?? $invoice['vat']) === 0 && !$invoice['deduction_confirmed']) {
                $invoice['deductible_cents'] = 0;
            } elseif (!$invoice['deduction_confirmed']) {
                $result['pending'][] = $invoice['document_number'];
            }
            $result['reverse_vat'] += $invoice['reverse_vat'];
            $result['eu_goods_net'] += $invoice['eu_goods_net'];
            $result['eu_services_net'] += $invoice['eu_services_net'];
            $result['domestic_purchase_net'] += $invoice['domestic_purchase_net'];
            $result['non_eu_services_net'] += $invoice['non_eu_services_net'];
            foreach ($invoice['reverse_base_by_rate'] as $rate => $net) {
                $result['reverse_base_by_rate'][$rate] = ($result['reverse_base_by_rate'][$rate] ?? 0) + $net;
            }
            $result['total'] += $invoice['deductible_cents'] ?? 0;
            $previousInvoice = $result['invoices'][$invoice['id']] ?? [];
            $previous = $previousInvoice['deductible_cents'] ?? 0;
            $result['invoices'][$invoice['id']] = $invoice;
            if (isset($invoice['period_plan_total'])) {
                $result['invoices'][$invoice['id']]['deductible_cents'] += $previous;
                if ($previous !== 0 && !empty($invoice['vat_line_allocations'])) {
                    foreach ($invoice['vat_line_allocations'] as $id=>$line) {
                        $priorLine = $previousInvoice['vat_line_allocations'][$id]['deductible_cents'] ?? 0;
                        $result['invoices'][$invoice['id']]['vat_line_allocations'][$id]['deductible_cents'] += $priorLine;
                    }
                }
            }
        }
    }
    $imports=loadImportVat($pdo,$companyId,$startDate,$endDate);
    $result['import_documents']=$imports['documents'];
    $result['import_vat']=$imports['deductible'];
    $result['import_payable']=$imports['payable'];
    $result['total']+=$imports['deductible'];
    $result['fixed_assets_vat']+=$imports['fixed_assets'];
    $result['errors']=array_merge($result['errors'],$imports['errors']);
    $result['scheduled'] = $loaded['scheduled'] ?? [];
    $result['planned_invoice_ids'] = $loaded['planned_invoice_ids'] ?? [];
    $result['has_period_plan'] = $loaded['has_period_plan'] ?? false;
    return $result;
}

function parseDeductibleCents(string $value, int $invoiceVat): int
{
    $value = str_replace(',', '.', trim($value));
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
        throw new InvalidArgumentException('Sisesta mahaarvatav summa eurodes kuni kahe komakohaga.');
    }
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    if (strlen($whole) > 12) throw new InvalidArgumentException('Mahaarvatav summa on liiga suur.');
    $amount = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    if ($amount < 0 || $amount > $invoiceVat) {
        throw new InvalidArgumentException('Mahaarvatav summa peab jääma nulli ja arve käibemaksusumma vahele.');
    }
    return $amount;
}
