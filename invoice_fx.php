<?php
require_once __DIR__.'/fx_rates.php';

function ensureInvoiceFxSchema(PDO $pdo): void
{
    foreach (['sales','purchase'] as $kind) {
        $invoice = $kind.'_invoices';
        $lines = $kind.'_invoice_lines';
        $payments = $kind === 'sales' ? 'invoice_payments' : 'purchase_invoice_payments';
        $groups = [
            $invoice => [
                'invoice_currency'=>"CHAR(3) NOT NULL DEFAULT 'EUR'",
                'fx_rate'=>'DECIMAL(19,10) NULL', 'fx_rate_date'=>'DATE NULL', 'fx_event_date'=>'DATE NULL',
                'fx_source'=>'VARCHAR(8) NULL', 'fx_note'=>'VARCHAR(250) NULL',
            ],
            $lines => [
                'fx_unit_price'=>'DECIMAL(19,4) NULL', 'fx_net'=>'DECIMAL(19,2) NULL',
                'fx_vat'=>'DECIMAL(19,2) NULL', 'fx_gross'=>'DECIMAL(19,2) NULL',
            ],
            $payments => ['fx_amount'=>'DECIMAL(19,2) NULL', 'fx_cash_eur'=>'DECIMAL(19,2) NULL'],
        ];
        foreach ($groups as $table => $definitions) {
            $columns = $pdo->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_COLUMN);
            $changes = [];
            foreach ($definitions as $column => $definition) {
                if (!in_array($column,$columns,true)) $changes[] = 'ADD COLUMN '.$column.' '.$definition;
            }
            if ($changes) $pdo->exec('ALTER TABLE '.$table.' '.implode(', ',$changes));
        }
    }
}

function invoiceFxCurrency(array $invoice): string
{
    return $invoice['invoice_currency'] ?? 'EUR';
}

function invoiceFxCurrencies(): array
{
    return explode(' ','EUR USD GBP SEK NOK DKK CHF JPY CAD AUD PLN CZK HUF CNY HKD INR NZD SGD BRL IDR ILS ISK KRW MXN MYR PHP RON THB TRY ZAR');
}

function invoiceFxFormat(string $amount, string $currency, bool $price = false): string
{
    $places = $price ? 4 : (in_array($currency,['JPY','KRW','ISK'],true) ? 0 : 2);
    $value = bcadd($amount,'0',$places);
    [$whole,$fraction] = array_pad(explode('.',$value,2),2,'');
    $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $whole);
    return $whole.($places ? ','.$fraction : '').' '.$currency;
}

function invoiceFxRound(string $amount, int $scale): string
{
    return bcadd($amount, $scale ? '0.'.str_repeat('0',$scale).'5' : '0.5', $scale);
}

