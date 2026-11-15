<?php
require_once __DIR__.'/period_lock.php';

function ensureCompanyVatStatusSchema(PDO $pdo): void
{
    if ($pdo->query("SHOW COLUMNS FROM companies LIKE 'non_vat_registered'")->fetch()) {
        return;
    }
    try {
        $pdo->exec('ALTER TABLE companies ADD COLUMN non_vat_registered TINYINT(1) NOT NULL DEFAULT 0');
    } catch (PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) !== 1060) {
            throw $error;
        }
    }
}

function companyIsNonVatRegistered(PDO $pdo, int $companyId): bool
{
    ensureCompanyVatStatusSchema($pdo);
    $statement = $pdo->prepare('SELECT non_vat_registered FROM companies WHERE id = ?');
    $statement->execute([$companyId]);
    return (bool) $statement->fetchColumn();
}

function ensureCompanyCashVatSchema(PDO $pdo): void
{
    $columns = $pdo->query('SHOW COLUMNS FROM companies')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['cash_vat_from', 'cash_vat_until'] as $column) {
        if (in_array($column,$columns,true)) continue;
        try {
            $pdo->exec('ALTER TABLE companies ADD COLUMN '.$column.' CHAR(7) NULL');
        } catch (PDOException $error) {
            if ((int)($error->errorInfo[1] ?? 0) !== 1060) throw $error;
        }
    }
    if(!in_array('cash_vat_history',$columns,true)) $pdo->exec('ALTER TABLE companies ADD COLUMN cash_vat_history TEXT NULL');
}

function companyCashVatPeriod(PDO $pdo, int $company): array
{
    ensureCompanyCashVatSchema($pdo);
    $statement = $pdo->prepare('SELECT cash_vat_from,cash_vat_until,cash_vat_history FROM companies WHERE id=?');
    $statement->execute([$company]);
    return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
}

function companyUsesCashVat(array $period, string $date): bool
{
    $month = substr($date,0,7);
    $intervals=json_decode($period['cash_vat_history']??'[]',true,512,JSON_THROW_ON_ERROR)??[];
    $intervals[]=['cash_vat_from'=>$period['cash_vat_from']??null,'cash_vat_until'=>$period['cash_vat_until']??null];
    foreach($intervals as $interval) if(!empty($interval['cash_vat_from']) && $month>=$interval['cash_vat_from']
        && (empty($interval['cash_vat_until']) || $month<=$interval['cash_vat_until'])) return true;
    return false;
}

function companyCashVatChangeHistory(PDO $pdo,int $company,string $from,string $until): ?string
{
    foreach([$from,$until] as $month) if($month!=='' && !preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D',$month)) throw new InvalidArgumentException('Vali korrektne kassapõhise arvestuse kuu.');
    if($until!=='' && ($from==='' || $until<$from)) throw new InvalidArgumentException('Kassapõhise arvestuse lõpp ei tohi olla algusest varasem.');
    $old=companyCashVatPeriod($pdo,$company);
    $history=json_decode($old['cash_vat_history']??'[]',true,512,JSON_THROW_ON_ERROR)??[];
    if(($old['cash_vat_from']??'')===$from && ($old['cash_vat_until']??'')===$until) return $old['cash_vat_history']??null;
    if(!empty($old['cash_vat_from']) && !empty($old['cash_vat_until']) && ($from==='' || $from>$old['cash_vat_until'])) {
        $closed=['cash_vat_from'=>$old['cash_vat_from'],'cash_vat_until'=>$old['cash_vat_until']];
        if(!in_array($closed,$history,true)) $history[]=$closed;
    }
    $proposed=['cash_vat_from'=>$from,'cash_vat_until'=>$until,'cash_vat_history'=>json_encode($history,JSON_THROW_ON_ERROR)];
    $q=$pdo->prepare("SELECT DISTINCT i.invoice_date,i.supply_date FROM sales_invoices i
        JOIN sales_invoice_lines l ON l.invoice_id=i.id JOIN tax_codes t ON t.id=l.tax_code_id AND t.company_id=i.company_id
        WHERE i.company_id=? AND i.status='posted' AND t.code IN ('M_CASH24','M_CASH13','M_CASH9')");
    $q->execute([$company]);$sales=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach($sales as $sale) {
        if($sale['supply_date'] && companyUsesCashVat($old,$sale['supply_date'])!==companyUsesCashVat($proposed,$sale['supply_date'])
            && ($issue=closedAccountingPeriodMessage($pdo,$company,$sale['supply_date']))) throw new InvalidArgumentException($issue);
        if(!companyUsesCashVat($proposed,$sale['supply_date']?:$sale['invoice_date']) && !companyUsesCashVat($proposed,$sale['invoice_date'])) {
            throw new InvalidArgumentException('Uus periood jätaks kinnitatud kassapõhise dokumendi väljapoole erikorda. Säilita selle dokumendi varasem periood.');
        }
    }
    $dates=[];
    if($pdo->query("SHOW TABLES LIKE 'purchase_vat_periods'")->fetchColumn()) {
    $q=$pdo->prepare('SELECT i.invoice_date,p.tax_event_date FROM purchase_vat_periods p
        JOIN purchase_invoices i ON i.id=p.invoice_id AND i.company_id=p.company_id WHERE p.company_id=? AND p.allocation_json=?');
    $q->execute([$company,'"cash_company"']);
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $purchase) {
        if(!companyUsesCashVat($proposed,$purchase['tax_event_date']?:$purchase['invoice_date']) && !companyUsesCashVat($proposed,$purchase['invoice_date'])) {
            throw new InvalidArgumentException('Uus periood jätaks kinnitatud kassapõhise ostu väljapoole erikorda. Säilita varasem periood.');
        }
    }
    }
    foreach($dates as $date) if(!companyUsesCashVat($proposed,$date)) throw new InvalidArgumentException('Uus periood jätaks kinnitatud kassapõhise dokumendi väljapoole erikorda. Säilita selle dokumendi varasem periood.');
    return $history?json_encode($history,JSON_THROW_ON_ERROR):null;
}
