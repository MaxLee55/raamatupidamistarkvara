<?php
require_once __DIR__.'/import_vat.php';

function ensureKmdManualAdjustments(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS kmd_manual_adjustments (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL, journal_entry_id INT NOT NULL, active_entry_id INT NULL,
        account_id INT NOT NULL, entry_date DATE NOT NULL, amount_cents BIGINT NOT NULL,
        entry_hash CHAR(64) NOT NULL, basis VARCHAR(500) NOT NULL, created_by INT NOT NULL,
        allocation_json TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        voided_at DATETIME NULL, voided_by INT NULL,
        UNIQUE KEY kmd_adjust_active (company_id,active_entry_id),
        KEY kmd_adjust_period (company_id,entry_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!$pdo->query("SHOW COLUMNS FROM kmd_manual_adjustments LIKE 'allocation_json'")->fetch()) {
        $pdo->exec('ALTER TABLE kmd_manual_adjustments ADD COLUMN allocation_json TEXT NULL');
    }
}

function kmdAdjustmentAccountAmounts(PDO $pdo,int $company,array $entry,int $accountId): array
{
    $q=$pdo->prepare('SELECT code,account_type FROM accounts WHERE company_id=? AND id=? AND is_active=1');
    $q->execute([$company,$accountId]); $account=$q->fetch(PDO::FETCH_ASSOC);
    if(!$account || !in_array($account['account_type'],['asset','liability'],true) || preg_match('/^10[0-9]{2}$/',$account['code'])) {
        throw new InvalidArgumentException('Vali ettevõtte aktiivne käibemaksukonto; panga- ega kulukonto ei sobi.');
    }
    $debit=$credit=0;
    foreach($entry['lines'] as $line) if($line['account']===$accountId) { $debit+=$line['debit']; $credit+=$line['credit']; }
    if(!$debit && !$credit) throw new InvalidArgumentException('Valitud käibemaksukontol puudub selle kande summa.');
    return ['debit'=>$debit,'credit'=>$credit,'net'=>$credit-$debit];
}

function validateKmdAdjustmentRates(array $allocations,array $account): array
{
    $normal=[]; $positive=$negative=0;
    foreach($allocations as $rate=>$amount) {
        if(!preg_match('/^\d{1,2}(?:\.\d{1,2})?$/D',(string)$rate) || !in_array((float)$rate,[24.0,13.0,9.0,22.0,20.0,5.0],true)
            || !is_int($amount) || $amount===0 || abs($amount)>999999999999) {
            throw new InvalidArgumentException('Sisesta iga paranduse tegelik maksumäär ja nullist erinev senditäpsusega summa.');
        }
        $key=number_format((float)$rate,2,'.','');
        $normal[$key]=($normal[$key]??0)+$amount;
        if($amount>0) $positive+=$amount; else $negative-=$amount;
    }
    $normal=array_filter($normal,static fn(int $value):bool=>$value!==0);
    if(!$normal || array_sum($normal)!==$account['net'] || $positive>$account['credit'] || $negative>$account['debit']) {
        throw new InvalidArgumentException('Maksumäärade jaotus ei klapi valitud käibemaksukonto summade ja suundadega.');
    }
    ksort($normal,SORT_NUMERIC); return $normal;
}

function saveKmdManualAdjustment(PDO $pdo, int $company, int $user, int $entryId, int $accountId, string $basis, ?int $expectedAmount = null, ?array $rateAmounts = null): void
{
    $basis = trim($basis);
    if ($basis === '' || mb_strlen($basis) > 500) throw new InvalidArgumentException('Sisesta paranduse alus kuni 500 märgiga.');
    $pdo->beginTransaction();
    try {
        $entry = importEntry($pdo,$company,$entryId);
        if ($issue = closedAccountingPeriodMessage($pdo,$company,$entry['entry_date'])) throw new InvalidArgumentException($issue);
        foreach (['sales_invoices','purchase_invoices','invoice_payments','purchase_invoice_payments',
            'import_vat_documents','import_vat_payments','purchase_vat_adjustment_history','credit_note_refunds'] as $table) {
            $query = $pdo->prepare("SELECT 1 FROM $table WHERE journal_entry_id=? LIMIT 1");
            $query->execute([$entryId]);
            if ($query->fetchColumn()) throw new InvalidArgumentException('Vali eraldi paranduskande number. Arve või makse kanne on seotud tavapärase arvestusega.');
        }
        $account=kmdAdjustmentAccountAmounts($pdo,$company,$entry,$accountId);
        $amount=$account['net'];
        $allocations=null;
        if($entry['entry_date']>='2027-04-01') {
            if($rateAmounts===null) throw new InvalidArgumentException('XBRL paranduse jaoks sisesta kontrollitud maksumäärade jaotus.');
            $allocations=validateKmdAdjustmentRates($rateAmounts,$account);
        } elseif($amount===0) throw new InvalidArgumentException('Valitud käibemaksukontol puudub selle kande netosumma.');
        if ($expectedAmount !== null && $amount !== $expectedAmount) {
            throw new InvalidArgumentException('Käibemaksukonto netosumma ei klapi arvutatud täpsustusega. Kontrolli summat ja kande suunda.');
        }
        $query = $pdo->prepare('SELECT 1 FROM kmd_manual_adjustments WHERE company_id=? AND active_entry_id=?');
        $query->execute([$company,$entryId]);
        if ($query->fetchColumn()) throw new InvalidArgumentException('See kanne on KMD täpsustusena juba seotud.');
        $pdo->prepare('INSERT INTO kmd_manual_adjustments
            (company_id,journal_entry_id,active_entry_id,account_id,entry_date,amount_cents,entry_hash,basis,created_by,allocation_json)
            VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$company,$entryId,$entryId,$accountId,$entry['entry_date'],$amount,$entry['hash'],$basis,$user,
                $allocations===null?null:json_encode($allocations,JSON_THROW_ON_ERROR)]);
        $pdo->commit();
    } catch (Throwable $error) {
        if($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

function voidKmdManualAdjustment(PDO $pdo, int $company, int $user, int $id): void
{
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT entry_date FROM kmd_manual_adjustments WHERE id=? AND company_id=? AND active_entry_id IS NOT NULL FOR UPDATE');
        $query->execute([$id,$company]);
        $date = $query->fetchColumn();
        if (!$date) throw new InvalidArgumentException('Aktiivset täpsustust ei leitud.');
        if ($issue = closedAccountingPeriodMessage($pdo,$company,$date)) throw new InvalidArgumentException($issue);
        $pdo->prepare('UPDATE kmd_manual_adjustments SET active_entry_id=NULL,voided_at=CURRENT_TIMESTAMP,voided_by=? WHERE id=? AND company_id=?')
            ->execute([$user,$id,$company]);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function loadKmdManualAdjustments(PDO $pdo, int $company, string $start, string $end): array
{
    $query = $pdo->prepare('SELECT * FROM kmd_manual_adjustments WHERE company_id=? AND entry_date BETWEEN ? AND ? ORDER BY entry_date,id');
    $query->execute([$company,$start,$end]);
    $result = ['rows'=>$query->fetchAll(PDO::FETCH_ASSOC),'net'=>0,'details'=>[],'errors'=>[]];
    foreach ($result['rows'] as $row) {
        if ($row['voided_at'] !== null) continue;
        try {
            $entry = importEntry($pdo,$company,(int)$row['journal_entry_id'],false);
            if (!hash_equals($row['entry_hash'],$entry['hash'])) throw new RuntimeException('Seotud pearaamatukanne on muutunud.');
            if($entry['entry_date']!==$row['entry_date']) throw new RuntimeException('Paranduse periood ei klapi pearaamatuga.');
            $account=kmdAdjustmentAccountAmounts($pdo,$company,$entry,(int)$row['account_id']);
            if($account['net']!==(int)$row['amount_cents']) throw new RuntimeException('Paranduse summa ei klapi pearaamatuga.');
            if($row['entry_date']>='2027-04-01') {
                $allocation=json_decode($row['allocation_json']??'null',true,512,JSON_THROW_ON_ERROR);
                if(!is_array($allocation)) throw new RuntimeException('XBRL paranduse maksumäärade jaotus puudub.');
                $allocation=validateKmdAdjustmentRates($allocation,$account);
                foreach($allocation as $rate=>$amount) $result['details'][]=[
                    'entry_type'=>'O_601','amount_cents'=>$amount,'vat_rate'=>(float)$rate/100,'party'=>null,
                    'document_number'=>'KM-PAR-'.$row['id'].' / kanne '.$row['journal_entry_id'],
                    'document_date'=>$row['entry_date'],
                ];
            }
            $result['net'] += (int)$row['amount_cents'];
        } catch (RuntimeException | InvalidArgumentException | JsonException $error) {
            $result['errors'][] = 'KMD täpsustus '.$row['id'].': '.$error->getMessage();
        }
    }
    return $result;
}
