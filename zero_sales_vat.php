<?php
require_once __DIR__.'/eu_reverse_vat.php';
/** A zero rate is an amount, never enough on its own to identify a transaction. */
function zeroSalesSpecs(): array
{
    return [
        'M_EXPORT0'=>['label'=>'Kauba eksport väljapoole EL-i','category'=>'zero_rated','box'=>'3.2','kind'=>'export','notice'=>'Kauba eksport, 0% — KMS § 15 lg 3 p 1.'],
        'M_EU_GOODS0'=>['label'=>'EL kaubamüük — ühendusesisene käive','category'=>'zero_rated','box'=>'3.1.1','kind'=>'eu_goods','notice'=>'Kauba ühendusesisene käive, 0% — direktiivi 2006/112/EÜ artikkel 138. Pöördmaksustamine / Reverse charge.'],
        'M_EU_SERVICES0'=>['label'=>'EL teenusemüük — B2B põhireegel, VD','category'=>'zero_rated','box'=>'3.1','kind'=>'eu_services','notice'=>'Pöördmaksustamine / Reverse charge — KMS § 10 lg 4 p 9; käibemaksu arvestab teenuse saaja.'],
        'M_NON_EU_SERVICES0'=>['label'=>'EL-väline teenusemüük — B2B põhireegel','category'=>'zero_rated','box'=>'3','kind'=>'non_eu_services','notice'=>'Teenuse käibe tekkimise koht ei ole Eesti — KMS § 10 lg 4 p 9 ja § 15 lg 4 p 1.'],
        'M_TRANSPORT0'=>['label'=>'Ekspordi/impordiga seotud kaubavedu — 0%','category'=>'zero_rated','box'=>'3','kind'=>'transport','notice'=>'Ekspordi või impordiga seotud kaubaveoteenus, 0% — KMS § 15 lg 4 p 9 või 10; täpne alus allpool.'],
        'M_EE_OTHER0'=>['label'=>'Muu 0% tehing — täpsustatud seaduslik alus','category'=>'zero_rated','box'=>'3','kind'=>'other_zero','notice'=>'0% määraga käive; maksustamise alus:'],
        'M_OTHER_GOODS0'=>['label'=>'Muu 0% kaubamüük — täpsustatud seaduslik alus','category'=>'zero_rated','box'=>'3','kind'=>'other_zero_goods','notice'=>'0% määraga kaubamüük; maksustamise alus:'],
        'M_OTHER_SERVICES0'=>['label'=>'Muu 0% teenusemüük — täpsustatud seaduslik alus','category'=>'zero_rated','box'=>'3','kind'=>'other_zero_services','notice'=>'0% määraga teenusemüük; maksustamise alus:'],
        'M_EXEMPT_RENT'=>['label'=>'Maksuvaba kinnisasja üür/rent','category'=>'exempt','box'=>'8','kind'=>'exempt_rent','notice'=>'Maksuvaba kinnisasja üür/rent — KMS § 16 lg 2 p 2.'],
        'M_EXEMPT_PROPERTY'=>['label'=>'Maksuvaba kinnisasja müük','category'=>'exempt','box'=>'8','kind'=>'exempt_property','notice'=>'Maksuvaba kinnisasja müük — KMS § 16 lg 2 p 3.'],
        'M_EXEMPT_OTHER'=>['label'=>'Muu maksuvaba käive — täpsustatud alus','category'=>'exempt','box'=>'8','kind'=>'other_exempt','notice'=>'Maksuvaba käive; maksuvabastuse alus:'],
    ];
}
function ensureZeroSalesSchema(PDO $pdo): void
{
    if($pdo->inTransaction()) return;
    try { $pdo->query('SELECT source_hash,party_json FROM zero_sales_context LIMIT 0'); $pdo->query('SELECT id FROM zero_sales_history LIMIT 0'); return; } catch(PDOException $missing) {}
    $pdo->exec("CREATE TABLE IF NOT EXISTS zero_sales_context (
        company_id INT UNSIGNED NOT NULL,invoice_id INT UNSIGNED NOT NULL,tax_date DATE NOT NULL,
        evidence VARCHAR(500) NOT NULL,basis_text VARCHAR(500) NOT NULL,override_code VARCHAR(40) NOT NULL DEFAULT '',
        conditions_confirmed TINYINT NOT NULL,party_json TEXT NOT NULL,seller_vat VARCHAR(30) NOT NULL,
        source_hash CHAR(64) NOT NULL,notice_text TEXT NOT NULL,confirmed_by INT UNSIGNED NOT NULL,
        confirmed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(company_id,invoice_id),KEY zero_tax_period(company_id,tax_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS zero_sales_history (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,company_id INT UNSIGNED NOT NULL,invoice_id INT UNSIGNED NOT NULL,
        before_json MEDIUMTEXT NULL,after_json MEDIUMTEXT NOT NULL,created_by INT UNSIGNED NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY zero_history(company_id,invoice_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function zeroCents(string $v): int
{
    $v=str_replace(',','.',trim($v));
    if(!preg_match('/^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/',$v,$m)) throw new InvalidArgumentException('Vigane rahasumma.');
    return ($m[1]==='-'?-1:1)*((int)$m[2]*100+(int)str_pad($m[3]??'',2,'0'));
}
function zeroLength(string $text): int { $n=preg_match_all('/./us',$text); return $n===false?strlen($text):$n; }
function zeroVatFormat(string $vat): bool
{
    // National format only. Existence/validity remains an explicitly confirmed VIES check.
    $formats=['AT'=>'U[0-9]{8}','BE'=>'[01][0-9]{9}','BG'=>'[0-9]{9,10}','CY'=>'[0-9]{8}[A-Z]','CZ'=>'[0-9]{8,10}','DE'=>'[0-9]{9}','DK'=>'[0-9]{8}','EE'=>'[0-9]{9}','EL'=>'[0-9]{9}','ES'=>'[A-Z0-9][0-9]{7}[A-Z0-9]','FI'=>'[0-9]{8}','FR'=>'[A-Z0-9]{2}[0-9]{9}','HR'=>'[0-9]{11}','HU'=>'[0-9]{8}','IE'=>'(?:[0-9]{7}[A-Z]{1,2}|[0-9][A-Z+*][0-9]{5}[A-Z])','IT'=>'[0-9]{11}','LT'=>'(?:[0-9]{9}|[0-9]{12})','LU'=>'[0-9]{8}','LV'=>'[0-9]{11}','MT'=>'[0-9]{8}','NL'=>'[0-9]{9}B[0-9]{2}','PL'=>'[0-9]{10}','PT'=>'[0-9]{9}','RO'=>'[0-9]{2,10}','SE'=>'[0-9]{10}01','SI'=>'[0-9]{8}','SK'=>'[0-9]{10}','XI'=>'(?:[0-9]{9}(?:[0-9]{3})?|(?:GD|HA)[0-9]{3})'];
    $prefix=substr($vat,0,2); return isset($formats[$prefix]) && (bool)preg_match('/^'.$prefix.$formats[$prefix].'$/',$vat);
}
function zeroContext(PDO $pdo,int $company,int $invoice): ?array
{
    $s=$pdo->prepare('SELECT * FROM zero_sales_context WHERE company_id=? AND invoice_id=?'); $s->execute([$company,$invoice]); return $s->fetch(PDO::FETCH_ASSOC)?:null;
}
function zeroSource(PDO $pdo,int $company,int $id): array
{
    $s=$pdo->prepare('SELECT * FROM sales_invoices WHERE company_id=? AND id=?'); $s->execute([$company,$id]); $invoice=$s->fetch(PDO::FETCH_ASSOC);
    if(!$invoice) throw new InvalidArgumentException('Selle ettevõtte müügiarvet ei leitud.');
    $s=$pdo->prepare('SELECT l.*,t.code AS tax_code,t.tax_category FROM sales_invoice_lines l LEFT JOIN tax_codes t ON t.id=l.tax_code_id AND t.company_id=? WHERE l.invoice_id=? ORDER BY l.id'); $s->execute([$company,$id]); $lines=$s->fetchAll(PDO::FETCH_ASSOC);
    return ['invoice'=>$invoice,'lines'=>$lines];
}
function zeroSourceHash(array $source): string
{
    $rows=[]; foreach($source['lines'] as $l) $rows[]=[(int)$l['id'],(string)$l['tax_code'],(string)$l['tax_category'],(string)$l['vat_rate'],(string)$l['net_amount'],(string)$l['vat_amount'],(string)$l['gross_amount'],(string)$l['description'],(string)$l['quantity'],(string)$l['unit_price'],$l['product_id']??null,$l['warehouse_id']??null];
    return hash('sha256',json_encode([(int)$source['invoice']['contact_id'],$source['invoice']['invoice_date'],$source['invoice']['supply_date']??null,$rows],JSON_THROW_ON_ERROR));
}
function zeroHasLines(array $source): bool
{
    foreach($source['lines'] as $l) if(in_array($l['tax_category']??'',['zero_rated','exempt'],true) || isset(zeroSalesSpecs()[$l['tax_code']??''])) return true;
    return false;
}
function zeroParty(array $party,string $kind): array
{
    $country=strtoupper(trim((string)($party['country_code']??''))); $vat=strtoupper(preg_replace('/\s+/','',(string)($party['vat_number']??'')));
    if($country==='EL') $country='GR';
    if(!preg_match('/^[A-Z]{2}$/',$country) || in_array($country,['XX','ZZ','EU'],true)) throw new InvalidArgumentException('Täida kliendi tegelik kahetäheline riigikood.');
    $eu=['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE'];
    if(in_array($kind,['eu_goods','eu_services'],true)) {
        if(!zeroVatFormat($vat)) throw new InvalidArgumentException('EL müügi kliendi KMKR numbri vorming ei vasta tema riigi nõuetele.');
        if($country==='XI') {
            if($kind!=='eu_goods') throw new InvalidArgumentException('Põhja-Iirimaa XI käsitlus sobib ainult kaubale, mitte EL teenusemüügile.');
        } else {
            if($country==='EE' || !in_array($country,$eu,true) || substr($vat,0,2)!==($country==='GR'?'EL':$country)) throw new InvalidArgumentException('EL müük vajab teise liikmesriigi klienti ja riigiga sobivat KMKR numbrit.');
        }
    }
    if($kind==='non_eu_services' && (in_array($country,$eu,true) || $country==='XI')) throw new InvalidArgumentException('EL-välise B2B teenuse kood vajab EL-välist ettevõtlusega tegelevat klienti.');
    return ['name'=>(string)($party['name']??''),'registry_code'=>(string)($party['registry_code']??''),'address'=>(string)($party['address']??''),'country_code'=>$country,'vat_number'=>$vat];
}
function zeroValidate(array $source,array $context,array $party,string $sellerVat): array
{
    if(empty($context['conditions_confirmed'])) throw new InvalidArgumentException('Kinnita 0% määra või maksuvabastuse tingimused.');
    foreach(['evidence','basis_text'] as $field) if(zeroLength((string)($context[$field]??''))>500) throw new InvalidArgumentException('0% alus ja tõendi viide võivad olla kuni 500 märki.');
    $taxDate=(string)($context['tax_date']??''); $date=DateTimeImmutable::createFromFormat('!Y-m-d',$taxDate);
    if(!$date || $date->format('Y-m-d')!==$taxDate) throw new InvalidArgumentException('Sisesta 0% või maksuvaba käibe tegelik maksustamiskuupäev.');
    $override=(string)($context['override_code']??''); $specs=zeroSalesSpecs();
    if($override && !isset($specs[$override])) throw new InvalidArgumentException('Vali toetatud 0% või maksuvaba käibe liik.');
    if($override) foreach($source['lines'] as $l) if(!in_array($l['tax_category']??'',['zero_rated','exempt'],true) || zeroCents($l['vat_amount'])!==0 || (float)$l['vat_rate']!==0.0) throw new InvalidArgumentException('Arvepõhine täpsustus sobib ainult ühtse 0%/maksuvaba arve jaoks. Segaarve vajab eraldi jaotust.');
    $kinds=[]; $notices=[]; $snapshot=null;
    foreach($source['lines'] as $l) {
        if(!in_array($l['tax_category']??'',['zero_rated','exempt'],true)) { if($l['tax_code']==='M_EE_REVERSE24') throw new InvalidArgumentException('Pöördmaks ja 0% erijuhud sisesta eraldi arvetena.'); continue; }
        $code=$override?:$l['tax_code']; $spec=$specs[$code]??null;
        if(!$spec) throw new InvalidArgumentException('Üldine 0% või maksuvaba kood ei määra tehinguliiki. Täpsusta arve maksukäsitlus.');
        if((float)$l['vat_rate']!==0.0 || zeroCents($l['vat_amount'])!==0 || zeroCents($l['net_amount'])!==zeroCents($l['gross_amount'])) throw new InvalidArgumentException('0% / maksuvaba arverea summad ei klapi.');
        $snapshot=zeroParty($party,$spec['kind']); $kinds[$spec['kind']]=true;
        if(in_array($spec['kind'],['eu_goods','eu_services','export'],true) && !preg_match('/^EE[0-9]{9}$/',$sellerVat)) throw new InvalidArgumentException('Täida ettevõtte Eesti KMKR number enne EL müügi või ekspordi kinnitamist.');
        if(in_array($spec['kind'],['eu_goods','export','transport'],true) && trim((string)($context['evidence']??''))==='') throw new InvalidArgumentException('Sisesta kauba väljaviimise/veo tõendi või ekspordi tollidokumendi viide.');
        $requiresBasis=in_array($spec['kind'],['other_zero','other_zero_goods','other_zero_services','other_exempt','transport'],true);
        if($requiresBasis && trim((string)($context['basis_text']??''))==='') throw new InvalidArgumentException('Sisesta selle 0% määra või maksuvabastuse täpne seaduslik alus.');
        $notice=$spec['notice'];
        if($requiresBasis) $notice.=' '.trim($context['basis_text']);
        if($spec['kind']==='transport' && $snapshot['country_code']!=='EE' && $snapshot['vat_number']!=='') $notice.=' Pöördmaksustamine / Reverse charge.';
        $notices[$notice]=true;
        if(in_array($spec['kind'],['eu_services','non_eu_services','other_zero_services'],true) && !empty($l['product_id'])) throw new InvalidArgumentException('Teenusemüügi koodiga real ei saa olla laokauba väljaminekut.');
    }
    if(!$kinds) throw new InvalidArgumentException('Arvel puudub 0% või maksuvaba tehing.');
    // The form records the actual tax date explicitly; advance receipts alone are not a supply of EU goods.
    $isCredit=(bool)array_filter($source['lines'],static fn(array $l):bool=>zeroCents($l['net_amount'])<0);
    if(isset($kinds['eu_goods']) && !$isCredit) {
        $supply=(string)($source['invoice']['supply_date']??'');
        $d=DateTimeImmutable::createFromFormat('!Y-m-d',$supply);
        if(!$d || $d->format('Y-m-d')!==$supply || $taxDate<$supply) throw new InvalidArgumentException('EL kaubamüügil täida tegelik lähetamise/üleandmise kuupäev; maksustamiskuupäev ei või seda ennetada. Ettemaks ei ole kauba ühendusesisene käive.');
    }
    return ['party'=>$snapshot,'notice'=>implode("\n",array_keys($notices))];
}
/** Called in the invoice transaction; keeps the historical party rather than consulting future contact edits. */
function saveZeroSalesContext(PDO $pdo,int $company,int $user,int $id,array $form,bool $refresh=true): void
{
    if(!$pdo->inTransaction()) throw new LogicException('0% käsitlus peab salvestuma samas tehingus arvega.');
    $source=zeroSource($pdo,$company,$id); if(!zeroHasLines($source)) return;
    $before=zeroContext($pdo,$company,$id);
    $context=['tax_date'=>(string)(($form['zero_tax_date']??'')?:$source['invoice']['invoice_date']),'evidence'=>trim((string)($form['zero_evidence']??'')),'basis_text'=>trim((string)($form['zero_basis']??'')),'override_code'=>(string)($form['zero_override']??''),'conditions_confirmed'=>(int)!empty($form['zero_confirmed'])];
    if (!empty($source['invoice']['fx_event_date']) && $context['tax_date'] !== $source['invoice']['fx_event_date']) {
        throw new InvalidArgumentException('Käibe kuupäev peab vastama arve valuutakursi aluseks olevale kuupäevale.');
    }
    if($refresh || !$before) {
        $s=$pdo->prepare('SELECT * FROM contacts WHERE company_id=? AND id=?'); $s->execute([$company,$source['invoice']['contact_id']]); $party=$s->fetch(PDO::FETCH_ASSOC)?:[];
        $s=$pdo->prepare('SELECT vat_number FROM companies WHERE id=?'); $s->execute([$company]); $seller=strtoupper(preg_replace('/\s+/','',(string)$s->fetchColumn()));
    } else { $party=json_decode($before['party_json'],true,512,JSON_THROW_ON_ERROR); $seller=$before['seller_vat']; }
    $valid=zeroValidate($source,$context,$party,$seller);
    foreach(array_unique([$context['tax_date'],$source['invoice']['invoice_date'],$before['tax_date']??$context['tax_date']]) as $date) if($issue=closedAccountingPeriodMessage($pdo,$company,$date)) throw new InvalidArgumentException($issue);
    $after=$context+['party_json'=>json_encode($valid['party'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'seller_vat'=>$seller,'source_hash'=>zeroSourceHash($source),'notice_text'=>$valid['notice'],'confirmed_by'=>$user];
    $s=$pdo->prepare('INSERT INTO zero_sales_context(company_id,invoice_id,tax_date,evidence,basis_text,override_code,conditions_confirmed,party_json,seller_vat,source_hash,notice_text,confirmed_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE tax_date=VALUES(tax_date),evidence=VALUES(evidence),basis_text=VALUES(basis_text),override_code=VALUES(override_code),conditions_confirmed=VALUES(conditions_confirmed),party_json=VALUES(party_json),seller_vat=VALUES(seller_vat),source_hash=VALUES(source_hash),notice_text=VALUES(notice_text),confirmed_by=VALUES(confirmed_by),confirmed_at=CURRENT_TIMESTAMP');
    $s->execute([$company,$id,$after['tax_date'],$after['evidence'],$after['basis_text'],$after['override_code'],$after['conditions_confirmed'],$after['party_json'],$seller,$after['source_hash'],$after['notice_text'],$user]);
    $pdo->prepare('INSERT INTO zero_sales_history(company_id,invoice_id,before_json,after_json,created_by) VALUES(?,?,?,?,?)')->execute([$company,$id,$before?json_encode($before,JSON_THROW_ON_ERROR):null,json_encode($after,JSON_THROW_ON_ERROR),$user]);
}
function zeroFormFromContext(array $c): array
{
    return ['zero_tax_date'=>$c['tax_date'],'zero_evidence'=>$c['evidence'],'zero_basis'=>$c['basis_text'],'zero_override'=>$c['override_code'],'zero_confirmed'=>$c['conditions_confirmed']];
}
function confirmZeroSalesInvoice(PDO $pdo,int $company,int $user,int $id): void
{
    $source=zeroSource($pdo,$company,$id); if(!zeroHasLines($source)) return;
    $c=zeroContext($pdo,$company,$id); if(!$c) throw new InvalidArgumentException('Täpsusta enne kinnitamist arve 0% / maksuvaba käibe alus. Ava arvel „0% / maksuvaba käibe käsitlus”.');
    saveZeroSalesContext($pdo,$company,$user,$id,zeroFormFromContext($c),true);
}
function copyZeroSalesCredit(PDO $pdo,int $company,int $user,int $original,int $credit): void
{
    $source=zeroSource($pdo,$company,$original); if(!zeroHasLines($source)) return;
    $c=zeroContext($pdo,$company,$original);
    if(!$c || !hash_equals($c['source_hash'],zeroSourceHash($source))) throw new InvalidArgumentException('Täpsusta enne kreeditarvet algse arve 0% / maksuvaba käsitlus.');
    $new=zeroSource($pdo,$company,$credit); $c['tax_date']=$new['invoice']['invoice_date']; $c['source_hash']=zeroSourceHash($new);
    $pdo->prepare('INSERT INTO zero_sales_context(company_id,invoice_id,tax_date,evidence,basis_text,override_code,conditions_confirmed,party_json,seller_vat,source_hash,notice_text,confirmed_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$company,$credit,$c['tax_date'],$c['evidence'],$c['basis_text'],$c['override_code'],1,$c['party_json'],$c['seller_vat'],$c['source_hash'],$c['notice_text'],$user]);
    $pdo->prepare('INSERT INTO zero_sales_history(company_id,invoice_id,before_json,after_json,created_by) VALUES(?,?,NULL,?,?)')->execute([$company,$credit,json_encode(['copied_from'=>$original,'context'=>$c],JSON_THROW_ON_ERROR),$user]);
}
function loadZeroSales(PDO $pdo,int $company,string $start,string $end): array
{
    ensureZeroSalesSchema($pdo);
    require_once __DIR__.'/credit_note_lib.php';
    $own=!$pdo->inTransaction();
    if($own) { $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY'); $pdo->beginTransaction(); }
    try {
    $result=['rows'=>[],'errors'=>[],'boxes'=>['3'=>0,'3.1'=>0,'3.1.1'=>0,'3.2'=>0,'8'=>0],'partners'=>[]];
    $s=$pdo->prepare("SELECT DISTINCT i.id FROM sales_invoices i JOIN sales_invoice_lines l ON l.invoice_id=i.id LEFT JOIN tax_codes t ON t.id=l.tax_code_id AND t.company_id=i.company_id LEFT JOIN zero_sales_context z ON z.company_id=i.company_id AND z.invoice_id=i.id WHERE i.company_id=? AND i.status='posted' AND i.journal_entry_id IS NOT NULL AND t.tax_category IN ('zero_rated','exempt') AND COALESCE(z.tax_date,i.invoice_date) BETWEEN ? AND ? ORDER BY i.id");
    $s->execute([$company,$start,$end]); $ids=$s->fetchAll(PDO::FETCH_COLUMN); $links=loadCreditLinks($pdo,$company,'sales');
    foreach($ids as $id) {
        try {
            $source=zeroSource($pdo,$company,(int)$id); $c=zeroContext($pdo,$company,(int)$id);
            if(!$c || !hash_equals($c['source_hash'],zeroSourceHash($source))) throw new InvalidArgumentException('0% / maksuvaba käsitlus on kinnitamata või arve andmed on muutunud.');
            $party=json_decode($c['party_json'],true,512,JSON_THROW_ON_ERROR); zeroValidate($source,$c,$party,$c['seller_vat']);
            $s=$pdo->prepare("SELECT status,entry_date FROM journal_entries WHERE company_id=? AND id=?"); $s->execute([$company,$source['invoice']['journal_entry_id']]); $entry=$s->fetch(PDO::FETCH_ASSOC);
            if(!$entry || $entry['status']!=='posted') throw new InvalidArgumentException('Arve kinnitatud pearaamatukanne puudub.');
            $invoiceRows=[];
            $mixedTaxable = (bool) array_filter($source['lines'], static fn(array $line): bool => !in_array($line['tax_category'], ['zero_rated', 'exempt'], true));
            foreach($source['lines'] as $l) {
                if(!in_array($l['tax_category'],['zero_rated','exempt'],true)) continue;
                $code=$c['override_code']?:$l['tax_code']; $spec=zeroSalesSpecs()[$code]; $net=zeroCents($l['net_amount']);
                if($net<0 && (!isset($links[$id]) || $links[$id]['original_status']!=='posted')) throw new InvalidArgumentException('Negatiivne 0% rida vajab kinnitatud algse arve kreeditseost.');
                $invoiceRows[]=['invoice_id'=>(int)$id,'line_id'=>(int)$l['id'],'invoice_number'=>$source['invoice']['invoice_number'],'invoice_date'=>$source['invoice']['invoice_date'],'tax_date'=>$c['tax_date'],'code'=>$code,'label'=>$spec['label'],'kind'=>$spec['kind'],'box'=>$spec['box'],'net'=>$net,'party'=>$party,'evidence'=>$c['evidence'],'notice'=>$c['notice_text'],'mixed_taxable'=>$mixedTaxable];
            }
            foreach($invoiceRows as $r) {
                $result['rows'][]=$r; $spec=zeroSalesSpecs()[$r['code']];
                if($spec['category']==='exempt') $result['boxes']['8']+=$r['net'];
                else { $result['boxes']['3']+=$r['net']; if(in_array($r['kind'],['eu_goods','eu_services'],true)) $result['boxes']['3.1']+=$r['net']; if($r['kind']==='eu_goods') $result['boxes']['3.1.1']+=$r['net']; if($r['kind']==='export') $result['boxes']['3.2']+=$r['net']; }
                if(in_array($r['kind'],['eu_goods','eu_services'],true)) {
                    $key=$party['vat_number']; $result['partners'][$key]??=['party'=>$party,'goods'=>0,'services'=>0]; $result['partners'][$key][$r['kind']==='eu_goods'?'goods':'services']+=$r['net'];
                }
            }
        } catch(Throwable $e) { $result['errors'][]='Müügiarve #'.$id.': '.$e->getMessage(); }
    }
    if($own) $pdo->commit();
    return $result;
    } catch(Throwable $e) { if($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function zeroOutputInvoice(PDO $pdo,int $company,int $id,array $invoice): array
{
    ensureZeroSalesSchema($pdo); $c=zeroContext($pdo,$company,$id); if(!$c) return $invoice;
    $party=json_decode($c['party_json'],true)??[];
    foreach(['name'=>'contact_name','registry_code'=>'contact_registry_code','vat_number'=>'contact_vat_number','address'=>'contact_address'] as $key=>$field) $invoice[$field]=$party[$key]??($invoice[$field]??'');
    $invoice['zero_vat_notice']=$c['notice_text']; $invoice['zero_seller_vat']=$c['seller_vat']; return $invoice;
}
function zeroEinvoiceData(PDO $pdo,int $company,int $id,array $invoice): array
{
    ensureZeroSalesSchema($pdo); $source=zeroSource($pdo,$company,$id);
    if(zeroHasLines($source)) {
        $c=zeroContext($pdo,$company,$id);
        if(!$c || !hash_equals($c['source_hash'],zeroSourceHash($source))) throw new RuntimeException('Täpsusta enne e-arve koostamist arve 0% / maksuvaba käibe käsitlus.');
    }
    return zeroOutputInvoice($pdo,$company,$id,$invoice);
}
function vdRound(int $cents): int { return ($cents<0?-1:1)*intdiv(abs($cents)+50,100); }
function vdXml(string $registry,int $year,int $month,array $partners): string
{
    if(!preg_match('/^[0-9]{8,11}$/',$registry) || $year<2000 || $year>2100 || $month<1 || $month>12 || !$partners) throw new InvalidArgumentException('VD jaoks on vaja korrektset registrikoodi, perioodi ja tehinguid.');
    $xml=new DOMDocument('1.0','UTF-8'); $xml->formatOutput=true; $root=$xml->createElementNS('http://www.emta.ee/VD/xsd/webimport/v1','v1:VD_deklaratsioon'); $xml->appendChild($root);
    foreach(['deklareerijaKood'=>$registry,'perioodAasta'=>$year,'perioodKuu'=>$month] as $key=>$value) $root->appendChild($xml->createElement($key,(string)$value));
    $rows=$root->appendChild($xml->createElement('aruandeRead')); ksort($partners);
    foreach($partners as $p) {
        $row=$rows->appendChild($xml->createElement('aruandeRida')); $vat=$p['party']['vat_number']; $country=substr($vat,0,2); $number=substr($vat,2);
        if(!preg_match('/^[A-Z0-9+*]{1,12}$/',$number)) throw new InvalidArgumentException('VD partneri KMKR numbri vorming on vigane.');
        $vatNode=$row->appendChild($xml->createElement('kmkrKood',$number)); $vatNode->setAttribute('riik',$country);
        foreach(['goods'=>'kaup','services'=>'teenusteMyyk'] as $field=>$tag) if($p[$field]!==0) $row->appendChild($xml->createElement($tag,(string)vdRound($p[$field])));
    }
    return $xml->saveXML();
}
