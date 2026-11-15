<?php
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) { header('Location: login.php'); exit; }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/period_lock.php';
require_once __DIR__ . '/cash_balance_guard.php';
require_once __DIR__ . '/bank_document_number.php';

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
function cents($v): int { return (int) round((float)$v * 100); }
function eur(int $v): string { return number_format($v / 100, 2, ',', ' ') . ' €'; }
function quantityText(int $quantity): string {
    $value = rtrim(rtrim(number_format($quantity / 1000000, 6, '.', ' '), '0'), '.');
    return str_replace('.', ',', $value);
}
function normalized(string $v): string { return function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v); }
function tickerFrom(string $description): ?array {
    if (preg_match('~^\s*([A-Z0-9][A-Z0-9._-]*(?:/[A-Z0-9][A-Z0-9._-]*)*)\s+([+-])\s*([0-9]+(?:[.,][0-9]+)?)\s*@~i', $description, $m)) {
        return ['symbol' => strtoupper($m[1]), 'sign' => $m[2], 'qty' => (int)round((float)str_replace(',', '.', $m[3]) * 1000000)];
    }
    return null;
}
function securityActivityFrom(string $description): ?array {
    if (preg_match('/väärtpaberite\s+(ost|müük)\s*\(\s*([^)]{1,60})\s*\)/iu', $description, $m)) {
        return ['action'=>normalized($m[1])==='ost'?'buy':'sell','symbol'=>trim($m[2])];
    }
    return null;
}
function rowKey(array $r): string {
    $archive = trim((string)($r['archive_reference'] ?? ''));
    $bankAccountId = (int)($r['bank_account_id'] ?? 0);
    if ($archive !== '') return implode('|', [$bankAccountId, $r['transaction_date'], $archive, strtoupper($r['debit_credit']), number_format((float)$r['amount'], 2, '.', '')]);
    return implode('|', [$bankAccountId, $r['transaction_date'], strtoupper($r['debit_credit']), number_format((float)$r['amount'], 2, '.', ''), normalized((string)$r['description']), normalized((string)$r['counterparty_name'])]);
}
$companyId=(int)$_SESSION['company_id']; $userId=(int)$_SESSION['user_id'];
$accountsStmt=$pdo->prepare("SELECT id,code,name,account_type FROM accounts WHERE company_id=? AND is_active=1 ORDER BY code");
$accountsStmt->execute([$companyId]); $accounts=$accountsStmt->fetchAll();
$byCode=[]; $accountsById=[]; foreach($accounts as $a) { $byCode[(string)$a['code']]=$a; $accountsById[(int)$a['id']]=$a; }
$openingStmt=$pdo->prepare("SELECT transaction_date,debit_credit,amount,archive_reference,description,counterparty_name,bank_account_id FROM portfelli_algsaldo WHERE company_id=? ORDER BY transaction_date,id");
$openingStmt->execute([$companyId]); $openingRows=$openingStmt->fetchAll();
$bankStmt=$pdo->prepare("SELECT b.*,a.code AS bank_code,a.name AS bank_name FROM bank_statement_lines b JOIN accounts a ON a.id=b.bank_account_id AND a.company_id=b.company_id WHERE b.company_id=? ORDER BY b.transaction_date,b.id");
$bankStmt->execute([$companyId]); $allBankRows=$bankStmt->fetchAll();
$openingKeys=[]; foreach($openingRows as $r) $openingKeys[rowKey($r)]=true;

// FIFO läbib algsaldo ajaloo ja pangatehingud kronoloogiliselt. Sama tehingu algsaldo- ja pangarida loetakse ainult üks kord.
$events=[];
$openingLotDates=[];
foreach($openingRows as $i=>$r) {
    $t=tickerFrom((string)$r['description']);
    if($t && $t['sign']==='+' && strtoupper((string)$r['debit_credit'])==='D') {
        $openingLotDates[(int)$r['bank_account_id']][$t['symbol']][]=(string)$r['transaction_date'];
        $events[]=['date'=>$r['transaction_date'],'order'=>0,'id'=>$i,'source'=>'opening','row'=>$r,'ticker'=>$t];
    }
}
foreach($allBankRows as $r) {
    $t=tickerFrom((string)$r['description']);
    if($t) $events[]=['date'=>$r['transaction_date'],'order'=>1,'id'=>(int)$r['id'],'source'=>'bank','row'=>$r,'ticker'=>$t];
}
usort($events, static function($a,$b){ return [$a['date'],$a['order'],$a['id']] <=> [$b['date'],$b['order'],$b['id']]; });
$seen=[]; $lots=[]; $fifoResults=[];
foreach($events as $ev) {
    $r=$ev['row']; $t=$ev['ticker']; $key=rowKey($r);
    if($ev['source']==='bank' && isset($openingKeys[$key])) continue;
    if($ev['source']==='bank' && isset($seen[$key])) continue;
    if($ev['source']==='bank') $seen[$key]=true;
    $sym=$t['symbol']; $bankAccountId=(int)$r['bank_account_id']; $portfolioKey=$bankAccountId.'|'.$sym; $amt=cents($r['amount']); $qty=max(0,$t['qty']);
    if($t['sign']==='+' && strtoupper((string)$r['debit_credit'])==='D') {
        $lots[$portfolioKey][]=['qty'=>$qty,'cost'=>$amt];
    } elseif($t['sign']==='-' && strtoupper((string)$r['debit_credit'])==='C' && $ev['source']==='bank') {
        $remaining=$qty; $cost=0; $missing=0;
        while($remaining>0 && !empty($lots[$portfolioKey])) {
            $idx=0; $available=$lots[$portfolioKey][$idx]['qty']; $take=min($remaining,$available);
            $partCost=$take===$available ? $lots[$portfolioKey][$idx]['cost'] : intdiv($lots[$portfolioKey][$idx]['cost']*$take,$available);
            $cost += $partCost; $remaining -= $take;
            $lots[$portfolioKey][$idx]['qty'] -= $take; $lots[$portfolioKey][$idx]['cost'] -= $partCost;
            if($lots[$portfolioKey][$idx]['qty']===0) array_shift($lots[$portfolioKey]);
        }
        $missing=$remaining;
        $fifoResults[(int)$r['id']]=['symbol'=>$sym,'bank_account_id'=>$bankAccountId,'qty'=>$qty,'cost'=>$cost,'missing'=>$missing,'profit'=>cents($r['amount'])-$cost];
    }
}

