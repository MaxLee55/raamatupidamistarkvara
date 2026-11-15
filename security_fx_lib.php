<?php
require_once __DIR__.'/fx_rates.php';
require_once __DIR__.'/period_lock.php';
require_once __DIR__.'/cash_balance_guard.php';
require_once __DIR__.'/journal_draft_lib.php';

function ensureSecurityFxSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS security_fx_trades (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        company_id INT UNSIGNED NOT NULL,
        portfolio VARCHAR(60) NOT NULL,
        instrument VARCHAR(40) NOT NULL,
        instrument_name VARCHAR(120) NOT NULL,
        instrument_type VARCHAR(24) NOT NULL,
        broker_reference VARCHAR(80) NOT NULL,
        side ENUM('buy','sell') NOT NULL,
        trade_date DATE NOT NULL,
        settlement_date DATE NOT NULL,
        currency CHAR(3) NOT NULL,
        quantity DECIMAL(15,6) NOT NULL,
        gross_amount DECIMAL(17,8) NOT NULL,
        fee_amount DECIMAL(17,8) NOT NULL,
        capitalized_fee TINYINT NOT NULL,
        trade_rate DECIMAL(19,10) NOT NULL,
        trade_rate_date DATE NOT NULL,
        trade_rate_source VARCHAR(8) NOT NULL,
        settlement_rate DECIMAL(19,10) NOT NULL,
        settlement_rate_date DATE NOT NULL,
        settlement_rate_source VARCHAR(8) NOT NULL,
        rate_note VARCHAR(250) NOT NULL,
        gross_cents BIGINT NOT NULL,
        fee_cents BIGINT NOT NULL,
        cost_cents BIGINT NOT NULL,
        result_cents BIGINT NOT NULL,
        cash_book_cents BIGINT NOT NULL,
        fx_result_cents BIGINT NOT NULL,
        security_account_id INT UNSIGNED NOT NULL,
        cash_account_id INT UNSIGNED NOT NULL,
        trade_entry_id INT UNSIGNED NOT NULL,
        settlement_entry_id INT UNSIGNED NULL,
        remaining_quantity DECIMAL(15,6) NOT NULL DEFAULT 0,
        remaining_cost_cents BIGINT NOT NULL DEFAULT 0,
        consumed_lots JSON NOT NULL,
        created_by INT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        voided_at TIMESTAMP NULL,
        void_trade_entry_id INT UNSIGNED NULL,
        void_settlement_entry_id INT UNSIGNED NULL,
        UNIQUE KEY trade_reference(company_id,portfolio,broker_reference),
        KEY position_history(company_id,security_account_id,portfolio,instrument,trade_date,id),
        KEY cash_currency(company_id,cash_account_id),
        KEY trade_entry(company_id,trade_entry_id),
        KEY settlement_entry(company_id,settlement_entry_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function securityFxTypes(): array
{
    return ['stock'=>'Aktsia','etf'=>'ETF','fund'=>'Fondiosak','bond'=>'Võlakiri',
        'note'=>'Sertifikaat või muu noteeritud võlakiri','other'=>'Muu väärtpaber'];
}

function securityFxText(string $value, int $max, string $label, bool $required = true): string
{
    $value = trim($value);
    if (($required && $value === '') || mb_strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) {
        throw new InvalidArgumentException($label.': kontrolli teksti pikkust ja sisu.');
    }
    return $value;
}

function securityFxLockCompany(PDO $pdo, int $company): void
{
    $lock = $pdo->prepare('SELECT id FROM companies WHERE id=? FOR UPDATE');
    $lock->execute([$company]);
    if (!$lock->fetchColumn()) throw new RuntimeException('Ettevõtet ei leitud.');
}

function securityFxPosition(PDO $pdo, int $company, int $account, string $portfolio, string $instrument): array
{
    $query = $pdo->prepare('SELECT id, trade_date, instrument_type, remaining_quantity, remaining_cost_cents
        FROM security_fx_trades WHERE company_id=? AND security_account_id=? AND portfolio=? AND instrument=?
        AND voided_at IS NULL ORDER BY trade_date,id FOR UPDATE');
    $query->execute([$company,$account,$portfolio,$instrument]);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

function securityFxMoney(int $cents): string
{
    return bcdiv((string)$cents, '100', 2);
}

function securityFxPost(PDO $pdo, int $company, int $user, string $date, string $document, string $description, array $lines, ?int $corrects = null): int
{
    $balance = 0;
    foreach ($lines as [, $amount]) $balance += $amount;
    if ($balance !== 0) throw new LogicException('Väärtpaberikande deebet ja kreedit ei klapi.');
    $query = $pdo->prepare("INSERT INTO journal_entries
        (company_id,entry_date,document_number,description,status,created_by,posted_at,corrects_entry_id)
        VALUES (?,?,?,?,'posted',?,CURRENT_TIMESTAMP,?)");
    $query->execute([$company,$date,$document,$description,$user,$corrects]);
    $entry = (int)$pdo->lastInsertId();
    $query = $pdo->prepare('INSERT INTO journal_lines (entry_id,account_id,description,debit,credit) VALUES (?,?,?,?,?)');
    foreach ($lines as [$account, $amount, $label]) {
        if ($amount !== 0) $query->execute([$entry,$account,$label,securityFxMoney(max(0,$amount)),securityFxMoney(max(0,-$amount))]);
    }
    return $entry;
}

function securityFxAccount(array $accounts, mixed $value, string $type, string $label): int
{
    $id = (int)$value;
    if (($accounts[$id]['account_type'] ?? '') !== $type) throw new InvalidArgumentException('Vali aktiivne konto väljale „'.$label.'”.');
    return $id;
}

function securityFxRate(array $quote, string $date, string $currency): array
{
    if ($currency === 'EUR') return ['rate'=>'1', 'date'=>$date, 'source'=>'eur'];
    $source = $quote['source'] ?? '';
    $quoteDate = fxDate((string)($quote['date'] ?? ''));
    if (!in_array($source,['ecb','manual'],true) || $quoteDate > $date
        || ($source === 'ecb' && $quoteDate < (new DateTimeImmutable($date))->modify('-7 days')->format('Y-m-d'))) {
        throw new InvalidArgumentException('Vali tehingu kuupäevale sobiv kurss.');
    }
    return ['rate'=>fxDecimal((string)($quote['rate'] ?? ''),10,'Valuutakurss'), 'date'=>$quoteDate, 'source'=>$source];
}

function createSecurityFxTrade(PDO $pdo, int $company, int $user, array $form, array $tradeQuote, array $settlementQuote): int
{
    $side = $form['side'] ?? '';
    if (!in_array($side,['buy','sell'],true)) throw new InvalidArgumentException('Vali ost või müük.');
    $buy = $side === 'buy';
    $portfolio = securityFxText((string)($form['portfolio'] ?? ''),60,'Maaklerikonto');
    $instrument = strtoupper(securityFxText((string)($form['instrument'] ?? ''),40,'Väärtpaberi tunnus'));
    $name = securityFxText((string)($form['instrument_name'] ?? ''),120,'Väärtpaberi nimi');
    $type = $form['instrument_type'] ?? '';
    if (!isset(securityFxTypes()[$type])) throw new InvalidArgumentException('Vali väärtpaberi liik.');
    $reference = securityFxText((string)($form['broker_reference'] ?? ''),80,'Maakleri tehinguviide');
    $date = fxDate((string)($form['trade_date'] ?? ''));
    $settlement = fxDate((string)(($form['settlement_date'] ?? '') ?: $date));
    if ($settlement < $date) throw new InvalidArgumentException('Arvelduspäev ei saa olla tehingupäevast varasem.');
    $separate = $settlement !== $date;
    $currency = fxCurrency((string)($form['currency'] ?? ''));
    $quantity = fxDecimal((string)($form['quantity'] ?? ''),6,'Kogus');
    $gross = fxDecimal((string)($form['gross_amount'] ?? ''),8,'Tehingusumma');
    $fee = fxDecimal((string)(($form['fee_amount'] ?? '') ?: '0'),8,'Teenustasu',true);
    if (!$buy && bccomp($fee,$gross,8) >= 0) throw new InvalidArgumentException('Müügi teenustasu peab jääma alla müügisumma.');
    $capital = $buy && ($form['fee_mode'] ?? '') === 'capital';
    $tradeRate = securityFxRate($tradeQuote,$date,$currency);
    $settlementRate = $separate ? securityFxRate($settlementQuote,$settlement,$currency) : $tradeRate;
    $note = securityFxText((string)($form['rate_note'] ?? ''),250,'Kursi või rahajäägi alus',false);
    $bookInput = trim((string)($form['cash_book'] ?? ''));
    if ($bookInput !== '' && (!$buy || $currency === 'EUR')) {
        throw new InvalidArgumentException('Raha bilansilist euromaksumust saab määrata välisvaluutas ostule.');
    }
    if (($tradeRate['source'] === 'manual' || $settlementRate['source'] === 'manual' || $bookInput !== '') && $note === '') {
        throw new InvalidArgumentException('Lisa käsikursi või valuuta bilansilise euromaksumuse alus.');
    }
    $grossCents = fxEuroCents($gross,$tradeRate['rate']);
    $feeCents = fxEuroCents($fee,$tradeRate['rate']);
    $net = $buy ? $grossCents + $feeCents : $grossCents - $feeCents;
    $settled = fxEuroCents($gross,$settlementRate['rate'])
        + ($buy ? 1 : -1) * fxEuroCents($fee,$settlementRate['rate']);
    if ($grossCents < 1 || $net < 1 || $settled < 1) throw new InvalidArgumentException('Tehingu ja arvelduse euroväärtus peab olema vähemalt üks sent.');
    $cashBook = $bookInput === '' ? $settled : fxEuroCents(fxDecimal($bookInput,2,'Raha bilansiline euromaksumus'));
    $settlementFx = ($buy ? 1 : -1) * ($net - $settled);
    $cashFx = $buy ? $settled - $cashBook : 0;

    $pdo->beginTransaction();
    try {
        securityFxLockCompany($pdo,$company);
        foreach (array_unique([$date,$settlement]) as $day) {
            if ($message = closedAccountingPeriodMessage($pdo,$company,$day)) throw new RuntimeException($message);
        }
        $query = $pdo->prepare('SELECT id,code,account_type FROM accounts WHERE company_id=? AND is_active=1 ORDER BY id FOR UPDATE');
        $query->execute([$company]);
        $accounts = $query->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        $asset = securityFxAccount($accounts,$form['security_account'] ?? 0,'asset','Väärtpaberikonto');
        $cash = securityFxAccount($accounts,$form['cash_account'] ?? 0,'asset','Maakleri rahakonto');
        if (!preg_match('/^10\d{2}$/D',$accounts[$cash]['code']) || $asset === $cash || preg_match('/^10\d{2}$/D',$accounts[$asset]['code'])) {
            throw new InvalidArgumentException('Vali maakleri rahakontoks 10xx konto ja väärtpaberitele eraldi varakonto.');
        }
        $clearing = $separate ? securityFxAccount($accounts,$form['clearing_account'] ?? 0,$buy ? 'liability' : 'asset','Arvelduste vahekonto') : null;
        if ($clearing !== null && (in_array($clearing,[$asset,$cash],true) || preg_match('/^10\d{2}$/D',$accounts[$clearing]['code']))) {
            throw new InvalidArgumentException('Arvelduste vahekonto peab erinema väärtpaberi- ja rahakontost.');
        }
        $query = $pdo->prepare('SELECT currency FROM security_fx_trades WHERE company_id=? AND cash_account_id=? AND voided_at IS NULL LIMIT 1');
        $query->execute([$company,$cash]);
        $usedCurrency = $query->fetchColumn();
        if ($usedCurrency && $usedCurrency !== $currency) throw new InvalidArgumentException('Selle rahakonto tehingud on valuutas '.$usedCurrency.'. Vali '.$currency.' jaoks eraldi konto.');
        $query = $pdo->prepare('SELECT id FROM security_fx_trades WHERE company_id=? AND portfolio=? AND broker_reference=?');
        $query->execute([$company,$portfolio,$reference]);
        if ($query->fetchColumn()) throw new InvalidArgumentException('See maakleri tehinguviide on selle konto jaoks juba kasutatud.');

        $history = securityFxPosition($pdo,$company,$asset,$portfolio,$instrument);
        $latest = $history ? $history[array_key_last($history)] : null;
        if ($latest && ($latest['trade_date'] > $date || $latest['instrument_type'] !== $type)) {
            throw new InvalidArgumentException('Sisesta selle väärtpaberi tehingud kuupäeva järjekorras ja kasuta sama väärtpaberiliiki.');
        }
        $cost = $buy ? $grossCents + ($capital ? $feeCents : 0) : 0;
        $consumed = [];
        $update = $pdo->prepare('UPDATE security_fx_trades SET remaining_quantity=?,remaining_cost_cents=? WHERE company_id=? AND id=?');
        if (!$buy) {
            $remaining = $quantity;
            foreach ($history as $lot) {
                $available = $lot['remaining_quantity'];
                if (bccomp($remaining,'0',6) === 0) break;
                if (bccomp($available,'0',6) === 0) continue;
                $all = bccomp($remaining,$available,6) >= 0;
                $take = $all ? $available : $remaining;
                $partCost = $all ? (int)$lot['remaining_cost_cents']
                    : (int)bcdiv(bcmul((string)$lot['remaining_cost_cents'],$take,6),$available,0);
                $remaining = bcsub($remaining,$take,6);
                $cost += $partCost;
                $consumed[] = ['id'=>(int)$lot['id'],'quantity'=>$take,'cost'=>$partCost];
                $update->execute([bcsub($available,$take,6),(int)$lot['remaining_cost_cents']-$partCost,$company,$lot['id']]);
            }
            if (bccomp($remaining,'0',6) > 0) throw new InvalidArgumentException('Müügiks ei jätku selle maaklerikonto FIFO ostupartiisid.');
        }
        $result = $buy ? 0 : $net - $cost;
        $gain = fn(mixed $value, string $label): int => securityFxAccount($accounts,$value,'income',$label);
        $loss = fn(mixed $value, string $label): int => securityFxAccount($accounts,$value,'expense',$label);
        $tradeLines = [[$asset,$buy ? $cost : -$cost,$buy ? 'Väärtpaberi soetus' : 'FIFO soetusmaksumus']];
        $tradeLines[] = [$clearing ?? $cash,$buy ? -($separate ? $net : $cashBook) : $net,$separate ? 'Arveldamata tehing' : 'Maakleri rahaliikumine'];
        if ($buy && !$capital && $feeCents) {
            $tradeLines[] = [$loss($form['fee_account'] ?? 0,'Teenustasukonto'),$feeCents,'Maakleri teenustasu'];
        }
        if ($result !== 0) {
            $resultAccount = $result > 0 ? $gain($form['gain_account'] ?? 0,'Müügikasumi konto') : $loss($form['loss_account'] ?? 0,'Müügikahjumi konto');
            $tradeLines[] = [$resultAccount,-$result,'Väärtpaberi müügitulemus pärast teenustasu'];
        }
        $settleLines = $separate ? [
            [$clearing,$buy ? $net : -$net,'Arveldamata tehingu sulgemine'],
            [$cash,$buy ? -$cashBook : $settled,'Maakleri rahaliikumine'],
        ] : [];
        $fxLines = [];
        foreach (['Arvelduse kursivahe'=>$settlementFx,'Valuutaraha kursivahe'=>$cashFx] as $label=>$difference) {
            if ($difference === 0) continue;
            $account = $difference > 0 ? $gain(($form['fx_gain_account'] ?? 0) ?: ($form['gain_account'] ?? 0),'Kursikasumi konto')
                : $loss(($form['fx_loss_account'] ?? 0) ?: ($form['loss_account'] ?? 0),'Kursikahjumi konto');
            $fxLines[] = [$account,-$difference,$label];
        }
        if ($separate) $settleLines = array_merge($settleLines,$fxLines);
        else $tradeLines = array_merge($tradeLines,$fxLines);
        assertCashAccountsNotFurtherNegative($pdo,$company,[$cash=>$buy ? -$cashBook : $settled,$asset=>$buy ? $cost : -$cost]);
        $document = 'VP-'.substr(hash('sha256',$company.'|'.$portfolio.'|'.$reference),0,16);
        $title = ($buy ? 'Väärtpaberi ost ' : 'Väärtpaberi müük ').$instrument;
        $entry = securityFxPost($pdo,$company,$user,$date,$document,$title,$tradeLines);
        $settlementEntry = $separate ? securityFxPost($pdo,$company,$user,$settlement,$document.'-A','Arveldus · '.$title,$settleLines) : null;
        $record = [
            'company_id'=>$company,'portfolio'=>$portfolio,'instrument'=>$instrument,'instrument_name'=>$name,'instrument_type'=>$type,
            'broker_reference'=>$reference,'side'=>$side,'trade_date'=>$date,'settlement_date'=>$settlement,'currency'=>$currency,
            'quantity'=>$quantity,'gross_amount'=>$gross,'fee_amount'=>$fee,'capitalized_fee'=>(int)$capital,
            'trade_rate'=>$tradeRate['rate'],'trade_rate_date'=>$tradeRate['date'],'trade_rate_source'=>$tradeRate['source'],
            'settlement_rate'=>$settlementRate['rate'],'settlement_rate_date'=>$settlementRate['date'],'settlement_rate_source'=>$settlementRate['source'],
            'rate_note'=>$note,'gross_cents'=>$grossCents,'fee_cents'=>$feeCents,'cost_cents'=>$cost,'result_cents'=>$result,
            'cash_book_cents'=>$cashBook,'fx_result_cents'=>$settlementFx+$cashFx,'security_account_id'=>$asset,'cash_account_id'=>$cash,
            'trade_entry_id'=>$entry,'settlement_entry_id'=>$settlementEntry,'remaining_quantity'=>$buy ? $quantity : '0',
            'remaining_cost_cents'=>$buy ? $cost : 0,'consumed_lots'=>json_encode($consumed,JSON_THROW_ON_ERROR),'created_by'=>$user,
        ];
        $pdo->prepare('INSERT INTO security_fx_trades ('.implode(',',array_keys($record)).') VALUES ('
            .implode(',',array_fill(0,count($record),'?')).')')->execute(array_values($record));
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        return $id;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}

function voidSecurityFxTrade(PDO $pdo, int $company, int $user, int $id): void
{
    $pdo->beginTransaction();
    try {
        securityFxLockCompany($pdo,$company);
        $query = $pdo->prepare('SELECT * FROM security_fx_trades WHERE company_id=? AND id=? AND voided_at IS NULL FOR UPDATE');
        $query->execute([$company,$id]);
        $trade = $query->fetch();
        if (!$trade) throw new InvalidArgumentException('Aktiivset tehingut ei leitud.');
        $history = securityFxPosition($pdo,$company,(int)$trade['security_account_id'],$trade['portfolio'],$trade['instrument']);
        if ((int)$history[array_key_last($history)]['id'] !== $id) {
            throw new InvalidArgumentException('Tühista esmalt selle positsiooni hilisemad tehingud.');
        }
        $entryIds = array_filter([$trade['trade_entry_id'],$trade['settlement_entry_id']]);
        $entries = [];
        $deltas = [];
        foreach ($entryIds as $entryId) {
            $query = $pdo->prepare("SELECT entry_date,document_number,description FROM journal_entries
                WHERE company_id=? AND id=? AND status='posted' FOR UPDATE");
            $query->execute([$company,$entryId]);
            $entry = $query->fetch();
            if (!$entry) throw new RuntimeException('Tehinguga seotud pearaamatukannet ei leitud.');
            if ($message = closedAccountingPeriodMessage($pdo,$company,$entry['entry_date'])) throw new RuntimeException($message);
            $query = $pdo->prepare('SELECT account_id,description,debit,credit FROM journal_lines WHERE entry_id=? ORDER BY id');
            $query->execute([$entryId]);
            $lines = [];
            foreach ($query->fetchAll() as $line) {
                $amount = fxEuroCents($line['credit']) - fxEuroCents($line['debit']);
                $account = (int)$line['account_id'];
                $lines[] = [$account,$amount,$line['description']];
                $deltas[$account] = ($deltas[$account] ?? 0) + $amount;
            }
            if (count($lines) < 2) throw new RuntimeException('Seotud kande read puuduvad.');
            $entries[] = [$entryId,$entry,$lines];
        }
        assertCashAccountsNotFurtherNegative($pdo,$company,$deltas);
        $reversals = [];
        foreach ($entries as [$entryId,$entry,$lines]) {
            $reversals[] = securityFxPost($pdo,$company,$user,$entry['entry_date'],$entry['document_number'].'-T','Tühistus · '.$entry['description'],$lines,(int)$entryId);
        }
        $restore = $pdo->prepare('UPDATE security_fx_trades SET remaining_quantity=remaining_quantity+?,
            remaining_cost_cents=remaining_cost_cents+? WHERE company_id=? AND id=? AND side=\'buy\' AND voided_at IS NULL');
        foreach (json_decode($trade['consumed_lots'],true,512,JSON_THROW_ON_ERROR) as $lot) {
            $restore->execute([$lot['quantity'],$lot['cost'],$company,$lot['id']]);
            if ($restore->rowCount() !== 1) throw new RuntimeException('FIFO ostupartii taastamine ebaõnnestus.');
        }
        $pdo->prepare('UPDATE security_fx_trades SET voided_at=CURRENT_TIMESTAMP,void_trade_entry_id=?,void_settlement_entry_id=?
            WHERE company_id=? AND id=?')->execute([$reversals[0],$reversals[1] ?? null,$company,$id]);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}
