<?php
/** Explicit card selection prevents matching unrelated assets by amount or name. */
function creditApplyAssets(PDO $pdo,int $companyId,int $userId,int $creditId,string $date,string $originalNumber,array $plan,array $selection): void
{
    $seen=[];
    foreach($plan['lines'] as $line) {
        $assetId=filter_var($selection[$line['id']]??0,FILTER_VALIDATE_INT);
        if(!$assetId) continue;
        if(isset($seen[$assetId]) || !empty($line['product_id'])) throw new RuntimeException('Üks põhivarakaart seo ühe arvereaga; laokaup ei saa samal ajal olla põhivara.');
        $seen[$assetId]=true;
        $stmt=$pdo->prepare("SELECT * FROM fixed_assets WHERE id=? AND company_id=? FOR UPDATE");
        $stmt->execute([$assetId,$companyId]); $asset=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$asset || !in_array($asset['status'],['draft','active'],true) || (int)$asset['asset_account_id']!==(int)$line['account_id']
            || trim((string)$asset['source_document'])!==$originalNumber || $asset['acquisition_date']>$date || !empty($asset['legacy_opening_balance'])) {
            throw new RuntimeException('Põhivara peab kuuluma sellele ettevõttele, viitama algsele ostuarvele ja kasutama sama varakontot. Algseisu või kõrvaldatud vara siin ei muudeta.');
        }
        $cost=creditCents($asset['acquisition_cost']); $reduction=$line['credit_net']+(int)($line['credit_nondeductible']??0);
        $later=$pdo->prepare("SELECT COUNT(*) FROM credit_note_asset_links l JOIN purchase_invoices i ON i.id=l.credit_invoice_id AND i.company_id=l.company_id WHERE l.company_id=? AND l.asset_id=? AND i.status='posted' AND i.invoice_date>?");
        $later->execute([$companyId,$assetId,$date]);
        if($later->fetchColumn()) throw new RuntimeException('Varal on hilisema kuupäevaga kreedit. Sisesta põhivara parandused kuupäevade järjekorras.');
        if($reduction>$cost) throw new RuntimeException('Põhivara kreedit ületab kaardi soetusmaksumust.');
        $stmt=$pdo->prepare('SELECT period_start,amount FROM fixed_asset_depreciation_runs WHERE company_id=? AND asset_id=? ORDER BY period_start');
        $stmt->execute([$companyId,$assetId]); $runs=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $accumulated=creditCents($asset['opening_accumulated_depreciation']);
        $start=new DateTimeImmutable(substr($asset['available_date'],0,7).'-01');
        $opening=(int)$asset['opening_depreciation_months'];
        foreach($runs as $i=>$run) {
            if($run['period_start']!==$start->modify('+'.($opening+$i).' months')->format('Y-m-d')) throw new RuntimeException('Põhivara amortisatsiooni ajaloos on vahe. Paranda see enne kreediti koostamist.');
            $accumulated+=creditCents($run['amount']);
        }
        $newCost=$cost-$reduction;
        if($newCost<$accumulated) throw new RuntimeException('Kreedit viiks põhivara alla arvestatud kulumi. Paranda või tühista esmalt selle vara amortisatsioon põhivara moodulis, seejärel koosta kreedit.');
        $month=substr($date,0,7).'-01';
        $offset=$opening+count($runs); $next=$start->modify('+'.$offset.' months')->format('Y-m-d');
        if($asset['status']==='active' && $month>=$start->format('Y-m-d') && $month!==$next) throw new RuntimeException('Arvesta enne kreeditit vara eelnevate kuude amortisatsioon või paranda juba arvestatud kreeditikuu amortisatsioon. Järgmine arvestamata kuu on '.substr($next,0,7).'.');
        $stmt=$pdo->prepare('SELECT * FROM fixed_asset_estimate_changes WHERE company_id=? AND asset_id=? ORDER BY effective_period,id');
        $stmt->execute([$companyId,$assetId]); $estimates=$stmt->fetchAll(PDO::FETCH_ASSOC); $latest=null;
        foreach($estimates as $estimate) {
            if($estimate['effective_period']>$month) throw new RuntimeException('Varal on hilisem arvestushinnang; kontrolli see enne kreeditit.');
            $latest=$estimate;
        }
        $residual=min(creditCents($asset['residual_value']),$newCost-$accumulated);
        $estimateId=null;
        if(($runs || $latest) && $newCost>0) {
            $life=$latest?(int)$latest['remaining_life_months']-($offset-(int)$latest['months_before_change']):(int)$asset['useful_life_months']-$offset;
            if($life<1) throw new RuntimeException('Vara kasulik eluiga on lõppenud; paranda esmalt põhivara arvestushinnang.');
            $residual=min($residual,$latest?creditCents($latest['residual_value']):$residual);
            $stmt=$pdo->prepare('INSERT INTO fixed_asset_estimate_changes (company_id,asset_id,effective_period,months_before_change,carrying_value_at_change,residual_value,remaining_life_months,reason,created_by) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$companyId,$assetId,$month,$offset,creditDecimal($newCost-$accumulated),creditDecimal($residual),$life,'Ostuarve kreedit '.$creditId,$userId]);
            $estimateId=(int)$pdo->lastInsertId();
        }
        $guardFields=array_flip(['acquisition_date','available_date','useful_life_months','asset_account_id','accumulated_depreciation_account_id','depreciation_expense_account_id','source_document','legacy_opening_balance','opening_accumulated_depreciation','opening_depreciation_months']);
        $after=array_merge(array_intersect_key($asset,$guardFields),['acquisition_cost'=>creditDecimal($newCost),'residual_value'=>creditDecimal($residual),'status'=>$newCost===0?'returned':$asset['status']]);
        $before=array_intersect_key($asset,$after);
        $stmt=$pdo->prepare('UPDATE fixed_assets SET acquisition_cost=?,residual_value=?,status=? WHERE id=? AND company_id=?');
        $stmt->execute([$after['acquisition_cost'],$after['residual_value'],$after['status'],$assetId,$companyId]);
        $stmt=$pdo->prepare('INSERT INTO credit_note_asset_links (company_id,credit_invoice_id,asset_id,original_line_id,before_json,after_json,depreciation_count,depreciation_hash,estimate_id) VALUES (?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$companyId,$creditId,$assetId,$line['id'],json_encode($before,JSON_THROW_ON_ERROR),json_encode($after,JSON_THROW_ON_ERROR),count($runs),hash('sha256',json_encode($runs,JSON_THROW_ON_ERROR)),$estimateId]);
    }
    foreach($selection as $lineId=>$assetId) if((int)$assetId && !in_array((int)$lineId,array_map(static fn(array $line):int=>(int)$line['id'],$plan['lines']),true)) throw new RuntimeException('Põhivarakaart on valitud reale, mida ei krediteerita.');
}
function creditVoidAssets(PDO $pdo,int $companyId,int $creditId): void
{
    $stmt=$pdo->prepare('SELECT * FROM credit_note_asset_links WHERE company_id=? AND credit_invoice_id=? ORDER BY asset_id');
    $stmt->execute([$companyId,$creditId]);
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $link) {
        $lock=$pdo->prepare('SELECT * FROM fixed_assets WHERE company_id=? AND id=? FOR UPDATE');
        $lock->execute([$companyId,$link['asset_id']]); $asset=$lock->fetch(PDO::FETCH_ASSOC);
        $before=json_decode($link['before_json'],true,512,JSON_THROW_ON_ERROR); $after=json_decode($link['after_json'],true,512,JSON_THROW_ON_ERROR);
        if(!$asset || array_intersect_key($asset,$after)!=$after) throw new RuntimeException('Põhivarakaarti on pärast kreeditit muudetud. Tühista muudatused esmalt tagurpidi järjekorras.');
        $count=$pdo->prepare('SELECT COUNT(*) FROM fixed_asset_depreciation_runs WHERE company_id=? AND asset_id=?');
        $count->execute([$companyId,$link['asset_id']]);
        if((int)$count->fetchColumn()!==(int)$link['depreciation_count']) throw new RuntimeException('Vara amortisatsiooni on pärast kreeditit muudetud. Paranda esmalt need kanded.');
        $runs=$pdo->prepare('SELECT period_start,amount FROM fixed_asset_depreciation_runs WHERE company_id=? AND asset_id=? ORDER BY period_start');
        $runs->execute([$companyId,$link['asset_id']]);
        if(!hash_equals($link['depreciation_hash'],hash('sha256',json_encode($runs->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR)))) throw new RuntimeException('Vara varasemat amortisatsiooni on pärast kreeditit parandatud. Kontrolli kõigepealt põhivara ajalugu.');
        if($link['estimate_id']) {
            $latest=$pdo->prepare('SELECT MAX(id) FROM fixed_asset_estimate_changes WHERE company_id=? AND asset_id=?');
            $latest->execute([$companyId,$link['asset_id']]);
            if((int)$latest->fetchColumn()!==(int)$link['estimate_id']) throw new RuntimeException('Varal on hilisem arvestushinnang. Paranda see enne kreediti tühistamist.');
            $delete=$pdo->prepare('DELETE FROM fixed_asset_estimate_changes WHERE company_id=? AND id=?'); $delete->execute([$companyId,$link['estimate_id']]);
        }
        $restore=$pdo->prepare('UPDATE fixed_assets SET acquisition_cost=?,residual_value=?,status=? WHERE company_id=? AND id=?');
        $restore->execute([$before['acquisition_cost'],$before['residual_value'],$before['status'],$companyId,$link['asset_id']]);
    }
}