$positions=[];
foreach($lots as $portfolioKey=>$symbolLots) {
    [$bankAccountId, $symbol] = explode('|', $portfolioKey, 2);
    $quantity=0; $cost=0;
    foreach($symbolLots as $lot) { $quantity += (int)$lot['qty']; $cost += (int)$lot['cost']; }
    if($quantity>0) $positions[$portfolioKey]=['symbol'=>$symbol,'bank_account_id'=>(int)$bankAccountId,'quantity'=>$quantity,'cost'=>$cost];
}
ksort($positions);
$fifoHasMissingLots=false;
foreach($fifoResults as $fifoResult) if($fifoResult['missing']>0) { $fifoHasMissingLots=true; break; }
$dateStmt=$pdo->prepare('SELECT MAX(transaction_date) FROM portfelli_algsaldo WHERE company_id=?');
$dateStmt->execute([$companyId]); $portfolioAsOf=$dateStmt->fetchColumn() ?: null;
$bankDateStmt=$pdo->prepare('SELECT MAX(transaction_date) FROM bank_statement_lines WHERE company_id=?');
$bankDateStmt->execute([$companyId]); $latestBankDate=$bankDateStmt->fetchColumn() ?: null;
if($latestBankDate && (!$portfolioAsOf || $latestBankDate>$portfolioAsOf)) $portfolioAsOf=$latestBankDate;
$bankAccounts=[]; foreach($accounts as $a) if($a['account_type']==='asset') $bankAccounts[]=$a;
$unmatched=[]; foreach($allBankRows as $r) if($r['matched_journal_line_id']===null && $r['draft_journal_entry_id']===null) $unmatched[]=$r;
$openSalesStmt=$pdo->prepare("SELECT si.id,si.invoice_number,si.invoice_date,c.name AS contact_name,COALESCE(t.gross_total,0)-COALESCE(p.paid_total,0) AS outstanding FROM sales_invoices si JOIN contacts c ON c.id=si.contact_id AND c.company_id=si.company_id LEFT JOIN (SELECT invoice_id,SUM(gross_amount) AS gross_total FROM sales_invoice_lines GROUP BY invoice_id) t ON t.invoice_id=si.id LEFT JOIN (SELECT invoice_id,SUM(amount) AS paid_total FROM invoice_payments GROUP BY invoice_id) p ON p.invoice_id=si.id WHERE si.company_id=? AND si.status='posted' AND si.invoice_currency='EUR' AND si.journal_entry_id IS NOT NULL HAVING outstanding>0 ORDER BY si.invoice_date,si.id");
$openSalesStmt->execute([$companyId]); $openSalesInvoices=$openSalesStmt->fetchAll();
$openPurchaseStmt=$pdo->prepare("SELECT pi.id,pi.supplier_invoice_number AS invoice_number,pi.invoice_date,c.name AS contact_name,COALESCE(t.gross_total,0)-COALESCE(p.paid_total,0) AS outstanding FROM purchase_invoices pi JOIN contacts c ON c.id=pi.contact_id AND c.company_id=pi.company_id LEFT JOIN (SELECT invoice_id,SUM(gross_amount) AS gross_total FROM purchase_invoice_lines GROUP BY invoice_id) t ON t.invoice_id=pi.id LEFT JOIN (SELECT invoice_id,SUM(amount) AS paid_total FROM purchase_invoice_payments GROUP BY invoice_id) p ON p.invoice_id=pi.id WHERE pi.company_id=? AND pi.status='posted' AND pi.invoice_currency='EUR' AND pi.journal_entry_id IS NOT NULL HAVING outstanding>0 ORDER BY pi.invoice_date,pi.id");
$openPurchaseStmt->execute([$companyId]); $openPurchaseInvoices=$openPurchaseStmt->fetchAll();
$openSalesById=[]; foreach($openSalesInvoices as $invoice) $openSalesById[(int)$invoice['id']]=$invoice;
$openPurchaseById=[]; foreach($openPurchaseInvoices as $invoice) $openPurchaseById[(int)$invoice['id']]=$invoice;
$notice=''; $error='';