/** Originals remain in document currency. The existing amount columns continue to be EUR. */
function invoiceFxPrepare(array $form, string $invoiceDate, ?string $supplyDate, bool $vatRegistered, array &$lines): ?array
{
    $currency = fxCurrency((string)($form['invoice_currency'] ?? 'EUR'));
    if ($currency === 'EUR') return null;
    if (!in_array($currency,invoiceFxCurrencies(),true)) throw new InvalidArgumentException('Vali valuuta arvel pakutavast loendist.');
    foreach ($lines as $line) {
        if (str_starts_with($line['tax_code'],'M_CASH')) {
            throw new InvalidArgumentException('Kassapõhise käibemaksuga välisvaluutaarve vajab eraldi arvestust.');
        }
    }
    $eventDate = fxDate(trim((string)($form['fx_event_date'] ?? '')));
    $usesKmd = $vatRegistered && (bool)array_filter($lines,static fn(array $line): bool =>
        !in_array($line['tax_code'],['O_IMPORT0','O_OUTSIDE_EE','O_EXEMPT'],true));
    if ($usesKmd && substr($eventDate,0,7) !== substr($invoiceDate,0,7)) {
        throw new InvalidArgumentException('Selle välisvaluutaarve käibemaksu sündmus ja arve peavad kuuluma samasse kuusse.');
    }
    if ($usesKmd && $supplyDate && $supplyDate < $eventDate && array_filter($lines,static fn(array $line): bool =>
        $line['tax_code'] !== 'M_EU_GOODS0')) {
        throw new InvalidArgumentException('Kontrolli käibemaksu sündmuse kuupäeva. See on kauba üleandmisest või teenuse osutamisest hilisem.');
    }
    if ($usesKmd && !empty($form['zero_tax_date']) && $form['zero_tax_date'] !== $eventDate) {
        throw new InvalidArgumentException('Nullmääraga käibe kuupäev peab vastama kursi aluseks olevale kuupäevale.');
    }
    $note = trim((string)($form['fx_note'] ?? ''));
    if (($form['fx_source'] ?? '') === 'manual') {
        if ($usesKmd) throw new InvalidArgumentException('KMD-le mineva arve jaoks vali EKP kurss.');
        if ($note === '' || mb_strlen($note)>250) throw new InvalidArgumentException('Lisa käsikursi alus, kuni 250 märki.');
        $quote = ['rate'=>fxDecimal((string)($form['fx_rate'] ?? ''),10,'Kurss'),'date'=>$eventDate,'source'=>'manual'];
    } else {
        $cache = $_SESSION['security_fx_quotes'][$currency.'|'.$eventDate] ?? null;
        $quote = $cache && $cache['expires'] > time() ? $cache['quote'] : fxEcbQuote($currency,$eventDate);
        $note = '';
    }
    $minor = in_array($currency,['JPY','KRW','ISK'],true) ? 0 : 2;
    foreach ($lines as &$line) {
        $net = invoiceFxRound(bcmul($line['quantity'],$line['unit_price'],7),$minor);
        $vat = invoiceFxRound(bcdiv(bcmul($net,$line['vat_rate'],4),'100',6),$minor);
        $line['fx_original'] = [$line['unit_price'],$net,$vat,bcadd($net,$vat,$minor)];
        $netEur = fxEuroCents($net,$quote['rate']);
        $vatEur = fxEuroCents($vat,$quote['rate']);
        if ($usesKmd && abs($vatEur - fxEuroCents(bcdiv(bcmul((string)$netEur,$line['vat_rate'],2),'10000',6))) > 1) {
            throw new InvalidArgumentException('Ühe arverea valuuta ümardus ei klapi eurodes käibemaksumääraga. Kontrolli arverida.');
        }
        $line['unit_price'] = invoiceFxRound(bcdiv($line['unit_price'],$quote['rate'],5),4);
        $line['net_amount'] = bcdiv((string)$netEur,'100',2);
        $line['vat_amount'] = bcdiv((string)$vatEur,'100',2);
        $line['gross_amount'] = bcdiv((string)($netEur+$vatEur),'100',2);
    }
    unset($line);
    if (!array_filter($lines,static fn(array $line): bool => (float)$line['gross_amount'] > 0)) {
        throw new InvalidArgumentException('Arve euroväärtus peab olema vähemalt üks sent.');
    }
    return [$currency,$quote['rate'],$quote['date'],$eventDate,$quote['source'],$note];
}

