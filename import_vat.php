<?php
/** Customs documents are the sole source of import VAT. Supplier prices and bank payments are not tax bases. */
require_once __DIR__.'/period_lock.php';
require_once __DIR__.'/credit_note_lib.php';

function ensureImportVatSchema(PDO $pdo): void
{
    if ($pdo->inTransaction()) return;
    try {
        $pdo->query('SELECT id,entry_hash FROM import_vat_documents LIMIT 0');
        $pdo->query('SELECT id,entry_hash FROM import_vat_payments LIMIT 0');
        $pdo->query('SELECT id FROM import_vat_events LIMIT 0'); return;
    } catch(PDOException $missing) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS import_vat_documents (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, company_id INT UNSIGNED NOT NULL,
        mrn VARCHAR(50) NOT NULL, active_mrn VARCHAR(50) NULL, release_date DATE NOT NULL,
        invoice_id INT UNSIGNED NOT NULL, cost_invoice_id INT UNSIGNED NULL,
        taxable_cents BIGINT NOT NULL, vat_cents BIGINT NOT NULL, deductible_cents BIGINT NOT NULL,
        duty_cents BIGINT NOT NULL DEFAULT 0, other_cents BIGINT NOT NULL DEFAULT 0,
        payment_mode VARCHAR(12) NOT NULL, permission_reference VARCHAR(160) NOT NULL DEFAULT '',
        vat_purpose VARCHAR(20) NOT NULL, input_account_id INT UNSIGNED NOT NULL,
        liability_account_id INT UNSIGNED NOT NULL, cost_account_id INT UNSIGNED NULL,
        journal_entry_id INT UNSIGNED NOT NULL, entry_hash CHAR(64) NOT NULL,
        notes VARCHAR(500) NOT NULL, created_by INT UNSIGNED NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'posted', void_entry_id INT UNSIGNED NULL, void_reason VARCHAR(500) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY import_mrn(company_id,active_mrn), UNIQUE KEY import_entry(journal_entry_id),
        KEY import_period(company_id,release_date), KEY import_invoice(company_id,invoice_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS import_vat_payments (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, company_id INT UNSIGNED NOT NULL,
        document_id INT UNSIGNED NOT NULL, journal_entry_id INT UNSIGNED NOT NULL,
        bank_line_id INT UNSIGNED NULL, payment_date DATE NOT NULL, amount_cents BIGINT NOT NULL,
        entry_hash CHAR(64) NOT NULL, created_by INT UNSIGNED NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY import_payment_entry(journal_entry_id), UNIQUE KEY import_payment_bank(bank_line_id),
        KEY import_document_payments(company_id,document_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS import_vat_events (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,company_id INT UNSIGNED NOT NULL,
        document_id INT UNSIGNED NOT NULL,event_name VARCHAR(30) NOT NULL,details_json TEXT NOT NULL,
        created_by INT UNSIGNED NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY import_events(company_id,document_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function importEvent(PDO $pdo,int $company,int $document,int $user,string $name,array $details): void
{
    $pdo->prepare('INSERT INTO import_vat_events(company_id,document_id,event_name,details_json,created_by) VALUES(?,?,?,?,?)')->execute([$company,$document,$name,json_encode($details,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$user]);
}
function importDate(string $date): void
{
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if (!$d || $d->format('Y-m-d')!==$date) throw new InvalidArgumentException('Sisesta kehtiv kuupäev.');
}
function importAmount(string $text): int
{
    $cents=creditCents($text);
    // Same limit as DECIMAL(12,2) journal lines; no floating point calculations.
    if ($cents<0 || $cents>999999999999) throw new InvalidArgumentException('Summa peab olema 0 kuni 9 999 999 999,99 eurot.');
    return $cents;
}
function importAccount(PDO $pdo,int $company,int $id,string $type): array
{
    $s=$pdo->prepare('SELECT * FROM accounts WHERE company_id=? AND id=? AND is_active=1 FOR UPDATE');
    $s->execute([$company,$id]); $a=$s->fetch(PDO::FETCH_ASSOC);
    if (!$a || $a['account_type']!==$type) throw new InvalidArgumentException('Valitud konto ei ole selle ettevõtte aktiivne '.(['asset'=>'varakonto','expense'=>'kulukonto','liability'=>'kohustuse konto'][$type]??'konto').'.');
    return $a;
}
/** Snapshot includes every line, not just net account balances. */
function importEntry(PDO $pdo,int $company,int $entry,bool $lock=true): array
{
    $s=$pdo->prepare('SELECT id,entry_date,status FROM journal_entries WHERE company_id=? AND id=?'.($lock?' FOR UPDATE':''));
    $s->execute([$company,$entry]); $e=$s->fetch(PDO::FETCH_ASSOC);
    if (!$e || $e['status']!=='posted') throw new RuntimeException('Seotud pearaamatukanne puudub või ei ole kinnitatud.');
    $s=$pdo->prepare('SELECT j.account_id,j.debit,j.credit FROM journal_lines j JOIN accounts a ON a.id=j.account_id AND a.company_id=? WHERE j.entry_id=? ORDER BY j.id');
    $s->execute([$company,$entry]); $lines=$s->fetchAll(PDO::FETCH_ASSOC); $debits=0; $credits=0;
    $s=$pdo->prepare('SELECT COUNT(*) FROM journal_lines WHERE entry_id=?'); $s->execute([$entry]);
    if (count($lines)<2 || count($lines)!==(int)$s->fetchColumn()) throw new RuntimeException('Seotud kande kontod või read ei klapi.');
    foreach ($lines as &$l) { $l=['account'=>(int)$l['account_id'],'debit'=>creditCents($l['debit']),'credit'=>creditCents($l['credit'])]; if($l['debit']<0 || $l['credit']<0 || ($l['debit'] && $l['credit'])) throw new RuntimeException('Seotud kande summad on vigased.'); $debits+=$l['debit']; $credits+=$l['credit']; } unset($l);
    if (!$debits || $debits!==$credits) throw new RuntimeException('Seotud kanne ei ole tasakaalus.');
    $s=$pdo->prepare("SELECT COUNT(*) FROM journal_entries WHERE company_id=? AND status='posted' AND (corrects_entry_id=? OR document_number=?)");
    $s->execute([$company,$entry,'PAR-'.$entry]);
    if ((int)$s->fetchColumn()) throw new RuntimeException('Seotud kandest on tehtud paranduskandeid. Kontrolli importi enne jätkamist.');
    $e['lines']=$lines; $e['hash']=hash('sha256',json_encode([$e['entry_date'],$lines],JSON_THROW_ON_ERROR)); return $e;
}
function importPlan(array $d): array
{
    $lines=[];
    if ($d['deductible_cents']) $lines[]=['account'=>(int)$d['input_account_id'],'debit'=>(int)$d['deductible_cents'],'credit'=>0];
    if ($d['vat_cents']>$d['deductible_cents']) $lines[]=['account'=>(int)$d['cost_account_id'],'debit'=>$d['vat_cents']-$d['deductible_cents'],'credit'=>0];
    $lines[]=['account'=>(int)$d['liability_account_id'],'debit'=>0,'credit'=>(int)$d['vat_cents']]; return $lines;
}
function importSamePlan(array $a,array $b): bool
{
    $sum=static function(array $lines): array { $out=[]; foreach($lines as $l) { $id=(int)$l['account']; $out[$id]??=[0,0]; $out[$id][0]+=$l['debit']; $out[$id][1]+=$l['credit']; } ksort($out); return $out; };
    return $sum($a)===$sum($b);
}
function importPost(PDO $pdo,int $company,int $user,string $date,string $number,string $description,array $lines): int
{
    $s=$pdo->prepare("INSERT INTO journal_entries(company_id,entry_date,document_number,description,status,created_by,posted_at) VALUES(?,?,?,?,'posted',?,CURRENT_TIMESTAMP)");
    $s->execute([$company,$date,$number,$description,$user]); $id=(int)$pdo->lastInsertId();
    $s=$pdo->prepare('INSERT INTO journal_lines(entry_id,account_id,description,debit,credit) VALUES(?,?,?,?,?)');
    foreach($lines as $line) $s->execute([$id,$line['account'],$description,creditDecimal($line['debit']),creditDecimal($line['credit'])]); return $id;
}
function importSupplierInvoice(PDO $pdo,int $company,int $id,bool $lock=true): array
{
    $s=$pdo->prepare("SELECT * FROM purchase_invoices WHERE company_id=? AND id=? AND status='posted'".($lock?' FOR UPDATE':''));
    $s->execute([$company,$id]); $invoice=$s->fetch(PDO::FETCH_ASSOC);
    if (!$invoice || !$invoice['journal_entry_id']) throw new InvalidArgumentException('Vali kinnitatud kauba ostuarve.');
    $s=$pdo->prepare('SELECT l.*,t.code,t.rate,t.tax_category FROM purchase_invoice_lines l LEFT JOIN tax_codes t ON t.id=l.tax_code_id AND t.company_id=? WHERE l.invoice_id=? ORDER BY l.id');
    $s->execute([$company,$id]); $lines=$s->fetchAll(PDO::FETCH_ASSOC);
    if (!$lines) throw new InvalidArgumentException('Kauba ostuarvel puuduvad read.');
    foreach($lines as $l) if($l['code']!=='O_IMPORT0' || (float)$l['rate']!==0.0 || (float)$l['vat_rate']!==0.0 || creditCents($l['vat_amount'])!==0 || creditCents($l['net_amount'])<=0 || creditCents($l['net_amount'])!==creditCents($l['gross_amount'])) throw new InvalidArgumentException('Kauba ostuarve kõigil ridadel peab olema kood „Kauba import — tarnija KM 0; KM tollidokumendilt”. Ära sisesta tolli käibemaksu tarnija arvele.');
    importEntry($pdo,$company,(int)$invoice['journal_entry_id'],$lock);
    $invoice['lines']=$lines; return $invoice;
}
function createImportVat(PDO $pdo,int $company,int $user,array $form): int
{
    ensureImportVatSchema($pdo);
    $mrn=strtoupper(preg_replace('/\s+/u','',trim((string)($form['mrn']??''))));
    if(!preg_match('/^[A-Z0-9][A-Z0-9\/-]{2,49}$/',$mrn)) throw new InvalidArgumentException('Sisesta tollideklaratsiooni MRN või tolliotsuse number (3–50 märki).');
    $date=(string)($form['release_date']??''); importDate($date);
    $d=['mrn'=>$mrn,'release_date'=>$date,'invoice_id'=>(int)($form['invoice_id']??0),'cost_invoice_id'=>(int)($form['cost_invoice_id']??0)?:null];
    foreach(['taxable','vat','deductible','duty','other'] as $field) $d[$field.'_cents']=importAmount((string)($form[$field]??'0'));
    if(!$d['taxable_cents'] || !$d['vat_cents'] || $d['deductible_cents']>$d['vat_cents']) throw new InvalidArgumentException('Maksustatav väärtus ja impordi KM peavad olema positiivsed; mahaarvamine ei tohi ületada impordi KM-i.');
    $d['payment_mode']=(string)($form['payment_mode']??'customs');
    $d['permission_reference']=trim((string)($form['permission_reference']??''));
    if(!in_array($d['payment_mode'],['customs','kmd'],true) || creditTextLength($d['permission_reference'])>160 || ($d['payment_mode']==='kmd' && $d['permission_reference']==='')) throw new InvalidArgumentException('Vali tasumisviis. KMD-l tasumine vajab kehtiva impordiloa viidet.');
    $d['vat_purpose']=(string)($form['vat_purpose']??'ordinary');
    if(!in_array($d['vat_purpose'],['ordinary','fixed_asset'],true)) throw new InvalidArgumentException('Vali kauba või põhivara import. Sõiduauto erijaotus ei ole selles vormis veel toetatud.');
    foreach(['input','liability','cost'] as $field) $d[$field.'_account_id']=(int)($form[$field.'_account_id']??0)?:null;
    $d['notes']=trim((string)($form['notes']??''));
    if(creditTextLength($d['notes'])>500 || empty($form['confirmed_basis'])) throw new InvalidArgumentException('Kinnita tollidokumendi alus ja summa eristamine teistest arvetest; selgitus võib olla kuni 500 märki.');
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); $pdo->beginTransaction();
    try {
        // Serializes repeated confirmations, including different MRNs linked to one purchase.
        $invoice=importSupplierInvoice($pdo,$company,$d['invoice_id']);
        if($issue=closedAccountingPeriodMessage($pdo,$company,$date)) throw new RuntimeException($issue);
        $s=$pdo->prepare("SELECT id FROM import_vat_documents WHERE company_id=? AND active_mrn=? FOR UPDATE"); $s->execute([$company,$mrn]);
        if($s->fetchColumn()) throw new InvalidArgumentException('See tollidokument on juba kinnitatud. Ava olemasolev dokument.');
        $inputAccount=importAccount($pdo,$company,(int)$d['input_account_id'],'asset'); importAccount($pdo,$company,(int)$d['liability_account_id'],'liability');
        if(preg_match('/^10[0-9]{2}$/',$inputAccount['code'])) throw new InvalidArgumentException('Sisendkäibemaksu kontoks ei saa valida panga- või kassakontot.');
        foreach($invoice['lines'] as $l) {
            if((int)$l['account_id']===(int)$d['input_account_id']) throw new InvalidArgumentException('Sisendkäibemaksu konto peab erinema kauba kulu-/varakontost.');
            $a=$pdo->prepare('SELECT code FROM accounts WHERE id=? AND company_id=?'); $a->execute([$l['account_id'],$company]);
            if(preg_match('/^15/',(string)$a->fetchColumn()) && $d['vat_purpose']!=='fixed_asset') throw new InvalidArgumentException('Kaubaarve kasutab põhivara kontot. Vali kasutuseks põhivara, et KM kajastuks ka lahtris 5.2.');
        }
        if($d['vat_purpose']==='fixed_asset') foreach($invoice['lines'] as $l) {
            $a=importAccount($pdo,$company,(int)$l['account_id'],'asset');
            if(!preg_match('/^15/',$a['code']) || !empty($l['product_id'])) throw new InvalidArgumentException('Põhivara impordi korral peavad kaubaarve read olema põhivara kontol, laotooteta. Sõiduautode erijaotus vajab eraldi käsitlust.');
        }
        $s=$pdo->prepare("SELECT account_id FROM journal_lines WHERE entry_id=? AND description='Tarnijavõlg'"); $s->execute([$invoice['journal_entry_id']]);
        if(in_array((int)$d['liability_account_id'],array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN)),true)) throw new InvalidArgumentException('Impordi KM kohustuseks vali eraldi konto, mitte kauba tarnijavõla konto.');
        if($d['vat_cents']>$d['deductible_cents']) {
            importAccount($pdo,$company,(int)$d['cost_account_id'],'expense');
            if($d['vat_purpose']==='fixed_asset') throw new InvalidArgumentException('Põhivara osaline impordi KM vajab põhivarakaardi soetusmaksumuse jaotust; seda vorm veel ei tee.');
            foreach($invoice['lines'] as $l) {
                $a=importAccount($pdo,$company,(int)$l['account_id'], !empty($l['product_id'])?'asset':'expense');
                if(!empty($l['product_id'])) throw new InvalidArgumentException('Laokauba mahaarvamata impordi KM vajab lao soetusmaksumuse jaotust. Praegu kinnita siin ainult täielikult mahaarvatav laokauba KM.');
            }
        }
        if($d['cost_invoice_id']) {
            if($d['cost_invoice_id']===$d['invoice_id']) throw new InvalidArgumentException('Tolli-/teenustasude arve peab olema kaubaarvest eraldi.');
            $s=$pdo->prepare("SELECT journal_entry_id FROM purchase_invoices WHERE company_id=? AND id=? AND status='posted' FOR UPDATE"); $s->execute([$company,$d['cost_invoice_id']]);
            $costEntry=(int)$s->fetchColumn(); if(!$costEntry) throw new InvalidArgumentException('Vali kinnitatud tolli-/teenustasude ostuarve.'); importEntry($pdo,$company,$costEntry);
        }
        if(($d['duty_cents'] || $d['other_cents']) && !$d['cost_invoice_id']) throw new InvalidArgumentException('Tolli- ja muud tasud kajasta eraldi ostuarvel ning vali siin selle arve viide. Neid summasid see vorm uuesti ei kirjenda.');
        $plan=importPlan($d); $entry=(int)($form['existing_entry_id']??0);
        if($entry) {
            $e=importEntry($pdo,$company,$entry);
            if($e['entry_date']!==$date || !importSamePlan($plan,$e['lines'])) throw new InvalidArgumentException('Olemasoleva impordi KM kande kuupäev, kontod või summad ei klapi vormiga.');
            // An invoice or payment entry cannot be adopted as a customs entry.
            importAssertUnassignedEntry($pdo,$company,$entry);
        } else {
            $s=$pdo->prepare("SELECT id FROM journal_entries WHERE company_id=? AND status IN ('draft','posted') AND (document_number=? OR document_number=? OR LOCATE(?,description)>0)");
            $s->execute([$company,$mrn,'IMP-'.$mrn,$mrn]);
            foreach($s->fetchAll(PDO::FETCH_COLUMN) as $existing) {
                $bank=$pdo->prepare('SELECT l.account_id,l.debit,l.credit,a.code,a.account_type FROM journal_lines l JOIN accounts a ON a.id=l.account_id AND a.company_id=? WHERE l.entry_id=?'); $bank->execute([$company,$existing]); $settlement=$bank->fetchAll(PDO::FETCH_ASSOC);
                $out=0; $control=0;
                foreach($settlement as $l) {
                    if((int)$l['account_id']===(int)$d['liability_account_id'] && creditCents($l['credit'])===0) $control+=creditCents($l['debit']);
                    if($l['account_type']==='asset' && preg_match('/^10[0-9]{2}$/',$l['code']) && creditCents($l['debit'])===0) $out+=creditCents($l['credit']);
                }
                if(count($settlement)===2 && $control>0 && $control===$out) continue; // A settlement is not the customs VAT recognition entry.
                $s=$pdo->prepare("SELECT COUNT(*) FROM import_vat_documents WHERE company_id=? AND status='reversed' AND (journal_entry_id=? OR void_entry_id=?)"); $s->execute([$company,$existing,$existing]);
                if(!(int)$s->fetchColumn()) throw new InvalidArgumentException('Selle tollinumbriga kanne on juba olemas. Seo olemasolev kinnitatud kanne või lahenda mustand enne jätkamist.');
            }
            $entry=importPost($pdo,$company,$user,$date,'IMP-'.$mrn,'Impordi käibemaks · '.$mrn,$plan); $e=importEntry($pdo,$company,$entry);
        }
        $d['journal_entry_id']=$entry; $d['entry_hash']=$e['hash']; $d['company_id']=$company; $d['active_mrn']=$mrn; $d['created_by']=$user;
        $fields=array_keys($d); $s=$pdo->prepare('INSERT INTO import_vat_documents('.implode(',',$fields).') VALUES('.implode(',',array_fill(0,count($fields),'?')).')'); $s->execute(array_values($d));
        $id=(int)$pdo->lastInsertId(); importEvent($pdo,$company,$id,$user,'confirmed',['journal_entry_id'=>$entry,'linked_existing'=>!empty($form['existing_entry_id'])]); $pdo->commit(); return $id;
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function importAssertUnassignedEntry(PDO $pdo,int $company,int $entry): void
{
    foreach(['purchase_invoices','sales_invoices','purchase_invoice_payments','invoice_payments','credit_note_refunds','import_vat_documents','import_vat_payments'] as $table) {
        $s=$pdo->prepare('SELECT COUNT(*) FROM '.$table.' WHERE journal_entry_id=?'); $s->execute([$entry]);
        if((int)$s->fetchColumn()) throw new InvalidArgumentException('See kanne on juba arve, makse või tollidokumendiga seotud.');
    }
}
function loadImportVat(PDO $pdo,int $company,string $start,string $end): array
{
    ensureImportVatSchema($pdo);
    $own=!$pdo->inTransaction();
    if($own) { $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY'); $pdo->beginTransaction(); }
    try {
    $s=$pdo->prepare("SELECT * FROM import_vat_documents WHERE company_id=? AND release_date BETWEEN ? AND ? AND status='posted' ORDER BY release_date,id"); $s->execute([$company,$start,$end]);
    $result=['documents'=>$s->fetchAll(PDO::FETCH_ASSOC),'deductible'=>0,'payable'=>0,'fixed_assets'=>0,'errors'=>[]];
    foreach($result['documents'] as $d) {
        try {
            $e=importEntry($pdo,$company,(int)$d['journal_entry_id'],false);
            if(!hash_equals($d['entry_hash'],$e['hash'])) throw new RuntimeException('Impordi pearaamatukande sisu on muutunud.');
            importSupplierInvoice($pdo,$company,(int)$d['invoice_id'],false);
            importPaymentTotal($pdo,$company,(int)$d['id'],false);
            $result['deductible']+=(int)$d['deductible_cents'];
            if($d['payment_mode']==='kmd') $result['payable']+=(int)$d['vat_cents'];
            if($d['vat_purpose']==='fixed_asset') $result['fixed_assets']+=(int)$d['deductible_cents'];
        } catch(Throwable $e) { $result['errors'][]='Tollidokument '.$d['mrn'].': '.$e->getMessage(); }
    }
    if($own) $pdo->commit();
    return $result;
    } catch(Throwable $e) { if($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function importPaymentTotal(PDO $pdo,int $company,int $id,bool $lock=true): int
{
    $s=$pdo->prepare('SELECT * FROM import_vat_payments WHERE company_id=? AND document_id=? ORDER BY id'); $s->execute([$company,$id]); $paid=0;
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $e=importEntry($pdo,$company,(int)$p['journal_entry_id'],$lock);
        if(!hash_equals($p['entry_hash'],$e['hash'])) throw new RuntimeException('Impordi KM maksekanne on muutunud. Paranda makse seos.');
        $paid+=(int)$p['amount_cents'];
    }
    return $paid;
}
function importDocument(PDO $pdo,int $company,int $id): array
{
    $s=$pdo->prepare("SELECT * FROM import_vat_documents WHERE company_id=? AND id=? AND status='posted' FOR UPDATE"); $s->execute([$company,$id]); $d=$s->fetch(PDO::FETCH_ASSOC);
    if(!$d) throw new InvalidArgumentException('Kinnitatud tollidokumenti ei leitud.');
    $e=importEntry($pdo,$company,(int)$d['journal_entry_id']);
    if(!hash_equals($d['entry_hash'],$e['hash'])) throw new RuntimeException('Impordi pearaamatukanne on muutunud.'); return $d;
}
/** Links a verified existing payment or posts only Dr customs liability / Cr bank. No VAT is added. */
function payImportVat(PDO $pdo,int $company,int $user,int $id,int $bankLine,int $existingEntry): int
{
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); $pdo->beginTransaction();
    try {
        $d=importDocument($pdo,$company,$id);
        if($d['payment_mode']!=='customs') throw new InvalidArgumentException('KMD-l tasutava impordi kohustust tasutakse koos deklaratsiooniga; siin seda teist korda ei maksta.');
        $remaining=$d['vat_cents']-importPaymentTotal($pdo,$company,$id);
        if($remaining<=0) throw new InvalidArgumentException('Impordi käibemaks on juba tasutud.');
        $row=null; $create=false;
        if($bankLine) {
            $s=$pdo->prepare('SELECT * FROM bank_statement_lines WHERE company_id=? AND id=? FOR UPDATE'); $s->execute([$company,$bankLine]); $row=$s->fetch(PDO::FETCH_ASSOC);
            if(!$row || strtoupper($row['debit_credit'])!=='D' || creditCents($row['service_fee'])!==0) throw new InvalidArgumentException('Vali selle ettevõtte väljaminev pangarida ilma eraldi teenustasuta.');
            if(strtoupper(trim((string)$row['currency']))!=='EUR') throw new InvalidArgumentException('Impordi KM makse sidumine toetab siin ainult eurodes pangarida; valuutamakse vajab eraldi kursi ja kursivahe arvestust.');
            if($row['draft_journal_entry_id']) throw new InvalidArgumentException('Pangareal on mustand. Kinnita või kustuta see enne makse sidumist.');
            if($row['matched_journal_line_id']) {
                $s=$pdo->prepare('SELECT entry_id FROM journal_lines WHERE id=?'); $s->execute([$row['matched_journal_line_id']]); $matched=(int)$s->fetchColumn();
                if($existingEntry && $matched!==$existingEntry) throw new InvalidArgumentException('Valitud kanne erineb pangareaga seotud kandest.'); $existingEntry=$matched;
                if(!$existingEntry) throw new RuntimeException('Pangarea kandeviide on katki.');
            } elseif(!$existingEntry) $create=true;
        }
        if(!$row && !$existingEntry) throw new InvalidArgumentException('Vali pangarida või olemasoleva kinnitatud maksekande number.');
        if($create) {
            $amount=importAmount($row['amount']); $date=$row['transaction_date']; $bank=(int)$row['bank_account_id'];
            $a=importAccount($pdo,$company,$bank,'asset');
            if(!preg_match('/^10[0-9]{2}$/',$a['code'])) throw new InvalidArgumentException('Pangarea konto peab olema panga- või kassakonto.');
            if($amount<1 || $amount>$remaining) throw new InvalidArgumentException('Pangarea summa ületab impordi KM jääki. Tolli-/teenustasude või teiste dokumentide koondmakse kirjenda ja jaota eraldi.');
            if($date<$d['release_date']) throw new InvalidArgumentException('Enne kauba vabastamist tehtud ettemakse vajab eraldi ettemaksu käsitlust.');
            if($issue=closedAccountingPeriodMessage($pdo,$company,$date)) throw new RuntimeException($issue);
            importAccount($pdo,$company,(int)$d['liability_account_id'],'liability');
            $existingEntry=importPost($pdo,$company,$user,$date,'IMP-MAKSE-'.$bankLine,'Impordi KM tasumine · '.$d['mrn'],[['account'=>(int)$d['liability_account_id'],'debit'=>$amount,'credit'=>0],['account'=>$bank,'debit'=>0,'credit'=>$amount]]);
        }
        $e=importEntry($pdo,$company,$existingEntry); importAssertUnassignedEntry($pdo,$company,$existingEntry);
        if(count($e['lines'])!==2) throw new InvalidArgumentException('Maksekanne peab sisaldama ainult impordi KM kohustuse deebetit ja pangakonto kreeditit. Koondmakse vajab eraldi jaotust.');
        $debit=null; $credit=null; foreach($e['lines'] as $l) { if($l['debit']) $debit=$l; if($l['credit']) $credit=$l; }
        if(!$debit || !$credit || $debit['account']!==(int)$d['liability_account_id']) throw new InvalidArgumentException('Maksekanne peab vähendama selle tollidokumendi impordi KM kohustuse kontot.');
        $bank=$credit['account']; $a=importAccount($pdo,$company,$bank,'asset'); $amount=$credit['credit']; $date=$e['entry_date'];
        if(!preg_match('/^10[0-9]{2}$/',$a['code']) || $amount>$remaining || $date<$d['release_date']) throw new InvalidArgumentException('Makse pangakonto, kuupäev või jääk ei klapi tollidokumendiga.');
        if($issue=closedAccountingPeriodMessage($pdo,$company,$date)) throw new RuntimeException($issue);
        if($row) {
            if($amount!==creditCents($row['amount']) || $bank!==(int)$row['bank_account_id'] || $date!==$row['transaction_date']) throw new InvalidArgumentException('Pangarida ei klapi valitud maksekandega.');
            $s=$pdo->prepare('SELECT id FROM journal_lines WHERE entry_id=? AND account_id=? AND credit>0'); $s->execute([$existingEntry,$bank]); $bankJournalLine=(int)$s->fetchColumn();
            $s=$pdo->prepare('SELECT id FROM bank_statement_lines WHERE company_id=? AND matched_journal_line_id=? AND id<>?'); $s->execute([$company,$bankJournalLine,$bankLine]);
            if($s->fetchColumn()) throw new InvalidArgumentException('Maksekanne on juba teise pangareaga seotud.');
            $pdo->prepare('UPDATE bank_statement_lines SET matched_journal_line_id=? WHERE company_id=? AND id=?')->execute([$bankJournalLine,$company,$bankLine]);
        }
        $s=$pdo->prepare('INSERT INTO import_vat_payments(company_id,document_id,journal_entry_id,bank_line_id,payment_date,amount_cents,entry_hash,created_by) VALUES(?,?,?,?,?,?,?,?)'); $s->execute([$company,$id,$existingEntry,$bankLine?:null,$date,$amount,$e['hash'],$user]);
        $payment=(int)$pdo->lastInsertId(); importEvent($pdo,$company,$id,$user,'payment_linked',['payment_id'=>$payment,'journal_entry_id'=>$existingEntry,'amount_cents'=>$amount,'created_entry'=>$create]); $pdo->commit(); return $payment;
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function unlinkImportPayment(PDO $pdo,int $company,int $user,int $document,int $payment): void
{
    $pdo->beginTransaction();
    try {
        importDocument($pdo,$company,$document);
        $s=$pdo->prepare('SELECT payment_date FROM import_vat_payments WHERE company_id=? AND document_id=? AND id=? FOR UPDATE'); $s->execute([$company,$document,$payment]); $date=$s->fetchColumn();
        if(!$date) throw new InvalidArgumentException('Makselinki ei leitud.');
        if($issue=closedAccountingPeriodMessage($pdo,$company,$date)) throw new RuntimeException($issue);
        importEvent($pdo,$company,$document,$user,'payment_unlinked',['payment_id'=>$payment,'message'=>'Pangakanne ja pangarea seos jäid alles.']);
        $pdo->prepare('DELETE FROM import_vat_payments WHERE company_id=? AND document_id=? AND id=?')->execute([$company,$document,$payment]); $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function voidImportVat(PDO $pdo,int $company,int $user,int $id,string $reason): void
{
    if(trim($reason)==='' || creditTextLength($reason)>500) throw new InvalidArgumentException('Sisesta tühistamise põhjus (kuni 500 märki).');
    $pdo->beginTransaction();
    try {
        $d=importDocument($pdo,$company,$id);
        if($issue=closedAccountingPeriodMessage($pdo,$company,$d['release_date'])) throw new RuntimeException($issue);
        $s=$pdo->prepare('SELECT COUNT(*) FROM import_vat_payments WHERE company_id=? AND document_id=?'); $s->execute([$company,$id]);
        if($s->fetchColumn()) throw new InvalidArgumentException('Eemalda esmalt maksete seosed. Tegelik pangakanne jääb alles ja tuleb siduda õige dokumendiga.');
        $lines=importPlan($d); foreach($lines as &$l) [$l['debit'],$l['credit']]=[$l['credit'],$l['debit']]; unset($l);
        $entry=importPost($pdo,$company,$user,$d['release_date'],'IMP-TYH-'.$id,'Impordi KM tühistamine · '.$d['mrn'].' · '.$reason,$lines);
        $pdo->prepare("UPDATE import_vat_documents SET status='reversed',active_mrn=NULL,void_entry_id=?,void_reason=? WHERE company_id=? AND id=?")->execute([$entry,$reason,$company,$id]); importEvent($pdo,$company,$id,$user,'reversed',['journal_entry_id'=>$entry,'reason'=>$reason]); $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
