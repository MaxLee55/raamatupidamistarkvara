<?php
require_once __DIR__ . '/eu_reverse_vat.php';
require_once __DIR__ . '/credit_note_lib.php';
function infCents(string $amount): int
{
    $amount = trim($amount);
    if (!preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $amount, $parts)) {
        throw new RuntimeException('Vigane rahasumma: ' . $amount);
    }
    $cents = ((int) $parts[2] * 100) + (int) str_pad($parts[3] ?? '', 2, '0');
    return ($parts[1] ?? '') === '-' ? -$cents : $cents;
}

function infEuros(int $cents): string
{
    return number_format($cents / 100, 2, ',', ' ') . ' €';
}

function contactInfStatus(array $invoice): array
{
    $category = $invoice['kmd_party_category'] === null ? null : (int) $invoice['kmd_party_category'];
    $registryCode = preg_replace('/\s+/', '', (string) ($invoice['registry_code'] ?? ''));
    $country = strtoupper(trim((string) ($invoice['country_code'] ?? 'EE')));
    $name = trim((string) ($invoice['contact_name'] ?? ''));

    if ($category === 1 || $category === 3) {
        $review = ($registryCode === '' && trim((string) ($invoice['vat_number'] ?? '')) === '')
            ? ['Partneri tunnus puudub; lisa registri- või KMKR number.'] : [];
        return ['kind' => 'business', 'label' => $category === 3 ? 'KM-grupi liige' : 'Ettevõte / FIE', 'review' => $review];
    }
    if ($category === 2 && preg_match('/^\d{11}$/', $registryCode)) {
        return ['kind' => 'person', 'label' => 'Eraisik', 'review' => []];
    }
    if (preg_match('/^(kassamüük|eraklient|eraisik)(\b|[-\s])/iu', $name)) {
        return ['kind' => 'person', 'label' => 'Eraisik / kassamüük', 'review' => []];
    }
    if ($category === 2) {
        return ['kind' => 'unknown', 'label' => 'Kategooria 2 vajab täpsustamist', 'review' => ['Kontakti KMD kategooria 2 ühendab eraisiku ja välismaa ettevõtte.']];
    }
    if (preg_match('/^\d{8}$/', $registryCode)) {
        return ['kind' => 'possible_business', 'label' => 'Registrikood olemas', 'review' => ['Kontakti KMD osapoole kategooria vajab kinnitamist.']];
    }
    if ($country !== 'EE' && $registryCode !== '') {
        return ['kind' => 'unknown', 'label' => 'Välispartner vajab kontrolli', 'review' => ['Kontrolli, kas partner on juriidiline isik või FIE ning kas tehing kuulub Eesti KMD INF-i.']];
    }
    return ['kind' => 'unknown', 'label' => 'Osapoole liik teadmata', 'review' => ['Täida kontakti KMD osapoole kategooria ja tunnuse andmed.']];
}