// Unknown rows can be assigned a counter account here and then included in
// the same bulk posting as the automatically classified rows.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['find_counterparty_id'])) {
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    } else {
        $rowId = filter_var($_POST['find_counterparty_id'], FILTER_VALIDATE_INT);
        $choiceValues = $_POST['counter_choice'] ?? [];
        $choice = is_array($choiceValues) && $rowId ? (string) ($choiceValues[$rowId] ?? '') : '';
        $bankRow = null;
        foreach ($unmatched as $candidate) {
            if ((int) $candidate['id'] === (int) $rowId) {
                $bankRow = $candidate;
                break;
            }
        }
        if (!$bankRow || $choice === '') {
            $error = 'Vali sellele pangatehingule arve või pearaamatukonto.';
        } elseif ($choice === 'auto') {
            unset($_SESSION['bank_counter_account_ids'][(int) $rowId]);
            unset($_SESSION['bank_invoice_allocations'][(int) $rowId]);
            header('Location: investment_review.php?rematched=1');
            exit;
        } elseif ((float) ($bankRow['service_fee'] ?? 0) > 0) {
            $error = 'Teenustasuga tehing vajab enne kinnitamist eraldi kontrolli.';
        } elseif (preg_match('/^(sales|purchase):([1-9][0-9]*)$/', $choice, $choiceMatch)) {
            $kind = $choiceMatch[1];
            $invoiceId = (int) $choiceMatch[2];
            $invoice = $kind === 'sales'
                ? ($openSalesById[$invoiceId] ?? null)
                : ($openPurchaseById[$invoiceId] ?? null);
            $direction = strtoupper((string) $bankRow['debit_credit']);
            $rightDirection = ($kind === 'sales' && $direction === 'C')
                || ($kind === 'purchase' && $direction === 'D');
            if (!$invoice || !$rightDirection) {
                $error = 'Valitud arve ei sobi selle pangarea suunaga või arve pole enam tasumata.';
            } elseif (cents((string) $bankRow['amount']) > cents((string) $invoice['outstanding'])) {
                $error = 'Pangarea summa ületab valitud arve tasumata jäägi.';
            } else {
                $_SESSION['bank_invoice_allocations'][(int) $rowId] = [
                    'kind' => $kind,
                    'invoice_id' => $invoiceId,
                ];
                unset($_SESSION['bank_counter_account_ids'][(int) $rowId]);
                header('Location: investment_review.php?mapped=1');
                exit;
            }
        } elseif (preg_match('/^account:([1-9][0-9]*)$/', $choice, $choiceMatch)) {
            $chosenAccountId = (int) $choiceMatch[1];
            $chosenAccount = null;
            foreach ($accounts as $candidateAccount) {
                if ((int) $candidateAccount['id'] === $chosenAccountId) {
                    $chosenAccount = $candidateAccount;
                    break;
                }
            }
            if (!$chosenAccount) {
                $error = 'Vali aktiivne vastaskonto.';
            } elseif ((int) $bankRow['bank_account_id'] === (int) $chosenAccount['id']) {
                $error = 'Vastaskonto peab pangakontost erinema.';
            } elseif (in_array((string) $chosenAccount['code'], ['1200', '2000'], true)) {
                $error = 'Ostjate nõuded ja tarnijavõlad seotakse arve valimise kaudu.';
            } else {
                $_SESSION['bank_counter_account_ids'][(int) $rowId] = (int) $chosenAccount['id'];
                unset($_SESSION['bank_invoice_allocations'][(int) $rowId]);
                header('Location: investment_review.php?mapped=1');
                exit;
            }
        } else {
            $error = 'Valitud vastaspool ei sobi.';
        }
    }
}

