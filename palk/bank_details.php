<?php
function ensurePayrollBankSchema(PDO $pdo): void
{
    if($pdo->inTransaction()) return;
    $columns=$pdo->query('SHOW COLUMNS FROM payroll_employees')->fetchAll(PDO::FETCH_COLUMN);
    foreach(['bank_iban'=>'VARCHAR(34)','bank_name'=>'VARCHAR(120)','bank_bic'=>'VARCHAR(11)'] as $column=>$type) if(!in_array($column,$columns,true)) $pdo->exec('ALTER TABLE payroll_employees ADD COLUMN '.$column.' '.$type.' NULL');
}
function payrollTextLength(string $value): int
{
    return function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):(preg_match_all('/./us',$value)?:strlen($value));
}
function validatePayrollBankDetails(array $data): array
{
    $iban=strtoupper(preg_replace('/\s+/u','',trim((string)($data['bank_iban']??''))));
    $name=trim((string)($data['bank_name']??''));
    $bic=strtoupper(preg_replace('/\s+/u','',trim((string)($data['bank_bic']??''))));
    if($iban!=='') {
        if(!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/',$iban)) throw new InvalidArgumentException('Sisesta kehtiv IBAN kontonumber.');
        $reordered=substr($iban,4).substr($iban,0,4); $remainder=0;
        foreach(str_split($reordered) as $character) foreach(str_split(ctype_alpha($character)?(string)(ord($character)-55):$character) as $digit) $remainder=($remainder*10+(int)$digit)%97;
        if($remainder!==1 || (str_starts_with($iban,'EE') && strlen($iban)!==20)) throw new InvalidArgumentException('IBAN kontonumbri kontrollnumber või pikkus ei klapi.');
    }
    if(payrollTextLength($name)>120) throw new InvalidArgumentException('Panga nimi võib olla kuni 120 märki.');
    if($bic!=='' && !preg_match('/^[A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/',$bic)) throw new InvalidArgumentException('BIC peab olema 8 või 11 märki.');
    return ['bank_iban'=>$iban,'bank_name'=>$name,'bank_bic'=>$bic];
}
function updatePayrollBankDetails(PDO $pdo,int $companyId,int $employeeId,array $data): void
{
    $bank=validatePayrollBankDetails($data);
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('SELECT id FROM payroll_employees WHERE company_id=? AND id=? FOR UPDATE'); $stmt->execute([$companyId,$employeeId]);
        if(!$stmt->fetchColumn()) throw new InvalidArgumentException('Selle ettevõtte töötajat ei leitud.');
        $stmt=$pdo->prepare('UPDATE payroll_employees SET bank_iban=?,bank_name=?,bank_bic=? WHERE company_id=? AND id=?');
        $stmt->execute([$bank['bank_iban']?:null,$bank['bank_name']?:null,$bank['bank_bic']?:null,$companyId,$employeeId]);
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
