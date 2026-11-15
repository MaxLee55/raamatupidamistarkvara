<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function lhvHeaderKey(string $value): string
{
    $value = preg_replace('/^\xEF\xBB\xBF/', '', trim($value));
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function lhvReadCsv(string $path, string $label): array
{
    $handle = fopen($path, 'rb');
    if (!$handle) {
        throw new RuntimeException($label . ' faili ei saanud lugeda.');
    }

    $firstLine = fgets($handle);
    if ($firstLine === false) {
        fclose($handle);
        throw new RuntimeException($label . ' fail on tühi.');
    }
    $delimiter = ',';
    $bestCount = 0;
    foreach ([',', ';', "\t"] as $candidate) {
        $count = count(str_getcsv($firstLine, $candidate, '"', '\\'));
        if ($count > $bestCount) {
            $bestCount = $count;
            $delimiter = $candidate;
        }
    }
    rewind($handle);
    $header = fgetcsv($handle, 0, $delimiter, '"', '\\');
    if (!$header || count($header) < 2) {
        fclose($handle);
        throw new RuntimeException($label . ' päiserida ei õnnestunud lugeda.');
    }

    $rows = [];
    while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
        if (count($row) === 1 && trim((string) $row[0]) === '') {
            continue;
        }
        $row = array_pad($row, count($header), '');
        $rows[] = array_slice($row, 0, count($header));
    }
    fclose($handle);

    return ['header' => $header, 'rows' => $rows, 'delimiter' => $delimiter];
}

function lhvColumnIndex(array $header, string $name): int
{
    $wanted = lhvHeaderKey($name);
    foreach ($header as $index => $label) {
        if (lhvHeaderKey((string) $label) === $wanted) {
            return (int) $index;
        }
    }
    throw new RuntimeException('Puudub vajalik veerg „' . $name . '”.');
}

function lhvAmountCents(string $value): ?int
{
    $value = trim(str_replace(["\xC2\xA0", ' '], '', $value));
    if ($value === '') return null;
    $comma = strrpos($value, ',');
    $dot = strrpos($value, '.');
    if ($comma !== false && $dot !== false) {
        if ($comma > $dot) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } elseif ($comma !== false) {
        $value = str_replace(',', '.', $value);
    }
    if (!preg_match('/^-?\d{1,13}(?:\.\d{1,8})?$/', $value)) return null;
    return (int) round((float) $value * 100);
}

function lhvCsvCell(string $value, string $delimiter): string
{
    if (strpbrk($value, $delimiter . "\"\r\n") !== false) {
        return '"' . str_replace('"', '""', $value) . '"';
    }
    return $value;
}

function lhvCsvLine(array $cells, string $delimiter): string
{
    return implode($delimiter, array_map(static fn($cell) => lhvCsvCell((string) $cell, $delimiter), $cells)) . "\r\n";
}

$errors = [];
$result = $_SESSION['lhv_merge_result'] ?? null;