function classifyBankRow(array $r,array $byCode,array $fifo,?array $manualAccount=null,?array $invoiceAllocation=null): array {
    $dc=strtoupper((string)$r['debit_credit']); $amt=cents($r['amount']); $desc=(string)$r['description']; $low=normalized($desc); $ticker=tickerFrom($desc);
    if($amt<=0 || !in_array($dc,['D','C'],true)) return ['ready'=>false,'label'=>'Vigane pangarida','reason'=>'Suund või summa vajab parandamist.','type'=>'unknown'];
    if((float)($r['service_fee'] ?? 0)>0) return ['ready'=>false,'label'=>'Tehing koos teenustasuga','reason'=>'Teenustasu vajab eraldi rida või kontrolli enne kinnitamist.','type'=>'unknown'];
    $securityActivity=securityActivityFrom($desc.' '.(string)($r['counterparty_name']??''));
    if ($manualAccount !== null) {
        $manualReason='Kontrolli valitud vastaskonto ja D/C suund enne kõigi valmis kannete kinnitamist.';
        if ($securityActivity && (string)$manualAccount['code']==='1100') {
            $manualReason=$securityActivity['action']==='sell'
                ? 'Käsikanne vähendab väärtpaberikontot pangast laekunud summa võrra. CSV-st puuduvad ühikute arv ja FIFO soetusmaksumus, seega müügikasum/-kahjum ei arvutu.'
                : 'Käsikanne lisab ostusumma väärtpaberikontole. CSV-st puudub ühikute arv, seega FIFO portfellijääk sellest ostust ei uuene.';
        }
        return ['ready'=>true,'label'=>'Käsitsi valitud vastaspool','reason'=>$manualReason,'type'=>'manual','account'=>$manualAccount,'amount'=>$amt];
    }
    if($ticker) {
        if($ticker['sign']==='+' && $dc==='D' && isset($byCode['1100'])) return ['ready'=>true,'label'=>'Väärtpaberi ost '.$ticker['symbol'],'reason'=>'Soetusmaksumus lisatakse väärtpaberikontole FIFO algmaksumusena.','type'=>'buy','account'=>$byCode['1100'],'amount'=>$amt];
        if($ticker['sign']==='-' && $dc==='C' && isset($fifo[(int)$r['id']])) {
            $f=$fifo[(int)$r['id']];
            if($f['missing']>0) {
                $laterOpeningDates=array_values(array_filter($GLOBALS['openingLotDates'][(int)$r['bank_account_id']][$f['symbol']]??[],static fn($date)=>(string)$date>(string)$r['transaction_date']));
                if($laterOpeningDates) {
                    sort($laterOpeningDates);
                    $reason='Müügikuupäev '.$r['transaction_date'].' eelneb selle väärtpaberi portfelli algsaldo kuupäevale '.$laterOpeningDates[0].'. Hilisemat algsaldo kogust ei saa varasema müügi katteks kasutada. Kontrolli pangarea kuupäeva või lisa enne müügikuupäeva olnud ostude ajalugu.';
                } else {
                    $reason='FIFO soetusmaksumus puudub osaliselt: '.number_format($f['missing']/1000000,6,',',' ').' ühikut. Lisa enne müügikuupäeva olnud ostude ajalugu algsaldo CSV-sse.';
                }
                return ['ready'=>false,'label'=>'Väärtpaberi müük '.$f['symbol'],'reason'=>$reason,'type'=>'sell','fifo'=>$f];
            }
            if(!isset($byCode['1100']) || !isset($byCode[$f['profit']>=0?'4100':'5050'])) return ['ready'=>false,'label'=>'Väärtpaberi müük '.$f['symbol'],'reason'=>'Väärtpaberite või kasumi/kahjumi konto puudub kontoplaanist.','type'=>'sell','fifo'=>$f];
            return ['ready'=>true,'label'=>'Väärtpaberi müük '.$f['symbol'],'reason'=>'FIFO soetusmaksumus '.eur($f['cost']).'; '.($f['profit']>=0?'kasum ':'kahjum ').eur(abs($f['profit'])).'.','type'=>'sell','fifo'=>$f];
        }
        return ['ready'=>false,'label'=>'Väärtpaberitehing','reason'=>'Kirjelduse pluss/miinus ja panga D/C suund ei sobitu.','type'=>'unknown'];
    }
    if ($securityActivity) {
        if ($securityActivity['action']==='buy' && $dc==='D' && isset($byCode['1100'])) {
            return ['ready'=>true,'label'=>'Väärtpaberi ost '.$securityActivity['symbol'],'reason'=>'Vastaskonto 1100 — Aktsiad, võlakirjad. CSV-st puudub ühikute arv, seega FIFO portfellijääk sellest ostust ei uuene.','type'=>'buy','account'=>$byCode['1100'],'amount'=>$amt];
        }
        if ($securityActivity['action']==='sell' && $dc==='C') {
            return ['ready'=>false,'label'=>'Väärtpaberi müük '.$securityActivity['symbol'],'reason'=>'LHV CSV kirjeldus ei sisalda müüdud ühikute arvu ega FIFO soetusmaksumust. Vali käsitsi vastaskonto ainult siis, kui soovid teha lihtkande; kasum/-kahjum vajab tehingu koguse ja soetusandmeid.','type'=>'unknown'];
        }
    }
    if((preg_match('/^\s*[kt]\s*:/i',$desc) || strpos($low,'kuutasu')!==false || strpos($low,'teenustasu')!==false) && isset($byCode['6400'])) return ['ready'=>true,'label'=>'Panga teenustasu','reason'=>'Vastaskonto 6400 — Panga teenustasud.','type'=>'fee','account'=>$byCode['6400'],'amount'=>$amt];
    if(strpos($low,'dividend')!==false && $dc==='C' && isset($byCode['4250'])) return ['ready'=>true,'label'=>'Dividenditulu','reason'=>'Vastaskonto 4250 — Dividenditulu.','type'=>'income','account'=>$byCode['4250'],'amount'=>$amt];
    if(strpos($low,'intress')!==false && $dc==='C' && isset($byCode['4200'])) return ['ready'=>true,'label'=>'Intressitulu','reason'=>'Vastaskonto 4200 — Intressitulu.','type'=>'income','account'=>$byCode['4200'],'amount'=>$amt];
    if(strpos($low,'laen')!==false && isset($byCode['2200'])) return ['ready'=>true,'label'=>'Laen','reason'=>'Vastaskonto 2200 — Laenud.','type'=>'loan','account'=>$byCode['2200'],'amount'=>$amt];
    if ($invoiceAllocation !== null) {
        $kind = $invoiceAllocation['kind'] ?? '';
        $invoice = $kind === 'sales'
            ? ($GLOBALS['openSalesById'][(int) ($invoiceAllocation['invoice_id'] ?? 0)] ?? null)
            : ($kind === 'purchase' ? ($GLOBALS['openPurchaseById'][(int) ($invoiceAllocation['invoice_id'] ?? 0)] ?? null) : null);
        $validDirection = ($kind === 'sales' && $dc === 'C') || ($kind === 'purchase' && $dc === 'D');
        if ($invoice && $validDirection && $amt <= cents((string) $invoice['outstanding'])) {
            $title = $kind === 'sales' ? 'Müügiarve laekumine' : 'Ostuarve tasumine';
            $counterAccount = $kind === 'sales' ? 'Nõuded ostjate vastu' : 'Võlad tarnijatele';
            return ['ready'=>true,'label'=>$title,'reason'=>$counterAccount.' · arve '.$invoice['invoice_number'].' · tasumata '.eur(cents((string)$invoice['outstanding'])).'.','type'=>$kind==='sales'?'sales_payment':'purchase_payment','invoice_id'=>(int)$invoice['id'],'invoice_number'=>(string)$invoice['invoice_number'],'amount'=>$amt];
        }
    }
    return ['ready'=>false,'label'=>'Vastaspool leidmata','reason'=>'Kontrolli rida ja vali sobiv vastaskonto käsitsi. Arvete 1200/2000 puhul kasuta arve või makse vormi.','type'=>'unknown'];
}

$manualAccountIds = $_SESSION['bank_counter_account_ids'] ?? [];
$invoiceAllocations = $_SESSION['bank_invoice_allocations'] ?? [];
$classified=[];
foreach($unmatched as $r) {
    $manualAccount = null;
    $manualId = (int) ($manualAccountIds[(int) $r['id']] ?? 0);
    if ($manualId > 0) {
        foreach ($accounts as $candidateAccount) {
            if ((int) $candidateAccount['id'] === $manualId) {
                $manualAccount = $candidateAccount;
                break;
            }
        }
    }
    $allocation = $invoiceAllocations[(int)$r['id']] ?? null;
    $classified[(int)$r['id']]=classifyBankRow($r,$byCode,$fifoResults,$manualAccount,$allocation);
}

