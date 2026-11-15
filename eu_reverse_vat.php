<?php
/** EU B2B acquisitions taxed by the Estonian buyer; supplier VAT stays zero. */
function euReverseSpec(string $code): ?array
{
    if (!preg_match('/^O_EU_(GOODS|SERVICES)(24|13|9)$/', $code, $m)) return null;
    return ['kind' => strtolower($m[1]), 'rate' => (int) $m[2],
        'valid_from' => $m[2] === '24' ? '2025-07-01' : '2025-01-01'];
}

function euReverseVat(int $netCents, int $rate): int
{
    if ($netCents < 0) throw new RuntimeException('Pöördmaksustamise kreeditarved vajavad eraldi algse arve käsitlust.');
    return intdiv($netCents * $rate + 50, 100);
}

function euReverseSupplierError(string $country, string $vatNumber): ?string
{
    $country = strtoupper(trim($country));
    $eu = ['AT','BE','BG','HR','CY','CZ','DK','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE'];
    if (!in_array($country, $eu, true)) return 'EL pöördmaksustamine vajab teise EL liikmesriigi tarnijat. Kontrolli kontakti riigikoodi.';
    $vatNumber = strtoupper(preg_replace('/\s+/', '', $vatNumber));
    $prefix = $country === 'GR' ? 'EL' : $country;
    if (!preg_match('/^' . $prefix . '[A-Z0-9]{2,12}$/', $vatNumber)) {
        return 'EL pöördmaksustamine vajab tarnija riigiga sobivat KMKR numbrit. Kontrolli kontakti KMKR numbrit.';
    }
    return null;
}

function euReversePostingLines(int $vatCents, int $inputAccount, int $outputAccount, int $payableAccount, array $types): array
{
    if ($vatCents <= 0) return [];
    if (($types[$inputAccount] ?? '') !== 'asset') throw new RuntimeException('Vali pöördmaksu sisendkäibemaksu varakonto.');
    if (($types[$outputAccount] ?? '') !== 'liability' || $outputAccount === $payableAccount) {
        throw new RuntimeException('Vali pöördmaksu käibemaksukohustuse konto, mis erineb tarnijavõla kontost.');
    }
    return [
        ['account_id' => $inputAccount, 'description' => 'Pöördmaksu sisendkäibemaks', 'debit_cents' => $vatCents, 'credit_cents' => 0],
        ['account_id' => $outputAccount, 'description' => 'Pöördmaksu käibemaksukohustus', 'debit_cents' => 0, 'credit_cents' => $vatCents],
    ];
}

/** All supported reverse-charge transactions; supplier charged VAT remains zero. */
function reverseVatSpec(string $code): ?array
{
    if ($code === 'O_EU_GOODS_EXEMPT') {
        return ['scope'=>'eu','side'=>'purchase','kind'=>'goods','rate'=>0,'valid_from'=>'2004-05-01','exempt'=>true];
    }
    $eu = euReverseSpec($code);
    if ($eu) return $eu + ['scope' => 'eu', 'side' => 'purchase'];
    if (preg_match('/^(O|M)_EE_REVERSE24$/', $code, $m)) {
        return ['scope'=>'domestic','side'=>$m[1]==='O'?'purchase':'sales','kind'=>'goods','rate'=>24,'valid_from'=>'2025-07-01'];
    }
    if (preg_match('/^O_NON_EU_SERVICES(24|9)$/', $code, $m)) {
        return ['scope'=>'non_eu','side'=>'purchase','kind'=>'services','rate'=>(int)$m[1],
            'valid_from'=>$m[1]==='24'?'2025-07-01':'2025-01-01'];
    }
    return null;
}

function reversePartyError(array $spec, string $country, string $vatNumber): ?string
{
    $country = strtoupper(trim($country));
    $vatNumber = strtoupper(preg_replace('/\s+/', '', $vatNumber));
    if ($spec['scope'] === 'eu') return euReverseSupplierError($country,$vatNumber);
    if ($spec['scope'] === 'domestic') {
        if ($country !== 'EE' || !preg_match('/^EE[0-9]{9}$/', $vatNumber)) {
            return 'Siseriiklik pöördmaksustamine vajab Eesti partnerit ja tema Eesti KMKR numbrit.';
        }
    } else {
        $eu = ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE','EL','XI'];
        if (!preg_match('/^[A-Z]{2}$/',$country) || in_array($country,$eu,true) || in_array($country,['EU','ZZ','XX'],true)) {
            return 'EL-välise teenuse pöördmaksustamine vajab tarnija tegelikku EL-välist riigikoodi.';
        }
    }
    return null;
}

function reverseVatLabel(array $spec): string
{
    return ['eu'=>'EL pöördmaks','domestic'=>'Siseriiklik pöördmaks','non_eu'=>'EL-välise teenuse pöördmaks'][$spec['scope']];
}

function ensureForeignPurchaseContactSchema(PDO $pdo): void
{
    if ($pdo->query("SHOW COLUMNS FROM contacts LIKE 'estonia_vat_number'")->fetch()) return;
    try {
        $pdo->exec('ALTER TABLE contacts ADD COLUMN estonia_vat_number VARCHAR(11) NULL');
    } catch (PDOException $error) {
        if ((int) ($error->errorInfo[1] ?? 0) !== 1060) throw $error;
    }
}

function foreignPurchaseEstonianVat(array $partner): ?string
{
    $vat = strtoupper(preg_replace('/\s+/', '', (string) ($partner['estonia_vat_number'] ?? $partner['vat_number'] ?? '')));
    return preg_match('/^EE[0-9]{9}$/', $vat) ? $vat : null;
}

function foreignPurchaseSupplierVatError(array $partner, int $supplierVat): ?string
{
    if ($supplierVat !== 0 && strtoupper(trim((string) $partner['country_code'])) !== 'EE'
        && foreignPurchaseEstonianVat($partner) === null) {
        return 'Eesti käibemaksuga rea jaoks lisa tarnija kontaktile eraldi Eesti KMKR number. Välisriigi käibemaks ei ole Eesti sisendkäibemaks.';
    }
    return null;
}

function foreignReverseDeductionCode(array $spec, string $purpose): ?string
{
    $eu = $spec['scope'] === 'eu';
    $goods = $eu && $spec['kind'] === 'goods';
    return match ($purpose) {
        'ordinary' => $goods ? 'O_401' : ($eu ? 'O_402' : 'O_404'),
        'fixed_asset' => $goods ? 'O_204' : null,
        'fixed_car_full' => $goods ? 'O_303' : null,
        'fixed_car_partial' => $goods ? 'O_306' : null,
        'car_full' => $eu ? 'O_308' : null,
        'car_partial' => $eu ? 'O_310' : null,
        default => null,
    };
}

function reverseCompanyError(PDO $pdo, int $companyId, array $spec): ?string
{
    if ($spec['scope'] !== 'domestic') return null;
    $stmt=$pdo->prepare('SELECT vat_number FROM companies WHERE id = ?');
    $stmt->execute([$companyId]);
    $vat=strtoupper(preg_replace('/\s+/','',(string)$stmt->fetchColumn()));
    return preg_match('/^EE[0-9]{9}$/',$vat) ? null : 'Siseriiklik pöördmaksustamine vajab ettevõtte seadetes Eesti KMKR numbrit.';
}
