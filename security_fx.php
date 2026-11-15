<?php
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
require_once __DIR__.'/security_fx_lib.php';

function securityFxHtml(mixed $value): string
{
    return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}

function securityFxSessionQuote(string $currency, string $date): array
{
    $key = $currency.'|'.$date;
    $cached = $_SESSION['security_fx_quotes'][$key] ?? null;
    if ($cached && $cached['expires'] > time()) return $cached['quote'];
    $quote = fxEcbQuote($currency,$date);
    $_SESSION['security_fx_quotes'][$key] = ['quote'=>$quote,'expires'=>time()+1800];
    $_SESSION['security_fx_quotes'] = array_slice($_SESSION['security_fx_quotes'],-24,null,true);
    return $quote;
}

function securityFxFormQuote(array $form, string $prefix, string $currency, string $date): array
{
    if ($currency === 'EUR') return ['rate'=>'1','date'=>$date,'source'=>'eur'];
    if (($form[$prefix.'_source'] ?? '') === 'manual') {
        return ['rate'=>(string)($form[$prefix.'_rate'] ?? ''),'date'=>$date,'source'=>'manual'];
    }
    return securityFxSessionQuote($currency,$date);
}

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && !hash_equals($_SESSION['csrf_token'],(string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Vorm aegus. Ava leht uuesti.');
}
if ($post && ($_POST['action'] ?? '') === 'quote') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $currency = fxCurrency((string)($_POST['currency'] ?? ''));
        $date = fxDate((string)($_POST['date'] ?? ''));
        $amount = isset($_POST['amount']) ? fxDecimal((string)$_POST['amount'],2,'Valuutasumma') : null;
        $quote = securityFxSessionQuote($currency,$date);
        if ($amount !== null) {
            $quote['euro_amount'] = securityFxMoney(fxEuroCents($amount,$quote['rate']));
        }
        echo json_encode($quote,JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        http_response_code(422);
        echo json_encode(['error'=>$error->getMessage()],JSON_THROW_ON_ERROR);
    }
    exit;
}