function lockInvoicePaymentDetails(PDO $pdo,int $companyId,string $kind,int $invoiceId,int $paymentCents): array {
    if ($kind === 'sales') {
        $invoiceStmt=$pdo->prepare("SELECT id,invoice_number,journal_entry_id,status,invoice_currency FROM sales_invoices WHERE id=? AND company_id=? FOR UPDATE");
        $invoiceStmt->execute([$invoiceId,$companyId]);
        $invoice=$invoiceStmt->fetch();
        if(!$invoice || $invoice['status']!=='posted' || empty($invoice['journal_entry_id'])) throw new DomainException('Valitud müügiarve pole enam kinnitatud. Värskenda lehte ja vali arve uuesti.');
        if ($invoice['invoice_currency'] !== 'EUR') throw new DomainException('Valuutaarve laekumine registreeri arve lehel.');
        $totalStmt=$pdo->prepare('SELECT COALESCE(SUM(gross_amount),0) FROM sales_invoice_lines WHERE invoice_id=?');
        $totalStmt->execute([$invoiceId]); $total=cents((string)$totalStmt->fetchColumn());
        $paidStmt=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE invoice_id=?');
        $paidStmt->execute([$invoiceId]); $paid=cents((string)$paidStmt->fetchColumn());
        $accountStmt=$pdo->prepare("SELECT jl.account_id FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id AND a.company_id=? WHERE jl.entry_id=? AND a.account_type='asset' AND jl.debit>0 AND jl.credit=0 ORDER BY jl.id LIMIT 1");
        $accountStmt->execute([$companyId,(int)$invoice['journal_entry_id']]);
        $label='Ostja võla vähenemine';
    } elseif ($kind === 'purchase') {
        $invoiceStmt=$pdo->prepare("SELECT id,supplier_invoice_number AS invoice_number,journal_entry_id,status,invoice_currency FROM purchase_invoices WHERE id=? AND company_id=? FOR UPDATE");
        $invoiceStmt->execute([$invoiceId,$companyId]);
        $invoice=$invoiceStmt->fetch();
        if(!$invoice || $invoice['status']!=='posted' || empty($invoice['journal_entry_id'])) throw new DomainException('Valitud ostuarve pole enam kinnitatud. Värskenda lehte ja vali arve uuesti.');
        if ($invoice['invoice_currency'] !== 'EUR') throw new DomainException('Valuutaarve makse registreeri arve lehel.');
        $totalStmt=$pdo->prepare('SELECT COALESCE(SUM(gross_amount),0) FROM purchase_invoice_lines WHERE invoice_id=?');
        $totalStmt->execute([$invoiceId]); $total=cents((string)$totalStmt->fetchColumn());
        $paidStmt=$pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM purchase_invoice_payments WHERE invoice_id=?');
        $paidStmt->execute([$invoiceId]); $paid=cents((string)$paidStmt->fetchColumn());
        $accountStmt=$pdo->prepare("SELECT jl.account_id FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id AND a.company_id=? WHERE jl.entry_id=? AND a.account_type='liability' AND jl.credit>0 ORDER BY jl.id LIMIT 1");
        $accountStmt->execute([$companyId,(int)$invoice['journal_entry_id']]);
        $label='Tarnijavõla tasumine';
    } else {
        throw new DomainException('Arve liik ei sobi pangatehinguga.');
    }
    $outstanding=$total-$paid;
    if($outstanding<=0 || $paymentCents>$outstanding) throw new DomainException('Pangarea summa ületab arve värsket tasumata jääki. Vali õige arve või jaga makse enne kinnitamist.');
    $accountId=(int)$accountStmt->fetchColumn();
    if($accountId<=0) throw new DomainException('Arve algsest kandest ei leitud nõuete või tarnijavõla kontot.');
    return ['invoice_number'=>(string)$invoice['invoice_number'],'account_id'=>$accountId,'description'=>$label];
}