function loadInfRows(PDO $pdo, int $companyId, string $startDate, string $endDate, string $kind, array $purchaseIds = [], array $salesIds = []): array
{
    ensureCreditNoteSchema($pdo);
    $creditLinks=loadCreditLinks($pdo,$companyId,$kind);
    $creditReverse=creditReverseAmounts($pdo,$companyId,$kind);
    $contactColumns = $pdo->query('SHOW COLUMNS FROM contacts')->fetchAll(PDO::FETCH_COLUMN);
    $optional = [
        'kmd_party_category' => in_array('kmd_party_category', $contactColumns, true) ? 'c.kmd_party_category' : 'NULL',
        'kmd_identifier_type' => in_array('kmd_identifier_type', $contactColumns, true) ? 'c.kmd_identifier_type' : 'NULL',
        'country_code' => in_array('country_code', $contactColumns, true) ? 'c.country_code' : "'EE'",
        'estonia_vat_number' => in_array('estonia_vat_number', $contactColumns, true) ? 'c.estonia_vat_number' : 'NULL',
    ];

    if ($kind === 'sales') {
        $sql = 'SELECT si.id, si.invoice_number AS document_number, si.invoice_date AS document_date,
                       si.contact_id, c.name AS contact_name, c.registry_code, c.vat_number,
                       ' . $optional['kmd_party_category'] . ' AS kmd_party_category,
                       ' . $optional['kmd_identifier_type'] . ' AS kmd_identifier_type,
                       ' . $optional['country_code'] . ' AS country_code,
                       sil.id AS line_id, sil.tax_code_id, sil.vat_rate, sil.net_amount, sil.vat_amount, sil.gross_amount,
                       tc.code AS tax_code, tc.label AS tax_code_label, tc.rate AS tax_code_rate,
                       tc.valid_from AS tax_valid_from, tc.valid_to AS tax_valid_to
                FROM sales_invoices si
                JOIN contacts c ON c.id = si.contact_id AND c.company_id = si.company_id
                JOIN sales_invoice_lines sil ON sil.invoice_id = si.id
                LEFT JOIN tax_codes tc ON tc.id = sil.tax_code_id AND tc.company_id = si.company_id
                WHERE si.company_id = :company_id AND si.status = \'posted\'
                  AND si.journal_entry_id IS NOT NULL
                  AND si.invoice_date BETWEEN :start_date AND :end_date
                ORDER BY si.invoice_date, si.id, sil.id';
    } else {
        $sql = 'SELECT pi.id, pi.supplier_invoice_number AS document_number, pi.invoice_date AS document_date,
                       pi.contact_id, c.name AS contact_name, c.registry_code, c.vat_number,
                       ' . $optional['estonia_vat_number'] . ' AS estonia_vat_number,
                       ' . $optional['kmd_party_category'] . ' AS kmd_party_category,
                       ' . $optional['kmd_identifier_type'] . ' AS kmd_identifier_type,
                       ' . $optional['country_code'] . ' AS country_code,
                       pil.id AS line_id, pil.description AS line_description, pil.tax_code_id, pil.vat_rate, pil.net_amount, pil.vat_amount, pil.gross_amount,
                       tc.code AS tax_code, tc.label AS tax_code_label, tc.rate AS tax_code_rate,
                       tc.valid_from AS tax_valid_from, tc.valid_to AS tax_valid_to
                FROM purchase_invoices pi
                JOIN contacts c ON c.id = pi.contact_id AND c.company_id = pi.company_id
                JOIN purchase_invoice_lines pil ON pil.invoice_id = pi.id
                LEFT JOIN tax_codes tc ON tc.id = pil.tax_code_id AND tc.company_id = pi.company_id
                WHERE pi.company_id = :company_id AND pi.status = \'posted\'
                  AND pi.journal_entry_id IS NOT NULL
                  AND pi.invoice_date BETWEEN :start_date AND :end_date
                ORDER BY pi.invoice_date, pi.id, pil.id';
    }

    $parameters = ['company_id' => $companyId, 'start_date' => $startDate, 'end_date' => $endDate];
    $invoiceIds = $kind === 'purchase' ? $purchaseIds : $salesIds;
    if ($invoiceIds) {
        $alias = $kind === 'purchase' ? 'pi' : 'si';
        unset($parameters['start_date'], $parameters['end_date']);
        $placeholders = [];
        foreach (array_values($invoiceIds) as $index => $id) {
            $key = 'invoice_id_'.$index;
            $placeholders[] = ':'.$key;
            $parameters[$key] = (int)$id;
        }
        $sql = str_replace($alias.'.invoice_date BETWEEN :start_date AND :end_date', $alias.'.id IN ('.implode(',', $placeholders).')', $sql);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parameters);
    $invoices = [];
    $errors = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['id'];
        if (!isset($invoices[$id])) {
            $invoices[$id] = [
                'id' => $id,
                'document_number' => (string) $row['document_number'],
                'document_date' => (string) $row['document_date'],
                'contact_id' => (int) $row['contact_id'],
                'contact_name' => (string) $row['contact_name'],
                'registry_code' => (string) ($row['registry_code'] ?? ''),
                'vat_number' => (string) ($row['vat_number'] ?? ''),
                'estonia_vat_number' => $row['estonia_vat_number'] ?? null,
                'country_code' => (string) ($row['country_code'] ?? 'EE'),
                'kmd_party_category' => $row['kmd_party_category'],
                'kmd_identifier_type' => $row['kmd_identifier_type'],
                'net' => 0, 'vat' => 0, 'gross' => 0,
                'reverse_vat' => 0, 'reverse_line_count' => 0, 'reverse_base_by_rate' => [], 'eu_goods_net' => 0, 'eu_services_net' => 0, 'domestic_purchase_net' => 0, 'domestic_sales_net' => 0, 'non_eu_services_net' => 0, 'reverse_info_vat' => 0, 'reverse_scope' => null, 'reverse_spec' => null, 'reverse_rates' => [],
                'rates' => [], 'codes' => [], 'qualifying_rates' => [], 'taxable_by_rate' => [], 'review' => [],
            ];
        }
        $snapshot = $row;
        unset($snapshot['line_description']);
        if (!isset($snapshot['estonia_vat_number'])) unset($snapshot['estonia_vat_number']);
        $invoices[$id]['confirmation_rows'][] = $snapshot;
        try {
            $net = infCents((string) $row['net_amount']);
            $vat = infCents((string) $row['vat_amount']);
            $gross = infCents((string) $row['gross_amount']);
        } catch (Throwable $exception) {
            $errors[] = ucfirst($kind === 'sales' ? 'Müügiarve ' : 'Ostuarve ') . $row['document_number'] . ': ' . $exception->getMessage();
            continue;
        }
        if ($net + $vat !== $gross) {
            $invoices[$id]['review'][] = 'Arverea neto, käibemaks ja kogusumma ei klapi.';
        }
        $rate = number_format((float) $row['vat_rate'], 2, '.', '');
        if ($kind === 'purchase') {
            $invoices[$id]['vat_input_lines'][(int)$row['line_id']] = [
                'vat'=>$vat,'rate'=>$rate,'net'=>$net,'description'=>$row['line_description'],'code'=>$row['tax_code'],
            ];
        }
        $invoices[$id]['net'] += $net;
        $invoices[$id]['vat'] += $vat;
        $invoices[$id]['gross'] += $gross;
        $invoices[$id]['rates'][$rate] = true;
        $invoices[$id]['taxable_by_rate'][$rate] = ($invoices[$id]['taxable_by_rate'][$rate] ?? 0) + $net;
        $code = (string) ($row['tax_code'] ?? '');
        if ($code !== '') {
            $invoices[$id]['codes'][$code] = true;
        }
        $reverseSpec = reverseVatSpec($code);
        if ($reverseSpec) {
            ++$invoices[$id]['reverse_line_count'];
            if (($invoices[$id]['reverse_scope'] !== null && $invoices[$id]['reverse_scope'] !== $reverseSpec['scope']) || $reverseSpec['side'] !== $kind) {
                $invoices[$id]['review'][] = 'Erineva pöördmaksustamise liigiga tehingud sisesta eraldi arvetena.';
            }
            $invoices[$id]['reverse_scope'] = $reverseSpec['scope'];
            $invoices[$id]['reverse_spec'] = $reverseSpec;
            $linkedCredit=$net<0 && isset($creditLinks[$id],$creditReverse[$row['line_id']??0]);
            if ($vat !== 0 || $net !== $gross || (float) $rate !== 0.0 || ($net < 0 && !$linkedCredit)
                || (!$linkedCredit && $row['document_date'] < $reverseSpec['valid_from'])) {
                $invoices[$id]['review'][] = 'Pöördmaksu real on vigane summa, tarnija käibemaks või maksukoodi kuupäev.';
            } else {
                $reverseAmount=$linkedCredit?-(int)$creditReverse[$row['line_id']]:euReverseVat($net,$reverseSpec['rate']);
                if ($kind === 'purchase') {
                    $invoices[$id]['vat_input_lines'][(int)$row['line_id']]['vat'] += $reverseAmount;
                    $invoices[$id]['vat_input_lines'][(int)$row['line_id']]['reverse'] = true;
                    $invoices[$id]['vat_input_lines'][(int)$row['line_id']]['reverse_spec'] = $reverseSpec;
                    if ($reverseSpec['scope'] !== 'domestic') {
                        $invoices[$id]['foreign_reverse_bases'][$code] = ($invoices[$id]['foreign_reverse_bases'][$code] ?? 0) + $net;
                    }
                }
                $invoices[$id]['reverse_info_vat'] += $reverseAmount;
                if ($kind === 'purchase') $invoices[$id]['reverse_vat'] += $reverseAmount;
                $reverseRate = number_format($reverseSpec['rate'], 2, '.', '');
                $invoices[$id]['reverse_rates'][$reverseRate] = true;
                if ($kind === 'purchase' && empty($reverseSpec['exempt'])) {
                    $invoices[$id]['reverse_base_by_rate'][$reverseRate] = ($invoices[$id]['reverse_base_by_rate'][$reverseRate] ?? 0) + $net;
                }
                if ($reverseSpec['scope'] === 'eu') $invoices[$id]['eu_' . $reverseSpec['kind'] . '_net'] += $net;
                elseif ($reverseSpec['scope'] === 'non_eu') $invoices[$id]['non_eu_services_net'] += $net;
                else {
                    $invoices[$id][$kind === 'purchase' ? 'domestic_purchase_net' : 'domestic_sales_net'] += $net;
                    $invoices[$id]['qualifying_rates'][$reverseRate] = true;
                    $invoices[$id]['taxable_by_rate'][$reverseRate] = ($invoices[$id]['taxable_by_rate'][$reverseRate] ?? 0) + $net;
                }
            }
        }
        $invoices[$id]['line_data'][] = [
            'rate' => $rate,
            'code' => $code,
            'tax_code_id' => $row['tax_code_id'],
            'tax_code_rate' => $row['tax_code_rate'],
            'valid_from' => $row['tax_valid_from'],
            'valid_to' => $row['tax_valid_to'],
            'document_date' => (string) $row['document_date'],
        ];
    }

    $expectedCodePrefix = $kind === 'sales' ? 'M_' : 'O_';
    foreach ($invoices as &$invoice) {
        $isCredit = $invoice['net'] < 0 || $invoice['gross'] < 0;
        $link=$creditLinks[$invoice['id']]??null;
        $invoice['credit_link']=$link;
        $validCredit=$isCredit && $link && (int)$link['original_contact_id']===$invoice['contact_id']
            && $link['original_status']==='posted' && $link['original_date']<=$invoice['document_date']
            && (int)$link['percentage_bp']>0 && (int)$link['percentage_bp']<=10000;
        if($isCredit && !$validCredit) $errors[]='Arve '.$invoice['document_number'].': negatiivne arve vajab korrektset algse arve viidet.';
        foreach ($invoice['line_data'] ?? [] as $line) {
            $rate = $line['rate'];
            $code = $line['code'];
            if ($line['tax_code_id'] !== null && $line['tax_code_rate'] !== null
                && number_format((float) $line['tax_code_rate'], 2, '.', '') !== $rate) {
                $invoice['review'][] = 'Salvestatud maksukoodi määr ei klapi arverea määraga.';
            }
            if (!$validCredit && (($line['valid_from'] !== null && $line['document_date'] < $line['valid_from'])
                || ($line['valid_to'] !== null && $line['document_date'] > $line['valid_to']))) {
                $invoice['review'][] = 'Maksukood ei kehti arve kuupäeval.';
            }
            $qualifies = in_array($rate, ['24.00', '13.00', '9.00'], true);
            if ($rate === '5.00') {
                $qualifies = true;
                if ($code === $expectedCodePrefix . 'EE5-TRANSITION' && !$validCredit) {
                    $invoice['review'][] = '5% määra kasutamine sõltub KMS üleminekusätte tingimustest.';
                } elseif ($code !== $expectedCodePrefix . 'EE5-OLD' && !$validCredit) {
                    $invoice['review'][] = '5% rida vajab õige ajaloolise või üleminekukoodi kontrolli.';
                }
            }
            if (in_array($rate, ['22.00', '20.00'], true)) {
                $qualifies = $isCredit;
                if ($qualifies && !$validCredit) {
                    $invoice['review'][] = 'Ajaloolise 22%/20% määra kreeditarve vajab algse tehingu kontrolli.';
                }
            }
            if ($qualifies) {
                $invoice['qualifying_rates'][$rate] = true;
                if ($line['tax_code_id'] === null) {
                    $invoice['review'][] = 'Arvereal puudub maksukood; kontrolli tehingu liiki.';
                } elseif ($rate === '13.00' && !in_array($code,[$expectedCodePrefix . 'EE13','M_CASH13'],true)) {
                    $invoice['review'][] = 'Kontrolli, kas 13% määr kuulub sellele majutusteenusele.';
                } elseif ($rate === '9.00' && !in_array($code, [$expectedCodePrefix . 'EE9', $expectedCodePrefix . 'EE9-OLD', $expectedCodePrefix . 'EE9-TRANSITION','M_CASH9'], true)) {
                    $invoice['review'][] = 'Kontrolli 9% vähendatud määra alust.';
                }
                if ($code === $expectedCodePrefix . 'EE9-TRANSITION' && !$validCredit) {
                    $invoice['review'][] = '9% majutuse kood eeldab KMS üleminekusätte tingimuste täitumist.';
                }
            }
        }
        $invoice['qualifying_rates'] = array_keys($invoice['qualifying_rates']);
        $invoice['rates'] = array_keys($invoice['reverse_line_count'] > 0
            ? array_filter($invoice['rates'],static fn($rate): bool => (string)$rate !== '0.00',ARRAY_FILTER_USE_KEY) + $invoice['reverse_rates']
            : $invoice['rates']);
        $invoice['codes'] = array_keys($invoice['codes']);
        $invoice['review'] = array_values(array_unique($invoice['review']));
        unset($invoice['line_data']);
        $invoice['deduction_vat'] = $invoice['vat'] + $invoice['reverse_vat'];
        $invoice['party'] = contactInfStatus($invoice);
        $invoice['zero_sales']=$kind==='sales' && !$invoice['qualifying_rates'] && !$invoice['reverse_line_count'] && $invoice['vat']===0;
        $invoice['import_goods']=$kind==='purchase' && in_array('O_IMPORT0',$invoice['codes'],true);
        if($invoice['import_goods']) {
            if(count($invoice['codes'])!==1 || $invoice['vat']!==0 || $invoice['qualifying_rates']) $errors[]='Ostuarve '.$invoice['document_number'].': impordi kaubaarve peab olema eraldi, tarnija KM-ta ja ainult O_IMPORT0 ridadega.';
            $invoice['party']=['kind'=>'business','label'=>'Kauba import — KM tollidokumendilt','review'=>[]];
        }
        if ($kind === 'purchase') {
            $issue = foreignPurchaseSupplierVatError($invoice, $invoice['vat']);
            if ($issue !== null) {
                $invoice['review'][] = $issue;
                if (!$invoice['reverse_line_count']) $errors[] = 'Ostuarve '.$invoice['document_number'].': '.$issue;
            }
            if ($invoice['country_code'] !== 'EE' && foreignPurchaseEstonianVat($invoice) !== null) {
                $invoice['party'] = ['kind'=>'business','label'=>'Välistarnija Eesti KMKR numbriga','review'=>[]];
            }
        }
        if ($invoice['reverse_line_count'] > 0) {
            $spec = $invoice['reverse_spec'];
            $supplierError = reversePartyError($spec, $invoice['country_code'], $invoice['vat_number']);
            $companyError = reverseCompanyError($pdo,$companyId,$spec);
            if ($companyError!==null) $invoice['review'][]=$companyError;
            if ($supplierError !== null) $invoice['review'][] = $supplierError;
            $invoice['mixed_reverse'] = $invoice['reverse_line_count'] !== count($invoice['confirmation_rows']);
            if ($invoice['mixed_reverse'] && $kind !== 'purchase') {
                $invoice['review'][] = 'Pöördmaksu tehing ja tavaline tehing sisesta eraldi arvetena.';
            }
            $invoice['requires_line_vat'] = $kind === 'purchase' && ($invoice['mixed_reverse']
                || count($invoice['foreign_reverse_bases'] ?? []) > 1);
            if ($invoice['reverse_scope'] !== 'domestic') {
                $invoice['party'] = ['kind' => 'business', 'label' => $invoice['reverse_scope'] === 'eu' ? 'EL käibemaksukohustuslane' : 'EL-väline ettevõte', 'review' => []];
            }
            foreach ($invoice['review'] as $issue) $errors[] = ($kind === 'purchase' ? 'Ostuarve ' : 'Müügiarve ') . $invoice['document_number'] . ': ' . $issue;
        }
        if ($invoice['party']['review']) {
            $invoice['review'] = array_values(array_unique(array_merge($invoice['review'], $invoice['party']['review'])));
        }
        $invoice['confirmation_hash'] = hash('sha256', json_encode($invoice['confirmation_rows'], JSON_THROW_ON_ERROR));
        unset($invoice['confirmation_rows']);
        if($validCredit) $invoice['confirmation_hash']=hash('sha256',$invoice['confirmation_hash'].json_encode($link,JSON_THROW_ON_ERROR));
        if ($kind === 'purchase') {
            $invoice['review'][] = 'Kontrolli, kas arve sisendkäibemaks kuulub KMD lahtris 5 mahaarvamisele.';
        }
        if ($invoice['net'] < 0 && !$validCredit) {
            $invoice['review'][] = 'Kreeditarve piirmäära mõju vajab käsitsi kontrolli.';
        }
        $invoice['review'] = array_values(array_unique($invoice['review']));
        $invoice['partner_key'] = $invoice['country_code'] . ':' . ($invoice['registry_code'] !== '' ? preg_replace('/\s+/', '', $invoice['registry_code']) : 'contact-' . $invoice['contact_id']);
    }
    unset($invoice);

    return ['invoices' => array_values($invoices), 'errors' => array_values(array_unique($errors))];
}

