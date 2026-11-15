<?php
/** Separate, reversible purchase-VAT cost postings; invoice and supplier totals never change. */
function ensurePurchaseVatAdjustmentSchema(PDO $pdo): void
{
    if($pdo->inTransaction()) return;
    try { $pdo->query('SELECT invoice_id,allocations_json FROM purchase_vat_adjustments LIMIT 0'); $pdo->query('SELECT id FROM purchase_vat_adjustment_history LIMIT 0'); return; } catch(PDOException $missing) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_vat_adjustments (
        company_id INT NOT NULL,invoice_id INT NOT NULL,input_account_id INT NOT NULL,
        amount_cents BIGINT NOT NULL,deductible_cents BIGINT NOT NULL,source_hash CHAR(64) NOT NULL,
        allocations_json MEDIUMTEXT NOT NULL,last_entry_id INT NULL,updated_by INT NOT NULL,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(company_id,invoice_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_vat_adjustment_history (
        id INT NOT NULL AUTO_INCREMENT,company_id INT NOT NULL,invoice_id INT NOT NULL,
        entry_date DATE NOT NULL,event_name VARCHAR(40) NOT NULL,before_json MEDIUMTEXT NOT NULL,after_json MEDIUMTEXT NOT NULL,
        journal_entry_id INT NULL,created_by INT NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(id),KEY invoice_history(company_id,invoice_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function purchaseVatState(PDO $pdo,int $companyId,int $invoiceId): ?array
{
    $stmt=$pdo->prepare('SELECT * FROM purchase_vat_adjustments WHERE company_id=? AND invoice_id=?');
    $stmt->execute([$companyId,$invoiceId]); $state=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$state) return null;
    $state['allocations']=json_decode($state['allocations_json'],true,512,JSON_THROW_ON_ERROR);
    return $state;
}
/** Largest remainder, stable by line ID; never lose a cent across several accounts. */
function purchaseVatMulDiv(int $a,int $b,int $denominator): array
{
    if($a<0 || $b<0 || $denominator<1) throw new InvalidArgumentException('Vigane käibemaksu jaotuse arvutus.');
    if(!$a || !$b) return [0,0];
    if($a<=intdiv(PHP_INT_MAX,$b)) return [intdiv($a*$b,$denominator),($a*$b)%$denominator];
    $base=intdiv($a,$denominator); $part=$a%$denominator; $quotient=0; $remainder=0;
    foreach(str_split(decbin($b)) as $bit) {
        $quotient*=2; $remainder*=2;
        if($remainder>=$denominator) { $remainder-=$denominator; ++$quotient; }
        if($bit==='1') { $quotient+=$base; $remainder+=$part; if($remainder>=$denominator) { $remainder-=$denominator; ++$quotient; } }
    }
    return [$quotient,$remainder];
}
function purchaseVatAllocate(int $amount,array $weights): array
{
    if($amount<0 || min($weights?:[0])<0 || $amount>array_sum($weights)) throw new InvalidArgumentException('Mahaarvamata käibemaksu jaotus on vigane.');
    ksort($weights); $sum=array_sum($weights); $result=[]; $remainders=[]; $left=$amount;
    foreach($weights as $id=>$weight) {
        [$result[$id],$remainders[$id]]=$sum?purchaseVatMulDiv($amount,$weight,$sum):[0,0]; $left-=$result[$id];
    }
    arsort($remainders,SORT_NUMERIC);
    foreach($remainders as $id=>$_) { if(!$left) break; ++$result[$id]; --$left; }
    return $result;
}
function purchaseVatSource(PDO $pdo,int $companyId,int $invoiceId): array
{
    $stmt=$pdo->prepare("SELECT * FROM purchase_invoices WHERE company_id=? AND id=? AND status='posted' FOR UPDATE");
    $stmt->execute([$companyId,$invoiceId]); $invoice=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$invoice || !$invoice['journal_entry_id']) throw new InvalidArgumentException('Mahaarvamise paranduseks on vaja kinnitatud ostuarvet.');
    $stmt=$pdo->prepare('SELECT t.*,tc.code AS tax_code,tc.tax_category,a.code AS account_code,a.account_type,a.is_active FROM purchase_invoice_lines t LEFT JOIN tax_codes tc ON tc.id=t.tax_code_id AND tc.company_id=? JOIN accounts a ON a.id=t.account_id AND a.company_id=? WHERE t.invoice_id=? ORDER BY t.id');
    $stmt->execute([$companyId,$companyId,$invoiceId]); $lines=$stmt->fetchAll(PDO::FETCH_ASSOC); $weights=[];
    if(!$lines) throw new InvalidArgumentException('Ostuarve read või nende kontod puuduvad.');
    foreach($lines as $line) {
        if(!in_array($line['account_type'],['expense','asset'],true) || !$line['is_active']) throw new InvalidArgumentException('Mahaarvamata käibemaksu jaoks peab arverea kulu- või varakonto olema aktiivne.');
        $vat=creditCents($line['vat_amount']); $net=creditCents($line['net_amount']);
        $spec=reverseVatSpec((string)($line['tax_code']??''));
        $weights[(int)$line['id']]=$vat+($spec?euReverseVat($net,$spec['rate']):0);
    }
    $stmt=$pdo->prepare("SELECT j.account_id,j.description,j.debit,j.credit,a.account_type,a.is_active FROM journal_lines j JOIN journal_entries e ON e.id=j.entry_id JOIN accounts a ON a.id=j.account_id AND a.company_id=e.company_id WHERE e.id=? AND e.company_id=? AND e.status='posted' ORDER BY j.id");
    $stmt->execute([$invoice['journal_entry_id'],$companyId]); $journal=$stmt->fetchAll(PDO::FETCH_ASSOC); $input=[];
    foreach($journal as $line) if(in_array(trim($line['description']),['Sisendkäibemaks','Pöördmaksu sisendkäibemaks'],true) && creditCents($line['debit'])>0) $input[(int)$line['account_id']]=true;
    if(array_sum($weights)>0 && count($input)!==1) throw new InvalidArgumentException('Algse arve sisendkäibemaksu kontot ei saa üheselt tuvastada.');
    if(array_sum($weights)>0) creditJournalPlan('purchase',$journal,$lines,creditPlan($lines,0,10000));
    return ['invoice'=>$invoice,'lines'=>$lines,'weights'=>$weights,'input_account_id'=>(int)(array_key_first($input)??0),'tax'=>array_sum($weights)];
}
function purchaseVatCapital(PDO $pdo,int $companyId,array $source,array $old,array &$next): void
{
    $assetGroups=[]; $usedStock=[];
    foreach($source['lines'] as $line) {
        $id=(int)$line['id']; $before=(int)($old[$id]['amount']??0); $after=(int)($next[$id]['amount']??0); $delta=$after-$before;
        if(!$delta) continue;
        if(!empty($line['product_id'])) {
            $lock=$pdo->prepare('SELECT id FROM inventory_products WHERE company_id=? AND id=? FOR UPDATE'); $lock->execute([$companyId,$line['product_id']]);
            $stmt=$pdo->prepare("SELECT l.*,m.movement_date FROM inventory_movements m JOIN inventory_movement_lines l ON l.movement_id=m.id WHERE m.company_id=? AND m.source_purchase_invoice_id=? AND m.status='posted' AND l.product_id=? AND l.warehouse_id=? ORDER BY l.id FOR UPDATE");
            $stmt->execute([$companyId,$source['invoice']['id'],$line['product_id'],$line['warehouse_id']]); $candidates=$stmt->fetchAll(PDO::FETCH_ASSOC); $match=null;
            foreach($candidates as $candidate) if(!isset($usedStock[$candidate['id']]) && (!isset($old[$id]['stock_line_id']) || (int)$old[$id]['stock_line_id']===(int)$candidate['id']) && creditMilli($candidate['quantity_in'])===creditMilli($line['quantity']) && creditCents($candidate['total_cost'])===creditCents($line['net_amount'])+$before) { $match=$candidate; break; }
            if(!$match) throw new InvalidArgumentException('Lao vastuvõtu maksumus ei klapi arvega; kontrolli lao ja pearaamatu võrdlust.');
            $usedStock[$match['id']]=true;
            $later=$pdo->prepare("SELECT COUNT(*) FROM inventory_movements m JOIN inventory_movement_lines l ON l.movement_id=m.id WHERE m.company_id=? AND m.status='posted' AND l.product_id=? AND l.warehouse_id=? AND l.quantity_out>0 AND (m.movement_date>? OR (m.movement_date=? AND m.id>?))");
            $later->execute([$companyId,$line['product_id'],$line['warehouse_id'],$match['movement_date'],$match['movement_date'],$match['movement_id']]);
            if($later->fetchColumn()) throw new InvalidArgumentException('Kaubal on juba väljaminekuid. Paranda need enne tagurpidi järjekorras ja kinnita mahaarvamine enne kauba väljastamist.');
            $cost=creditCents($line['net_amount'])+$after; $quantity=creditMilli($line['quantity']);
            if(!$quantity) throw new InvalidArgumentException('Lao kogus peab olema positiivne.');
            $update=$pdo->prepare('UPDATE inventory_movement_lines SET total_cost=?,unit_cost=? WHERE id=?');
            $update->execute([creditDecimal($cost),number_format($cost*10/$quantity,4,'.',''),$match['id']]);
            $next[$id]['stock_line_id']=(int)$match['id'];
        } elseif($line['account_type']==='asset') {
            $account=(int)$line['account_id']; $assetGroups[$account]??=['delta'=>0,'net'=>0,'old'=>0,'ids'=>[]];
            $assetGroups[$account]['delta']+=$delta; $assetGroups[$account]['ids'][]=$id;
        }
    }
    foreach($assetGroups as $account=>$group) {
        foreach($source['lines'] as $line) if((int)$line['account_id']===$account && empty($line['product_id'])) { $group['net']+=creditCents($line['net_amount']); $group['old']+=(int)($old[$line['id']]['amount']??0); }
        $stmt=$pdo->prepare("SELECT * FROM fixed_assets WHERE company_id=? AND source_document=? AND asset_account_id=? AND status IN ('draft','active') ORDER BY id FOR UPDATE");
        $stmt->execute([$companyId,$source['invoice']['supplier_invoice_number'],$account]); $cards=$stmt->fetchAll(PDO::FETCH_ASSOC);
        if(!$cards) continue; // A subsequently created card must use the capitalized amount shown in the history.
        if(count($cards)!==1 || creditCents($cards[0]['acquisition_cost'])!==$group['net']+$group['old'] || $cards[0]['legacy_opening_balance']) throw new InvalidArgumentException('Põhivarakaardi seost või maksumust ei saa üheselt tuvastada. Algdokument ja soetusmaksumus peavad ostuarvega klappima.');
        $card=$cards[0];
        foreach(['fixed_asset_depreciation_runs','fixed_asset_estimate_changes'] as $table) {
            $check=$pdo->prepare('SELECT COUNT(*) FROM '.$table.' WHERE company_id=? AND asset_id=?'); $check->execute([$companyId,$card['id']]);
            if($check->fetchColumn()) throw new InvalidArgumentException('Põhivaral on juba amortisatsioon või arvestushinnang. Paranda need enne algse soetusmaksumuse muutmist.');
        }
        $cost=creditCents($card['acquisition_cost'])+$group['delta'];
        if($cost<creditCents($card['residual_value'])+creditCents($card['opening_accumulated_depreciation'])) throw new InvalidArgumentException('Põhivara maksumus ei tohi langeda alla lõppväärtuse ja kulumi.');
        $update=$pdo->prepare('UPDATE fixed_assets SET acquisition_cost=? WHERE company_id=? AND id=?'); $update->execute([creditDecimal($cost),$companyId,$card['id']]);
        foreach($group['ids'] as $id) $next[$id]['asset_id']=(int)$card['id'];
    }
}
function postPurchaseVatState(PDO $pdo,int $companyId,int $userId,int $invoiceId,string $date,int $input,int $deduction,string $hash,array $allocations,string $event): void
{
    if(!$pdo->inTransaction()) throw new LogicException('Käibemaksu parandus peab olema samas tehingus kinnitusega.');
    ksort($allocations); $old=purchaseVatState($pdo,$companyId,$invoiceId); $oldAlloc=$old['allocations']??[];
    if($old && (int)$old['input_account_id']!==$input) throw new InvalidArgumentException('Algse sisendkäibemaksu konto on muutunud; kontrolli paranduse ajalugu.');
    $accountChanges=[]; $amount=0; $oldAmount=0;
    foreach($oldAlloc as $row) { $oldAmount+=(int)$row['amount']; $accountChanges[$row['account_id']]=($accountChanges[$row['account_id']]??0)-(int)$row['amount']; }
    foreach($allocations as $row) { $amount+=(int)$row['amount']; $accountChanges[$row['account_id']]=($accountChanges[$row['account_id']]??0)+(int)$row['amount']; }
    if($old && $oldAlloc==$allocations && (int)$old['deductible_cents']===$deduction && $old['source_hash']===$hash) return;
    $delta=$amount-$oldAmount; $entry=null; $changes=array_filter($accountChanges);
    if($changes || $delta || ($old && (int)$old['deductible_cents']!==$deduction)) {
        if($issue=closedAccountingPeriodMessage($pdo,$companyId,$date)) throw new InvalidArgumentException($issue);
    }
    if($changes || $delta) {
        $accountIds=array_keys($changes); if($delta) $accountIds[]=$input;
        $stmt=$pdo->prepare('SELECT id,account_type FROM accounts WHERE company_id=? AND is_active=1 AND id IN ('.implode(',',array_fill(0,count($accountIds),'?')).')');
        $stmt->execute(array_merge([$companyId],$accountIds)); $types=$stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach($changes as $account=>$value) if(!in_array($types[$account]??'',['expense','asset'],true) || (int)$account===$input) throw new InvalidArgumentException('Paranduse kulu-/varakonto on vigane või mitteaktiivne.');
        if($delta && ($types[$input]??'')!=='asset') throw new InvalidArgumentException('Sisendkäibemaksu konto peab olema aktiivne varakonto.');
        $stmt=$pdo->prepare("INSERT INTO journal_entries (company_id,entry_date,description,document_number,status,created_by,posted_at) VALUES (?,?,?,?,'posted',?,CURRENT_TIMESTAMP)");
        $description='Mahaarvamata sisendkäibemaks — ostuarve '.$invoiceId;
        $stmt->execute([$companyId,$date,$description,'KM-PAR-'.$invoiceId,$userId]); $entry=(int)$pdo->lastInsertId();
        $stmt=$pdo->prepare('INSERT INTO journal_lines (entry_id,account_id,description,debit,credit) VALUES (?,?,?,?,?)');
        foreach($changes as $account=>$value) $stmt->execute([$entry,$account,$description,creditDecimal(max(0,$value)),creditDecimal(max(0,-$value))]);
        if($delta) $stmt->execute([$entry,$input,$description,creditDecimal(max(0,-$delta)),creditDecimal(max(0,$delta))]);
    }
    $record=['amount_cents'=>$amount,'deductible_cents'=>$deduction,'source_hash'=>$hash,'allocations'=>$allocations];
    $before=['amount_cents'=>$oldAmount,'deductible_cents'=>$old['deductible_cents']??null,'source_hash'=>$old['source_hash']??null,'allocations'=>$oldAlloc];
    $stmt=$pdo->prepare('INSERT INTO purchase_vat_adjustment_history (company_id,invoice_id,entry_date,event_name,before_json,after_json,journal_entry_id,created_by) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([$companyId,$invoiceId,$date,$event,json_encode($before,JSON_THROW_ON_ERROR),json_encode($record,JSON_THROW_ON_ERROR),$entry,$userId]);
    $stmt=$pdo->prepare('INSERT INTO purchase_vat_adjustments (company_id,invoice_id,input_account_id,amount_cents,deductible_cents,source_hash,allocations_json,last_entry_id,updated_by) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE input_account_id=VALUES(input_account_id),amount_cents=VALUES(amount_cents),deductible_cents=VALUES(deductible_cents),source_hash=VALUES(source_hash),allocations_json=VALUES(allocations_json),last_entry_id=VALUES(last_entry_id),updated_by=VALUES(updated_by)');
    $stmt->execute([$companyId,$invoiceId,$input,$amount,$deduction,$hash,json_encode($allocations,JSON_THROW_ON_ERROR),$entry,$userId]);
}
function syncPurchaseVatOriginal(PDO $pdo,int $companyId,int $userId,array $candidate): array
{
    $source=purchaseVatSource($pdo,$companyId,(int)$candidate['id']); $tax=$source['tax'];
    $deduction=(int)($candidate['deductible_cents']??$tax);
    if($deduction<0 || $deduction>$tax || $tax!==(int)($candidate['deduction_vat']??$candidate['vat'])) throw new InvalidArgumentException('Mahaarvatav summa ei klapi algse ostuarvega.');
    $old=purchaseVatState($pdo,$companyId,$candidate['id']);
    $explicit = $candidate['vat_line_allocations'] ?? [];
    $previousExplicit = array_column($old['allocations'] ?? [],'vat_line','original_line_id');
    if ($explicit != $previousExplicit) {
        foreach (loadCreditLinks($pdo,$companyId,'purchase') as $link) {
            if ((int)$link['original_invoice_id'] === (int)$candidate['id'] && $link['credit_status'] === 'posted') {
                throw new InvalidArgumentException('Arvel on aktiivne kreeditarve. Reajaotuse muutmine vajab kreedi eraldi käsitlust.');
            }
        }
    }
    $shares=$explicit ? [] : purchaseVatAllocate($tax-$deduction,$source['weights']); $alloc=[];
    foreach($source['lines'] as $line) {
        $id=(int)$line['id'];
        if ($explicit) {
            $purpose = $explicit[$id]['vat_purpose'];
            $fixedAsset = in_array($purpose,['fixed_asset','fixed_car_full','fixed_car_partial'],true);
            if ($fixedAsset && $line['account_type'] !== 'asset') {
                throw new InvalidArgumentException('Põhivara liigiga arverida vajab varakontot.');
            }
            if (!$fixedAsset && str_starts_with($line['account_code'],'15')) {
                throw new InvalidArgumentException('Põhivara kontoga arvereale vali põhivara ostuliik.');
            }
            $shares[$id] = $source['weights'][$id] - $explicit[$id]['deductible_cents'];
        }
        $alloc[$id]=['account_id'=>(int)$line['account_id'],'amount'=>$shares[$id],'tax'=>$source['weights'][$id],'original_line_id'=>$id];
        if ($explicit) $alloc[$id]['vat_line'] = $explicit[$id];
        foreach(['asset_id','stock_line_id'] as $field) if(isset($old['allocations'][$id][$field])) $alloc[$id][$field]=$old['allocations'][$id][$field];
    }
    $changed=false; foreach($alloc as $id=>$row) if((int)($old['allocations'][$id]['amount']??0)!==$row['amount']) $changed=true;
    if($changed) {
        if($tax-$deduction>0) {
            $manual=$pdo->prepare("SELECT COALESCE(SUM(j.credit-j.debit),0) AS manual_vat_change FROM journal_entries e JOIN journal_lines j ON j.entry_id=e.id WHERE e.company_id=? AND e.status='posted' AND e.document_number=? AND e.id<>? AND j.account_id=?");
            $manual->execute([$companyId,$source['invoice']['supplier_invoice_number'],$source['invoice']['journal_entry_id'],$source['input_account_id']]);
            if(creditCents((string)$manual->fetchColumn())!==0) throw new InvalidArgumentException('Sama arvenumbriga on sisendkäibemaksu käsiparandus. Pööra see sama dokumendinumbriga vastukandega tagasi enne automaatset parandust, et vältida topeltkannet.');
        }
        foreach(loadCreditLinks($pdo,$companyId,'purchase') as $link) if((int)$link['original_invoice_id']===(int)$candidate['id'] && $link['credit_status']==='posted') {
            foreach($source['lines'] as $line) if($line['account_type']==='asset') throw new InvalidArgumentException('Varaga seotud arvel on aktiivsed kreedid. Tühista need enne mahaarvamise muutmist, seejärel koosta uuesti.');
        }
        purchaseVatCapital($pdo,$companyId,$source,$old['allocations']??[],$alloc);
    }
    postPurchaseVatState($pdo,$companyId,$userId,$candidate['id'],$source['invoice']['invoice_date'],$source['input_account_id'],$deduction,$candidate['confirmation_hash'],$alloc,'confirmation');
    $source['vat_purpose']=$candidate['vat_purpose']??'ordinary'; $source['car_ids']=$candidate['car_ids']??[];
    return $source;
}
/** One original-line capacity per credit: exact even after arbitrary cancellation and rounding. */
function purchaseCreditVatPlan(array $originalState,array &$plan,array $credited): int
{
    $deduction=0;
    foreach($plan['lines'] as &$line) {
        $id=(int)$line['id']; $allocation=$originalState['allocations'][$id]??null;
        if(!$allocation) throw new InvalidArgumentException('Algse ostuarve käibemaksu jaotus puudub. Salvesta algse arve kontroll.');
        $past=$credited[$id]??['vat'=>0,'reverse'=>0,'nondeductible'=>0];
        $tax=$line['credit_vat']+$line['credit_reverse']; $originalTax=(int)$allocation['tax'];
        $pastTax=(int)$past['vat']+(int)($past['reverse']??0);
        [$target,$remainder]=$originalTax?purchaseVatMulDiv((int)$allocation['amount'],$pastTax+$tax,$originalTax):[0,0];
        if($originalTax && $remainder>=intdiv($originalTax+1,2)) ++$target;
        $nonDeduct=$target-(int)($past['nondeductible']??0);
        if($nonDeduct<0 || $nonDeduct>$tax) throw new InvalidArgumentException('Kreedi mahaarvamise jaotus ei klapi varasemate kreeditidega. Kontrolli paranduste ajalugu.');
        $line['credit_nondeductible']=$nonDeduct; $deduction-=$tax-$nonDeduct;
        if (isset($allocation['vat_line'])) {
            $line['vat_line'] = array_replace($allocation['vat_line'],[
                'vat'=>-$tax, 'deductible_cents'=>-($tax-$nonDeduct),
            ]);
        }
    }
    unset($line); return $deduction;
}
function postPurchaseCreditVat(PDO $pdo,int $companyId,int $userId,int $creditId,string $date,array $source,array $plan,int $deduction): void
{
    $tax=0; foreach($plan['lines'] as $line) $tax+=$line['credit_vat']+$line['credit_reverse'];
    validateVatPurpose(['deduction_vat'=>-$tax,'deductible_cents'=>$deduction],$source['vat_purpose']??'ordinary',implode(',',$source['car_ids']??[]));
    $alloc=[];
    foreach ($plan['lines'] as $line) {
        $id = (int)$line['id'];
        $alloc[$id]=['account_id'=>(int)$line['account_id'],'amount'=>-(int)$line['credit_nondeductible'],
            'tax'=>-($line['credit_vat']+$line['credit_reverse']),'original_line_id'=>$id];
        if (isset($line['vat_line'])) {
            $alloc[$id]['vat_line'] = $line['vat_line'];
            $alloc[$id]['credit_line_id'] = (int)$line['credit_line_id'];
        }
    }
    postPurchaseVatState($pdo,$companyId,$userId,$creditId,$date,$source['input_account_id'],$deduction,hash('sha256',json_encode($alloc,JSON_THROW_ON_ERROR)),$alloc,'credit');
}
function syncPurchaseVatCredits(PDO $pdo,int $companyId,int $userId,int $originalId,array $source): void
{
    $state=purchaseVatState($pdo,$companyId,$originalId); $past=[];
    $stmt=$pdo->prepare("SELECT c.id,c.invoice_date,n.deduction_cents FROM credit_note_links n JOIN purchase_invoices c ON c.id=n.credit_invoice_id AND c.company_id=n.company_id WHERE n.company_id=? AND n.invoice_kind='purchase' AND n.original_invoice_id=? AND c.status='posted' ORDER BY c.invoice_date,c.id FOR UPDATE");
    $stmt->execute([$companyId,$originalId]);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $credit) {
        $rows=$pdo->prepare("SELECT x.original_line_id AS id,x.credit_line_id,t.account_id,-t.net_amount AS credit_net,-t.vat_amount AS credit_vat,x.reverse_cents AS credit_reverse FROM credit_note_line_links x JOIN purchase_invoice_lines t ON t.id=x.credit_line_id AND t.invoice_id=x.credit_invoice_id WHERE x.company_id=? AND x.invoice_kind='purchase' AND x.credit_invoice_id=? ORDER BY x.original_line_id");
        $rows->execute([$companyId,$credit['id']]); $plan=['lines'=>[]];
        foreach($rows->fetchAll(PDO::FETCH_ASSOC) as $line) { $line['credit_net']=creditCents($line['credit_net']); $line['credit_vat']=creditCents($line['credit_vat']); $line['credit_reverse']=(int)$line['credit_reverse']; $plan['lines'][]=$line; }
        $deduction=purchaseCreditVatPlan($state,$plan,$past);
        if((int)$credit['deduction_cents']!==$deduction && ($issue=closedAccountingPeriodMessage($pdo,$companyId,$credit['invoice_date']))) throw new InvalidArgumentException($issue);
        postPurchaseCreditVat($pdo,$companyId,$userId,(int)$credit['id'],$credit['invoice_date'],$source,$plan,$deduction);
        $update=$pdo->prepare("UPDATE credit_note_links SET deduction_cents=? WHERE company_id=? AND invoice_kind='purchase' AND credit_invoice_id=?"); $update->execute([$deduction,$companyId,$credit['id']]);
        foreach($plan['lines'] as $line) {
            $id=(int)$line['id']; $past[$id]??=['vat'=>0,'reverse'=>0,'nondeductible'=>0];
            $past[$id]['vat']+=$line['credit_vat']; $past[$id]['reverse']+=$line['credit_reverse']; $past[$id]['nondeductible']+=$line['credit_nondeductible'];
        }
    }
}
function voidPurchaseVatAdjustment(PDO $pdo,int $companyId,int $userId,int $invoiceId,string $date,bool $capital): void
{
    $old=purchaseVatState($pdo,$companyId,$invoiceId); if(!$old) return;
    if($capital) { $source=purchaseVatSource($pdo,$companyId,$invoiceId); $next=$old['allocations']; foreach($next as &$row) $row['amount']=0; unset($row); purchaseVatCapital($pdo,$companyId,$source,$old['allocations'],$next); }
    postPurchaseVatState($pdo,$companyId,$userId,$invoiceId,$date,(int)$old['input_account_id'],0,$old['source_hash'],[],'cancellation');
}
/** FIFO consumes the actual remaining receipt cents, including capitalized VAT. */
function purchaseVatFifoAllocations(PDO $pdo,int $companyId,int $movementId,string $date,int $warehouseId,int $productId,string $quantity): array
{
    $stmt=$pdo->prepare("SELECT l.quantity_in,l.quantity_out,l.unit_cost,l.total_cost FROM inventory_movement_lines l JOIN inventory_movements m ON m.id=l.movement_id WHERE m.company_id=? AND m.status='posted' AND (m.movement_date<? OR (m.movement_date=? AND m.id<?)) AND l.warehouse_id=? AND l.product_id=? ORDER BY m.movement_date,m.id,l.id FOR UPDATE");
    $stmt->execute([$companyId,$date,$date,$movementId,$warehouseId,$productId]); $lots=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $in=creditMilli($row['quantity_in']); $out=creditMilli($row['quantity_out']);
        if($in) $lots[]=['quantity'=>$in,'cost'=>creditCents($row['total_cost']),'unit_cost'=>$row['unit_cost']];
        if($out) {
            $used=creditTakeLots($lots,$out); $difference=array_sum(array_column($used,'cost'))-creditCents($row['total_cost']);
            if(abs($difference)>count($used) || ($difference && !$lots)) throw new RuntimeException('Varasem FIFO maksumus ei klapi laoseisuga. Kontrolli lao ja pearaamatu võrdlust.');
            if($difference) $lots[0]['cost']+=$difference;
        }
    }
    return array_map(static fn(array $line):array=>['quantity'=>creditQuantity($line['quantity']),'unit_cost'=>$line['unit_cost'],'total_cost'=>creditDecimal($line['cost'])],creditTakeLots($lots,creditMilli($quantity)));
}