if($_SERVER['REQUEST_METHOD']==='POST' && !isset($_POST['find_counterparty_id'])) {
    if(empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],$_POST['csrf_token']??'')) $error='Vorm aegus. Värskenda lehte ja proovi uuesti.';
    elseif(!$unmatched) $error='Kinnitamiseks sobivaid sidumata ridu ei ole.';
    else {
        $readyRows=[]; foreach($unmatched as $r) if(!empty($classified[(int)$r['id']]['ready'])) $readyRows[]=$r;
        if(!$readyRows) $error='Ühtegi FIFO ja konto järgi valmis rida ei ole. Kontrolli algandmeid.';
        else {
            try {
                $pdo->beginTransaction();
                $ids=array_map(static fn($r)=>(int)$r['id'],$readyRows);
                $marks=implode(',',array_fill(0,count($ids),'?'));
                $lock=$pdo->prepare("SELECT * FROM bank_statement_lines WHERE company_id=? AND id IN ($marks) AND matched_journal_line_id IS NULL AND draft_journal_entry_id IS NULL ORDER BY transaction_date,id FOR UPDATE");
                $lock->execute(array_merge([$companyId],$ids)); $locked=$lock->fetchAll();
                if(count($locked)!==count($readyRows)) throw new DomainException('Mõne pangarea olek muutus. Värskenda lehte ja kontrolli read uuesti.');
                $manualAccountLock=$pdo->prepare('SELECT code FROM accounts WHERE id=? AND company_id=? AND is_active=1 FOR UPDATE');
                $deltas=[]; foreach($locked as $r) {
                    $cl=$classified[(int)$r['id']];
                    $msg=closedAccountingPeriodMessage($pdo,$companyId,$r['transaction_date']); if($msg!==null) throw new DomainException($msg);
                    $bankId=(int)$r['bank_account_id']; $aId=(int)($cl['account']['id']??0); $amt=cents($r['amount']); $dc=strtoupper($r['debit_credit']);
                    if($cl['type']==='manual') {
                        $manualAccountLock->execute([$aId,$companyId]);
                        $manualCode=$manualAccountLock->fetchColumn();
                        if($manualCode===false || $aId===$bankId || in_array((string)$manualCode,['1200','2000'],true)) throw new DomainException('Valitud vastaskonto pole enam kasutatav. Vali vastaspool uuesti.');
                    }
                    if($cl['type']==='sales_payment' || $cl['type']==='purchase_payment') {
                        $kind=$cl['type']==='sales_payment'?'sales':'purchase';
                        $expectedDirection=$kind==='sales'?'C':'D';
                        if($dc!==$expectedDirection) throw new DomainException('Pangarea D/C suund ei sobi valitud arve liigiga.');
                        $details=lockInvoicePaymentDetails($pdo,$companyId,$kind,(int)$cl['invoice_id'],$amt);
                        $cl['settlement_account_id']=$details['account_id'];
                        $cl['invoice_number']=$details['invoice_number'];
                        $cl['payment_line_description']=$details['description'];
                        $classified[(int)$r['id']]=$cl;
                    }
                    $deltas[$bankId]=($deltas[$bankId]??0)+($dc==='C'?$amt:-$amt);
                    if($cl['type']==='buy') $deltas[(int)$byCode['1100']['id']]=($deltas[(int)$byCode['1100']['id']]??0)+$amt;
                    elseif($cl['type']==='sell') { $f=$cl['fifo']; $deltas[(int)$byCode['1100']['id']]=($deltas[(int)$byCode['1100']['id']]??0)-$f['cost']; if($f['profit']<0) $deltas[(int)$byCode['5050']['id']]=($deltas[(int)$byCode['5050']['id']]??0)+abs($f['profit']); else $deltas[(int)$byCode['4100']['id']]=($deltas[(int)$byCode['4100']['id']]??0)-$f['profit']; }
                    elseif($cl['type']==='fee') $deltas[$aId]=($deltas[$aId]??0)+$amt;
                    elseif($cl['type']==='income') $deltas[$aId]=($deltas[$aId]??0)-$amt;
                    elseif($cl['type']==='loan') $deltas[$aId]=($deltas[$aId]??0)+($dc==='C'?-1:1)*$amt;
                    elseif($cl['type']==='manual') $deltas[$aId]=($deltas[$aId]??0)+($dc==='D'?$amt:-$amt);
                }
                assertCashAccountsNotFurtherNegative($pdo,$companyId,$deltas);
                $entry=$pdo->prepare("INSERT INTO journal_entries(company_id,entry_date,description,document_number,status,created_by,posted_at) VALUES(?,?,?,?, 'posted', ?, CURRENT_TIMESTAMP)");
                $line=$pdo->prepare("INSERT INTO journal_lines(entry_id,account_id,description,debit,credit) VALUES(?,?,?,?,?)");
                $link=$pdo->prepare("UPDATE bank_statement_lines SET matched_journal_line_id=? WHERE id=? AND company_id=? AND matched_journal_line_id IS NULL AND draft_journal_entry_id IS NULL");
                $salesPaymentInsert=$pdo->prepare('INSERT INTO invoice_payments(invoice_id,payment_date,amount,bank_account_id,journal_entry_id,created_by,notes) VALUES(?,?,?,?,?,?,?)');
                $purchasePaymentInsert=$pdo->prepare('INSERT INTO purchase_invoice_payments(invoice_id,payment_date,amount,bank_account_id,journal_entry_id,created_by,notes) VALUES(?,?,?,?,?,?,?)');
                foreach($locked as $r) {
                    $id=(int)$r['id']; $cl=$classified[$id]; $dc=strtoupper($r['debit_credit']); $amt=cents($r['amount']); $bank=(int)$r['bank_account_id']; $date=$r['transaction_date'];
                    $text=trim(($r['counterparty_name']??'').' '.($r['description']??'')); $text=function_exists('mb_substr')?mb_substr($text,0,450,'UTF-8'):substr($text,0,450);
                    $documentNumber=nextBankDocumentNumber($pdo,$companyId);
                    $entry->execute([$companyId,$date,$cl['label'].($text!==''?' — '.$text:''),$documentNumber,$userId]); $entryId=(int)$pdo->lastInsertId(); $bankLineId=0;
                    $add=static function(int $accountId,int $debit,int $credit,string $desc) use($line,$entryId): int { $line->execute([$entryId,$accountId,$desc,number_format($debit/100,2,'.',''),number_format($credit/100,2,'.','')]); return (int)$GLOBALS['pdo']->lastInsertId(); };
                    if($cl['type']==='buy') { $add($bank,$dc==='C'?$amt:0,$dc==='D'?$amt:0,'Pangatehing'); $add((int)$byCode['1100']['id'],$dc==='D'?$amt:0,$dc==='C'?$amt:0,'Väärtpaberi soetusmaksumus'); }
                    elseif($cl['type']==='sell') { $f=$cl['fifo']; $bankLineId=$add($bank,$amt,0,'Väärtpaberi müügitulu'); $add((int)$byCode['1100']['id'],0,$f['cost'],'FIFO soetusmaksumus'); if($f['profit']>0)$add((int)$byCode['4100']['id'],0,$f['profit'],'Kasum väärtpaberite müügist'); elseif($f['profit']<0)$add((int)$byCode['5050']['id'],abs($f['profit']),0,'Kahjum väärtpaberite müügist'); }
                    elseif($cl['type']==='sales_payment') {
                        $bankLineId=$add($bank,$amt,0,'Laekumine pangakontole');
                        $add((int)$cl['settlement_account_id'],0,$amt,'Ostja võla vähenemine');
                        $salesPaymentInsert->execute([(int)$cl['invoice_id'],$date,number_format($amt/100,2,'.',''),$bank,$entryId,$userId,$text!==''?$text:null]);
                    }
                    elseif($cl['type']==='purchase_payment') {
                        $add((int)$cl['settlement_account_id'],$amt,0,'Tarnijavõla tasumine');
                        $bankLineId=$add($bank,0,$amt,'Makse pangakontolt');
                        $purchasePaymentInsert->execute([(int)$cl['invoice_id'],$date,number_format($amt/100,2,'.',''),$bank,$entryId,$userId,$text!==''?$text:null]);
                    }
                    else { $bankDebit=$dc==='C'?$amt:0; $bankCredit=$dc==='D'?$amt:0; $bankLineId=$add($bank,$bankDebit,$bankCredit,'Pangatehing'); $counterDebit=$dc==='D'?$amt:0; $counterCredit=$dc==='C'?$amt:0; if($cl['type']==='loan'){ $counterDebit=$dc==='D'?$amt:0; $counterCredit=$dc==='C'?$amt:0; } $add((int)$cl['account']['id'],$counterDebit,$counterCredit,$cl['label']); }
                    if(!$bankLineId) { // ostu pangarida on esimene kanne
                        $q=$pdo->prepare('SELECT id FROM journal_lines WHERE entry_id=? AND account_id=? ORDER BY id LIMIT 1'); $q->execute([$entryId,$bank]); $bankLineId=(int)$q->fetchColumn();
                    }
                    $link->execute([$bankLineId,$id,$companyId]); if($link->rowCount()!==1) throw new DomainException('Pangarea sidumine ebaõnnestus; toiming tühistati.');
                }
                $postedCount=count($locked);
                $pdo->commit();
                foreach ($locked as $postedRow) {
                    unset($_SESSION['bank_counter_account_ids'][(int) $postedRow['id']]);
                    unset($_SESSION['bank_invoice_allocations'][(int) $postedRow['id']]);
                }
                $notice='Kinnitati ja pearaamatusse lisati '.$postedCount.' pangatehingut. Lahendamata read jäid ülevaatuseks.';
                header('Location: investment_review.php?done=1'); exit;
            } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); error_log($e->getMessage()); $error=$e instanceof DomainException?$e->getMessage():'Kannete kinnitamine ebaõnnestus; midagi ei salvestatud.'; }
        }
    }
}
if(isset($_GET['done'])) $notice='Valmis read kinnitati ja seoti pearaamatu kannetega. Lahendamata read jäid ülevaatuseks.';
if(isset($_GET['mapped'])) $notice='Vastaspool valiti. Rida lisati valmis kannete hulka.';
if(isset($_GET['rematched'])) $notice='Käsitsi valitud vastaspool eemaldati. Rida hinnati automaatse tuvastuse järgi uuesti.';
$editCounterpartyId=(int)($_GET['edit_counterparty_id']??0);
$readyCount=count(array_filter($classified,static fn($c)=>!empty($c['ready'])));
?>
<!doctype html><html lang="et"><head>
    <link rel="icon" type="image/png" href="favicon.png?v=1"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Pangaväljavõtte kannete ettevalmistus</title><style>
