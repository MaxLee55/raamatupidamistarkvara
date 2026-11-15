<?php
function creditRefundTotals(PDO $pdo,int $companyId,string $kind): array
{
    $stmt=$pdo->prepare("SELECT original_invoice_id,SUM(amount_cents) FROM credit_note_refunds WHERE company_id=? AND invoice_kind=? AND status='posted' GROUP BY original_invoice_id");
    $stmt->execute([$companyId,$kind]);
    return array_map('intval',$stmt->fetchAll(PDO::FETCH_KEY_PAIR));
}
function creditRefundAvailable(PDO $pdo,int $companyId,string $kind,int $creditId,string $date): array
{
    [$table,$lines]=creditTables($kind);
    $link=loadCreditLinks($pdo,$companyId,$kind)[$creditId]??null;
    if(!$link || $link['credit_status']!=='posted' || $link['original_status']!=='posted') throw new RuntimeException('Kinnitatud kreeditarvet ja algset arvet ei leitud.');
    $paymentTable=$kind==='sales'?'invoice_payments':'purchase_invoice_payments';
    $stmt=$pdo->prepare('SELECT COALESCE(SUM(p.amount),0) AS total,MAX(p.payment_date) AS last_date FROM '.$paymentTable.' p
        JOIN journal_entries j ON j.id=p.journal_entry_id AND j.company_id=? AND j.status=\'posted\'
        WHERE p.invoice_id=?');
    $stmt->execute([$companyId,$link['original_invoice_id']]); $payments=$stmt->fetch(PDO::FETCH_ASSOC);
    if($payments['last_date'] && $date<$payments['last_date']) throw new RuntimeException('Tagasimakse kuupäev ei tohi olla algse arve viimase makse kuupäevast varasem.');
    $stmt=$pdo->prepare('SELECT invoice_date,journal_entry_id FROM '.$table.' WHERE company_id=? AND id=?');
    $stmt->execute([$companyId,$creditId]); $credit=$stmt->fetch(PDO::FETCH_ASSOC);
    if($date<$credit['invoice_date']) throw new RuntimeException('Tagasimakse ei tohi olla kreeditarvest varasem.');
    $stmt=$pdo->prepare('SELECT COALESCE(SUM(gross_amount),0) FROM '.$lines.' WHERE invoice_id=?');
    $stmt->execute([$link['original_invoice_id']]); $originalTotal=creditCents((string)$stmt->fetchColumn());
    $stmt->execute([$creditId]); $creditTotal=-creditCents((string)$stmt->fetchColumn());
    $stmt=$pdo->prepare("SELECT credit_invoice_id,amount_cents,refund_date FROM credit_note_refunds WHERE company_id=? AND invoice_kind=? AND original_invoice_id=? AND status='posted'");
    $stmt->execute([$companyId,$kind,$link['original_invoice_id']]); $returned=0; $creditReturned=0;
    foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if($date<$row['refund_date']) throw new RuntimeException('Sisesta tagasimaksed kuupäevade järjekorras.');
        $returned+=(int)$row['amount_cents']; if((int)$row['credit_invoice_id']===$creditId) $creditReturned+=(int)$row['amount_cents'];
    }
    $stmt=$pdo->prepare("SELECT COALESCE(-SUM(t.gross_amount),0) FROM credit_note_links n JOIN $table c ON c.id=n.credit_invoice_id AND c.company_id=n.company_id JOIN $lines t ON t.invoice_id=c.id WHERE n.company_id=? AND n.invoice_kind=? AND n.original_invoice_id=? AND c.status='posted' AND c.invoice_date<=?");
    $stmt->execute([$companyId,$kind,$link['original_invoice_id'],$date]); $credited=creditCents((string)$stmt->fetchColumn());
    $available=max(0,min($creditTotal-$creditReturned,creditCents($payments['total'])-($originalTotal-$credited)-$returned));
    return ['available'=>$available,'link'=>$link];
}
function createCreditRefund(PDO $pdo,int $companyId,int $userId,string $kind,int $creditId,string $date,int $amount,int $bankId,string $notes): int
{
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$parsed || $parsed->format('Y-m-d')!==$date || $amount<=0 || creditTextLength($notes)>500) throw new RuntimeException('Sisesta korrektne kuupäev, summa ja kuni 500 märgi pikkune selgitus.');
    if($issue=closedAccountingPeriodMessage($pdo,$companyId,$date)) throw new RuntimeException($issue);
    [$table]=creditTables($kind);
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $pdo->beginTransaction();
    try {
        $link=loadCreditLinks($pdo,$companyId,$kind)[$creditId]??null;
        if(!$link) throw new RuntimeException('Kreeditarvet ei leitud.');
        $lock=$pdo->prepare('SELECT journal_entry_id FROM '.$table.' WHERE company_id=? AND id=? FOR UPDATE');
        $lock->execute([$companyId,$link['original_invoice_id']]); $originalEntry=(int)$lock->fetchColumn();
        $lock->execute([$companyId,$creditId]); $lock->fetchColumn();
        $available=creditRefundAvailable($pdo,$companyId,$kind,$creditId,$date);
        if($amount>$available['available']) throw new RuntimeException('Tagasimakse ületab tegelikult tagastatavat jääki ('.creditDecimal($available['available']).' €).');
        $controlDescription=$kind==='sales'?'Ostja võlg':'Tarnijavõlg';
        $stmt=$pdo->prepare('SELECT j.account_id,a.account_type FROM journal_lines j JOIN accounts a ON a.id=j.account_id AND a.company_id=? AND a.is_active=1 WHERE j.entry_id=? AND j.description=?');
        $stmt->execute([$companyId,$originalEntry,$controlDescription]); $controls=$stmt->fetchAll(PDO::FETCH_ASSOC);
        if(count($controls)!==1 || $controls[0]['account_type']!==($kind==='sales'?'asset':'liability')) throw new RuntimeException('Algse arve nõuete või tarnijavõla kontot ei saa üheselt tuvastada.');
        $control=(int)$controls[0]['account_id'];
        $stmt=$pdo->prepare("SELECT id FROM accounts WHERE id=? AND company_id=? AND account_type='asset' AND is_active=1 AND code REGEXP '^10[0-9]{2}$' FOR UPDATE");
        $stmt->execute([$bankId,$companyId]);
        if(!$stmt->fetchColumn() || $control===$bankId) throw new RuntimeException('Vali aktiivne panga- või kassakonto.');
        assertCashAccountsNotFurtherNegative($pdo,$companyId,[$bankId=>$kind==='sales'?-$amount:$amount]);
        $description=creditDescription('Kreeditarve tagasimakse '.$creditId.'. '.$notes);
        $stmt=$pdo->prepare("INSERT INTO journal_entries (company_id,entry_date,description,document_number,status,created_by,posted_at) VALUES (?,?,?,?,'posted',?,CURRENT_TIMESTAMP)");
        $stmt->execute([$companyId,$date,$description,'KR-TAG-'.$creditId,$userId]); $entry=(int)$pdo->lastInsertId();
        $stmt=$pdo->prepare('INSERT INTO journal_lines (entry_id,account_id,description,debit,credit) VALUES (?,?,?,?,?)');
        $stmt->execute([$entry,$bankId,$description,$kind==='purchase'?creditDecimal($amount):'0.00',$kind==='sales'?creditDecimal($amount):'0.00']);
        $stmt->execute([$entry,$control,$description,$kind==='sales'?creditDecimal($amount):'0.00',$kind==='purchase'?creditDecimal($amount):'0.00']);
        $stmt=$pdo->prepare("INSERT INTO credit_note_refunds (company_id,invoice_kind,credit_invoice_id,original_invoice_id,refund_date,amount_cents,bank_account_id,journal_entry_id,notes,created_by,status) VALUES (?,?,?,?,?,?,?,?,?,?,'posted')");
        $stmt->execute([$companyId,$kind,$creditId,$link['original_invoice_id'],$date,$amount,$bankId,$entry,$notes,$userId]);
        $id=(int)$pdo->lastInsertId(); $pdo->commit(); return $id;
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function voidCreditRefund(PDO $pdo,int $companyId,int $userId,int $refundId,string $reason): void
{
    if(trim($reason)==='' || creditTextLength($reason)>500) throw new RuntimeException('Sisesta tagasimakse tühistamise põhjus.');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('SELECT * FROM credit_note_refunds WHERE company_id=? AND id=?');
        $stmt->execute([$companyId,$refundId]); $refund=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$refund || $refund['status']!=='posted') throw new RuntimeException('Kinnitatud tagasimakset ei leitud.');
        [$table]=creditTables($refund['invoice_kind']);
        $lock=$pdo->prepare('SELECT id FROM '.$table.' WHERE company_id=? AND id=? FOR UPDATE'); $lock->execute([$companyId,$refund['original_invoice_id']]);
        $lock=$pdo->prepare('SELECT status FROM credit_note_refunds WHERE company_id=? AND id=? FOR UPDATE'); $lock->execute([$companyId,$refundId]);
        if($lock->fetchColumn()!=='posted') throw new RuntimeException('Tagasimakse on juba tühistatud.');
        if($issue=closedAccountingPeriodMessage($pdo,$companyId,$refund['refund_date'])) throw new RuntimeException($issue);
        $stmt=$pdo->prepare('SELECT account_id,debit,credit FROM journal_lines WHERE entry_id=?'); $stmt->execute([$refund['journal_entry_id']]); $lines=$stmt->fetchAll(PDO::FETCH_ASSOC);
        if(count($lines)!==2) throw new RuntimeException('Tagasimakse kanne ei klapi.');
        $deltas=[]; foreach($lines as $line) $deltas[(int)$line['account_id']]=creditCents($line['credit'])-creditCents($line['debit']);
        assertCashAccountsNotFurtherNegative($pdo,$companyId,$deltas);
        $notes=creditDescription('Kreeditarve tagasimakse tühistamine. '.$reason);
        $stmt=$pdo->prepare("INSERT INTO journal_entries (company_id,entry_date,description,document_number,status,created_by,posted_at) VALUES (?,?,?,?,'posted',?,CURRENT_TIMESTAMP)");
        $stmt->execute([$companyId,$refund['refund_date'],$notes,'KR-TAG-TYH-'.$refundId,$userId]); $entry=(int)$pdo->lastInsertId();
        $stmt=$pdo->prepare('INSERT INTO journal_lines (entry_id,account_id,description,debit,credit) VALUES (?,?,?,?,?)');
        foreach($lines as $line) $stmt->execute([$entry,$line['account_id'],$notes,$line['credit'],$line['debit']]);
        $stmt=$pdo->prepare("UPDATE credit_note_refunds SET status='reversed',void_entry_id=?,void_reason=? WHERE company_id=? AND id=?");
        $stmt->execute([$entry,$reason,$companyId,$refundId]); $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
