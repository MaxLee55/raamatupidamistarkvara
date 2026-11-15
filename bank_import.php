<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/db.php';

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function parseBankDate(string $value): ?string
{
    $value = trim($value);
    $formats = [
        ['!d.m.Y', 'd.m.Y'],
        ['!Y-m-d', 'Y-m-d'],
        ['!d/m/Y', 'd/m/Y'],
    ];

    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format[0], $value);

        if ($date && $date->format($format[1]) === $value) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}

function parseAmountCents(string $value): ?int
{
    $value = trim($value);
    $value = str_replace(["\xC2\xA0", ' '], '', $value);

    if ($value === '') {
        return null;
    }

    $commaPosition = strrpos($value, ',');
    $dotPosition = strrpos($value, '.');

    if ($commaPosition !== false && $dotPosition !== false) {
        if ($commaPosition > $dotPosition) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
    } elseif ($commaPosition !== false) {
        $value = str_replace(',', '.', $value);
    }

    if (!preg_match('/^-?\d{1,13}(?:\.\d{1,2})?$/', $value)) {
        return null;
    }

    $negative = isset($value[0]) && $value[0] === '-';
    $value = ltrim($value, '-');
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '0');

    $cents = ((int) $whole * 100)
        + (int) str_pad(substr($fraction, 0, 2), 2, '0');

    return $negative ? -$cents : $cents;
}

