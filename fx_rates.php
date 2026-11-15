<?php

function fxCurrency(string $value): string
{
    $currency = strtoupper(trim($value));
    if (!preg_match('/^[A-Z]{3}$/D', $currency)) throw new InvalidArgumentException('Sisesta kolmetäheline valuutakood, näiteks USD.');
    return $currency;
}

function fxDate(string $value): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value || $value < '1999-01-01' || $value > date('Y-m-d')) {
        throw new InvalidArgumentException('Sisesta kehtiv kuupäev, mis ei ole tulevikus.');
    }
    return $value;
}

function fxDecimal(string $value, int $places, string $label, bool $allowZero = false): string
{
    $value = str_replace(',', '.', trim($value));
    if (!preg_match('/^\d{1,9}(?:\.\d{1,'.$places.'})?$/D', $value)
        || (!$allowZero && !preg_match('/[1-9]/', $value))) {
        throw new InvalidArgumentException($label.': sisesta '.($allowZero ? 'null või ' : '').'positiivne summa kuni '.$places.' komakohaga.');
    }
    return bcadd($value, '0', $places);
}

function fxEuroCents(string $amount, string $rate = '1'): int
{
    $cents = bcdiv(bcmul($amount, '100', 8), $rate, 1);
    $rounded = bcadd($cents, '0.5', 0);
    if (bccomp($rounded, '999999999999', 0) > 0) throw new InvalidArgumentException('Euroväärtus ületab lubatud piiri.');
    return (int) $rounded;
}

/** ECB CSV contains only the requested currency. The most recent published rate at or before the date wins. */
function fxParseEcb(string $csv, string $currency, string $first, string $last): array
{
    $rows = preg_split('/\r\n|\n|\r/', trim($csv));
    $header = str_getcsv(ltrim(array_shift($rows) ?? '', "\xEF\xBB\xBF"), ',', '"', '');
    $fields = array_flip($header);
    foreach (['CURRENCY','CURRENCY_DENOM','TIME_PERIOD','OBS_VALUE'] as $field) {
        if (!isset($fields[$field])) throw new RuntimeException('EKP kursi vastuse vorming on vigane.');
    }
    $best = null;
    foreach ($rows as $line) {
        $row = str_getcsv($line, ',', '"', '');
        $date = $row[$fields['TIME_PERIOD']] ?? '';
        if (($row[$fields['CURRENCY']] ?? '') !== $currency || ($row[$fields['CURRENCY_DENOM']] ?? '') !== 'EUR'
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || $date < $first || $date > $last
            || ($best !== null && $date <= $best['date'])) continue;
        $rate = $row[$fields['OBS_VALUE']] ?? '';
        if (!preg_match('/^\d{1,9}(?:\.\d{1,10})?$/D', $rate) || bccomp($rate, '0', 10) <= 0) continue;
        $best = ['rate'=>$rate, 'date'=>$date, 'source'=>'ecb'];
    }
    if ($best === null) throw new RuntimeException('EKP kurssi selleks kuupäevaks ei leitud. Sisesta dokumenteeritud käsikurss.');
    return $best;
}

function fxEcbQuote(string $currency, string $date): array
{
    $currency = fxCurrency($currency);
    $date = fxDate($date);
    if ($currency === 'EUR') return ['rate'=>'1', 'date'=>$date, 'source'=>'eur'];
    $first = (new DateTimeImmutable($date))->modify('-7 days')->format('Y-m-d');
    $url = 'https://data-api.ecb.europa.eu/service/data/EXR/D.'.$currency.'.EUR.SP00.A?'
        .http_build_query(['startPeriod'=>$first, 'endPeriod'=>$date, 'format'=>'csvdata']);
    $handle = curl_init($url);
    $body = '';
    curl_setopt_array($handle, [
        CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_TIMEOUT=>8, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_HTTPHEADER=>['Accept: text/csv'],
        CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 65536) return 0;
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);
    if (defined('CURLSSLOPT_NATIVE_CA')) curl_setopt($handle, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    $success = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    unset($handle);
    if (!$success || $status !== 200) {
        throw new RuntimeException('EKP kurssi ei õnnestunud laadida. Proovi hiljem või sisesta dokumenteeritud käsikurss.');
    }
    return fxParseEcb($body, $currency, $first, $date);
}
