<?php
function vatPurposeOptions(): array
{
    return [
        'ordinary'=>'Tavaline ost',
        'fixed_asset'=>'Põhivara soetus (5.2)',
        'car_full'=>'Sõiduauto kulu — ainult ettevõtluses (5.3)',
        'car_partial'=>'Sõiduauto kulu — segakasutus, kuni 50% (5.4)',
        'fixed_car_full'=>'Sõiduauto põhivarana — ainult ettevõtluses (5.2 ja 5.3)',
        'fixed_car_partial'=>'Sõiduauto põhivarana — segakasutus, kuni 50% (5.2 ja 5.4)',
    ];
}
function vatPurposeUsage(string $purpose): ?string
{
    if (in_array($purpose,['car_full','fixed_car_full'],true)) return 'full';
    if (in_array($purpose,['car_partial','fixed_car_partial'],true)) return 'partial';
    return null;
}
function vatCarIds(string $text): array
{
    if (strlen($text)>2000) throw new InvalidArgumentException('Sõiduautode tunnuste loend on liiga pikk.');
    $items=preg_split('/[,;\r\n]+/',strtoupper(trim($text)),-1,PREG_SPLIT_NO_EMPTY);
    $ids=[];
    foreach($items as $item) {
        $id=preg_replace('/[ \t-]+/','',trim($item));
        if (!preg_match('/^[A-Z0-9]{2,20}$/',$id)) throw new InvalidArgumentException('Kasuta sõiduauto registreerimismärki või VIN-i, tunnused eralda komaga.');
        $ids[$id]=true;
    }
    if(count($ids)>50) throw new InvalidArgumentException('Ühele arvele saab märkida kuni 50 sõiduautot.');
    $result=array_map('strval',array_keys($ids)); sort($result,SORT_STRING); return $result;
}
function validateVatPurpose(array $invoice, string $purpose, string $carText): array
{
    if(!isset(vatPurposeOptions()[$purpose])) throw new InvalidArgumentException('Vali kehtiv käibemaksu jaotus.');
    $usage=vatPurposeUsage($purpose); $ids=vatCarIds($carText);
    if($usage!==null && !$ids) throw new InvalidArgumentException('Sõiduauto kulu puhul sisesta registreerimismärk või VIN.');
    if($usage===null && $ids) throw new InvalidArgumentException('Autode tunnused on lubatud ainult sõiduauto jaotuse puhul.');
    $vat=(int)($invoice['deduction_vat']??$invoice['vat']);
    $deduction=(int)($invoice['deductible_cents']??$vat);
    if($deduction<min(0,$vat) || $deduction>max(0,$vat)) throw new InvalidArgumentException('Mahaarvatav summa peab jääma nulli ja arve käibemaksusumma vahele.');
    if($usage==='partial' && abs($deduction)>intdiv(abs($vat)+1,2)) {
        throw new InvalidArgumentException('Segakasutuses sõiduauto mahaarvatav KM ei või ületada 50% arve käibemaksust. Vajadusel vähenda summat.');
    }
    $invoice['vat_purpose']=$purpose; $invoice['car_ids']=$ids;
    return $invoice;
}
function vatCarTotals(array $invoices, array $manualCars=[]): array
{
    $result=['fixed_assets_vat'=>0,'cars_vat'=>0,'cars_partial_vat'=>0,'cars'=>['full'=>[],'partial'=>[]],'errors'=>[]];
    foreach($manualCars as $car) {
        if(in_array($car['usage_kind'],['full','partial'],true)) $result['cars'][$car['usage_kind']][$car['car_identifier']]=true;
    }
    foreach($invoices as $invoice) {
        if(empty($invoice['deduction_confirmed'])) continue;
        foreach ($invoice['vat_line_allocations'] ?? [$invoice] as $item) {
            $purpose=$item['vat_purpose']??'ordinary';
            $amount=(int)($item['deductible_cents']??0);
            if(in_array($purpose,['fixed_asset','fixed_car_full','fixed_car_partial'],true)) $result['fixed_assets_vat']+=$amount;
            $usage=vatPurposeUsage($purpose);
            if($usage!==null) {
                $result[$usage==='full'?'cars_vat':'cars_partial_vat']+=$amount;
                if(empty($invoice['credit_link'])) foreach($item['car_ids']??[] as $id) $result['cars'][$usage][$id]=true;
            }
        }
    }
    $overlap=array_keys(array_intersect_key($result['cars']['full'],$result['cars']['partial']));
    if($overlap) $result['errors'][]='Sama sõiduauto on samal kuul mõlemas kasutusliigis: '.implode(', ',$overlap).'. Ühtlusta autode ja arvete liigitus.';
    $result['number_of_cars']=count($result['cars']['full']);
    $result['number_of_cars_partial']=count($result['cars']['partial']);
    return $result;
}
function loadVatCars(PDO $pdo,int $companyId,string $period): array
{
    $stmt=$pdo->prepare('SELECT car_identifier, usage_kind FROM kmd_vat_period_cars WHERE company_id = ? AND period = ? ORDER BY car_identifier');
    $stmt->execute([$companyId,$period]); return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function saveVatCar(PDO $pdo,int $companyId,string $period,string $identifier,string $usage): void
{
    $ids=vatCarIds($identifier);
    if(count($ids)!==1 || !in_array($usage,['full','partial'],true)) throw new InvalidArgumentException('Sisesta üks sõiduauto tunnus ja vali kasutusliik.');
    $stmt=$pdo->prepare('INSERT INTO kmd_vat_period_cars (company_id, period, car_identifier, usage_kind) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE usage_kind = VALUES(usage_kind)');
    $stmt->execute([$companyId,$period,$ids[0],$usage]);
}

function copyPreviousVatCars(PDO $pdo, int $companyId, string $period, array $invoices): int
{
    $start = new DateTimeImmutable($period.'-01');
    $end = $start->modify('first day of next month');
    $previous = $start->modify('first day of previous month')->format('Y-m');
    $pdo->beginTransaction();
    try {
        for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
            $message = closedAccountingPeriodMessage($pdo, $companyId, $day->format('Y-m-d'));
            if ($message !== null) throw new InvalidArgumentException($message);
        }

        $existing = vatCarTotals($invoices, loadVatCars($pdo, $companyId, $period))['cars'];
        $occupied = $existing['full'] + $existing['partial'];
        $insert = $pdo->prepare('INSERT INTO kmd_vat_period_cars (company_id, period, car_identifier, usage_kind)
            VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE usage_kind = kmd_vat_period_cars.usage_kind');
        $copied = 0;
        foreach (loadVatCars($pdo, $companyId, $previous) as $car) {
            $identifier = $car['car_identifier'];
            if (isset($occupied[$identifier])) continue;
            $insert->execute([$companyId, $period, $identifier, $car['usage_kind']]);
            $copied += $insert->rowCount();
        }
        $pdo->commit();
        return $copied;
    } catch (Throwable $error) {
        $pdo->rollBack();
        throw $error;
    }
}