function invoiceFxSave(PDO $pdo, string $kind, int $id, array $fx): void
{
    $pdo->prepare('UPDATE '.$kind.'_invoices SET invoice_currency=?,fx_rate=?,fx_rate_date=?,fx_event_date=?,fx_source=?,fx_note=?
        WHERE id=?')->execute([...$fx,$id]);
}

function invoiceFxSaveLine(PDO $pdo, string $kind, int $id, array $original): void
{
    $pdo->prepare('UPDATE '.$kind.'_invoice_lines SET fx_unit_price=?,fx_net=?,fx_vat=?,fx_gross=? WHERE id=?')
        ->execute([...$original,$id]);
}

function invoiceFxForeignBalance(array $lines, array $payments): string
{
    $balance = invoiceFxSum($lines,'fx_gross');
    foreach ($payments as $payment) $balance = bcsub($balance,(string)$payment['fx_amount'],2);
    return $balance;
}

function invoiceFxSum(array $lines, string $key): string
{
    $sum = '0.00';
    foreach ($lines as $line) $sum = bcadd($sum,(string)$line[$key],2);
    return $sum;
}

function invoiceFxPaymentStep(string $currency): string
{
    return in_array($currency,['JPY','KRW','ISK'],true) ? '1' : '0.01';
}

function invoiceFxPayment(PDO $pdo, string $kind, array $invoice, array $form, int $outstanding): array
{
    $currency = invoiceFxCurrency($invoice);
    $places = in_array($currency,['JPY','KRW','ISK'],true) ? 0 : 2;
    $foreign = fxDecimal((string)($form['fx_amount'] ?? ''),2,'Tasumine arve valuutas');
    if ($places === 0 && bccomp($foreign,bcadd($foreign,'0',0),2)!==0) {
        throw new InvalidArgumentException($currency.' summa peab olema täisarv.');
    }
    $cash = fxEuroCents(fxDecimal((string)($form['fx_cash_eur'] ?? ''),2,'Rahakande summa eurodes'));
    $table = $kind === 'sales' ? 'invoice_payments' : 'purchase_invoice_payments';
    $query = $pdo->prepare("SELECT
        (SELECT SUM(fx_gross) FROM ".$kind."_invoice_lines WHERE invoice_id=?)
        - COALESCE(SUM(fx_amount),0) AS remaining,
        COUNT(*)-COUNT(fx_amount) AS incomplete
        FROM $table WHERE invoice_id=?");
    $query->execute([$invoice['id'],$invoice['id']]);
    $record = $query->fetch(PDO::FETCH_ASSOC);
    $remaining = (string)($record['remaining'] ?? '0');
    if ((int)$record['incomplete'] !== 0) throw new RuntimeException('Varasema makse valuutasumma puudub.');
    if ($outstanding <= 0 || bccomp($foreign,$remaining,2)>0) {
        throw new RuntimeException('Makse ületab tasumata jääki ('.invoiceFxFormat($remaining,$currency).').');
    }
    $basis = bccomp($foreign,$remaining,2) === 0 ? $outstanding
        : (int)invoiceFxRound(bcdiv(bcmul((string)$outstanding,$foreign,2),$remaining,1),0);
    if ($basis <= 0 || (bccomp($foreign,$remaining,2)<0 && $basis >= $outstanding)) {
        throw new RuntimeException('Osamakse on arve eurojäägi suhtes ümardamiseks liiga väike või suur.');
    }
    return ['foreign'=>$foreign,'cash'=>$cash,'basis'=>$basis,'table'=>$table];
}

function invoiceFxPostPaymentDifference(PDO $pdo, PDOStatement $lineStmt, int $company, int $entry, int $gain): void
{
    if ($gain === 0) return;
    $code = $gain > 0 ? '4160' : '5060';
    $query = $pdo->prepare('SELECT id FROM accounts WHERE company_id=? AND code=? AND account_type=? AND is_active=1');
    $query->execute([$company,$code,$gain>0?'income':'expense']);
    $account = $query->fetchColumn();
    if (!$account) throw new RuntimeException('Kursivahe kirjendamiseks lisa aktiivne konto '.$code.'.');
    $lineStmt->execute([
        'entry_id'=>$entry,'account_id'=>$account,'description'=>$gain>0?'Valuutakursi kasum':'Valuutakursi kahjum',
        'debit'=>bcdiv((string)max(0,-$gain),'100',2),'credit'=>bcdiv((string)max(0,$gain),'100',2),
    ]);
}

function invoiceFxSavePayment(PDO $pdo, int $id, array $payment): void
{
    $pdo->prepare('UPDATE '.$payment['table'].' SET fx_amount=?,fx_cash_eur=? WHERE id=?')
        ->execute([$payment['foreign'],bcdiv((string)$payment['cash'],'100',2),$id]);
}
