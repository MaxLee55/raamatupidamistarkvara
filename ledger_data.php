<?php
function ledgerCents(string $value): int
{
    if(!preg_match('/^(-?)([0-9]+)(?:\.([0-9]{1,2}))?$/',trim($value),$m)) throw new RuntimeException('Pearaamatus on vigane rahasumma.');
    $digits=ltrim($m[2].str_pad($m[3]??'',2,'0'),'0'); $max=(string)PHP_INT_MAX;
    if(strlen($digits)>strlen($max) || (strlen($digits)===strlen($max) && strcmp($digits,$max)>0)) throw new RuntimeException('Pearaamatu summa ületab toetatud täpsust.');
    return ($m[1]==='-'?-1:1)*(int)$digits;
}
function ledgerAdd(int $a,int $b): int
{
    if(($b>0 && $a>PHP_INT_MAX-$b) || ($b<0 && $a<PHP_INT_MIN-$b)) throw new RuntimeException('Pearaamatu kogusumma on liiga suur.');
    return $a+$b;
}
function ledgerDecimal(int $amount): string
{
    return ($amount<0?'-':'').intdiv(abs($amount),100).'.'.str_pad((string)(abs($amount)%100),2,'0',STR_PAD_LEFT);
}
function ledgerMoney(int $amount): string
{
    $decimal=ledgerDecimal($amount); [$whole,$fraction]=explode('.',$decimal);
    return preg_replace('/\B(?=(\d{3})+(?!\d))/',' ',$whole).','.$fraction.' €';
}
function ledgerHtml(string $value): string { return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function ledgerDateValid(string $value): bool
{
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    return $date && $date->format('Y-m-d')===$value;
}
function ledgerToday(): string { return (new DateTimeImmutable('now',new DateTimeZone('Europe/Tallinn')))->format('Y-m-d'); }
function ledgerFilters(array $query): array
{
    $scope=$query['scope']??'range';
    if(!is_string($scope) || !in_array($scope,['range','all'],true)) throw new InvalidArgumentException('Vali kehtiv periood.');
    $id=$query['account_id']??'';
    if($id==='' || $id==='0' || $id===0) $id=null;
    elseif(!is_scalar($id) || !filter_var($id,FILTER_VALIDATE_INT) || (int)$id<1) throw new InvalidArgumentException('Vali kehtiv konto.');
    else $id=(int)$id;
    $start=$query['start_date']??(substr(ledgerToday(),0,4).'-01-01'); $end=$query['end_date']??ledgerToday();
    if($scope==='all') { $start=null; $end=null; }
    elseif(!is_string($start) || !is_string($end) || !ledgerDateValid($start) || !ledgerDateValid($end) || $start>$end) throw new InvalidArgumentException('Vali kehtivad algus- ja lõpukuupäevad õiges järjekorras.');
    return ['scope'=>$scope,'account_id'=>$id,'start_date'=>$start,'end_date'=>$end];
}
function ledgerPeriod(array $report): string
{
    return $report['filters']['scope']==='all'?'Kogu ajalugu':$report['filters']['start_date'].' – '.$report['filters']['end_date'];
}
function ledgerType(string $type): string
{
    return ['asset'=>'Vara','liability'=>'Kohustus','equity'=>'Omakapital','income'=>'Tulu','expense'=>'Kulu'][$type]??$type;
}
function ledgerLoad(PDO $pdo,int $companyId,array $filters): array
{
    if($companyId<1) throw new InvalidArgumentException('Vali ettevõte.');
    $stmt=$pdo->prepare('SELECT id,name,registry_code,vat_number FROM companies WHERE id=?'); $stmt->execute([$companyId]); $company=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$company) throw new InvalidArgumentException('Ettevõtet ei leitud.');
    $stmt=$pdo->prepare('SELECT id,code,name,account_type,is_active FROM accounts WHERE company_id=? ORDER BY code,id'); $stmt->execute([$companyId]); $catalog=$stmt->fetchAll(PDO::FETCH_ASSOC);
    if($filters['account_id']!==null && !in_array($filters['account_id'],array_map(static fn(array $a):int=>(int)$a['id'],$catalog),true)) throw new InvalidArgumentException('Valitud kontot ei leitud sellest ettevõttest.');
    $integrity="SELECT COUNT(*) FROM journal_lines jl JOIN journal_entries je ON je.id=jl.entry_id LEFT JOIN accounts a ON a.id=jl.account_id WHERE je.company_id=? AND je.status='posted' AND (a.id IS NULL OR a.company_id<>je.company_id)";
    $params=[$companyId]; if($filters['end_date']!==null) { $integrity.=' AND je.entry_date<=?'; $params[]=$filters['end_date']; }
    $stmt=$pdo->prepare($integrity); $stmt->execute($params);
    if($stmt->fetchColumn()) throw new RuntimeException('Mõne kinnitatud kanderea konto seos ettevõttega on vigane. Paranda seos enne pearaamatu eksportimist.');
    $base=" FROM journal_lines jl JOIN journal_entries je ON je.id=jl.entry_id JOIN accounts a ON a.id=jl.account_id AND a.company_id=je.company_id WHERE je.company_id=? AND je.status='posted'";
    $opening=[];
    if($filters['start_date']!==null) {
        $sql='SELECT jl.account_id,COALESCE(SUM(jl.debit-jl.credit),0) AS balance'.$base.' AND je.entry_date<?'; $params=[$companyId,$filters['start_date']];
        if($filters['account_id']!==null) { $sql.=' AND jl.account_id=?'; $params[]=$filters['account_id']; }
        $stmt=$pdo->prepare($sql.' GROUP BY jl.account_id'); $stmt->execute($params); $opening=$stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    $params=[$companyId]; $where='';
    if($filters['start_date']!==null) { $where.=' AND je.entry_date BETWEEN ? AND ?'; $params[]=$filters['start_date']; $params[]=$filters['end_date']; }
    if($filters['account_id']!==null) { $where.=' AND jl.account_id=?'; $params[]=$filters['account_id']; }
    $stmt=$pdo->prepare('SELECT jl.account_id,COALESCE(SUM(jl.debit),0) AS debit,COALESCE(SUM(jl.credit),0) AS credit,COUNT(*) AS row_count'.$base.$where.' GROUP BY jl.account_id'); $stmt->execute($params);
    $activity=$stmt->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
    $report=['company'=>$company,'filters'=>$filters,'catalog'=>$catalog,'accounts'=>[],'debit'=>0,'credit'=>0,'row_count'=>0,'generated_at'=>(new DateTimeImmutable('now',new DateTimeZone('Europe/Tallinn')))->format('Y-m-d H:i T')];
    foreach($catalog as $account) {
        $id=(int)$account['id']; if($filters['account_id']!==null && $filters['account_id']!==$id) continue;
        $raw=ledgerCents((string)($opening[$id]??'0')); $row=$activity[$id]??['debit'=>'0','credit'=>'0','row_count'=>0];
        if($filters['account_id']===null && $raw===0 && (int)$row['row_count']===0) continue;
        $account['id']=$id; $account['sign']=in_array($account['account_type'],['liability','equity','income'],true)?-1:1;
        $account['opening_raw']=$raw; $account['opening']=$raw*$account['sign']; $account['debit']=ledgerCents($row['debit']); $account['credit']=ledgerCents($row['credit']); $account['row_count']=(int)$row['row_count'];
        $account['closing_raw']=ledgerAdd($raw,ledgerAdd($account['debit'],-$account['credit'])); $account['closing']=$account['closing_raw']*$account['sign'];
        $report['accounts'][]=$account; $report['debit']=ledgerAdd($report['debit'],$account['debit']); $report['credit']=ledgerAdd($report['credit'],$account['credit']); $report['row_count']+=$account['row_count'];
    }
    return $report;
}
/** One iterator shared by screen/CSV/PDF/XLSX; no row limits or omitted inactive accounts. */
function ledgerRecords(PDO $pdo,array $report): Generator
{
    $filters=$report['filters']; $sql="SELECT jl.id AS line_id,jl.account_id,je.id AS entry_id,je.entry_date,je.document_number,je.description AS entry_description,je.corrects_entry_id,jl.description AS line_description,jl.debit,jl.credit FROM journal_lines jl JOIN journal_entries je ON je.id=jl.entry_id JOIN accounts a ON a.id=jl.account_id AND a.company_id=je.company_id WHERE je.company_id=? AND je.status='posted'";
    $params=[(int)$report['company']['id']];
    if($filters['start_date']!==null) { $sql.=' AND je.entry_date BETWEEN ? AND ?'; $params[]=$filters['start_date']; $params[]=$filters['end_date']; }
    if($filters['account_id']!==null) { $sql.=' AND jl.account_id=?'; $params[]=$filters['account_id']; }
    $stmt=$pdo->prepare($sql.' ORDER BY a.code,a.id,je.entry_date,je.id,jl.id'); $stmt->execute($params); $line=$stmt->fetch(PDO::FETCH_ASSOC); $cache=[];
    $source=$pdo->prepare('SELECT document_number FROM journal_entries WHERE company_id=? AND id=?');
    foreach($report['accounts'] as $account) {
        $balance=$account['opening']; $debit=0; $credit=0; $count=0;
        $empty=['account'=>$account,'date'=>'','entry_id'=>null,'line_id'=>null,'document'=>'','corrected_document'=>'','entry_description'=>'','line_description'=>'','debit'=>null,'credit'=>null];
        yield $empty+['kind'=>'opening','balance'=>$balance];
        while($line && (int)$line['account_id']===$account['id']) {
            $d=ledgerCents($line['debit']); $c=ledgerCents($line['credit']); $debit=ledgerAdd($debit,$d); $credit=ledgerAdd($credit,$c); ++$count;
            $balance=ledgerAdd($balance,ledgerAdd($d,-$c)*$account['sign']);
            $original=(int)($line['corrects_entry_id']??0);
            if(!$original && preg_match('/^PAR-([0-9]+)$/',(string)$line['document_number'],$m)) $original=(int)$m[1];
            $document='';
            if($original && $original!==(int)$line['entry_id']) {
                if(!array_key_exists($original,$cache)) { $source->execute([(int)$report['company']['id'],$original]); $value=$source->fetchColumn(); $cache[$original]=$value===false?'':((string)$value?:'Kanne #'.$original); }
                $document=$cache[$original];
            }
            yield ['account'=>$account,'kind'=>'entry','date'=>$line['entry_date'],'entry_id'=>(int)$line['entry_id'],'line_id'=>(int)$line['line_id'],'document'=>(string)($line['document_number']??''),'corrected_document'=>$document,'entry_description'=>(string)$line['entry_description'],'line_description'=>(string)($line['line_description']??''),'debit'=>$d,'credit'=>$c,'balance'=>$balance];
            $line=$stmt->fetch(PDO::FETCH_ASSOC);
        }
        if($debit!==$account['debit'] || $credit!==$account['credit'] || $count!==$account['row_count'] || $balance!==$account['closing']) throw new RuntimeException('Pearaamatu ridade ja kokkuvõtte summad ei klapi. Värskenda aruanne.');
        $summary=$empty; $summary['debit']=$debit; $summary['credit']=$credit;
        yield $summary+['kind'=>'summary','balance'=>$balance];
        yield $empty+['kind'=>'closing','balance'=>$balance];
    }
    if($line) throw new RuntimeException('Pearaamatu kontode loend ei vasta kanderidadele.');
}