if (isset($_GET['download'])) {
    $saved = $_SESSION['lhv_merge_result'] ?? null;
    if (!$saved || empty($saved['csv'])) {
        http_response_code(404);
        exit('Ühendatud CSV puudub. Laadi failid uuesti üles.');
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="LHV_uhendatud_FIFO.csv"');
    header('X-Content-Type-Options: nosniff');
    echo $saved['csv'];
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    unset($_SESSION['lhv_merge_result']);
    $result = null;
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    } else {
        try {
            foreach (['statement_file' => 'LHV kontoväljavõtte', 'trades_file' => 'LHV tehingute'] as $field => $label) {
                if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Vali mõlemad CSV-failid: kontoväljavõte ja tehingute väljavõte.');
                }
                if ((int) $_FILES[$field]['size'] > 10 * 1024 * 1024) {
                    throw new RuntimeException($label . ' fail on suurem kui 10 MB.');
                }
            }

            $bank = lhvReadCsv($_FILES['statement_file']['tmp_name'], 'Kontoväljavõtte');
            $trades = lhvReadCsv($_FILES['trades_file']['tmp_name'], 'Tehingute');
            $bankColumns = [];
            foreach (['Kuupäev', 'Deebet/Kreedit (D/C)', 'Summa', 'Selgitus', 'Kande viide'] as $column) {
                $bankColumns[$column] = lhvColumnIndex($bank['header'], $column);
            }
            $tradeColumns = [];
            foreach (['TEHING', 'SÜMBOL', 'KOGUS', 'HIND', 'VALUUTA', 'NETOSUMMA', 'VIIDE'] as $column) {
                $tradeColumns[$column] = lhvColumnIndex($trades['header'], $column);
            }
            // LHV ekspordis võib kuupäeva päis olla kas TEHINGUPÄEV või VÄÄRTUSPÄEV.
            // Kuupäeva kasutame ainult kontrolli jaoks; imporditav kuupäev jääb pangarealt.
            foreach (['TEHINGUPÄEV', 'VÄÄRTUSPÄEV'] as $dateColumn) {
                foreach ($trades['header'] as $index => $label) {
                    if (lhvHeaderKey((string) $label) === lhvHeaderKey($dateColumn)) {
                        $tradeColumns['TRADE_DATE'] = (int) $index;
                        break 2;
                    }
                }
            }

            $bankByRef = [];
            foreach ($bank['rows'] as $rowIndex => $row) {
                $ref = trim((string) ($row[$bankColumns['Kande viide']] ?? ''));
                if ($ref !== '') $bankByRef[$ref][] = $rowIndex;
            }

            $tradeRefCounts = [];
            foreach ($trades['rows'] as $tradeRow) {
                $ref = trim((string) ($tradeRow[$tradeColumns['VIIDE']] ?? ''));
                if ($ref !== '') $tradeRefCounts[$ref] = ($tradeRefCounts[$ref] ?? 0) + 1;
            }

            $issues = [];
            $matchedBankRows = [];
            $matched = 0;
            foreach ($trades['rows'] as $tradeIndex => $tradeRow) {
                $ref = trim((string) ($tradeRow[$tradeColumns['VIIDE']] ?? ''));
                $actionText = lhvHeaderKey((string) ($tradeRow[$tradeColumns['TEHING']] ?? ''));
                $isBuy = preg_match('/\b(ost|buy)\b/u', $actionText) === 1;
                $isSell = preg_match('/\b(müük|muuk|sell)\b/u', $actionText) === 1;
                $symbol = strtoupper(trim((string) ($tradeRow[$tradeColumns['SÜMBOL']] ?? '')));
                $qtyRaw = trim((string) ($tradeRow[$tradeColumns['KOGUS']] ?? ''));
                $priceRaw = trim((string) ($tradeRow[$tradeColumns['HIND']] ?? ''));
                $qty = lhvAmountCents($qtyRaw);
                $net = lhvAmountCents((string) ($tradeRow[$tradeColumns['NETOSUMMA']] ?? ''));
                $validSymbol = preg_match('/^[A-Z0-9][A-Z0-9._-]{0,29}$/', $symbol) === 1;
                $qtyText = str_replace(',', '.', preg_replace('/\s+/', '', $qtyRaw));
                $qtyText = ltrim($qtyText, '+-');
                $priceText = str_replace(',', '.', preg_replace('/\s+/', '', $priceRaw));
                $validNumber = preg_match('/^\d+(?:\.\d+)?$/', $qtyText) && preg_match('/^\d+(?:\.\d+)?$/', $priceText);

                if ($ref === '' || ($tradeRefCounts[$ref] ?? 0) !== 1) {
                    $issues[] = 'Tehingu real ' . ($tradeIndex + 2) . ' puudub unikaalne VIIDE.';
                    continue;
                }
                if (!$isBuy && !$isSell) {
                    $issues[] = 'Tehingu real ' . ($tradeIndex + 2) . ' ei saanud TEHING veeru järgi ostu või müüki määrata.';
                    continue;
                }
                if (!$validSymbol || !$validNumber || $qty === null || abs($qty) === 0 || $net === null) {
                    $issues[] = 'Tehingu real ' . ($tradeIndex + 2) . ' on sümbol, kogus, hind või netosumma vigane.';
                    continue;
                }

                $expectedDc = $isBuy ? 'D' : 'C';
                $candidateRows = [];
                foreach ($bankByRef[$ref] ?? [] as $bankRowIndex) {
                    $bankRow = $bank['rows'][$bankRowIndex];
                    $description = lhvHeaderKey((string) ($bankRow[$bankColumns['Selgitus']] ?? ''));
                    $dc = strtoupper(trim((string) ($bankRow[$bankColumns['Deebet/Kreedit (D/C)']] ?? '')));
                    $amount = lhvAmountCents((string) ($bankRow[$bankColumns['Summa']] ?? ''));
                    if ($dc !== $expectedDc || strpos($description, 'teenustasu') !== false || $amount === null || abs($amount) !== abs($net)) {
                        continue;
                    }
                    $candidateRows[] = $bankRowIndex;
                }

                if (count($candidateRows) !== 1 || isset($matchedBankRows[$candidateRows[0] ?? -1])) {
                    $issues[] = 'Viitele ' . $ref . ' ei leitud ühte kindlat pangatehingu põhirida (või see rida on juba kasutusel). Teenustasuread jäetakse puutumata.';
                    continue;
                }

                $bankRowIndex = $candidateRows[0];
                $bankDate = trim((string) ($bank['rows'][$bankRowIndex][$bankColumns['Kuupäev']] ?? ''));
                $qtyOut = rtrim(rtrim(number_format((float) $qtyText, 8, '.', ''), '0'), '.');
                $priceOut = rtrim(rtrim(number_format((float) $priceText, 8, '.', ''), '0'), '.');
                if ($isSell) $qtyOut = '-' . $qtyOut;
                $actionEst = $isBuy ? 'ost' : 'müük';
                $bank['rows'][$bankRowIndex][$bankColumns['Selgitus']] = $symbol . ' ' . ($isBuy ? '+' : '') . $qtyOut . '@' . $priceOut . ' LHV väärtpaberite ' . $actionEst . ' (' . $symbol . ') ' . $bankDate . ' viide ' . $ref;
                $matchedBankRows[$bankRowIndex] = true;
                $matched++;
            }

            if ($issues) {
                throw new RuntimeException(implode("\n", $issues));
            }
            if ($matched === 0) {
                throw new RuntimeException('Tehingute väljavõttest ei leitud ühendatavaid ostu- ega müügitehinguid.');
            }

            $csv = "\xEF\xBB\xBF" . lhvCsvLine($bank['header'], $bank['delimiter']);
            foreach ($bank['rows'] as $row) $csv .= lhvCsvLine($row, $bank['delimiter']);

            $result = [
                'csv' => $csv,
                'bank_rows' => count($bank['rows']),
                'trade_rows' => count($trades['rows']),
                'matched' => $matched,
                'fees_preserved' => count($bank['rows']) - $matched,
            ];
            $_SESSION['lhv_merge_result'] = $result;
        } catch (RuntimeException $e) {
            $errors = array_merge($errors, explode("\n", $e->getMessage()));
        } catch (Throwable $e) {
            $errors[] = 'Failide töötlemine ebaõnnestus. Kontrolli, et valitud oleksid LHV kontoväljavõtte ja tehingute CSV-failid.';
        }
    }
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>LHV CSV ühendamine</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .page { max-width: 1100px; margin: 32px auto; padding: 0 18px; }
        .card { background: #fff; border: 1px solid #d8e1ec; border-radius: 12px; padding: 22px; margin: 18px 0; }
        .fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 18px; }
        label { display:block; font-weight:600; margin-bottom:8px; }
        input[type=file] { width:100%; padding:12px; border:1px solid #c9d4e1; border-radius:8px; background:#f8fafc; }
        .muted { color:#617286; }
        .notice { padding:14px 16px; border-radius:8px; margin:14px 0; white-space:pre-line; }
        .error { color:#8d2020; background:#fff0f0; border:1px solid #efbebe; }
        .success { color:#155f35; background:#eaf7ef; border:1px solid #b9e2c8; }
        .actions { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
        .button, button { display:inline-block; padding:11px 16px; border:0; border-radius:8px; background:#1769aa; color:#fff; font-weight:600; text-decoration:none; cursor:pointer; }
        .button:hover, button:hover { background:#11588f; }
    </style>
</head>
<body>
<main class="page">
    <h1>LHV kontoväljavõtte ja tehingute ühendamine</h1>
    <p class="muted">Laadi üles sama perioodi LHV kontoväljavõtte CSV ja väärtpaberite tehingute CSV. Programm seob tehingud veeru „Kande viide” ja „VIIDE” järgi, lisab ostu või müügi sümboli, koguse ning hinna pangarea selgitusse ja jätab teenustasud eraldi ridadeks.</p>

    <?php foreach ($errors as $error): ?>
        <div class="notice error"><?= h($error) ?></div>
    <?php endforeach; ?>

    <?php if ($result): ?>
        <div class="notice success">Ühendatud fail on valmis. Pangaridu: <?= (int) $result['bank_rows'] ?> · tehinguid: <?= (int) $result['trade_rows'] ?> · seotud väärtpaberitehinguid: <?= (int) $result['matched'] ?>. Kõik pangaväljavõtte ülejäänud read, sealhulgas teenustasud, jäid faili alles.</div>
        <section class="card">
            <p>Laadi ühendatud fail alla ja impordi see tavalisest <strong>Impordi CSV</strong> lehelt. Päis jääb LHV pangaväljavõtte päiseks, nii tunneb olemasolev importija selle LHV failina ära. Sümboli, koguse ja hinna lisatud kirjeldus võimaldab pangaväljavõtte lehel FIFO arvestust kasutada.</p>
            <div class="actions">
                <a class="button" href="lhv_merge.php?download=1">Laadi ühendatud LHV CSV alla</a>
                <a class="button" href="bank_import.php">Ava Impordi CSV</a>
            </div>
        </section>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="card">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <div class="fields">
            <div>
                <label for="statement-file">1. LHV kontoväljavõte CSV</label>
                <input id="statement-file" type="file" name="statement_file" accept=".csv,text/csv" required>
            </div>
            <div>
                <label for="trades-file">2. LHV väärtpaberite tehingute CSV</label>
                <input id="trades-file" type="file" name="trades_file" accept=".csv,text/csv" required>
            </div>
        </div>
        <p class="muted">Mõlema faili periood peab kattuma. Kui mõne tehingu viide, summa või D/C suund ei klapi ühe kindla pangareaga, ühendatud faili ei väljastata ja leht näitab, millist rida kontrollida.</p>
        <button type="submit">Ühenda failid ja kontrolli</button>
    </form>
    <p><a href="index.php">Tagasi avalehele</a></p>
</main>
</body>
</html>
