<?php
function creditMilli(string $value): int
{
    if(!preg_match('/^([0-9]{1,10})(?:\.([0-9]{1,3}))?$/',$value,$m)) throw new RuntimeException('Vigane kaubakogus.');
    return (int)$m[1]*1000+(int)str_pad($m[2]??'',3,'0');
}
function creditQuantity(int $milli): string
{
    return intdiv($milli,1000).'.'.str_pad((string)($milli%1000),3,'0',STR_PAD_LEFT);
}
/** Consume remaining lot cents as well as quantities; a final return recovers rounding. */
function creditTakeLots(array &$lots,int $needed): array
{
    $result=[];
    while($needed>0) {
        if(!$lots) throw new RuntimeException('Laos ei ole tagastuseks piisavalt kaupa.');
        if($lots[0]['quantity']<0 || $lots[0]['cost']<0) throw new RuntimeException('FIFO koguse või maksumuse jääk on vigane.');
        if($lots[0]['quantity']===0) { array_shift($lots); continue; }
        $q=min($needed,$lots[0]['quantity']);
        [$cost,$remainder]=purchaseVatMulDiv($lots[0]['cost'],$q,$lots[0]['quantity']);
        if($remainder>=intdiv($lots[0]['quantity']+1,2)) ++$cost;
        $result[]=['quantity'=>$q,'cost'=>$cost,'unit_cost'=>$lots[0]['unit_cost'],'original_lot_id'=>$lots[0]['original_lot_id']??0];
        $lots[0]['quantity']-=$q; $lots[0]['cost']-=$cost; $needed-=$q;
        if(!$lots[0]['quantity']) array_shift($lots);
    }
    return $result;
}
function creditStockPlan(PDO $pdo,int $companyId,string $kind,int $originalId,string $date,array &$plan,array $credited,array $journalSource,array $originalLines=[]): array
{
    if($kind==='sales') {
        $costSource=[]; $base=[];
        foreach($journalSource as $line) {
            if(str_starts_with($line['description'],'Müüdud kauba kulu — arve ') || str_starts_with($line['description'],'Varude vähenemine — arve ')) {
                if(empty($line['is_active'])) throw new RuntimeException('Algne laokonto pole aktiivne.');
                $id=(int)$line['account_id']; $costSource[$id]=($costSource[$id]??0)+creditCents($line['debit'])-creditCents($line['credit']);
            } else $base[]=$line;
        }
        if($costSource) {
            $stmt=$pdo->prepare("SELECT p.inventory_account_id,p.cogs_account_id,SUM(l.total_cost) AS cost FROM inventory_movements m JOIN inventory_movement_lines l ON l.movement_id=m.id JOIN inventory_products p ON p.id=l.product_id AND p.company_id=m.company_id WHERE m.company_id=? AND m.source_sales_invoice_id=? AND m.status='posted' GROUP BY p.inventory_account_id,p.cogs_account_id");
            $stmt->execute([$companyId,$originalId]); $expected=[];
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cost=creditCents($row['cost']); $a=(int)$row['inventory_account_id']; $c=(int)$row['cogs_account_id'];
                $expected[$a]=($expected[$a]??0)-$cost; $expected[$c]=($expected[$c]??0)+$cost;
            }
            ksort($expected); ksort($costSource);
            if($expected!==$costSource) throw new RuntimeException('Algse müügi FIFO omahind ja pearaamat ei klapi või on toote kontod muutunud.');
            $journalSource=$base;
        }
    }
    $groups=[];
    foreach($plan['lines'] as &$line) {
        $line['credit_quantity']=0;
        if(empty($line['product_id']) && empty($line['warehouse_id'])) continue;
        if(empty($line['product_id']) || empty($line['warehouse_id'])) throw new RuntimeException('Algse rea toote ja lao seos on puudulik.');
        $net=creditCents((string)$line['net_amount']); $quantity=creditMilli((string)$line['quantity']);
        if(!$net || !$quantity) throw new RuntimeException('Laotagastus vajab positiivse hinna ja kogusega algset rida.');
        $past=$credited[$line['id']]??['net'=>0,'quantity'=>0];
        $target=intdiv($quantity*($past['net']+$line['credit_net'])+intdiv($net,2),$net);
        $q=$target-($past['quantity']??0);
        if($q<=0 || $q>$quantity-($past['quantity']??0)) throw new RuntimeException('Tagastatav kogus on alla 0,001 ühiku või ületab algset kogust.');
        $line['credit_quantity']=$q;
        $key=$line['product_id'].':'.$line['warehouse_id'];
        $groups[$key]??=['product_id'=>(int)$line['product_id'],'warehouse_id'=>(int)$line['warehouse_id'],'quantity'=>0,'net'=>0,'past'=>0];
        $groups[$key]['quantity']+=$q; $groups[$key]['net']+=$line['credit_net']+(int)($line['credit_nondeductible']??0);
    }
    unset($line);
    if(!$groups) return ['movements'=>[],'journal'=>[],'source'=>$journalSource];
    // Product row is the same serialization lock used by normal invoice posting.
    uasort($groups,static fn(array $a,array $b):int=>$a['product_id']<=>$b['product_id']);
    $movements=[]; $journal=[];
    foreach($groups as $group) {
        $stmt=$pdo->prepare('SELECT * FROM inventory_products WHERE id=? AND company_id=? AND is_active=1 FOR UPDATE');
        $stmt->execute([$group['product_id'],$companyId]); $product=$stmt->fetch(PDO::FETCH_ASSOC);
        $stmt=$pdo->prepare('SELECT id FROM inventory_warehouses WHERE id=? AND company_id=? AND is_active=1');
        $stmt->execute([$group['warehouse_id'],$companyId]);
        if(!$product || !$stmt->fetchColumn()) throw new RuntimeException('Tagastuse toode või ladu pole aktiivne.');
        $originalQuantity=0; $originalNet=0;
        foreach($originalLines as $line) if((int)($line['product_id']??0)===$group['product_id'] && (int)($line['warehouse_id']??0)===$group['warehouse_id']) {
            $originalQuantity+=creditMilli($line['quantity']); $originalNet+=creditCents($line['net_amount'])+(int)($line['capitalized_nondeductible']??0);
        }
        $column=$kind==='sales'?'source_sales_invoice_id':'source_purchase_invoice_id'; $direction=$kind==='sales'?'quantity_out':'quantity_in';
        $stmt=$pdo->prepare("SELECT SUM(l.$direction) AS quantity,SUM(l.total_cost) AS cost FROM inventory_movements m JOIN inventory_movement_lines l ON l.movement_id=m.id WHERE m.company_id=? AND m.$column=? AND m.status='posted' AND l.product_id=? AND l.warehouse_id=?");
        $stmt->execute([$companyId,$originalId,$group['product_id'],$group['warehouse_id']]); $movement=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$movement['quantity'] || creditMilli($movement['quantity'])!==$originalQuantity || ($kind==='purchase' && creditCents($movement['cost'])!==$originalNet)) throw new RuntimeException('Algse arve kogused või soetusmaksumus ei klapi seotud laoliikumisega.');
        $inventory=(int)$product['inventory_account_id']; $cogs=(int)$product['cogs_account_id'];
        $stmt=$pdo->prepare('SELECT id,account_type FROM accounts WHERE company_id=? AND is_active=1 AND id IN (?,?)');
        $stmt->execute([$companyId,$inventory,$cogs]); $types=$stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if(($types[$inventory]??'')!=='asset' || ($types[$cogs]??'')!=='expense') throw new RuntimeException('Tootel peavad olema aktiivsed varude ja kaubakulu kontod.');
        foreach($plan['lines'] as $line) if((int)($line['product_id']??0)===$group['product_id'] && $kind==='purchase' && (int)$line['account_id']!==$inventory) throw new RuntimeException('Toote varude konto on algse ostuga võrreldes muutunud.');
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM inventory_movements m JOIN inventory_movement_lines l ON l.movement_id=m.id WHERE m.company_id=? AND m.status='posted' AND m.movement_date>? AND l.product_id=? AND l.warehouse_id=?");
        $stmt->execute([$companyId,$date,$group['product_id'],$group['warehouse_id']]);
        if($stmt->fetchColumn()) throw new RuntimeException('Laotagastuse kuupäevast hilisemaid liikumisi on juba kinnitatud. Kasuta viimase liikumise kuupäeva või hilisemat päeva.');
        if($kind==='sales') {
            $stmt=$pdo->prepare("SELECT l.* FROM inventory_movements m JOIN inventory_movement_lines l ON l.movement_id=m.id WHERE m.company_id=? AND m.source_sales_invoice_id=? AND m.status='posted' AND l.product_id=? AND l.warehouse_id=? ORDER BY l.id");
            $stmt->execute([$companyId,$originalId,$group['product_id'],$group['warehouse_id']]);
            $lots=[]; foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $lots[(int)$row['id']]=['original_lot_id'=>(int)$row['id'],'quantity'=>creditMilli($row['quantity_out']),'cost'=>creditCents($row['total_cost']),'unit_cost'=>$row['unit_cost']];
            if(!$lots) throw new RuntimeException('Algse müügi FIFO väljaminekut ei leitud.');
            $stmt=$pdo->prepare("SELECT x.original_lot_id,SUM(x.quantity_milli) AS quantity,SUM(x.cost_cents) AS cost FROM credit_note_stock_lots x JOIN credit_note_links n ON n.company_id=x.company_id AND n.credit_invoice_id=x.credit_invoice_id AND n.invoice_kind=x.invoice_kind JOIN sales_invoices c ON c.id=x.credit_invoice_id AND c.company_id=x.company_id WHERE x.company_id=? AND x.invoice_kind='sales' AND n.original_invoice_id=? AND c.status='posted' GROUP BY x.original_lot_id");
            $stmt->execute([$companyId,$originalId]);
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $past) if(isset($lots[$past['original_lot_id']])) {
                $lots[$past['original_lot_id']]['quantity']-=(int)$past['quantity'];
                $lots[$past['original_lot_id']]['cost']-=(int)$past['cost'];
            }
            $lots=array_values($lots);
            $allocations=creditTakeLots($lots,$group['quantity']);
        } else {
            $stmt=$pdo->prepare("SELECT l.* FROM inventory_movements m JOIN inventory_movement_lines l ON l.movement_id=m.id WHERE m.company_id=? AND m.status='posted' AND m.movement_date<=? AND l.product_id=? AND l.warehouse_id=? ORDER BY m.movement_date,m.id,l.id");
            $stmt->execute([$companyId,$date,$group['product_id'],$group['warehouse_id']]);
            $lots=[];
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $in=creditMilli($row['quantity_in']); $out=creditMilli($row['quantity_out']);
                if($in) $lots[]=['quantity'=>$in,'cost'=>creditCents($row['total_cost']),'unit_cost'=>$row['unit_cost']];
                if($out) {
                    $used=creditTakeLots($lots,$out); $difference=array_sum(array_column($used,'cost'))-creditCents($row['total_cost']);
                    if(abs($difference)>count($used)) throw new RuntimeException('Varasem lao FIFO maksumus ei klapi; kontrolli lao ja pearaamatu võrdlust.');
                    if($difference && $lots) $lots[0]['cost']+=$difference;
                    elseif($difference) throw new RuntimeException('Varasemal laoliikumisel on ümardusjääk; paranda lao võrdlus enne tagastust.');
                }
            }
            $allocations=creditTakeLots($lots,$group['quantity']);
        }
        $cost=array_sum(array_column($allocations,'cost'));
        foreach($allocations as $allocation) $movements[]=$allocation+['product_id'=>$group['product_id'],'warehouse_id'=>$group['warehouse_id'],'incoming'=>$kind==='sales'];
        if($kind==='sales') {
            $journal[]=['account_id'=>$inventory,'debit'=>creditDecimal($cost),'credit'=>'0.00'];
            $journal[]=['account_id'=>$cogs,'debit'=>'0.00','credit'=>creditDecimal($cost)];
        } else {
            // Base credit reverses the original purchase price. Reconcile that to returned FIFO value.
            $difference=$group['net']-$cost;
            if($difference) {
                $journal[]=['account_id'=>$inventory,'debit'=>creditDecimal(max(0,$difference)),'credit'=>creditDecimal(max(0,-$difference))];
                $journal[]=['account_id'=>$cogs,'debit'=>creditDecimal(max(0,-$difference)),'credit'=>creditDecimal(max(0,$difference))];
            }
        }
    }
    return ['movements'=>$movements,'journal'=>$journal,'source'=>$journalSource];
}
function creditSaveStock(PDO $pdo,int $companyId,int $userId,string $kind,int $creditId,int $entryId,string $date,string $number,array $movements): void
{
    if(!$movements) return;
    $column=$kind==='sales'?'source_sales_invoice_id':'source_purchase_invoice_id';
    $stmt=$pdo->prepare('INSERT INTO inventory_movements (company_id,'.$column.',journal_entry_id,movement_type,movement_date,document_number,description,status,created_by,posted_at) VALUES (?,?,?,?,?,?,?,\'posted\',?,CURRENT_TIMESTAMP)');
    $stmt->execute([$companyId,$creditId,$entryId,$kind==='sales'?'receipt':'issue',$date,$number,'Kreeditarve kaubatagastus '.$number,$userId]); $movementId=(int)$pdo->lastInsertId();
    $stmt=$pdo->prepare('INSERT INTO inventory_movement_lines (movement_id,warehouse_id,product_id,quantity_in,quantity_out,unit_cost,total_cost) VALUES (?,?,?,?,?,?,?)');
    foreach($movements as $line) {
        $stmt->execute([$movementId,$line['warehouse_id'],$line['product_id'],$line['incoming']?creditQuantity($line['quantity']):'0.000',$line['incoming']?'0.000':creditQuantity($line['quantity']),$line['unit_cost'],creditDecimal($line['cost'])]);
        $lineId=(int)$pdo->lastInsertId();
        if($line['original_lot_id']) {
            $link=$pdo->prepare('INSERT INTO credit_note_stock_lots (company_id,invoice_kind,credit_invoice_id,movement_line_id,original_lot_id,quantity_milli,cost_cents) VALUES (?,?,?,?,?,?,?)');
            $link->execute([$companyId,$kind,$creditId,$lineId,$line['original_lot_id'],$line['quantity'],$line['cost']]);
        }
    }
}
function creditVoidStock(PDO $pdo,int $companyId,string $kind,int $creditId): void
{
    $column=$kind==='sales'?'source_sales_invoice_id':'source_purchase_invoice_id';
    $stmt=$pdo->prepare("SELECT m.id,m.movement_date,l.product_id,l.warehouse_id FROM inventory_movements m JOIN inventory_movement_lines l ON l.movement_id=m.id WHERE m.company_id=? AND m.$column=? AND m.status='posted' ORDER BY l.product_id");
    $stmt->execute([$companyId,$creditId]); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $row) {
        $lock=$pdo->prepare('SELECT id FROM inventory_products WHERE id=? AND company_id=? FOR UPDATE');
        $lock->execute([$row['product_id'],$companyId]);
        $later=$pdo->prepare("SELECT COUNT(*) FROM inventory_movements m JOIN inventory_movement_lines l ON l.movement_id=m.id WHERE m.company_id=? AND m.status='posted' AND (m.movement_date>? OR (m.movement_date=? AND m.id>?)) AND l.product_id=? AND l.warehouse_id=?");
        $later->execute([$companyId,$row['movement_date'],$row['movement_date'],$row['id'],$row['product_id'],$row['warehouse_id']]);
        if($later->fetchColumn()) throw new RuntimeException('Tagastuse järel on kinnitatud laoliikumisi. Tühista need esmalt tagurpidi järjekorras.');
    }
    $stmt=$pdo->prepare("UPDATE inventory_movements SET status='cancelled' WHERE company_id=? AND $column=? AND status='posted'");
    $stmt->execute([$companyId,$creditId]);
}