require_once __DIR__.'/db.php';
$company = (int)$_SESSION['company_id'];
$user = (int)$_SESSION['user_id'];
ensureSecurityFxSchema($pdo);
ensureJournalCorrectionSchema($pdo);
$error = '';
$form = [];
if ($post) {
    try {
        if (($_POST['action'] ?? '') === 'void') {
            voidSecurityFxTrade($pdo,$company,$user,(int)($_POST['id'] ?? 0));
            header('Location: security_fx.php?voided=1');
            exit;
        }
        if (($_POST['action'] ?? '') !== 'create') throw new InvalidArgumentException('Vigane toiming.');
        $form = $_POST;
        if (($form['currency'] ?? '') === 'other') $form['currency'] = $form['other_currency'] ?? '';
        $currency = fxCurrency((string)($form['currency'] ?? ''));
        $date = fxDate((string)($form['trade_date'] ?? ''));
        $settlement = fxDate((string)(($form['settlement_date'] ?? '') ?: $date));
        $tradeQuote = securityFxFormQuote($form,'trade',$currency,$date);
        $settlementQuote = $settlement === $date ? $tradeQuote : securityFxFormQuote($form,'settlement',$currency,$settlement);
        $id = createSecurityFxTrade($pdo,$company,$user,$form,$tradeQuote,$settlementQuote);
        header('Location: security_fx.php?created='.$id.'#tehingud');
        exit;
    } catch (Throwable $exception) {
        if ($exception instanceof PDOException) {
            error_log($exception->getMessage());
            $error = 'Tehingut ei salvestatud. Kontrolli kontosid ja proovi uuesti.';
        } else {
            $error = $exception->getMessage();
        }
    }
}
$query = $pdo->prepare('SELECT id,code,name,account_type FROM accounts WHERE company_id=? AND is_active=1 ORDER BY code');
$query->execute([$company]);
$accounts = $query->fetchAll(PDO::FETCH_ASSOC);
$query = $pdo->prepare('SELECT * FROM security_fx_trades WHERE company_id=? ORDER BY trade_date DESC,id DESC LIMIT 200');
$query->execute([$company]);
$trades = $query->fetchAll(PDO::FETCH_ASSOC);
$query = $pdo->prepare('SELECT portfolio,instrument,MAX(instrument_name) AS name, MAX(instrument_type) AS kind,security_account_id,
        SUM(remaining_quantity) AS quantity,SUM(remaining_cost_cents) AS cost
    FROM security_fx_trades WHERE company_id=? AND side=\'buy\' AND voided_at IS NULL AND remaining_quantity>0
    GROUP BY portfolio,instrument,security_account_id ORDER BY portfolio,instrument');
$query->execute([$company]);
$positions = $query->fetchAll(PDO::FETCH_ASSOC);
$query = $pdo->prepare('SELECT MAX(id) FROM security_fx_trades WHERE company_id=? AND voided_at IS NULL
    GROUP BY portfolio,instrument,security_account_id');
$query->execute([$company]);
$lastIds = array_fill_keys($query->fetchAll(PDO::FETCH_COLUMN),true);
$defaults = array_column($accounts,'id','code');
$currency = strtoupper(trim((string)($form['currency'] ?? 'USD')));
$currencies = [
    'USD' => 'USA dollar', 'EUR' => 'Euro', 'GBP' => 'Suurbritannia nael', 'JPY' => 'Jaapani jeen',
    'SEK' => 'Rootsi kroon', 'NOK' => 'Norra kroon', 'DKK' => 'Taani kroon', 'CHF' => 'Šveitsi frank',
    'AUD' => 'Austraalia dollar', 'BRL' => 'Brasiilia reaal', 'CAD' => 'Kanada dollar', 'CNY' => 'Hiina jüaan',
    'CZK' => 'Tšehhi kroon', 'HKD' => 'Hongkongi dollar', 'HUF' => 'Ungari forint', 'IDR' => 'Indoneesia ruupia',
    'ILS' => 'Iisraeli seekel', 'INR' => 'India ruupia', 'ISK' => 'Islandi kroon', 'KRW' => 'Lõuna-Korea vonn',
    'MXN' => 'Mehhiko peeso', 'MYR' => 'Malaisia ringgit', 'NZD' => 'Uus-Meremaa dollar', 'PHP' => 'Filipiinide peeso',
    'PLN' => 'Poola zlott', 'RON' => 'Rumeenia leu', 'SGD' => 'Singapuri dollar', 'THB' => 'Tai baat',
    'TRY' => 'Türgi liir', 'ZAR' => 'Lõuna-Aafrika rand',
];
$otherCurrency = !isset($currencies[$currency]);
$isUsd = $currency === 'USD';
$clearingCode = ($form['side'] ?? '') === 'sell' ? '1210' : '2020';
$escape = 'securityFxHtml';
$value = static fn(string $key, mixed $fallback = ''): string => securityFxHtml($form[$key] ?? $fallback);
$num = static fn(string $number): string => str_replace('.',',',rtrim(rtrim($number,'0'),'.')) ?: '0';
$euro = static fn(mixed $cents): string => str_replace('.',',',securityFxMoney((int)$cents)).' €';
$select = static function (string $name, array $types, callable $filter, mixed $default = '') use ($accounts,$form): void {
    $current = (string)($form[$name] ?? $default);
    echo '<select name="'.securityFxHtml($name).'"><option value="">Vali konto</option>';
    foreach ($accounts as $account) {
        if (!in_array($account['account_type'],$types,true) || !$filter($account)) continue;
        $id = (string)$account['id'];
        echo '<option value="'.securityFxHtml($id).'"'.($current===$id?' selected':'').'>'
            .securityFxHtml($account['code'].' — '.$account['name']).'</option>';
    }
    echo '</select>';
};
$any = static fn(array $account): bool => true;
$isCash = static fn(array $account): bool => (bool)preg_match('/^10\d{2}$/D',$account['code']);
$nonCash = static fn(array $account): bool => !$isCash($account);
?>
<!doctype html>
<html lang="et"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Väärtpaberitehingud</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f2f6fa;color:#17324d;font:15px system-ui,sans-serif}main{max-width:1680px;margin:auto;padding:24px clamp(12px,2vw,24px)}
h1,h2{margin:0 0 12px}a{color:#12649a}p{margin:8px 0 16px}.muted,small{color:#526980}
.card{background:white;border:1px solid #d8e3ee;border-radius:12px;padding:20px;margin:16px 0}.top{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px 16px;margin:12px 0}label{display:block;font-weight:600}
.grid [hidden]{display:none}
input,select{display:block;width:100%;border:1px solid #aebfd1;border-radius:7px;padding:8px;color:#17324d;background:#fff;margin-top:4px;font:inherit}
button{padding:8px 13px;border:1px solid #135e94;border-radius:7px;background:#12669e;color:#fff;font:600 14px system-ui;cursor:pointer}.secondary{background:#fff;color:#12669e}
button:disabled{opacity:.6}.error,.success{border-radius:8px;padding:10px 14px;background:#fce8e8;color:#95231d}.success{background:#e7f5eb;color:#176136}
summary{cursor:pointer;font-weight:650;margin:8px 0}.table{overflow-x:auto}table{width:100%;border-collapse:collapse;white-space:nowrap}th,td{text-align:left;padding:8px;border-bottom:1px solid #dde7f0}
th{background:#edf3f8}.num{text-align:right}.inline{display:inline}.rate-status{display:block;min-height:1.4em;margin-top:6px}
</style></head><body><main>
<div class="top"><div><h1>Väärtpaberitehingud</h1><p class="muted">Ost ja müük maakleri väljavõtte alusel. Pearaamatukanded on eurodes.</p></div>
<nav><a href="index.php">Avaleht</a> · <a href="investment_review.php">Pangaväljavõte</a> · <a href="manual_document.php?id=security-fx">Kasutusjuhend</a></nav></div>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= $escape($error) ?></p><?php endif; ?>
<?php if (isset($_GET['created'])): ?><p class="success">Väärtpaberitehing salvestatud ja kirjendatud.</p><?php elseif (isset($_GET['voided'])): ?><p class="success">Tehing tühistatud ja FIFO jääk taastatud.</p><?php endif; ?>
<section class="card"><h2>Uus tehing</h2>
<form method="post" id="trade-form" onsubmit="return confirm('Kas kinnitad väärtpaberitehingu ja pearaamatukanded?')">
<input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="create">
<div class="grid">
<label>Tehing<select name="side"><option value="buy">Ost</option><option value="sell" <?= ($form['side']??'')==='sell'?'selected':'' ?>>Müük</option></select></label>
<label>Maaklerikonto<input name="portfolio" maxlength="60" required list="portfolios" value="<?= $value('portfolio') ?>" placeholder="Näiteks LHV 123"></label>
<label>Maakleri tehinguviide<input name="broker_reference" maxlength="80" required value="<?= $value('broker_reference') ?>"></label>
<label>Väärtpaberi ISIN või tunnus<input name="instrument" maxlength="40" required value="<?= $value('instrument') ?>" list="instruments"></label>
<label>Väärtpaberi nimi<input name="instrument_name" maxlength="120" required value="<?= $value('instrument_name') ?>"></label>
<label>Väärtpaberi liik<select name="instrument_type"><?php foreach (securityFxTypes() as $type=>$label): ?><option value="<?= $escape($type) ?>" <?= ($form['instrument_type']??'')===$type?'selected':'' ?>><?= $escape($label) ?></option><?php endforeach; ?></select></label>
<label>Tehingupäev<input name="trade_date" type="date" required value="<?= $value('trade_date',date('Y-m-d')) ?>"></label>
<label>Arvelduspäev<input name="settlement_date" type="date" value="<?= $value('settlement_date') ?>"><small>Tühjana sama mis tehingupäev.</small></label>
<div>
<label>Valuuta<select name="currency">
<?php foreach ($currencies as $code => $label): ?>
    <option value="<?= $escape($code) ?>" <?= $currency === $code ? 'selected' : '' ?>><?= $escape($code.' — '.$label) ?></option>
<?php endforeach; ?>
    <option value="other" <?= $otherCurrency ? 'selected' : '' ?>>Muu valuuta</option>
</select></label>
<label id="other-currency-box" <?= $otherCurrency ? '' : 'hidden' ?>>Valuutakood
    <input name="other_currency" maxlength="3" pattern="[A-Za-z]{3}" <?= $otherCurrency ? 'required' : 'disabled' ?> value="<?= $escape($otherCurrency ? $currency : '') ?>">
</label>
</div>
<label>Kogus<input name="quantity" inputmode="decimal" required value="<?= $value('quantity') ?>"></label>
<label>Tehingusumma valuutas<input name="gross_amount" inputmode="decimal" required value="<?= $value('gross_amount') ?>"><small>Maakleri summa enne teenustasu.</small></label>
<label>Teenustasu samas valuutas<input name="fee_amount" inputmode="decimal" value="<?= $value('fee_amount','0') ?>"></label>
</div>
<datalist id="portfolios"><?php foreach (array_unique(array_column($positions,'portfolio')) as $portfolio): ?><option value="<?= $escape($portfolio) ?>"><?php endforeach; ?></datalist>
<datalist id="instruments"><?php foreach ($positions as $position): ?><option value="<?= $escape($position['instrument']) ?>"><?= $escape($position['name']) ?></option><?php endforeach; ?></datalist>
<div class="grid">
<?php foreach (['trade'=>'Tehingupäeva','settlement'=>'Arvelduspäeva'] as $prefix=>$label): ?>
<div <?= $prefix==='settlement'?'id="settlement-rate-box"':'' ?>>
<label><?= $escape($label) ?> kursi allikas<select name="<?= $prefix ?>_source"><option value="ecb">EKP</option><option value="manual" <?= ($form[$prefix.'_source']??'')==='manual'?'selected':'' ?>>Käsitsi</option></select></label>
<label>1 EUR = X valuutaühikut<input name="<?= $prefix ?>_rate" inputmode="decimal" value="<?= $value($prefix.'_rate') ?>"></label>
<p><button class="secondary" type="button" data-quote="<?= $prefix ?>">Too EKP kurss</button><small class="rate-status" id="<?= $prefix ?>-status" aria-live="polite"></small></p>
</div><?php endforeach; ?>
<label>Käsikursi või rahajäägi alus<input name="rate_note" maxlength="250" value="<?= $value('rate_note') ?>" placeholder="Näiteks maakleri arveldusraport"></label>
</div>
<div class="grid">
<label>Väärtpaberikonto<?php $select('security_account',['asset'],$nonCash,($isUsd ? ($defaults['1110'] ?? null) : null) ?? $defaults['1100'] ?? ''); ?></label>
<label>Maakleri rahakonto<?php $select('cash_account',['asset'],$isCash,$isUsd ? ($defaults['1040'] ?? '') : ''); ?><small>Vali sellele valuutale oma konto.</small></label>
<label id="clearing-box">Arvelduste vahekonto<?php $select('clearing_account',['asset','liability'],$nonCash,$defaults[$clearingCode]??''); ?><small>Eri kuupäevade korral ostule kohustise ja müügile nõude konto.</small></label>
<label>Müügikasumi konto<?php $select('gain_account',['income'],$any,$defaults['4100']??''); ?></label>
<label>Müügikahjumi konto<?php $select('loss_account',['expense'],$any,$defaults['5050']??''); ?></label>
</div>
<details <?= $error && $form?'open':'' ?>><summary>Teenustasu ja kursivahe kontod</summary><div class="grid">
<label>Ostu teenustasu<select name="fee_mode"><option value="expense">Kanda kulusse</option><option value="capital" <?= ($form['fee_mode']??'')==='capital'?'selected':'' ?>>Lisada soetusmaksumusele</option></select></label>
<label>Ostu teenustasukonto<?php $select('fee_account',['expense'],$any,$defaults['6410']??$defaults['5050']??''); ?></label>
<label>Kursikasumi konto<?php $select('fx_gain_account',['income'],$any,$defaults['4160']??''); ?><small>Tühjana müügikasumi konto.</small></label>
<label>Kursikahjumi konto<?php $select('fx_loss_account',['expense'],$any,$defaults['5060']??''); ?><small>Tühjana müügikahjumi konto.</small></label>
<label>Ostuks kasutatud raha bilansiline euromaksumus<input name="cash_book" inputmode="decimal" value="<?= $value('cash_book') ?>"><small>Täida, kui raha bilansiline väärtus erineb arvelduspäeva euroväärtusest.</small></label>
</div></details>
<p><button type="submit">Kinnita ja kirjenda</button></p>
</form></section>
<section class="card"><h2>Portfelli jääk</h2>
<p class="muted">Sellel lehel sisestatud tehingute allesjäänud kogus ja ajalooline soetusmaksumus.</p>
<?php if (!$positions): ?><p>Avatud positsioone pole.</p><?php else: ?><div class="table"><table>
<thead><tr><th>Maaklerikonto</th><th>Väärtpaber</th><th class="num">Kogus</th><th class="num">FIFO maksumus</th></tr></thead><tbody>
<?php foreach ($positions as $row): ?><tr><td><?= $escape($row['portfolio']) ?></td><td><?= $escape($row['instrument'].' · '.$row['name']) ?></td><td class="num"><?= $num($row['quantity']) ?></td><td class="num"><?= $euro($row['cost']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<section class="card" id="tehingud"><h2>Tehingud</h2>
<p class="muted">Algsummad on tehingu valuutas. Nende kõrval on pearaamatus kajastatud eurosummad.</p>
<?php if (!$trades): ?><p>Tehinguid pole veel sisestatud.</p><?php else: ?><div class="table"><table>
<thead><tr><th>Tehing / arveldus</th><th>Väärtpaber ja viide</th><th>Liik</th><th class="num">Kogus</th><th class="num">Tehingusumma</th><th class="num">Teenustasu</th><th class="num">Rahaliikumine</th><th>Tehingukurss</th><th class="num">FIFO maksumus</th><th class="num">Müügitulemus</th><th class="num">Kursivahe</th><th>Kanded</th></tr></thead><tbody>
<?php foreach ($trades as $row):
    $buy = $row['side'] === 'buy';
    $cashAmount = $buy ? bcadd($row['gross_amount'], $row['fee_amount'], 8)
        : bcsub($row['gross_amount'], $row['fee_amount'], 8);
    $currency = $escape($row['currency']);
    $sign = $buy ? '−' : '+';
?>
<tr><td><?= $escape($row['trade_date']) ?><br><small><?= $escape($row['settlement_date']) ?></small></td>
<td><?= $escape($row['instrument'].' · '.$row['portfolio']) ?><br><small><?= $escape($row['broker_reference']) ?></small></td>
<td><?= $row['side']==='buy'?'Ost':'Müük' ?><?= $row['voided_at']?' · tühistatud':'' ?></td>
<td class="num"><?= $num($row['quantity']) ?></td>
<td class="num"><?= $num($row['gross_amount']).' '.$currency ?><br><small><?= $euro($row['gross_cents']) ?></small></td>
<td class="num"><?= $num($row['fee_amount']).' '.$currency ?><br><small><?= $euro($row['fee_cents']) ?></small></td>
<td class="num"><strong><?= $sign.$num($cashAmount).' '.$currency ?></strong><br><small>Rahakonto kandes <?= $sign.$euro($row['cash_book_cents']) ?></small></td>
<td><?= $num($row['trade_rate']) ?><br><small><?= $escape(strtoupper($row['trade_rate_source']).' · '.$row['trade_rate_date']) ?></small>
<?php if ($row['settlement_date']!==$row['trade_date'] || $row['rate_note']!==''): ?>
<details><summary>Alus</summary><small><?= $num($row['settlement_rate']).' · '.$escape(strtoupper($row['settlement_rate_source']).' · '.$row['settlement_rate_date']) ?><br><?= $escape($row['rate_note']) ?></small></details>
<?php endif; ?></td>
<td class="num"><?= $euro($row['cost_cents']) ?></td><td class="num"><?= $row['side']==='sell'?$euro($row['result_cents']):'–' ?></td><td class="num"><?= $euro($row['fx_result_cents']) ?></td>
<td><a href="entry.php?id=<?= (int)$row['trade_entry_id'] ?>">Tehing</a>
<?php if ($row['settlement_entry_id']): ?> · <a href="entry.php?id=<?= (int)$row['settlement_entry_id'] ?>">Arveldus</a><?php endif; ?>
<?php if ($row['void_trade_entry_id']): ?> · <a href="entry.php?id=<?= (int)$row['void_trade_entry_id'] ?>">Vastukanne</a><?php endif; ?>
<?php if ($row['void_settlement_entry_id']): ?> · <a href="entry.php?id=<?= (int)$row['void_settlement_entry_id'] ?>">Arvelduse vastukanne</a><?php endif; ?>
<?php if (isset($lastIds[$row['id']])): ?><form method="post" class="inline" onsubmit="return confirm('Kas tühistad tehingu ja taastad positsiooni eelmise seisu?')">
<input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="void"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
<button class="secondary">Tühista</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?></section>
</main><script>
const tradeForm = document.getElementById('trade-form');
const fields = tradeForm.elements;
const accountDefaults = <?= json_encode([
    'usdCash' => (string)($defaults['1040'] ?? ''),
    'usdSecurity' => (string)($defaults['1110'] ?? $defaults['1100'] ?? ''),
    'otherSecurity' => (string)($defaults['1100'] ?? ''),
    'buyClearing' => (string)($defaults['2020'] ?? ''),
    'sellClearing' => (string)($defaults['1210'] ?? ''),
], JSON_THROW_ON_ERROR) ?>;
function selectedCurrency() {
    return (fields.currency.value === 'other' ? fields.other_currency.value : fields.currency.value).trim().toUpperCase();
}
let wasUsd = selectedCurrency() === 'USD';
function syncCurrencyAccounts() {
    const isUsd = selectedCurrency() === 'USD';
    if (wasUsd === isUsd) return;
    if (fields.cash_account.value === (wasUsd ? accountDefaults.usdCash : '')) {
        fields.cash_account.value = isUsd ? accountDefaults.usdCash : '';
    }
    if (fields.security_account.value === (wasUsd ? accountDefaults.usdSecurity : accountDefaults.otherSecurity)) {
        fields.security_account.value = isUsd ? accountDefaults.usdSecurity : accountDefaults.otherSecurity;
    }
    wasUsd = isUsd;
}
for (const name of ['currency', 'other_currency']) {
    fields[name].addEventListener('input', syncCurrencyAccounts);
}
fields.side.addEventListener('change', () => {
    fields.clearing_account.value = fields.side.value === 'sell' ? accountDefaults.sellClearing : accountDefaults.buyClearing;
});
function syncFields() {
    const delayed = fields.settlement_date.value && fields.settlement_date.value !== fields.trade_date.value;
    document.getElementById('settlement-rate-box').hidden = !delayed;
    document.getElementById('clearing-box').hidden = !delayed;
    const other = fields.currency.value === 'other';
    document.getElementById('other-currency-box').hidden = !other;
    fields.other_currency.disabled = !other;
    fields.other_currency.required = other;
    for (const prefix of ['trade', 'settlement']) {
        fields[prefix + '_rate'].readOnly = fields[prefix + '_source'].value === 'ecb';
    }
}
tradeForm.addEventListener('change', syncFields);
function quoteKey(prefix) {
    return selectedCurrency() + '|' + (fields[prefix + '_date'].value || fields.trade_date.value);
}
for (const button of document.querySelectorAll('[data-quote]')) {
    const prefix = button.dataset.quote;
    const status = document.getElementById(prefix + '-status');
    const state = {key: quoteKey(prefix), loaded: '', request: null};

    function syncQuote() {
        const key = quoteKey(prefix);
        if (state.key === key) return;
        state.key = key;
        state.loaded = '';
        state.request?.abort();
        state.request = null;
        if (fields[prefix + '_source'].value === 'ecb') {
            fields[prefix + '_rate'].value = '';
            status.textContent = '';
        }
    }

    async function loadQuote(force = false) {
        syncQuote();
        if (state.request || (!force && state.loaded === state.key)) return;
        const dateField = fields[prefix + '_date'].value ? fields[prefix + '_date'] : fields.trade_date;
        if (!dateField.value || !dateField.validity.valid || !/^[A-Z]{3}$/.test(selectedCurrency())) {
            if (force) {
                dateField.reportValidity();
                status.textContent = 'Vali kuupäev ja valuuta.';
            }
            return;
        }
        if (!force && fields[prefix + '_source'].value !== 'ecb') return;
        const key = state.key;
        const controller = new AbortController();
        state.request = controller;
        button.setAttribute('aria-busy', 'true');
        status.textContent = 'Laen kurssi…';
        try {
            const body = new URLSearchParams({
                action: 'quote', csrf_token: fields.csrf_token.value,
                currency: selectedCurrency(), date: dateField.value
            });
            const reply = await fetch('security_fx.php', {method: 'POST', body, signal: controller.signal});
            const quote = await reply.json();
            if (!reply.ok || !quote.rate || !quote.date) throw new Error(quote.error || 'Kurssi ei õnnestunud laadida.');
            if (state.request !== controller || key !== quoteKey(prefix)) return;
            fields[prefix + '_source'].value = 'ecb';
            fields[prefix + '_rate'].value = quote.rate;
            state.loaded = key;
            status.textContent = 'Kursi kuupäev ' + quote.date;
            syncFields();
        } catch (error) {
            if (state.request === controller && error.name !== 'AbortError') {
                status.textContent = error.message || 'Kurssi ei õnnestunud laadida.';
            }
        } finally {
            if (state.request === controller) {
                state.request = null;
                button.removeAttribute('aria-busy');
            }
        }
    }

    button.addEventListener('click', () => loadQuote(true));
    const dependencies = ['currency', 'other_currency', prefix + '_date', prefix + '_source'];
    if (prefix === 'settlement') dependencies.push('trade_date');
    for (const name of dependencies) {
        fields[name].addEventListener('input', () => {
            syncQuote();
            if (name === prefix + '_source') state.loaded = '';
            button.removeAttribute('aria-busy');
            if (name === prefix + '_source' && fields[name].value !== 'ecb') {
                state.request?.abort();
                state.request = null;
            }
        });
        fields[name].addEventListener('change', () => {
            syncQuote();
            if (prefix === 'trade' || fields.settlement_date.value !== fields.trade_date.value) loadQuote();
        });
    }
}
syncFields();
</script></body></html>
