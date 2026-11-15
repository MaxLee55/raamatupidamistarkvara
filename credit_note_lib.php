<?php
require_once __DIR__.'/eu_reverse_vat.php';
require_once __DIR__.'/zero_sales_vat.php';
require_once __DIR__.'/purchase_vat_period.php';
require_once __DIR__.'/credit_note_special.php';
require_once __DIR__.'/credit_note_stock.php';
require_once __DIR__.'/credit_note_assets.php';
require_once __DIR__.'/credit_note_refunds.php';
require_once __DIR__.'/purchase_vat_adjustment.php';
require_once __DIR__.'/cash_sales_credit.php';
function ensureCreditNoteSchema(PDO $pdo): void
{
    if($pdo->inTransaction()) return;
    ensureZeroSalesSchema($pdo);
    ensurePurchaseVatAdjustmentSchema($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS credit_note_links (
        company_id INT NOT NULL, invoice_kind VARCHAR(10) NOT NULL, credit_invoice_id INT NOT NULL,
        original_invoice_id INT NOT NULL, percentage_bp INT NOT NULL,
        original_number VARCHAR(100) NOT NULL, reason VARCHAR(500) NOT NULL,
        deduction_cents BIGINT NULL, vat_purpose VARCHAR(32) NOT NULL DEFAULT 'ordinary', car_ids TEXT NULL,
        void_entry_id INT NULL, void_reason VARCHAR(500) NULL, created_by INT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (company_id,invoice_kind,credit_invoice_id),
        KEY original_credit (company_id,invoice_kind,original_invoice_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS credit_note_line_links (
        company_id INT NOT NULL, invoice_kind VARCHAR(10) NOT NULL, credit_invoice_id INT NOT NULL,
        original_line_id INT NOT NULL, credit_line_id INT NOT NULL,
        reverse_cents BIGINT NOT NULL DEFAULT 0, quantity_milli BIGINT NOT NULL DEFAULT 0,
        PRIMARY KEY(company_id,invoice_kind,credit_line_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $columns=$pdo->query('SHOW COLUMNS FROM credit_note_line_links')->fetchAll(PDO::FETCH_COLUMN);
    foreach(['reverse_cents','quantity_milli'] as $column) if(!in_array($column,$columns,true))
        $pdo->exec('ALTER TABLE credit_note_line_links ADD COLUMN '.$column.' BIGINT NOT NULL DEFAULT 0');
    $pdo->exec("CREATE TABLE IF NOT EXISTS credit_note_stock_lots (
        company_id INT NOT NULL, invoice_kind VARCHAR(10) NOT NULL, credit_invoice_id INT NOT NULL,
        movement_line_id INT NOT NULL, original_lot_id INT NOT NULL, quantity_milli BIGINT NOT NULL, cost_cents BIGINT NOT NULL,
        PRIMARY KEY(company_id,invoice_kind,movement_line_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS credit_note_asset_links (
        company_id INT NOT NULL,credit_invoice_id INT NOT NULL,asset_id INT NOT NULL,original_line_id INT NOT NULL,
        before_json TEXT NOT NULL,after_json TEXT NOT NULL,depreciation_count INT NOT NULL,depreciation_hash CHAR(64) NOT NULL,estimate_id INT NULL,
        PRIMARY KEY(company_id,credit_invoice_id,asset_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS credit_note_refunds (
        id INT NOT NULL AUTO_INCREMENT,company_id INT NOT NULL,invoice_kind VARCHAR(10) NOT NULL,
        credit_invoice_id INT NOT NULL,original_invoice_id INT NOT NULL,refund_date DATE NOT NULL,amount_cents BIGINT NOT NULL,
        bank_account_id INT NOT NULL,journal_entry_id INT NOT NULL,notes VARCHAR(500) NOT NULL,created_by INT NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'posted',void_entry_id INT NULL,void_reason VARCHAR(500) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(id),KEY original_refunds(company_id,invoice_kind,original_invoice_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function creditTables(string $kind): array
{
    if($kind==='sales') return ['sales_invoices','sales_invoice_lines','invoice_number','invoices.php'];
    if($kind==='purchase') return ['purchase_invoices','purchase_invoice_lines','supplier_invoice_number','purchase_invoices.php'];
    throw new InvalidArgumentException('Vigane arve liik.');
}
function creditCents(string $value): int
{
    $value=str_replace(',','.',trim($value));
    if(!preg_match('/^(-?)([0-9]{1,12})(?:\.([0-9]{1,2}))?$/',$value,$m)) throw new InvalidArgumentException('Vigane rahasumma.');
    return ($m[1]==='-'?-1:1)*((int)$m[2]*100+(int)str_pad($m[3]??'',2,'0'));
}
function creditDecimal(int $value): string
{
    return ($value<0?'-':'').intdiv(abs($value),100).'.'.str_pad((string)(abs($value)%100),2,'0',STR_PAD_LEFT);
}
function creditTextLength(string $value): int
{
    $count=preg_match_all('/./us',$value); return $count===false?strlen($value):$count;
}
function creditDescription(string $text): string
{
    return preg_match('/^.{0,500}/us',$text,$m)?$m[0]:substr($text,0,500);
}
function creditPercentage(string $value): int
{
    $bp=creditCents($value);
    if($bp<1 || $bp>10000) throw new InvalidArgumentException('Kreediti osakaal peab olema 0,01 kuni 100 protsenti.');
    return $bp;
}
function creditSlice(int $amount,int $before,int $portion): int
{
    if($amount<0 || $before<0 || $portion<1 || $before+$portion>10000) throw new InvalidArgumentException('Krediteeritav osakaal ületab algse arve jääki.');
    return intdiv($amount*($before+$portion)+5000,10000)-intdiv($amount*$before+5000,10000);
}
function loadCreditLinks(PDO $pdo,int $companyId,string $kind): array
{
    [$table]=creditTables($kind);
    $stmt=$pdo->prepare('SELECT l.credit_invoice_id AS credit_key, l.*, o.invoice_date AS original_date, o.contact_id AS original_contact_id, o.status AS original_status
        FROM credit_note_links l JOIN '.$table.' o ON o.id=l.original_invoice_id AND o.company_id=l.company_id
        WHERE l.company_id=? AND l.invoice_kind=?');
    $stmt->execute([$companyId,$kind]); $links=$stmt->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
    if(!$links) return [];
    $ids=array_keys($links);
    $stmt=$pdo->prepare('SELECT id,status FROM '.$table.' WHERE company_id=? AND id IN ('.implode(',',array_fill(0,count($ids),'?')).')');
    $stmt->execute(array_merge([$companyId],$ids)); $statuses=$stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach($links as $id=>&$link) $link['credit_status']=$statuses[$id]??'missing';
    unset($link); return $links;
}
function creditPlan(array $lines,int $before,int $portion,?array $credited=null,?array $selection=null): array
{
    $plan=['lines'=>[],'net'=>0,'vat'=>0,'gross'=>0,'reverse'=>0,'net_accounts'=>[]];
    if($selection===null && ($before<0 || $portion<1 || $portion>10000 || $before+$portion>10000)) throw new InvalidArgumentException('Krediteeritav osakaal ületab algse arve jääki.');
    foreach($lines as $line) {
        creditValidateTaxLine($line);
        $net=creditCents((string)$line['net_amount']); $vat=creditCents((string)$line['vat_amount']); $gross=creditCents((string)$line['gross_amount']);
        if($net<0 || $vat<0 || $net+$vat!==$gross) throw new RuntimeException('Algne arve peab sisaldama korrektseid positiivseid ridu.');
        $n=creditSlice($net,$before,$portion); $v=creditSlice($vat,$before,$portion);
        if($credited!==null) {
            $past=$credited[$line['id']]??['net'=>0,'vat'=>0];
            $n=max(0,min($net-$past['net'],creditSlice($net,0,$before+$portion)-$past['net']));
            $v=max(0,min($vat-$past['vat'],creditSlice($vat,0,$before+$portion)-$past['vat']));
        }
        if($selection!==null) {
            $past=$credited[$line['id']]??['net'=>0,'vat'=>0];
            $n=creditCents((string)($selection[$line['id']]??'0'));
            if($n<0 || $n>$net-$past['net']) throw new InvalidArgumentException('Rea kreedit ületab algse rea krediteerimata netosummat.');
            $v=$net>0?intdiv($vat*($past['net']+$n)+intdiv($net,2),$net)-$past['vat']:0;
            if($v<0 || $v>$vat-$past['vat']) throw new InvalidArgumentException('Rea käibemaksu jääk ei klapi.');
        }
        if($n===0 && $v===0) continue;
        $spec=reverseVatSpec((string)($line['tax_code']??''));
        $reverse=$spec?euReverseVat($net,$spec['rate']):0;
        $past=$credited[$line['id']??0]??['net'=>0,'vat'=>0,'reverse'=>0];
        $r=$net>0?intdiv($reverse*($past['net']+$n)+intdiv($net,2),$net)-(int)($past['reverse']??0):0;
        $line['credit_reverse']=$r; $plan['reverse']+=$r;
        $line['credit_net']=$n; $line['credit_vat']=$v; $line['credit_gross']=$n+$v;
        $plan['lines'][]=$line; $plan['net']+=$n; $plan['vat']+=$v; $plan['gross']+=$n+$v;
        if(isset($line['account_id'])) $plan['net_accounts'][(int)$line['account_id']]=($plan['net_accounts'][(int)$line['account_id']]??0)+$n;
    }
    if($plan['gross']<=0) throw new RuntimeException('Valitud kreedit on liiga väike, et tekiks rahaline summa.');
    return $plan;
}
function creditJournalPlan(string $kind,array $sourceLines,array $originalLines,array $plan): array
{
    $accountTotals=[]; $control=0; $vatAccount=0; $revenue=0; $reverseInput=0; $reverseOutput=0;
    foreach($sourceLines as $line) {
        $id=(int)$line['account_id']; $d=creditCents((string)$line['debit']); $c=creditCents((string)$line['credit']);
        if(empty($line['is_active'])) throw new RuntimeException('Algse kande konto pole aktiivne.');
        $accountTotals[$id]=($accountTotals[$id]??0)+$d-$c;
        if($kind==='purchase' && trim((string)$line['description'])==='Pöördmaksu sisendkäibemaks') { $reverseInput=$id; continue; }
        if($kind==='purchase' && trim((string)$line['description'])==='Pöördmaksu käibemaksukohustus') { $reverseOutput=$id; continue; }
        if($kind==='sales') {
            if($d>0 && $line['account_type']==='asset') { if($control && $control!==$id) throw new RuntimeException('Algsel kandel on mitu nõuete kontot.'); $control=$id; }
            if($c>0 && $line['account_type']==='income') { if($revenue && $revenue!==$id) throw new RuntimeException('Algsel kandel on mitu tulukontot.'); $revenue=$id; }
            if($c>0 && $line['account_type']==='liability') $vatAccount=$id;
        } else {
            if($c>0 && $line['account_type']==='liability') { if($control && $control!==$id) throw new RuntimeException('Algsel kandel on mitu kohustuste kontot.'); $control=$id; }
            if($d>0 && trim((string)$line['description'])==='Sisendkäibemaks') $vatAccount=$id;
        }
    }
    $net=0; $vat=0; $reverse=0; $originalNetAccounts=[];
    foreach($originalLines as $line) {
        $n=creditCents((string)$line['net_amount']); $net+=$n; $vat+=creditCents((string)$line['vat_amount']);
        $spec=reverseVatSpec((string)($line['tax_code']??''));
        if($spec && $kind==='purchase') $reverse+=euReverseVat($n,$spec['rate']);
        if($kind==='purchase') $originalNetAccounts[(int)$line['account_id']]=($originalNetAccounts[(int)$line['account_id']]??0)+$n;
    }
    if(!$control || ($vat>0 && !$vatAccount) || ($kind==='sales' && !$revenue)) throw new RuntimeException('Algse kande kontosid ei saa üheselt tuvastada.');
    $expected=[];
    $add=static function(array &$map,int $id,int $signed): void { $map[$id]=($map[$id]??0)+$signed; };
    if($kind==='sales') { $add($expected,$control,$net+$vat); $add($expected,$revenue,-$net); $add($expected,$vatAccount,-$vat); }
    else { foreach($originalNetAccounts as $id=>$n) $add($expected,$id,$n); $add($expected,$vatAccount,$vat); $add($expected,$control,-$net-$vat); }
    if($reverse) {
        if(!$reverseInput || !$reverseOutput || $reverseOutput===$control) throw new RuntimeException('Algse pöördmaksukande kontod puuduvad.');
        $add($expected,$reverseInput,$reverse); $add($expected,$reverseOutput,-$reverse);
    }
    $clean=static function(array $map): array { $map=array_filter($map,static fn(int $v):bool=>$v!==0); ksort($map); return $map; };
    if($clean($expected)!==$clean($accountTotals)) throw new RuntimeException('Algne kanne erineb tavalisest arvekandest. Kreedit vajab eraldi kontrolli.');
    $result=[];
    $push=static function(int $id,int $debit,int $credit) use (&$result): void {
        if($debit || $credit) $result[]=['account_id'=>$id,'debit'=>creditDecimal($debit),'credit'=>creditDecimal($credit)];
    };
    if($kind==='sales') { $push($revenue,$plan['net'],0); $push($vatAccount,$plan['vat'],0); $push($control,0,$plan['gross']); }
    else { $push($control,$plan['gross'],0); foreach($plan['net_accounts'] as $id=>$n) $push($id,0,$n); $push($vatAccount,0,$plan['vat']); }
    if($reverse) { $push($reverseOutput,$plan['reverse'],0); $push($reverseInput,0,$plan['reverse']); }
    return $result;
}

function creditAppliedTotals(PDO $pdo,int $companyId,string $kind): array
{
    [$table,$lines]=creditTables($kind);
    $stmt=$pdo->prepare("SELECT l.original_invoice_id, -SUM(t.gross_amount) AS credited
        FROM credit_note_links l JOIN ".$table." c ON c.id=l.credit_invoice_id AND c.company_id=l.company_id
        JOIN ".$lines." t ON t.invoice_id=c.id
        WHERE l.company_id=? AND l.invoice_kind=? AND c.status='posted'
        GROUP BY l.original_invoice_id");
    $stmt->execute([$companyId,$kind]); $result=[];
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $result[(int)$row['original_invoice_id']]=creditCents((string)$row['credited']);
    return $result;
}
function createCreditNote(PDO $pdo,int $companyId,int $userId,string $kind,int $originalId,string $number,string $date,int $portion,string $reason,?array $selection=null,array $assetSelection=[]): int
{
    [$table,$lines,$numberColumn]=creditTables($kind);
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$parsed || $parsed->format('Y-m-d')!==$date || trim($number)==='' || creditTextLength($number)>50 || trim($reason)==='' || creditTextLength($reason)>500) throw new RuntimeException('Sisesta korrektne kuupäev, kreeditarve number ja põhjus.');
    $message=closedAccountingPeriodMessage($pdo,$companyId,$date);
    if($message!==null) throw new RuntimeException($message);
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare("SELECT i.*,c.country_code,c.vat_number AS partner_vat_number FROM ".$table." i JOIN contacts c ON c.id=i.contact_id AND c.company_id=i.company_id WHERE i.id=? AND i.company_id=? FOR UPDATE");
        $stmt->execute([$originalId,$companyId]); $original=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$original || $original['status']!=='posted' || !$original['journal_entry_id'] || $date<$original['invoice_date']) throw new RuntimeException('Algne arve peab olema kinnitatud ja kreeditarve kuupäev ei tohi olla varasem.');
        if (($original['invoice_currency'] ?? 'EUR') !== 'EUR') {
            throw new RuntimeException('Valuutaarve kreeditarve vajab eraldi valuutaarvestust. Kasuta algse arve tühistamist, kui tehingu tingimused seda lubavad.');
        }
        $periodPlan = $kind === 'purchase' ? purchaseVatPeriodPlan($pdo, $companyId, $originalId) : null;
        if ($periodPlan) {
            if (!isCashVatPlan($periodPlan['allocation_json'])) {
                $allocationMonths = array_keys(json_decode($periodPlan['allocation_json'], true, 512, JSON_THROW_ON_ERROR));
                if (max($allocationMonths) > substr($date, 0, 7)) {
                    throw new RuntimeException('Ostuarve mahaarvamine on jaotatud kuude vahel ja osa mahaarvamisest on veel ees. Kreeditarve vajab tulevaste summade eraldi ümberjaotust.');
                }
            }
        }
        $links=loadCreditLinks($pdo,$companyId,$kind);
        if(isset($links[$originalId])) throw new RuntimeException('Kreeditarvele uut kreeditarvet siin ei koostata.');
        $stmt=$pdo->prepare("SELECT COALESCE(SUM(l.percentage_bp),0) FROM credit_note_links l
            JOIN ".$table." c ON c.id=l.credit_invoice_id AND c.company_id=l.company_id
            WHERE l.company_id=? AND l.invoice_kind=? AND l.original_invoice_id=? AND c.status='posted'");
        $stmt->execute([$companyId,$kind,$originalId]); $before=(int)$stmt->fetchColumn();
        $stmt=$pdo->prepare("SELECT t.*, tc.tax_category, tc.code AS tax_code FROM ".$lines." t
            LEFT JOIN tax_codes tc ON tc.id=t.tax_code_id AND tc.company_id=?
            WHERE t.invoice_id=? ORDER BY t.id");
        $stmt->execute([$companyId,$originalId]); $source=$stmt->fetchAll(PDO::FETCH_ASSOC);
        if(!$source) throw new RuntimeException('Algsel arvel puuduvad read.');
        if (array_filter($source,static fn(array $line): bool => str_starts_with((string)($line['tax_code'] ?? ''),'M_CASH'))) {
            if ($kind!=='sales') throw new RuntimeException('Kassapõhise müügi maksukood ei sobi ostuarvele.');
            assertCashSaleCreditBasis($pdo,$companyId,$originalId,$date);
        }
        $reverseCount=0;
        foreach($source as $line) {
            $spec=reverseVatSpec((string)($line['tax_code']??''));
            if(!$spec) continue;
            ++$reverseCount;
            $issue=reversePartyError($spec,(string)$original['country_code'],(string)$original['partner_vat_number'])??reverseCompanyError($pdo,$companyId,$spec);
            if($spec['side']!==$kind || $issue!==null) throw new RuntimeException($issue??'Algse pöördmaksurea arveliik ei klapi.');
        }
        if($kind==='sales' && $reverseCount && $reverseCount!==count($source)) {
            throw new RuntimeException('Müügi segatud pöördmaksu kreedit vajab eraldi jaotust.');
        }
        $stmt=$pdo->prepare("SELECT x.original_line_id,-SUM(t.net_amount) AS credited_net,-SUM(t.vat_amount) AS credited_vat,SUM(x.reverse_cents) AS credited_reverse,SUM(x.quantity_milli) AS credited_quantity
            FROM credit_note_line_links x JOIN ".$table." c ON c.id=x.credit_invoice_id AND c.company_id=x.company_id
            JOIN ".$lines." t ON t.id=x.credit_line_id AND t.invoice_id=c.id
            JOIN credit_note_links l ON l.credit_invoice_id=c.id AND l.company_id=c.company_id AND l.invoice_kind=x.invoice_kind
            WHERE x.company_id=? AND x.invoice_kind=? AND l.original_invoice_id=? AND c.status='posted' GROUP BY x.original_line_id");
        $stmt->execute([$companyId,$kind,$originalId]); $credited=[];
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $past) $credited[(int)$past['original_line_id']]=['net'=>creditCents((string)$past['credited_net']),'vat'=>creditCents((string)$past['credited_vat']),'reverse'=>(int)$past['credited_reverse'],'quantity'=>(int)$past['credited_quantity']];
        if($selection!==null) foreach($selection as $lineId=>$value) if(!in_array((int)$lineId,array_map(static fn(array $l):int=>(int)$l['id'],$source),true)) throw new InvalidArgumentException('Valitud rida ei kuulu algsele arvele.');
        $plan=creditPlan($source,$selection===null?$before:0,$portion,$credited,$selection);
        $stmt=$pdo->prepare("SELECT j.account_id,j.description,j.debit,j.credit,a.account_type,a.is_active
            FROM journal_lines j JOIN journal_entries e ON e.id=j.entry_id
            JOIN accounts a ON a.id=j.account_id AND a.company_id=e.company_id
            WHERE e.id=? AND e.company_id=? AND e.status='posted' ORDER BY j.id");
        $stmt->execute([(int)$original['journal_entry_id'],$companyId]);
        $journalSource=$stmt->fetchAll(PDO::FETCH_ASSOC);
        $deduction=null; $purpose='ordinary'; $carIds=[];
        if($kind==='purchase') {
            if ($periodPlan) {
                require_once __DIR__.'/kmd_inf_data.php';
                $loaded = loadInfRows($pdo, $companyId, $original['invoice_date'], $original['invoice_date'], 'purchase', [$originalId]);
                if (!$loaded['invoices']) throw new RuntimeException('Algse ostuarve read puuduvad.');
                $sourceDeduction = purchaseVatPeriodSource($pdo, $companyId, $loaded['invoices'][0]);
                if(isCashVatPlan($periodPlan['allocation_json'])) {
                    if($date<$periodPlan['received_date']) throw new RuntimeException('Kassapõhise ostukreediti kuupäev ei tohi eelneda arve saamisele.');
                    purchaseCashSupplierAllocations($sourceDeduction,purchaseCashSupplierPayments($pdo,$companyId,$originalId,$date),$periodPlan['received_date']);
                }
            } else {
                $loaded=loadPurchaseDeductions($pdo,$companyId,$original['invoice_date'],$original['invoice_date']);
                $sourceDeduction=$loaded['invoices'][$originalId]??null;
            }
            if(!$sourceDeduction || (!$sourceDeduction['deduction_confirmed'] && $sourceDeduction['deduction_vat']!==0)) throw new RuntimeException('Kinnita esmalt algse ostuarve mahaarvatav käibemaks.');
            $vatSource=syncPurchaseVatOriginal($pdo,$companyId,$userId,$sourceDeduction);
            syncPurchaseVatCredits($pdo,$companyId,$userId,$originalId,$vatSource);
            $vatState=purchaseVatState($pdo,$companyId,$originalId);
            $pastStates=$pdo->prepare("SELECT a.allocations_json FROM purchase_vat_adjustments a JOIN credit_note_links n ON n.company_id=a.company_id AND n.credit_invoice_id=a.invoice_id AND n.invoice_kind='purchase' JOIN purchase_invoices c ON c.id=a.invoice_id AND c.company_id=a.company_id WHERE n.company_id=? AND n.original_invoice_id=? AND c.status='posted'");
            $pastStates->execute([$companyId,$originalId]);
            foreach($pastStates->fetchAll(PDO::FETCH_COLUMN) as $json) foreach(json_decode($json,true,512,JSON_THROW_ON_ERROR) as $id=>$allocation) $credited[$id]['nondeductible']=($credited[$id]['nondeductible']??0)-(int)$allocation['amount'];
            $deduction=purchaseCreditVatPlan($vatState,$plan,$credited);
            foreach($source as &$line) $line['capitalized_nondeductible']=(int)($vatState['allocations'][$line['id']]['amount']??0); unset($line);
            foreach($plan['lines'] as $line) if(!empty($vatState['allocations'][$line['id']]['asset_id'])) {
                $mapped=(int)$vatState['allocations'][$line['id']]['asset_id'];
                if(!empty($assetSelection[$line['id']]) && (int)$assetSelection[$line['id']]!==$mapped) throw new RuntimeException('Valitud põhivarakaart erineb käibemaksu parandusega seotud kaardist.');
                $assetSelection[$line['id']]=$mapped;
            }
            $purpose=$sourceDeduction['vat_purpose']; $carIds=$sourceDeduction['car_ids'];
        }
        $stock=creditStockPlan($pdo,$companyId,$kind,$originalId,$date,$plan,$credited,$journalSource,$source);
        $journal=array_merge(creditJournalPlan($kind,$stock['source'],$source,$plan),$stock['journal']);
        $notes='Kreeditarve algsele arvele '.$original[$numberColumn].'. '.$reason;
        $stmt=$pdo->prepare("INSERT INTO ".$table." (company_id,contact_id,".$numberColumn.",invoice_date,due_date,status,notes,created_by,journal_entry_id)
            VALUES (?,?,?,?,?,'posted',?,?,NULL)");
        $stmt->execute([$companyId,$original['contact_id'],$number,$date,$date,$notes,$userId]);
        $creditId=(int)$pdo->lastInsertId();
        if($kind==='purchase') creditApplyAssets($pdo,$companyId,$userId,$creditId,$date,$original[$numberColumn],$plan,$assetSelection);
        elseif(array_filter($assetSelection)) throw new RuntimeException('Põhivara soetuskaardi parandus on ostukreediti toiming.');
        foreach($plan['lines'] as &$line) {
            $fields='invoice_id,description,quantity,unit_price,vat_rate,tax_code_id,net_amount,vat_amount,gross_amount';
            $values=[$creditId,$line['description'],'-1.000',creditDecimal($line['credit_net']),$line['vat_rate'],$line['tax_code_id'],
                creditDecimal(-$line['credit_net']),creditDecimal(-$line['credit_vat']),creditDecimal(-$line['credit_gross'])];
            if($kind==='purchase') { $fields.=',account_id'; $values[]=$line['account_id']; }
            if(!empty($line['product_id'])) {
                $fields.=',product_id,warehouse_id'; $values[]=$line['product_id']; $values[]=$line['warehouse_id'];
                $values[2]='-'.creditQuantity($line['credit_quantity']); $values[3]=$line['unit_price'];
            }
            $stmt=$pdo->prepare('INSERT INTO '.$lines.' ('.$fields.') VALUES ('.implode(',',array_fill(0,count($values),'?')).')');
            $stmt->execute($values);
            $creditLineId=(int)$pdo->lastInsertId();
            $line['credit_line_id'] = $creditLineId;
            $map=$pdo->prepare('INSERT INTO credit_note_line_links (company_id,invoice_kind,credit_invoice_id,original_line_id,credit_line_id,reverse_cents,quantity_milli) VALUES (?,?,?,?,?,?,?)');
            $map->execute([$companyId,$kind,$creditId,$line['id'],$creditLineId,$line['credit_reverse'],$line['credit_quantity']]);
        }
        unset($line);
        $stmt=$pdo->prepare("INSERT INTO journal_entries (company_id,entry_date,description,document_number,status,created_by,posted_at)
            VALUES (?,?,?,?,'posted',?,CURRENT_TIMESTAMP)");
        $stmt->execute([$companyId,$date,creditDescription($notes),$number,$userId]); $entryId=(int)$pdo->lastInsertId();
        $stmt=$pdo->prepare('INSERT INTO journal_lines (entry_id,account_id,description,debit,credit) VALUES (?,?,?,?,?)');
        foreach($journal as $line) $stmt->execute([$entryId,$line['account_id'],creditDescription($notes),$line['debit'],$line['credit']]);
        creditSaveStock($pdo,$companyId,$userId,$kind,$creditId,$entryId,$date,$number,$stock['movements']);
        $stmt=$pdo->prepare('UPDATE '.$table.' SET journal_entry_id=? WHERE id=? AND company_id=?');
        $stmt->execute([$entryId,$creditId,$companyId]);
        $stmt=$pdo->prepare('INSERT INTO credit_note_links (company_id,invoice_kind,credit_invoice_id,original_invoice_id,percentage_bp,original_number,reason,deduction_cents,vat_purpose,car_ids,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$companyId,$kind,$creditId,$originalId,$portion,$original[$numberColumn],$reason,$deduction,$purpose,json_encode($carIds,JSON_THROW_ON_ERROR),$userId]);
        if($kind==='purchase') postPurchaseCreditVat($pdo,$companyId,$userId,$creditId,$date,$vatSource,$plan,$deduction);
        if($kind==='sales') copyZeroSalesCredit($pdo,$companyId,$userId,$originalId,$creditId);
        $pdo->commit(); return $creditId;
    } catch(Throwable $exception) { if($pdo->inTransaction()) $pdo->rollBack(); throw $exception; }
}

function voidCreditNote(PDO $pdo,int $companyId,int $userId,string $kind,int $creditId,string $reason): void
{
    [$table]=creditTables($kind);
    if(trim($reason)==='' || creditTextLength($reason)>500) throw new RuntimeException('Sisesta kreeditarve tühistamise põhjus.');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();
    try {
        $links=loadCreditLinks($pdo,$companyId,$kind); $link=$links[$creditId]??null;
        if(!$link) throw new RuntimeException('Kreeditarve viidet ei leitud.');
        $stmt=$pdo->prepare('SELECT id FROM '.$table.' WHERE id=? AND company_id=? FOR UPDATE');
        $stmt->execute([(int)$link['original_invoice_id'],$companyId]);
        $stmt=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=? AND company_id=? FOR UPDATE');
        $stmt->execute([$creditId,$companyId]); $credit=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$credit || $credit['status']!=='posted' || !$credit['journal_entry_id']) throw new RuntimeException('Kreeditarve ei ole kinnitatud.');
        $message=closedAccountingPeriodMessage($pdo,$companyId,$credit['invoice_date']);
        if($message!==null) throw new RuntimeException($message);
        $paymentTable=$kind==='sales'?'invoice_payments':'purchase_invoice_payments';
        $stmt=$pdo->prepare('SELECT COUNT(*) FROM '.$paymentTable.' WHERE invoice_id=?'); $stmt->execute([$creditId]);
        if((int)$stmt->fetchColumn()>0) throw new RuntimeException('Kreeditarvel on makseid; paranda need enne tühistamist.');
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM credit_note_refunds WHERE company_id=? AND invoice_kind=? AND credit_invoice_id=? AND status='posted'");
        $stmt->execute([$companyId,$kind,$creditId]);
        if($stmt->fetchColumn()) throw new RuntimeException('Kreeditarvel on tagasimakseid. Tühista need enne kreeditarve tühistamist.');
        creditVoidStock($pdo,$companyId,$kind,$creditId);
        if($kind==='purchase') creditVoidAssets($pdo,$companyId,$creditId);
        if($kind==='purchase') voidPurchaseVatAdjustment($pdo,$companyId,$userId,$creditId,$credit['invoice_date'],false);
        $stmt=$pdo->prepare('SELECT account_id,description,debit,credit FROM journal_lines WHERE entry_id=? ORDER BY id');
        $stmt->execute([(int)$credit['journal_entry_id']]); $journal=$stmt->fetchAll(PDO::FETCH_ASSOC);
        if(!$journal) throw new RuntimeException('Kreeditarve kanne puudub.');
        $balance=0; foreach($journal as $line) $balance+=creditCents((string)$line['debit'])-creditCents((string)$line['credit']);
        if($balance!==0) throw new RuntimeException('Kreeditarve kanne pole tasakaalus.');
        $description=creditDescription('Kreeditarve tühistamine: '.$reason);
        $stmt=$pdo->prepare("INSERT INTO journal_entries (company_id,entry_date,description,document_number,status,created_by,posted_at) VALUES (?,?,?,?,'posted',?,CURRENT_TIMESTAMP)");
        $stmt->execute([$companyId,$credit['invoice_date'],$description,$kind==='sales'?$credit['invoice_number']:$credit['supplier_invoice_number'],$userId]);
        $entryId=(int)$pdo->lastInsertId();
        $stmt=$pdo->prepare('INSERT INTO journal_lines (entry_id,account_id,description,debit,credit) VALUES (?,?,?,?,?)');
        foreach($journal as $line) $stmt->execute([$entryId,$line['account_id'],$description,$line['credit'],$line['debit']]);
        $stmt=$pdo->prepare("UPDATE ".$table." SET status='cancelled' WHERE id=? AND company_id=? AND status='posted'");
        $stmt->execute([$creditId,$companyId]);
        $stmt=$pdo->prepare('UPDATE credit_note_links SET void_entry_id=?,void_reason=? WHERE company_id=? AND invoice_kind=? AND credit_invoice_id=?');
        $stmt->execute([$entryId,$reason,$companyId,$kind,$creditId]); $pdo->commit();
    } catch(Throwable $exception) { if($pdo->inTransaction()) $pdo->rollBack(); throw $exception; }
}
