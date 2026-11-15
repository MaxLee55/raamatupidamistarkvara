<?php
function ensureJournalCorrectionSchema(PDO $pdo): void
{
    if($pdo->inTransaction()) return;
    $columns=$pdo->query('SHOW COLUMNS FROM journal_entries')->fetchAll(PDO::FETCH_COLUMN);
    if(!in_array('corrects_entry_id',$columns,true)) $pdo->exec('ALTER TABLE journal_entries ADD COLUMN corrects_entry_id INT UNSIGNED NULL, ADD KEY ix_journal_corrects_entry (company_id,corrects_entry_id)');
}
function journalCorrectionJoin(): string
{
    return " LEFT JOIN journal_entries corrected ON corrected.company_id=je.company_id AND corrected.id=COALESCE(je.corrects_entry_id,CASE WHEN je.document_number REGEXP '^PAR-[0-9]+$' THEN CAST(SUBSTRING(je.document_number,5) AS UNSIGNED) ELSE NULL END) ";
}
function journalCorrectionSource(PDO $pdo,int $companyId,array $entry): ?array
{
    $id=(int)($entry['corrects_entry_id']??0);
    if(!$id && preg_match('/^PAR-([0-9]+)$/',(string)($entry['document_number']??''),$m)) $id=(int)$m[1];
    if(!$id || $id===(int)$entry['id']) return null;
    $stmt=$pdo->prepare('SELECT id,document_number,description,entry_date FROM journal_entries WHERE company_id=? AND id=?');
    $stmt->execute([$companyId,$id]); return $stmt->fetch(PDO::FETCH_ASSOC)?:null;
}
function journalBelongsToSecurityFx(PDO $pdo, int $companyId, array $entry): bool
{
    if (!preg_match('/^VP-[0-9a-f]{16}(?:-A)?(?:-T)?$/D', (string)($entry['document_number'] ?? ''))) return false;
    if (!$pdo->query("SHOW TABLES LIKE 'security\\_fx\\_trades'")->fetchColumn()) return false;
    $query = $pdo->prepare('SELECT id FROM security_fx_trades WHERE company_id=?
        AND ? IN (trade_entry_id,settlement_entry_id,void_trade_entry_id,void_settlement_entry_id) LIMIT 1');
    $query->execute([$companyId,$entry['id']]);
    return (bool)$query->fetchColumn();
}

function createJournalReversalDraft(PDO $pdo,int $companyId,int $userId,int $originalId,string $date): int
{
    require_once __DIR__.'/import_vat.php';
    ensureImportVatSchema($pdo);
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$parsed || $parsed->format('Y-m-d')!==$date) throw new InvalidArgumentException('Vigane paranduskande kuupäev.');
    if($message=closedAccountingPeriodMessage($pdo,$companyId,$date)) throw new RuntimeException($message);
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare("SELECT * FROM journal_entries WHERE company_id=? AND id=? AND status='posted' FOR UPDATE");
        $stmt->execute([$companyId,$originalId]); $original=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$original) throw new RuntimeException('Kinnitatud kannet ei leitud.');
        if (journalBelongsToSecurityFx($pdo,$companyId,$original)) {
            throw new RuntimeException('Tühista tehing Väärtpaberitehingute lehel, et pearaamat ja FIFO jääk püsiksid kooskõlas.');
        }
        foreach (['sales'=>'invoice_payments','purchase'=>'purchase_invoice_payments'] as $kind=>$payments) {
            $invoices = $kind.'_invoices';
            $fx = $pdo->prepare("SELECT i.id FROM $invoices i LEFT JOIN $payments p ON p.invoice_id=i.id
                WHERE i.company_id=? AND i.invoice_currency<>'EUR' AND (i.journal_entry_id=? OR p.journal_entry_id=?) LIMIT 1");
            $fx->execute([$companyId,$originalId,$originalId]);
            if ($fx->fetchColumn()) throw new RuntimeException('Valuutaarve ja selle makse parandamine peab säilitama arve jäägi mõlemas valuutas. Kasuta arve töövoogu.');
        }
        foreach(['import_vat_documents','import_vat_payments'] as $table) {
            $s=$pdo->prepare('SELECT COUNT(*) FROM '.$table.' WHERE company_id=? AND (journal_entry_id=?'.($table==='import_vat_documents'?' OR void_entry_id=?':'').')'); $s->execute($table==='import_vat_documents'?[$companyId,$originalId,$originalId]:[$companyId,$originalId]);
            if((int)$s->fetchColumn()) throw new RuntimeException('Kanne on seotud impordi KM registriga. Paranda või eemalda seos esmalt tollidokumendi juures, et register ja KMD jääksid kooskõlla.');
        }
        $stmt=$pdo->prepare('SELECT account_id,description,debit,credit FROM journal_lines WHERE entry_id=? ORDER BY id');
        $stmt->execute([$originalId]); $lines=$stmt->fetchAll(PDO::FETCH_ASSOC);
        if(count($lines)<2) throw new RuntimeException('Kandel pole vastukande jaoks piisavalt ridu.');
        $description='Parandus kandele #'.$originalId.($original['document_number']?' · dokument '.$original['document_number']:'');
        $stmt=$pdo->prepare("INSERT INTO journal_entries (company_id,entry_date,description,document_number,status,created_by,posted_at,corrects_entry_id) VALUES (?,?,?,?,'draft',?,NULL,?)");
        $stmt->execute([$companyId,$date,$description,'PAR-'.$originalId,$userId,$originalId]); $id=(int)$pdo->lastInsertId();
        $stmt=$pdo->prepare('INSERT INTO journal_lines (entry_id,account_id,description,debit,credit) VALUES (?,?,?,?,?)');
        foreach($lines as $line) $stmt->execute([$id,$line['account_id'],$line['description'],$line['credit'],$line['debit']]);
        $pdo->commit(); return $id;
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function changeJournalDraft(PDO $pdo,int $companyId,int $entryId,string $action): void
{
    if(!in_array($action,['cancel','delete'],true)) throw new InvalidArgumentException('Vigane mustandi toiming.');
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('SELECT id,status,posted_at FROM journal_entries WHERE company_id=? AND id=? FOR UPDATE');
        $stmt->execute([$companyId,$entryId]); $entry=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$entry || !in_array($entry['status'],['draft','cancelled'],true) || $entry['posted_at']!==null) throw new RuntimeException('Tühistada või kustutada saab ainult kinnitamata mustandit.');
        // Check both foreign keys and application links before deleting a draft attached to another document.
        $references=$pdo->query("SELECT DISTINCT TABLE_NAME,COLUMN_NAME FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() AND (COLUMN_NAME='entry_id' OR RIGHT(COLUMN_NAME,9)='_entry_id') AND TABLE_NAME<>'journal_lines'")->fetchAll(PDO::FETCH_ASSOC);
        foreach($references as $reference) {
            $table=$reference['TABLE_NAME']; $column=$reference['COLUMN_NAME'];
            if(str_contains(strtolower($table),'backup')) continue;
            if(!preg_match('/^[A-Za-z0-9_]+$/',$table.$column)) throw new LogicException('Vigane tabeli viide.');
            $check=$pdo->prepare('SELECT COUNT(*) FROM `'.$table.'` WHERE `'.$column.'`=?'); $check->execute([$entryId]);
            $count=(int)$check->fetchColumn();
            if($count && $table==='bank_statement_lines' && $column==='draft_journal_entry_id') {
                $unlink=$pdo->prepare('UPDATE bank_statement_lines SET draft_journal_entry_id=NULL WHERE company_id=? AND draft_journal_entry_id=?'); $unlink->execute([$companyId,$entryId]);
                if($unlink->rowCount()!==$count) throw new RuntimeException('Pangarea ettevõtte seos ei klapi. Mustandit ei muudetud.');
            } elseif($count) throw new RuntimeException('Mustand on seotud teise dokumendiga. Eemalda või tühista see seos esmalt dokumendi enda vaates.');
        }
        if($action==='cancel') {
            if($entry['status']!=='draft') throw new RuntimeException('Mustand on juba tühistatud.');
            $stmt=$pdo->prepare("UPDATE journal_entries SET status='cancelled' WHERE company_id=? AND id=? AND status='draft' AND posted_at IS NULL"); $stmt->execute([$companyId,$entryId]);
        } else {
            $stmt=$pdo->prepare('DELETE FROM journal_lines WHERE entry_id=?'); $stmt->execute([$entryId]);
            $stmt=$pdo->prepare("DELETE FROM journal_entries WHERE company_id=? AND id=? AND status IN ('draft','cancelled') AND posted_at IS NULL"); $stmt->execute([$companyId,$entryId]);
        }
        if($stmt->rowCount()!==1) throw new RuntimeException('Mustandi olek muutus. Värskenda lehte.');
        $pdo->commit();
    } catch(Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