function money(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

$companyId = (int) $_SESSION['company_id'];
$bankImportNotice = $_SESSION['bank_import_notice'] ?? null;
unset($_SESSION['bank_import_notice']);
$errors = [];
$previewRows = [];
$bankFormat = '';
$openingBalanceInfo = null;
$ignoredRecordCount = 0;
$selectedAccountId = '';
$selectedDelimiter = 'semicolon';

$accountsStmt = $pdo->prepare(
    "SELECT id, code, name
     FROM accounts
     WHERE company_id = :company_id
       AND is_active = 1
       AND account_type = 'asset'
     ORDER BY code"
);
$accountsStmt->execute(['company_id' => $companyId]);
$bankAccounts = $accountsStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        empty($_SESSION['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')
    ) {
        $errors[] = 'Vorm aegus. Värskenda lehte ja proovi uuesti.';
    } else {
        $selectedAccountId = (string) ($_POST['bank_account_id'] ?? '');
        $selectedDelimiter = (string) ($_POST['delimiter'] ?? 'semicolon');

        $accountExists = false;
        foreach ($bankAccounts as $bankAccount) {
            if ((string) $bankAccount['id'] === $selectedAccountId) {
                $accountExists = true;
                break;
            }
        }

        if (!$accountExists) {
            $errors[] = 'Vali oma ettevõtte aktiivne pangakonto.';
        }

        $delimiters = [
            'semicolon' => ';',
            'comma' => ',',
            'tab' => "\t",
        ];

        if (!isset($delimiters[$selectedDelimiter])) {
            $errors[] = 'Vali CSV-faili eraldaja.';
        }

        if (!isset($_FILES['csv_file'])) {
            $errors[] = 'Vali arvutist panga CSV-fail.';
        } elseif ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'CSV-faili üleslaadimine ebaõnnestus.';
        } elseif ($_FILES['csv_file']['size'] > 5 * 1024 * 1024) {
            $errors[] = 'CSV-fail võib olla kuni 5 MB.';
        } elseif (
            strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION))
            !== 'csv'
        ) {
            $errors[] = 'Vali .csv-laiendiga fail.';
        }

        if (!$errors) {
            $handle = fopen($_FILES['csv_file']['tmp_name'], 'rb');

            if ($handle === false) {
                $errors[] = 'CSV-faili ei õnnestunud lugeda.';
            } else {
                $headerLine = fgets($handle);
                $header = false;
                if ($headerLine !== false) {
                    $headerLine = preg_replace('/^\xEF\xBB\xBF/', '', $headerLine);
                    $headerLine = rtrim($headerLine, "\r\n");
                    $bestHeader = [];
                    $bestDelimiterKey = $selectedDelimiter;
                    $bestColumnCount = 0;
                    foreach ($delimiters as $delimiterKey => $candidateDelimiter) {
                        $candidateHeader = str_getcsv($headerLine, $candidateDelimiter, '"', '\\');
                        if (count($candidateHeader) > $bestColumnCount) {
                            $bestHeader = $candidateHeader;
                            $bestDelimiterKey = $delimiterKey;
                            $bestColumnCount = count($candidateHeader);
                        }
                    }
                    if ($bestColumnCount > 1) {
                        $selectedDelimiter = $bestDelimiterKey;
                        $delimiter = $delimiters[$selectedDelimiter];
                        $header = $bestHeader;
                    }
                }

                if ($header === false) {
                    $errors[] = 'CSV-failist ei leitud päiserida.';
                } else {
                    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
                    $headerNames = array_map(
                        static function ($name): string {
                            $name = preg_replace('/^\xEF\xBB\xBF/', '', (string) $name);
                            return mb_strtolower(trim($name, " \t\r\n\0\x0B\""), 'UTF-8');
                        },
                        $header
                    );

                    $swedbankLabels = [
                        'account_number' => 'kliendi konto',
                        'record_type' => 'reatüüp',
                        'date' => 'kuupäev',
                        'counterparty_name' => 'saaja/maksja',
                        'description' => 'selgitus',
                        'amount' => 'summa',
                        'currency' => 'valuuta',
                        'direction' => 'deebet/kreedit',
                        'archive_reference' => 'arhiveerimistunnus',
                        'type' => 'tehingu tüüp',
                        'reference_number' => 'viitenumber',
                        'document_number' => 'dokumendi number',
                    ];

                    $mapHeaders = static function (array $labels, array $headers, array $required): ?array {
                        $indexes = [];
                        foreach ($labels as $key => $label) {
                            $index = array_search($label, $headers, true);
                            if ($index === false) {
                                if (in_array($key, $required, true)) {
                                    return null;
                                }
                                continue;
                            }
                            $indexes[$key] = $index;
                        }
                        return $indexes;
                    };

                    $swedbankIndexes = $mapHeaders(
                        $swedbankLabels,
                        $headerNames,
                        array_keys($swedbankLabels)
                    );

                    $lhvLabels = [
                        'account_number' => 'kliendi konto',
                        'document_number' => 'dokumendi number',
                        'date' => 'kuupäev',
                        'counterparty_name' => 'saaja/maksja nimi',
                        'bank_code' => 'saaja/maksja panga bic',
                        'direction' => 'deebet/kreedit (d/c)',
                        'amount' => 'summa',
                        'reference_number' => 'viitenumber',
                        'archive_reference' => 'arhiveerimistunnus',
                        'description' => 'selgitus',
                        'currency' => 'valuuta',
                    ];
                    $lhvIndexes = $mapHeaders(
                        $lhvLabels,
                        $headerNames,
                        ['account_number', 'document_number', 'date', 'counterparty_name', 'direction', 'amount', 'archive_reference', 'description', 'currency']
                    );

                    $coopLabels = [
                        'account_number' => 'kliendi konto',
                        'date' => 'kuupäev',
                        'counterparty_name' => 'saaja/maksja nimi',
                        'direction' => 'deebet/kreedit',
                        'amount' => 'summa',
                        'currency' => 'valuuta',
                        'description' => 'selgitus',
                        'reference_number' => 'viitenumber',
                        'fee' => 'teenustasu',
                        'document_number' => 'dokumendi number',
                        'archive_reference' => 'arhiveerimistunnus',
                    ];
                    $coopIndexes = $mapHeaders(
                        $coopLabels,
                        $headerNames,
                        ['account_number', 'date', 'counterparty_name', 'direction', 'amount', 'currency', 'description', 'fee', 'archive_reference']
                    );

                    $sebLabels = [
                        'account_number' => 'kliendi konto',
                        'document_number' => 'dokumendi number',
                        'date' => 'kuupäev',
                        'counterparty_name' => 'saaja/maksja nimi',
                        'bank_code' => 'saaja panga kood',
                        'type' => 'tüüp',
                        'direction' => 'deebet/kreedit (d/c)',
                        'amount' => 'summa',
                        'reference_number' => 'viitenumber',
                        'archive_reference' => 'arhiveerimistunnus',
                        'description' => 'selgitus',
                        'fee' => 'teenustasu',
                        'currency' => 'valuuta',
                    ];
                    $sebIndexes = $mapHeaders(
                        $sebLabels,
                        $headerNames,
                        array_keys($sebLabels)
                    );

                    if ($swedbankIndexes !== null) {
                        $bankFormat = 'Swedbank';
                        $indexes = $swedbankIndexes;
                    } elseif ($lhvIndexes !== null) {
                        $bankFormat = 'LHV';
                        $indexes = $lhvIndexes;
                    } elseif ($coopIndexes !== null) {
                        $bankFormat = 'Coop';
                        $indexes = $coopIndexes;
                    } elseif ($sebIndexes !== null) {
                        $bankFormat = 'SEB';
                        $indexes = $sebIndexes;
                    } else {
                        $errors[] =
                            'CSV päist ei tuntud ära. Toetatud on Swedbanki, SEB, LHV ja Coopi näidisvormingud.';
                    }

                    if (!$errors) {
                        $lineNumber = 1;
                        $minimumColumns = max($indexes) + 1;

                        while (($columns = fgetcsv(
                            $handle,
                            1000000,
                            $delimiter,
                            '"',
                            '\\'
                        )) !== false) {
                            $lineNumber++;

                            if (count($columns) === 1 && trim((string) $columns[0]) === '') {
                                continue;
                            }

                            if (count($columns) < $minimumColumns) {
                                $errors[] =
                                    'CSV real ' . $lineNumber . ' puuduvad vajalikud veerud.';
                                continue;
                            }

                            if ($bankFormat === 'Swedbank') {
                                $recordType = trim((string) $columns[$indexes['record_type']]);

                                if ($recordType === '10') {
                                    $openingDate = parseBankDate(
                                        (string) $columns[$indexes['date']]
                                    );
                                    $openingAmount = parseAmountCents(
                                        (string) $columns[$indexes['amount']]
                                    );
                                    $openingDirection = strtoupper(trim(
                                        (string) $columns[$indexes['direction']]
                                    ));
                                    $openingCurrency = strtoupper(trim(
                                        (string) $columns[$indexes['currency']]
                                    ));

                                    if (
                                        $openingDate === null
                                        || $openingAmount === null
                                        || !in_array($openingDirection, ['D', 'K'], true)
                                    ) {
                                        $errors[] =
                                            'CSV real ' . $lineNumber . ' on vigane algsaldo rida.';
                                        continue;
                                    }

                                    if ($openingCurrency !== '' && $openingCurrency !== 'EUR') {
                                        $errors[] =
                                            'CSV algsaldo valuuta peab olema EUR.';
                                        continue;
                                    }

                                    $openingBalanceInfo = [
                                        'date' => $openingDate,
                                        'amount_cents' => abs($openingAmount),
                                        'direction' => $openingDirection,
                                        'currency' => $openingCurrency ?: 'EUR',
                                    ];
                                    continue;
                                }

                                // Impordime ainult päris tehinguid. Koond- ja
                                // lõppsaldo read ei ole eraldi pangatehingud.
                                if ($recordType !== '20') {
                                    $ignoredRecordCount++;
                                    continue;
                                }
                            }

                            $date = parseBankDate(
                                (string) $columns[$indexes['date']]
                            );
                            $direction = strtoupper(trim(
                                (string) $columns[$indexes['direction']]
                            ));
                            $amountCents = parseAmountCents(
                                (string) $columns[$indexes['amount']]
                            );
                            $currency = strtoupper(trim(
                                (string) $columns[$indexes['currency']]
                            ));

                            if ($bankFormat === 'Swedbank' && $direction === 'K') {
                                // K tähendab pangakontole laekumist;
                                // C on rakenduse sisemine vastav tähis.
                                $direction = 'C';
                            }

                            $feeCents = isset($indexes['fee'])
                                ? (trim((string) $columns[$indexes['fee']]) === ''
                                    ? 0
                                    : parseAmountCents((string) $columns[$indexes['fee']]))
                                : 0;

                            if ($date === null) {
                                $errors[] =
                                    'CSV real ' . $lineNumber . ' on vigane kuupäev.';
                                continue;
                            }

                            if (!in_array($direction, ['D', 'C'], true)) {
                                $errors[] =
                                    'CSV real ' . $lineNumber
                                    . ' peab deebet/kreedit veerus olema D, C või Swedbanki K.';
                                continue;
                            }

                            if ($amountCents === null || $amountCents === 0) {
                                $errors[] =
                                    'CSV real ' . $lineNumber
                                    . ' on vigane või nullsummaga tehing.';
                                continue;
                            }

                            if ($feeCents === null) {
                                $errors[] =
                                    'CSV real ' . $lineNumber . ' on vigane teenustasu.';
                                continue;
                            }

                            if ($currency !== '' && $currency !== 'EUR') {
                                $errors[] =
                                    'CSV real ' . $lineNumber
                                    . ' on valuuta ' . h($currency)
                                    . '. Praegu impordime ainult eurotehinguid.';
                                continue;
                            }

                            $getValue = static function (
                                array $row,
                                array $map,
                                string $key
                            ): string {
                                return isset($map[$key])
                                    ? trim((string) ($row[$map[$key]] ?? ''))
                                    : '';
                            };

                            $previewRows[] = [
                                'date' => $date,
                                'account_number' => $getValue(
                                    $columns,
                                    $indexes,
                                    'account_number'
                                ),
                                'record_type' => $getValue(
                                    $columns,
                                    $indexes,
                                    'record_type'
                                ),
                                'document_number' => $getValue(
                                    $columns,
                                    $indexes,
                                    'document_number'
                                ),
                                'counterparty_name' => $getValue(
                                    $columns,
                                    $indexes,
                                    'counterparty_name'
                                ),
                                'bank_code' => $getValue($columns, $indexes, 'bank_code'),
                                'type' => $getValue($columns, $indexes, 'type'),
                                'direction' => $direction,
                                'amount_cents' => abs($amountCents),
                                'reference_number' => $getValue(
                                    $columns,
                                    $indexes,
                                    'reference_number'
                                ),
                                'archive_reference' => $getValue(
                                    $columns,
                                    $indexes,
                                    'archive_reference'
                                ),
                                'description' => $getValue(
                                    $columns,
                                    $indexes,
                                    'description'
                                ),
                                'fee_cents' => abs($feeCents),
                                'currency' => $currency !== '' ? $currency : 'EUR',
                            ];

                            if (count($previewRows) > 2000) {
                                $errors[] = 'Ühes failis võib olla kuni 2000 tehingurida.';
                                break;
                            }
                        }

                        if (!$errors && $previewRows) {
                            $sourceFileName = basename(str_replace('\\', '/', (string) $_FILES['csv_file']['name']));
                            $sourceFileName = preg_replace('/[\\x00-\\x1F\\x7F]/u', '', $sourceFileName);
                            $sourceFileName = function_exists('mb_substr')
                                ? mb_substr($sourceFileName, 0, 255, 'UTF-8')
                                : substr($sourceFileName, 0, 255);
                            $_SESSION['bank_import_preview'] = [
                                'company_id' => $companyId,
                                'bank_account_id' => (int) $selectedAccountId,
                                'import_batch_id' => bin2hex(random_bytes(16)),
                                'source_file_name' => $sourceFileName,
                                'rows' => $previewRows,
                            ];
                        }

                        if (!$errors && !$previewRows) {
                            $errors[] = 'CSV-failist ei leitud ühtegi imporditavat tehingurida.';
                        }
                    }
                }

                fclose($handle);
            }

            if (!$errors && !$previewRows) {
                $errors[] = 'CSV-failist ei leitud ühtegi tehingurida.';
            }
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
    <title>Panga CSV eelvaade</title>

    <style>
        body {
            margin: 32px;
            font-family: Arial, sans-serif;
            color: #263746;
        }

        h1, h2 {
            color: #17324d;
        }

        .card {
            max-width: 760px;
            margin: 20px 0;
            padding: 22px;
            background: #f7f9fc;
            border: 1px solid #dce3ec;
            border-radius: 12px;
        }

        .field {
            margin: 14px 0;
        }

        .field label {
            display: block;
            margin-bottom: 7px;
            font-weight: 600;
        }

        .field select,
        .field input[type="file"] {
            box-sizing: border-box;
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #b8c4d1;
            border-radius: 7px;
            background: #fff;
            font: inherit;
        }

        .button {
            display: inline-block;
            padding: 11px 18px;
            border: 0;
            border-radius: 7px;
            background: #1769aa;
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }

        .button:hover {
            background: #0f4f83;
        }

        .error {
            margin: 10px 0;
            padding: 12px 15px;
            border: 1px solid #e5a3a3;
            border-radius: 8px;
            background: #fff0f0;
            color: #a32121;
        }

        .preview-note {
            padding: 12px 15px;
            border: 1px solid #b8dfc7;
            border-radius: 8px;
            background: #eaf7ef;
            color: #176b3a;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            margin: 18px 0;
            border-collapse: collapse;
        }

        th, td {
            padding: 10px 12px;
            border-bottom: 1px solid #e5eaf0;
            text-align: left;
        }

        th {
            background: #f3f6fa;
            color: #526273;
            font-size: 13px;
        }

        .number {
            text-align: right;
            white-space: nowrap;
        }

        @media (max-width: 600px) {
            body {
                margin: 18px;
            }
        }
    </style>
</head>
<body>
    <h1>Panga CSV või portfelli algsaldo eelvaade</h1>

    <p><a class="button" href="lhv_merge.php">Ühenda LHV kontoväljavõte ja tehingute CSV</a></p>

    <p>
        Vali ettevõtte pangakonto ning Swedbanki, SEB, LHV või Coopi pangaväljavõtte CSV. Eelvaate järel saad read salvestada panga tehingutena või portfelli algsaldona. LHV näidisfail kasutab komaeraldit ja Coop näidisfail semikooloneraldit. Swedbanki algsaldo rida kuvatakse eraldi ega impordita tehinguna.
        Eelvaade ei salvesta faili ega tehinguid andmebaasi.
    </p>

    <?php foreach ($errors as $error): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endforeach; ?>

    <?php if ($bankImportNotice): ?>
        <p class="<?= $bankImportNotice['type'] === 'success' ? 'preview-note' : 'error' ?>">
            <?= h($bankImportNotice['text']) ?>
        </p>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="card">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">

        <div class="field">
            <label for="bank-account">Pangakonto kontoplaanis</label>
            <select id="bank-account" name="bank_account_id" required>
                <option value="">Vali varakonto</option>
                <?php foreach ($bankAccounts as $account): ?>
                    <option
                        value="<?= (int) $account['id'] ?>"
                        <?= (string) $account['id'] === $selectedAccountId ? 'selected' : '' ?>
                    >
                        <?= h($account['code'] . ' — ' . $account['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="delimiter">CSV väljade eraldaja</label>
            <select id="delimiter" name="delimiter" required>
                <option value="semicolon" <?= $selectedDelimiter === 'semicolon' ? 'selected' : '' ?>>
                    Semikoolon (;)
                </option>
                <option value="comma" <?= $selectedDelimiter === 'comma' ? 'selected' : '' ?>>
                    Koma (,)
                </option>
                <option value="tab" <?= $selectedDelimiter === 'tab' ? 'selected' : '' ?>>
                    Tabeldusmärk
                </option>
            </select>
            <small class="muted">Eraldaja leitakse tavaliselt päisereast automaatselt. Sinu LHV fail kasutab koma (,) ja Coopi fail semikoolonit (;).</small>
        </div>

        <div class="field">
            <label for="csv-file">Panga või portfelli CSV-fail</label>
            <input
                id="csv-file"
                type="file"
                name="csv_file"
                accept=".csv,text/csv"
                required
            >
        </div>

        <button class="button" type="submit">Näita eelvaadet</button>
    </form>

    <?php if ($openingBalanceInfo): ?>
        <p class="preview-note">
            Swedbanki algsaldo seisuga <?= h($openingBalanceInfo['date']) ?>:
            <?= money($openingBalanceInfo['amount_cents']) ?> (<?= h($openingBalanceInfo['direction']) ?>).
            Seda rida ei impordita tehinguna.
        </p>
    <?php endif; ?>

    <?php if ($bankFormat === 'Swedbank' && $ignoredRecordCount > 0): ?>
        <p class="preview-note">
            Vahele jäetud mitte-tehingulisi ridu: <?= (int) $ignoredRecordCount ?>.
        </p>
    <?php endif; ?>
    <?php if ($previewRows): ?>
        <section>
            <h2>Leitud tehingud: <?= count($previewRows) ?></h2>

            <p class="preview-note">
                See on ainult eelvaade. Praegu pole ühtegi tehingut imporditud.
            </p>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Kuupäev</th>
                            <th>Tüüp</th>
                            <th>D/C</th>
                            <th>Saaja/maksja</th>
                            <th>Selgitus</th>
                            <th class="number">Summa</th>
                            <th class="number">Teenustasu</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($previewRows as $row): ?>
                            <tr>
                                <td><?= h($row['date']) ?></td>
                                <td><?= h($row['type']) ?></td>
                                <td><?= h($row['direction']) ?></td>
                                <td><?= h($row['counterparty_name']) ?></td>
                                <td><?= h($row['description']) ?></td>
                                <td class="number"><?= money($row['amount_cents']) ?></td>
                                <td class="number"><?= money($row['fee_cents']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
<form method="post" action="save_bank_statement.php" class="card">
    <input
        type="hidden"
        name="csrf_token"
        value="<?= h($_SESSION['csrf_token']) ?>"
    >

    <p>
        Imporditakse <?= count($previewRows) ?> pangatehingut valitud pangakonto alla.
        See ei loo pearaamatu kandeid.
    </p>

    <button
        class="button"
        type="submit"
        onclick="return confirm('Kas impordin need pangatehingud?')"
    >
        Impordi need read
    </button>
</form>

<?php if ($previewRows): ?>
    <form method="post" action="save_portfolio_opening.php" class="card">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= h($_SESSION['csrf_token']) ?>"
        >

        <p>
            Kasuta seda nuppu ainult varasema väärtpaberiportfelli osturidade jaoks.
            Read salvestatakse eraldi algsaldo tabelisse FIFO arvestuse alusandmetena;
            pearaamatu kandeid siin ei looda.
        </p>

        <button
            class="button"
            type="submit"
            onclick="return confirm('Kas salvestan eelvaates olevad read portfelli algsaldo tabelisse?')"
        >
            Lisa portfelli algsaldo
        </button>
    </form>
<?php endif; ?>

    <p><a class="button" href="portfelli_algsaldo.php">Vaata portfelli algsaldo ridu</a> <a class="button" href="index.php">Tagasi avalehele</a></p>
</body>
</html>
