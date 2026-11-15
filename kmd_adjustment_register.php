<?php
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__.'/db.php';
require_once __DIR__.'/kmd_inf_confirmation.php';
require_once __DIR__.'/kmd_inf_data.php';
require_once __DIR__.'/kmd_manual_adjustments.php';
require_once __DIR__.'/kmd_adjustment_calculator.php';
ensureInfConfirmationSchema($pdo);
ensureCreditNoteSchema($pdo);
ensureImportVatSchema($pdo);
ensureKmdManualAdjustments($pdo);

$company = (int)$_SESSION['company_id'];
$user = (int)$_SESSION['user_id'];
$year = filter_var($_GET['year'] ?? date('Y'),FILTER_VALIDATE_INT,['options'=>['min_range'=>2000,'max_range'=>2099]]) ?: (int)date('Y');
$month = filter_var($_GET['month'] ?? date('n'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>12]]) ?: (int)date('n');
$period = sprintf('%04d-%02d',$year,$month);
$isXbrl=$period>='2027-04';
$start = $period.'-01';
$end = (new DateTimeImmutable($start))->format('Y-m-t');
$assetId = filter_var($_GET['asset_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: null;
$assetSource = $assetId ? loadKmdAssetCalculationSource($pdo,$company,$assetId) : null;
$page = 'kmd_adjustment_register.php?'.http_build_query(['year'=>$year,'month'=>$month,'asset_id'=>$assetId]);
$csrf = $_SESSION['kmd_adjustment_csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$calculation = null;
$carCalculation = null;
$calculatorKinds = [
    'annual'=>'Aastane proportsioon',
    'first_use'=>'Põhivara või kinnisasja esmane kasutuselevõtt',
    'asset_year'=>'Põhivara, sh sõiduauto, aastane ümberarvestus (1/5)',
    'property_year'=>'Kinnisasja aastane ümberarvestus (1/10)',
    'asset_sale'=>'Muu kasutusele võetud põhivara maksustatav müük',
    'property_sale_taxed'=>'Kasutusele võetud kinnisasja maksustatav müük',
    'property_sale_exempt'=>'Kasutusele võetud kinnisasja maksuvaba müük',
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf,(string)($_POST['csrf'] ?? ''))) throw new InvalidArgumentException('Vorm aegus. Värskenda lehte.');
        $action = $_POST['action'] ?? '';
        if ($action === 'calculate') {
            $kind = (string)($_POST['kind'] ?? '');
            $total = parseDeductibleCents((string)($_POST['total_vat'] ?? ''),999999999999);
            $reference = parseDeductibleCents((string)($_POST['reference_vat'] ?? ''),$total);
            $remaining = filter_var($_POST['remaining_years'] ?? '',FILTER_VALIDATE_INT,FILTER_NULL_ON_FAILURE);
            $calculation = calculateKmdAdjustment($kind,$period,$total,$reference,(string)($_POST['eligible_percent'] ?? ''),$remaining);
        } elseif ($action === 'calculate_car') {
            if (empty($_POST['full_deduction'])) throw new InvalidArgumentException('Kinnita, et auto soetamisel arvati sisendkäibemaks maha täies ulatuses.');
            $carCalculation = calculateKmdCarAcquisitionReduction((string)($_POST['acquired'] ?? ''),(string)($_POST['private_use'] ?? ''),
                parseDeductibleCents((string)($_POST['car_vat'] ?? ''),999999999999),(string)($_POST['car_percent'] ?? ''));
        } elseif ($action === 'void') {
            voidKmdManualAdjustment($pdo,$company,$user,(int)($_POST['id'] ?? 0));
        } else {
            if (empty($_POST['confirmed'])) throw new InvalidArgumentException('Kinnita paranduse käsitlus ja valitud konto.');
            $entry = (int)($_POST['journal_entry_id'] ?? 0);
            $query = $pdo->prepare('SELECT 1 FROM journal_entries WHERE company_id=? AND id=? AND entry_date BETWEEN ? AND ?');
            $query->execute([$company,$entry,$start,$end]);
            if (!$query->fetchColumn()) throw new InvalidArgumentException('Vali selle kuu pearaamatukanne.');
            $expected = trim((string)($_POST['expected_amount'] ?? ''));
            $rateAmounts=null;
            if($isXbrl) {
                $rates=$_POST['rate']??[]; $amounts=$_POST['rate_amount']??[];
                if(!is_array($rates) || !is_array($amounts) || count($rates)>6 || array_keys($rates)!==array_keys($amounts)) {
                    throw new InvalidArgumentException('Kontrolli maksumäärade jaotuse ridu.');
                }
                $rateAmounts=[];
                foreach($rates as $i=>$rate) {
                    $amount=trim((string)$amounts[$i]); $rate=trim((string)$rate);
                    if($rate==='' && $amount==='') continue;
                    if(isset($rateAmounts[$rate])) throw new InvalidArgumentException('Sisesta iga maksumäär ainult ühel real.');
                    $rateAmounts[$rate]=creditCents(str_starts_with($amount,'+')?substr($amount,1):$amount);
                }
            }
            saveKmdManualAdjustment($pdo,$company,$user,$entry,(int)($_POST['account_id'] ?? 0),(string)($_POST['basis'] ?? ''),
                $expected === '' ? null : creditCents(str_starts_with($expected,'+')?substr($expected,1):$expected),$rateAmounts);
        }
        if (!in_array($action,['calculate','calculate_car'],true)) {
            header('Location: '.$page);
            exit;
        }
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        $error = 'KMD täpsustust ei õnnestunud salvestada.';
    } catch (RuntimeException | InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    }
}
$adjustments = loadKmdManualAdjustments($pdo,$company,$start,$end);
$accounts = $pdo->prepare("SELECT id,code,name FROM accounts WHERE company_id=? AND is_active=1 AND account_type IN ('asset','liability') AND code NOT REGEXP '^10[0-9]{2}$' ORDER BY code");
$accounts->execute([$company]);
function h(string $text): string { return htmlspecialchars($text,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8'); }
?>
<!doctype html>
<html lang="et"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sisendkäibemaksu parandused</title>
<link rel="icon" href="favicon.png?v=1">
<style>
* { box-sizing: border-box; }
body { max-width: 1100px; margin: 32px auto; padding: 0 20px; background: #f4f7fb; color: #263746; font: 16px/1.5 Arial, sans-serif; }
h1, h2 { color: #17324d; line-height: 1.25; }
h1 { margin: 0 0 12px; }
h2 { margin: 0 0 12px; font-size: 22px; }
a { color: #1769aa; }
.card { margin: 18px 0; padding: 22px; background: #fff; border: 1px solid #dce3ec; border-radius: 12px; }
.card > :last-child { margin-bottom: 0; }
.intro, .form-note { color: #526579; }
.period-form { display: flex; flex-wrap: wrap; align-items: end; gap: 12px; }
.form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: start; gap: 14px 16px; }
.full-width { grid-column: 1 / -1; min-width: 0; }
label { display: grid; gap: 6px; font-weight: 600; }
input:not([type="checkbox"]), select, textarea {
    width: 100%; min-width: 0; min-height: 42px; padding: 8px 10px;
    border: 1px solid #b8c4d1; border-radius: 7px; background: #fff; color: inherit; font: inherit;
}
.period-form input { width: 7rem; }
textarea { resize: vertical; }
.check { display: flex; align-items: flex-start; gap: 9px; font-weight: 400; }
.check input { width: 17px; height: 17px; margin: 3px 0 0; flex-shrink: 0; accent-color: #1769aa; }
.button, button {
    display: inline-flex; align-items: center; justify-content: center; min-height: 42px; padding: 8px 14px;
    border: 0; border-radius: 7px; background: #1769aa; color: #fff; font: 600 15px/1.35 Arial, sans-serif;
    text-decoration: none; cursor: pointer;
}
.button:hover, button:hover { background: #12558a; }
.secondary { background: #63788b; }
.secondary:hover { background: #526579; }
.form-grid > button { justify-self: start; }
fieldset { min-inline-size: 0; margin: 0; padding: 12px 16px 16px; border: 1px solid #dce3ec; border-radius: 8px; background: #f7f9fc; }
legend { padding: 0 6px; color: #17324d; font-weight: 600; }
fieldset p, .form-note { margin: 0; }
.rate-row { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 2fr) auto; gap: 8px; margin: 10px 0; }
fieldset > button + p { margin-top: 10px; }
.error, .result { padding: 10px 13px; border: 1px solid; border-radius: 7px; }
.error { background: #fff0f0; border-color: #e5a3a3; color: #a32121; }
.result { background: #eef5fc; border-color: #cfe0f2; color: #17324d; }
.table-wrap { overflow-x: auto; }
table { border-collapse: collapse; width: 100%; }
th, td { text-align: left; vertical-align: top; padding: 10px; border-bottom: 1px solid #e5eaf0; }
th { background: #f3f6fa; }
.num { text-align: right; white-space: nowrap; }
td ul { margin: 6px 0 0; padding-left: 18px; }
@media (max-width: 640px) {
    body { margin: 20px auto; padding: 0 12px; }
    .card { padding: 15px; }
    .form-grid { grid-template-columns: 1fr; }
    .rate-row { grid-template-columns: minmax(0, 1fr) minmax(0, 2fr); }
    .rate-row button { grid-column: 1 / -1; justify-self: start; }
}
</style></head><body>
<p><a class="button secondary" href="<?= $isXbrl?'kmd_export.php':'kmd_export_legacy.php' ?>?year=<?= $year ?>&amp;month=<?= $month ?>">Tagasi <?= $isXbrl?'XBRL ettevalmistusse':'vana KMD juurde' ?></a></p>
<section class="card">
<h1>Sisendkäibemaksu parandused · <?= h($period) ?></h1>
<p class="intro"><?= $isXbrl?'Parandused lisatakse valitud kuu XBRL GL faili liigiga O_601.':'Vana KMD kasutab kuu netoparandust lahtris 10 või 11.' ?> Plussmärk vähendab sisendkäibemaksu, miinusmärk suurendab seda.</p>
<form class="period-form" method="get">
    <?php if ($assetSource): ?><input type="hidden" name="asset_id" value="<?= $assetId ?>"><?php endif; ?>
    <label>Aasta <input type="number" name="year" min="2000" max="2099" value="<?= $year ?>" required></label>
        <label>Kuu <input type="number" name="month" min="1" max="12" value="<?= $month ?>" required></label>
    <button type="submit">Vaata perioodi</button>
</form>
</section>
<?php if ($assetSource): $asset = $assetSource['asset']; ?>
<section class="card">
    <h2><a href="pohivara/asset_card.php?id=<?= $assetId ?>"><?= h($asset['asset_number'].' — '.$asset['name']) ?></a></h2>
    <p>Soetatud <?= h($asset['acquisition_date']) ?> · kasutusvalmis <?= h($asset['available_date']) ?>.</p>
    <?php if ($assetSource['invoice']): $purchase = $assetSource['invoice']; ?>
        <p>Algdokument <a href="purchase_invoices.php?id=<?= (int)$purchase['id'] ?>"><?= h($purchase['supplier_invoice_number']) ?></a>.</p>
    <?php endif; ?>
    <?php if ($assetSource['input_vat'] !== null): ?>
        <p>Ostuarve käibemaks <?= h(creditDecimal($assetSource['input_vat'])) ?> €. Soetamisel kinnitatud mahaarvamine <?= h(creditDecimal($assetSource['confirmed_vat'])) ?> €. Kontrolli võrdlussummat, kui pärast soetamist on käibemaksu juba korrigeeritud.</p>
    <?php else: ?>
        <p>Kaardilt ei leitud üheselt seostatavat ja kinnitatud sisendkäibemaksu summat. Sisesta kontrollitud andmed käsitsi.</p>
    <?php endif; ?>
</section>
<?php elseif ($assetId): ?><p class="error">Põhivarakaarti ei leitud.</p><?php endif; ?>
<?php foreach (array_filter(array_merge([$error],$adjustments['errors'])) as $message): ?><p class="error" role="alert"><?= h($message) ?></p><?php endforeach; ?>
<section class="card">
    <h2>Sisendkäibemaksu ümberarvestus</h2>
    <p>Sisesta kontrollitud mahaarvamise osatähtsus. Aastase proportsiooni ja esmase kasutuselevõtu puhul on võrdlussumma juba maha arvatud käibemaks. Vara aastase ümberarvestuse puhul sisesta alusena kasutatav mahaarvamine enne 1/5 või 1/10 rakendamist.</p>
    <form class="form-grid" method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <label>Arvutuse liik <select name="kind">
            <?php foreach ($calculatorKinds as $key=>$label): ?><option value="<?= h($key) ?>" <?= ($_POST['kind'] ?? '') === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
        </select></label>
        <label>Arvutuse aluseks olev kogu sisendkäibemaks (€) <input name="total_vat" inputmode="decimal" required value="<?= h((string)($_POST['total_vat'] ?? (isset($assetSource['input_vat']) ? creditDecimal($assetSource['input_vat']) : ''))) ?>"></label>
        <label>Võrdlussumma (€) <input name="reference_vat" inputmode="decimal" required value="<?= h((string)($_POST['reference_vat'] ?? '')) ?>"></label>
        <label>Tegelik mahaarvatav osatähtsus (%) · aastaseks või kasutuselevõtu arvutuseks <input name="eligible_percent" inputmode="decimal" value="<?= h((string)($_POST['eligible_percent'] ?? '')) ?>"></label>
        <label>Müügi korral järelejäänud aastad koos müügiaastaga <input type="number" name="remaining_years" min="0" max="10" step="1" value="<?= h((string)($_POST['remaining_years'] ?? '')) ?>"></label>
        <p class="form-note full-width">Müügi jaoks vali müügikuu KMD. Sisesta enne müüki kasutatud mahaarvamise võrdlussumma ja kontrollitud järelejäänud korrigeerimisaastad.</p>
        <button type="submit" name="action" value="calculate">Arvuta täpsustus</button>
    </form>
    <?php if ($calculation !== null): ?>
        <p class="result" role="status"><strong><?= $calculation === 0 ? 'Täpsustust ei teki.' : ($isXbrl?'O_601 · '.h(creditDecimal($calculation)):'Lahter '.($calculation > 0 ? '10' : '11').' · '.h(creditDecimal(abs($calculation)))).' €' ?></strong></p>
    <?php endif; ?>
</section>
<section class="card">
    <h2>Sõiduauto erakasutus esimese kahe aasta jooksul</h2>
    <p>Arvutus on soetamisel täies ulatuses maha arvatud käibemaksu kohta. Vaata <a href="https://www.emta.ee/ariklient/e-teenused-koolitused/nouanded/kui-ostate-uue-voi-kasutatud-soiduki">MTA sõiduauto juhendit</a>.</p>
    <form class="form-grid" method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <label>Soetamise kuupäev <input type="date" name="acquired" required value="<?= h((string)($_POST['acquired'] ?? '')) ?>"></label>
        <label>Erakasutuse algus <input type="date" name="private_use" required value="<?= h((string)($_POST['private_use'] ?? '')) ?>"></label>
        <label>Soetamisel maha arvatud KM (€) <input name="car_vat" inputmode="decimal" required value="<?= h((string)($_POST['car_vat'] ?? '')) ?>"></label>
        <label>Lubatud mahaarvamine (%) <input name="car_percent" inputmode="decimal" required value="<?= h((string)($_POST['car_percent'] ?? '50')) ?>"></label>
        <label class="check full-width"><input type="checkbox" name="full_deduction" value="1" required <?= !empty($_POST['full_deduction']) ? 'checked' : '' ?>> Soetamisel arvati kogu sisendkäibemaks maha.</label>
        <button type="submit" name="action" value="calculate_car">Arvuta soetamiskuu parandus</button>
    </form>
    <?php if ($carCalculation): ?>
        <p class="result" role="status">Soetamiskuu <strong><?= h($carCalculation['period']) ?></strong>. Vähendatav sisendkäibemaks <strong><?= h(creditDecimal($carCalculation['reduction'])) ?> €</strong>. Alles jääb <?= h(creditDecimal($carCalculation['retained'])) ?> €.</p>
        <p>Paranda soetamiskuu algset mahaarvamist ja vastava perioodi väljundit. Seda erijuhtu ei kanta automaatselt jooksva kuu O_601 reale. Arvutus ei muuda deklaratsiooni ega pearaamatut.</p>
    <?php endif; ?>
</section>
<section class="card">
    <h2>Seo olemasolev pearaamatukanne</h2>
    <p>Sisesta arvutatud korrigeerimise alus, näiteks põhivara kasutuse muutus, sõiduauto ümberarvestus või aastane proportsioon. KMD kasutab valitud käibemaksukonto kreediti ja deebeti vahet.</p>
    <form class="form-grid" method="post">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <label>Pearaamatukande ID <input type="number" name="journal_entry_id" min="1" required></label>
        <label>Käibemaksukonto <select name="account_id" required><option value="">Vali konto</option>
            <?php foreach ($accounts as $account): ?><option value="<?= (int)$account['id'] ?>"><?= h($account['code'].' — '.$account['name']) ?></option><?php endforeach; ?>
        </select></label>
        <label>Oodatav paranduse netosumma (+ vähendab, − suurendab sisendkäibemaksu)
            <input name="expected_amount" inputmode="decimal" value="<?= h((string)($_POST['expected_amount'] ?? ($calculation ? creditDecimal($calculation) : ''))) ?>">
        </label>
        <?php if($isXbrl): ?>
        <fieldset class="full-width"><legend>XBRL maksumäärade jaotus</legend>
        <p>Vali algse sisendkäibemaksu tegelik määr ja sisesta paranduse summa koos märgiga. Mitme määraga alus jaga ridadeks. Jaotus peab klappima valitud konto netosummaga; maksumäära kontonumbri põhjal ei oletata.</p>
        <div id="adjustment-rates">
        <?php $postedRates=is_array($_POST['rate']??null)?array_slice($_POST['rate'],0,6):['']; foreach($postedRates as $i=>$chosenRate): ?>
            <div class="rate-row"><select name="rate[]" aria-label="Algne käibemaksumäär"><option value="">Vali määr</option>
                <?php foreach([24,13,9,22,20,5] as $rate): ?><option value="<?= $rate ?>" <?= (string)$chosenRate===(string)$rate?'selected':'' ?>><?= $rate ?>%</option><?php endforeach; ?>
            </select><input name="rate_amount[]" aria-label="Paranduse summa eurodes" inputmode="decimal" placeholder="Parandus eurodes" value="<?= h((string)($_POST['rate_amount'][$i]??($i===0 && $calculation!==null?creditDecimal($calculation):''))) ?>">
            <button class="secondary" type="button" onclick="if(this.parentElement.parentElement.children.length>1)this.parentElement.remove()">Eemalda rida</button></div>
        <?php endforeach; ?></div>
        <button class="secondary" type="button" onclick="const p=document.getElementById('adjustment-rates');if(p.children.length<6){const r=p.firstElementChild.cloneNode(true);r.querySelector('select').value='';r.querySelector('input').value='';p.appendChild(r)}">Lisa maksumäär</button>
        <p>22%, 20% ja 5% sobivad kontrollitud ajaloolise sisendkäibemaksu parandamiseks. Eri määrade vastassuunalised parandused säilivad ka siis, kui kuu netosumma on null.</p>
        </fieldset>
        <?php endif; ?>
        <label class="full-width">Paranduse alus ja dokumendiviide <textarea name="basis" rows="2" maxlength="500" required><?= h((string)($_POST['basis'] ?? ($assetSource ? 'Põhivarakaart '.$assetSource['asset']['asset_number'].'. ' : ''))) ?></textarea></label>
        <label class="check full-width"><input type="checkbox" name="confirmed" value="1" required> Kontrollisin kontot, summat ja KMD täpsustuse alust.</label>
        <button type="submit" name="action" value="save">Seo kanne</button>
    </form>
</section>
<section class="card">
    <h2>Perioodi ajalugu</h2>
    <p>KMD netotäpsustus <strong><?= h(creditDecimal($adjustments['net'])) ?> €</strong></p>
    <?php if (!$adjustments['rows']): ?><p>Täpsustusi pole.</p><?php else: ?>
    <div class="table-wrap"><table><thead><tr><th>Kuupäev</th><th>Kanne</th><th>Alus</th><th class="num" scope="col">Summa</th><th>Olek</th></tr></thead><tbody>
    <?php foreach ($adjustments['rows'] as $row): ?>
    <tr><td><?= h($row['entry_date']) ?></td><td><a href="entry.php?id=<?= (int)$row['journal_entry_id'] ?>">#<?= (int)$row['journal_entry_id'] ?></a></td>
        <td><?= h($row['basis']) ?><?php $rates=json_decode($row['allocation_json']??'null',true); if(is_array($rates)): ?><ul><?php foreach($rates as $rate=>$value): ?><li>O_601 · <?= h((string)$rate) ?>% · <?= h(creditDecimal((int)$value)) ?> €</li><?php endforeach; ?></ul><?php endif; ?></td><td class="num"><?= h(creditDecimal((int)$row['amount_cents'])) ?> €</td>
        <td><?php if ($row['voided_at']): ?>Tühistatud <?= h($row['voided_at']) ?><?php else: ?>
            <form class="form-grid" method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button class="secondary" type="submit" name="action" value="void">Eemalda deklaratsiooni arvestusest</button>
            </form>
        <?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
</body></html>