body{max-width:1500px;margin:28px auto;padding:0 20px;background:#f4f7fb;color:#243447;font-family:Arial,sans-serif}h1,h2{color:#17324d}.card{margin:18px 0;padding:20px;border:1px solid #dce3ec;border-radius:12px;background:#fff;box-shadow:0 2px 8px #17324d0a}.muted{color:#667788;line-height:1.5}.button{display:inline-block;padding:11px 17px;border:0;border-radius:7px;background:#1769aa;color:white;font:inherit;font-weight:600;text-decoration:none;cursor:pointer}.button:hover{background:#0f4f83}.find-button{margin-top:7px;padding:7px 10px;background:#a83232;font-size:13px}.find-button:hover{background:#812424}.edit-link{margin-left:8px;color:#1769aa;text-decoration:underline;font-size:13px;font-weight:400;white-space:nowrap}.edit-link:hover{color:#0f4f83}select{max-width:250px;min-height:38px;padding:7px;border:1px solid #b8c4d1;border-radius:6px;background:#fff;font:inherit}.error,.warning,.success{padding:12px 15px;border-radius:8px}.error{border:1px solid #e5a3a3;background:#fff0f0;color:#a32121}.warning{border:1px solid #ead9a2;background:#fff8df;color:#715600}.success{border:1px solid #b8dfc7;background:#eaf7ef;color:#176b3a}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;background:white}th,td{padding:10px;border-bottom:1px solid #e5eaf0;text-align:left;vertical-align:top}th{background:#f3f6fa;color:#526273;white-space:nowrap}.num{text-align:right;white-space:nowrap}.bad{background:#fff4f4}.good{color:#176b3a;font-weight:600}.small{font-size:13px}.actions{display:flex;gap:10px;flex-wrap:wrap}
</style></head><body>
<h1>Pangaväljavõte</h1><p class="muted">Vaata pangatehingute vastaspooli ja FIFO järgi arvutatud väärtpaberitehingute kasumit või kahjumit. Leia puuduvale reale vastaskonto ning kinnita kõik valmis read korraga.</p>
<p><a class="button" href="security_fx.php">Väärtpaberitehingud välisvaluutas</a></p>
<?php if($error!==''):?><p class="error"><?=h($error)?></p><?php endif;?><?php if($notice!==''):?><p class="success"><?=h($notice)?></p><?php endif;?>
<section class="card"><div class="actions"><a class="button" href="bank_import.php">Impordi CSV</a><a class="button" href="bank_statement.php">Kõik pangatehingud</a><a class="button" href="portfelli_algsaldo.php">Portfelli algsaldo</a><a class="button" href="index.php">Avalehele</a></div><p>Sidumata ridu: <strong><?=count($unmatched)?></strong> · valmis kinnitamiseks: <strong><?=$readyCount?></strong> · vajab kontrolli: <strong><?=count($unmatched)-$readyCount?></strong></p>
<p class="warning">Kontrolli enne kinnitamist kõik ettepanekud üle. Müügiarve laekumine ja ostuarve tasumine tuleb siduda vastava kinnitatud arvega, et arve tasumata jääk uueneks. FIFO soetusmaksumuse puudumisel rida ei kinnitata.</p>
<details style="margin:14px 0 20px;border:1px solid #dce3ec;border-radius:9px;background:#fff"><summary style="padding:13px 16px;color:#17324d;font-weight:600;cursor:pointer">Portfelli jääk · <?=count($positions)?> väärtpaberi- ja pangakonto paari</summary><div style="padding:0 16px 16px">
<p class="muted">FIFO järgi alles olev väärtpaberite kogus ja nende teadaolev soetusmaksumus pangakontode kaupa<?= $portfolioAsOf ? ' seisuga ' . h($portfolioAsOf) : '' ?>. Arvestus kasutab portfelli algsaldo ridu ja imporditud pangatehinguid.</p>
<?php if($fifoHasMissingLots): ?><p class="error">Mõne müügi jaoks puudub FIFO algkogus. Selle väärtpaberi jääk võib olla ebatäielik, kuni algsaldo andmed on parandatud.</p><?php endif; ?>
<?php if($positions): ?><div class="table-wrap"><table><thead><tr><th>Pangakonto</th><th>Sümbol</th><th class="num">Kogus portfellis</th><th class="num">FIFO soetusmaksumus</th></tr></thead><tbody><?php foreach($positions as $position): $positionAccount=$accountsById[$position['bank_account_id']]??null; ?><tr><td><?= h($positionAccount ? $positionAccount['code'].' — '.$positionAccount['name'] : 'Kontot ei leitud') ?></td><td><?= h($position['symbol']) ?></td><td class="num"><?= h(quantityText($position['quantity'])) ?></td><td class="num"><?= h(eur($position['cost'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p>Algandmete põhjal avatud väärtpaberipositsioone ei leitud.</p><?php endif; ?>
</div></details>
<?php if($unmatched):?><form method="post" onsubmit="if(event.submitter&&event.submitter.name==='find_counterparty_id')return true;return confirm('Kas kinnitad kõik valmis read, sealhulgas sinu valitud vastaspooled, pearaamatusse?');"><input type="hidden" name="csrf_token" value="<?=h($_SESSION['csrf_token'])?>"><div class="table-wrap"><table><thead><tr><th>Kuupäev</th><th>Pangakonto</th><th>D/C ja summa</th><th>Saaja / selgitus</th><th>Ettepanek</th><th>FIFO tulemus / kontroll</th><th>Olek</th></tr></thead><tbody>
<?php foreach($unmatched as $r): $rowId=(int)$r['id']; $c=$classified[$rowId]; $isEditing=$editCounterpartyId===$rowId; $searchable=($c['type']==='manual')||$isEditing||(!$c['ready'] && in_array($c['type'],['unknown','sell'],true) && (float)$r['service_fee']<=0 && cents((string)$r['amount'])>0 && in_array(strtoupper((string)$r['debit_credit']),['D','C'],true)); $selectedChoice=''; if(isset($invoiceAllocations[$rowId])) $selectedChoice=$invoiceAllocations[$rowId]['kind'].':'.(int)$invoiceAllocations[$rowId]['invoice_id']; elseif(isset($manualAccountIds[$rowId])) $selectedChoice='account:'.(int)$manualAccountIds[$rowId]; elseif($c['type']==='sales_payment') $selectedChoice='sales:'.(int)$c['invoice_id']; elseif($c['type']==='purchase_payment') $selectedChoice='purchase:'.(int)$c['invoice_id']; elseif(isset($c['account']['id'])) $selectedChoice='account:'.(int)$c['account']['id'];?><tr class="<?=$c['ready']?'':'bad'?>"><td><?=h($r['transaction_date'])?></td><td><?=h($r['bank_code'].' — '.$r['bank_name'])?></td><td><?=h($r['debit_credit'])?> · <span class="num"><?=h(eur(cents($r['amount'])))?></span></td><td><?=h((string)$r['counterparty_name'])?><br><span class="small muted"><?=h((string)$r['description'])?></span></td><td><?=h($c['label'])?><?php if(isset($c['account'])):?><br><span class="small muted"><?=h($c['account']['code'].' — '.$c['account']['name'])?></span><?php endif;?></td><td><?=h($c['reason'])?></td><td><?php if($c['ready'] && !$isEditing):?><span class="good"><?=$c['type']==='manual'?'Valmis käsitsi':'Valmis'?></span> <a class="edit-link" href="investment_review.php?edit_counterparty_id=<?=$rowId?>">Muuda</a><?php elseif($searchable):?><select name="counter_choice[<?=$rowId?>]" aria-label="Vastaspool pangatehingule <?=$rowId?>"><?php if($c['type']==='manual' || isset($manualAccountIds[$rowId]) || isset($invoiceAllocations[$rowId])):?><option value="auto">Automaatne tuvastus</option><?php else:?><option value="">Vali vastaspool</option><?php endif;?><?php if(strtoupper((string)$r['debit_credit'])==='C' && $openSalesInvoices):?><optgroup label="Müügiarve laekumine — nõuded ostjate vastu"><?php foreach($openSalesInvoices as $invoice): $value='sales:'.(int)$invoice['id'];?><option value="<?=h($value)?>" <?=$selectedChoice===$value?'selected':''?>><?=h($invoice['invoice_number'].' · '.$invoice['contact_name'].' · tasumata '.eur(cents((string)$invoice['outstanding'])))?></option><?php endforeach;?></optgroup><?php elseif(strtoupper((string)$r['debit_credit'])==='D' && $openPurchaseInvoices):?><optgroup label="Ostuarve tasumine — võlad tarnijatele"><?php foreach($openPurchaseInvoices as $invoice): $value='purchase:'.(int)$invoice['id'];?><option value="<?=h($value)?>" <?=$selectedChoice===$value?'selected':''?>><?=h($invoice['invoice_number'].' · '.$invoice['contact_name'].' · tasumata '.eur(cents((string)$invoice['outstanding'])))?></option><?php endforeach;?></optgroup><?php endif;?><optgroup label="Muu pearaamatukonto"><?php foreach($accounts as $accountOption): if(in_array((string)$accountOption['code'],['1200','2000'],true) || (int)$accountOption['id']===(int)$r['bank_account_id']) continue; $value='account:'.(int)$accountOption['id'];?><option value="<?=h($value)?>" <?=$selectedChoice===$value?'selected':''?>><?=h($accountOption['code'].' — '.$accountOption['name'])?></option><?php endforeach;?></optgroup></select><button class="button find-button" type="submit" name="find_counterparty_id" value="<?=$rowId?>">Salvesta vastaspool</button><?php else:?><span class="error">Vajab kontrolli</span><?php endif;?></td></tr><?php endforeach;?></tbody></table></div><p><button class="button" type="submit" name="confirm_all" value="1" <?=$readyCount===0?'disabled':''?>>Kinnita kõik valmis kanded (<?=$readyCount?>)</button></p></form><?php else:?><p>Praegu pole uusi pangaridu üle vaadata.</p><?php endif;?></section></body></html>
