<?php

/**
 * Fetches public company autocomplete results from the Estonian e-Business Register.
 * Returns an empty list when the service is temporarily unavailable.
 */
function fetchBusinessRegistryCompanies(string $query): array
{
    $query = trim($query);
    if ($query === '' || mb_strlen($query, 'UTF-8') < 3) {
        return [];
    }

    $url = 'https://ariregister.rik.ee/est/api/autocomplete?q=' . rawurlencode($query);
    $body = businessRegistryRequest($url);
    if ($body === null) return [];

    $decoded = json_decode($body, true);
    if (!is_array($decoded) || ($decoded['status'] ?? '') !== 'OK' || !is_array($decoded['data'] ?? null)) {
        return [];
    }

    $companies = [];
    foreach ($decoded['data'] as $company) {
        if (!is_array($company) || empty($company['name']) || empty($company['reg_code'])) {
            continue;
        }

        $companies[] = [
            'name' => (string) $company['name'],
            'registry_code' => (string) $company['reg_code'],
            'address' => (string) ($company['legal_address'] ?? ''),
            'status' => (string) ($company['status'] ?? ''),
            'url' => (string) ($company['url'] ?? ''),
        ];
    }

    return $companies;
}

/** Fetch only fixed RIK endpoints supplied by this module. */
function businessRegistryRequest(string $url): ?string
{
    $body = false;

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'Raamatupidamine/1.0',
        ]);
        $response = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (is_string($response) && $statusCode >= 200 && $statusCode < 300) {
            $body = $response;
        }
    } elseif (ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 6,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: Raamatupidamine/1.0\r\n",
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        if (is_string($response)) {
            $body = $response;
        }
    }

    if (!is_string($body)) {
        return null;
    }
    return $body;
}

/** Reads the current VAT registration, excluding the historical registrations table. */
function businessRegistryVatNumberFromHtml(string $html): ?string
{
    $document = new DOMDocument();
    $previousErrors = libxml_use_internal_errors(true);
    try {
        if (!$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET)) return null;
        $xpath = new DOMXPath($document);
        $rows = $xpath->query('//div[normalize-space(.)="Käibemaksukohustuslase number"]/parent::div');
        foreach ($rows as $row) {
            $number = trim((string) $xpath->evaluate('string(./div[2])', $row));
            $period = trim((string) $xpath->evaluate('string(../div[div[normalize-space(.)="Käibemaksukohustuslase periood"]]/div[2])', $row));
            if (preg_match('/^EE[0-9]{9}$/', $number)
                && preg_match('/^(\d{2}\.\d{2}\.\d{4})\s*[–-]\s*\.\.$/u', $period, $matches)) {
                $start = DateTimeImmutable::createFromFormat('!d.m.Y', $matches[1], new DateTimeZone('Europe/Tallinn'));
                if ($start && $start->format('d.m.Y') === $matches[1]
                    && $start <= new DateTimeImmutable('today', new DateTimeZone('Europe/Tallinn'))) return $number;
            }
        }
        return null;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
    }
}

function fetchBusinessRegistryVatNumber(string $registryCode): ?string
{
    if (!preg_match('/^[0-9]{8}$/', $registryCode)) return null;
    $html = businessRegistryRequest('https://ariregister.rik.ee/est/company/' . $registryCode);
    return $html === null ? null : businessRegistryVatNumberFromHtml($html);
}