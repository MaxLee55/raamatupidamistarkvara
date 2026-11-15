<?php
require_once __DIR__.'/ledger_data.php';
function ledgerHeaders(): array
{
    return ['Konto kood','Konto nimi','Konto liik','Saldo suund','Rea liik','Kuupäev','Kande ID','Kanderea ID','Dokumendi nr','Parandatav dokument','Kande kirjeldus','Rea kirjeldus','Deebet (EUR)','Kreedit (EUR)','Saldo (EUR)'];
}
function ledgerCells(array $record): array
{
    $a=$record['account'];
    return [(string)$a['code'],$a['name'],ledgerType($a['account_type']),$a['sign']===1?'Deebet':'Kreedit',
        ['opening'=>'Algsaldo','entry'=>'Kanne','summary'=>'Perioodi kokku','closing'=>'Lõppsaldo'][$record['kind']],
        $record['date'],$record['entry_id'],$record['line_id'],$record['document'],$record['corrected_document'],$record['entry_description'],$record['line_description'],$record['debit'],$record['credit'],$record['balance']];
}
function ledgerCsvText(string $value): string
{
    return preg_match('/^[\x00-\x20]*[=+\-@]/u',$value)?"'".$value:$value;
}
function ledgerWriteCsv(PDO $pdo,array $report,string $path): void
{
    $file=fopen($path,'wb'); if(!$file) throw new RuntimeException('CSV faili ei saanud luua.');
    try {
        fwrite($file,"\xEF\xBB\xBF");
        fputcsv($file,array_merge(ledgerHeaders(),['Ettevõte','Registrikood','Periood','Koostatud']),';','"','',"\r\n");
        foreach(ledgerRecords($pdo,$report) as $record) {
            $cells=ledgerCells($record);
            foreach($cells as $i=>&$value) { if($value===null) $value=''; elseif($i>=12) $value=ledgerDecimal($value); elseif(is_string($value)) $value=ledgerCsvText($value); } unset($value);
            $cells[]=ledgerCsvText($report['company']['name']); $cells[]=ledgerCsvText((string)$report['company']['registry_code']); $cells[]=ledgerPeriod($report); $cells[]=$report['generated_at'];
            if(fputcsv($file,$cells,';','"','',"\r\n")===false) throw new RuntimeException('CSV faili kirjutamine ebaõnnestus.');
        }
    } finally { fclose($file); }
}
function ledgerXml(string $value): string
{
    $value=preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',' ',$value)??'';
    return htmlspecialchars($value,ENT_XML1|ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
function ledgerColumn(int $index): string
{
    $name=''; for(++$index;$index>0;$index=intdiv($index-1,26)) $name=chr(65+($index-1)%26).$name; return $name;
}
function ledgerSheetOpen(string $path,array $widths): mixed
{
    $file=fopen($path,'wb'); if(!$file) throw new RuntimeException('Exceli töölehte ei saanud luua.');
    fwrite($file,'<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>');
    foreach($widths as $i=>$width) fwrite($file,'<col min="'.($i+1).'" max="'.($i+1).'" width="'.$width.'" customWidth="1"/>');
    fwrite($file,'</cols><sheetData>'); return $file;
}
function ledgerSheetRow(mixed $file,int $row,array $values,array $money=[],array $numbers=[],array $dates=[],bool $header=false,bool $emphasis=false): void
{
    $xml='<row r="'.$row.'">';
    foreach($values as $i=>$value) {
        if($value===null || $value==='') continue;
        $ref=ledgerColumn($i).$row; $style=$header?1:($emphasis?4:0); $numeric=false;
        if(!$header && in_array($i,$money,true)) {
            $style=$emphasis?5:3; $value=ledgerDecimal((int)$value);
            // Excel stores at most 15 significant digits: keep larger amounts exact as text.
            $numeric=strlen(ltrim(str_replace(['-','.'],'',$value),'0'))<=15;
        } elseif(!$header && in_array($i,$numbers,true)) $numeric=true;
        elseif(!$header && in_array($i,$dates,true) && is_string($value) && ledgerDateValid($value) && $value>='1900-03-01') {
            $value=(new DateTimeImmutable('1899-12-30',new DateTimeZone('UTC')))->diff(new DateTimeImmutable($value,new DateTimeZone('UTC')))->days; $numeric=true; $style=2;
        }
        $xml.=$numeric?'<c r="'.$ref.'" s="'.$style.'"><v>'.$value.'</v></c>':'<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.ledgerXml((string)$value).'</t></is></c>';
    }
    if(fwrite($file,$xml.'</row>')===false) throw new RuntimeException('Exceli töölehe kirjutamine ebaõnnestus.');
}
function ledgerSheetClose(mixed $file,int $lastRow,int $lastColumn,bool $filter=true): void
{
    fwrite($file,'</sheetData>'.($filter?'<autoFilter ref="A1:'.ledgerColumn($lastColumn).max(1,$lastRow).'"/>':'').'<printOptions horizontalCentered="1"/><pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>'); fclose($file);
}
function ledgerWriteXlsx(PDO $pdo,array $report,string $path,string $directory,int $sheetRowLimit=1048576): void
{
    if($sheetRowLimit<2 || $sheetRowLimit>1048576) throw new InvalidArgumentException('Vigane Exceli töölehe suurus.');
    $sheets=[]; $file=null; $row=0; $part=0;
    try {
        // Split rather than truncate if the complete ledger exceeds Excel's worksheet limit.
        foreach(ledgerRecords($pdo,$report) as $record) {
            if($file===null || $row>=$sheetRowLimit) {
                if($file!==null) ledgerSheetClose($file,$row,14);
                $number=count($sheets)+1; ++$part; $sheet=$directory.'/sheet'.$number.'.xml';
                $sheets[]=['name'=>$part===1?'Pearaamat':'Pearaamat '.$part,'path'=>$sheet];
                $file=ledgerSheetOpen($sheet,[15,28,15,15,20,15,12,14,23,23,42,42,20,20,20]); $row=1; ledgerSheetRow($file,$row,ledgerHeaders(),[],[],[],true);
            }
            ledgerSheetRow($file,++$row,ledgerCells($record),[12,13,14],[6,7],[5],false,$record['kind']!=='entry');
        }
        if($file===null) { $sheet=$directory.'/sheet1.xml'; $sheets[]=['name'=>'Pearaamat','path'=>$sheet]; $file=ledgerSheetOpen($sheet,array_fill(0,15,20)); $row=1; ledgerSheetRow($file,1,ledgerHeaders(),[],[],[],true); }
        ledgerSheetClose($file,$row,14); $file=null;
        $number=count($sheets)+1; $sheet=$directory.'/sheet'.$number.'.xml'; $sheets[]=['name'=>'Kontode kokkuvõte','path'=>$sheet]; $file=ledgerSheetOpen($sheet,[15,35,18,15,20,20,20,20,20,20,20,20]);
        $headers=['Konto kood','Konto nimi','Konto liik','Saldo suund','Algsaldo D','Algsaldo K','Perioodi D','Perioodi K','Lõppsaldo D','Lõppsaldo K','Algsaldo','Lõppsaldo']; ledgerSheetRow($file,1,$headers,[],[],[],true); $row=1; $totals=array_fill(4,6,0);
        foreach($report['accounts'] as $account) {
            $values=[(string)$account['code'],$account['name'],ledgerType($account['account_type']),$account['sign']===1?'Deebet':'Kreedit',max(0,$account['opening_raw']),max(0,-$account['opening_raw']),$account['debit'],$account['credit'],max(0,$account['closing_raw']),max(0,-$account['closing_raw']),$account['opening'],$account['closing']];
            foreach($totals as $i=>$_) $totals[$i]=ledgerAdd($totals[$i],$values[$i]);
            ledgerSheetRow($file,++$row,$values,range(4,11));
        }
        $totalRow=['Kokku','','','']; foreach($totals as $value) $totalRow[]=$value;
        ledgerSheetRow($file,++$row,$totalRow,range(4,9),[],[],false,true); ledgerSheetClose($file,$row,11,false); $file=null;
        $number=count($sheets)+1; $sheet=$directory.'/sheet'.$number.'.xml'; $sheets[]=['name'=>'Andmed','path'=>$sheet]; $file=ledgerSheetOpen($sheet,[30,100]);
        ledgerSheetRow($file,1,['Aruanne','Pearaamat'],[],[],[],true); $row=1;
        $accountLabel=$report['filters']['account_id']===null?'Kõik kontod (ka mitteaktiivsed)':($report['accounts'][0]['code'].' — '.$report['accounts'][0]['name']);
        foreach([
            ['Ettevõte',$report['company']['name']],['Registrikood',(string)$report['company']['registry_code']],['Periood',ledgerPeriod($report)],['Kontod',$accountLabel],['Koostatud',$report['generated_at']],['Valuuta','EUR'],['Kanderidade arv',(string)$report['row_count']],
            ['Kaasatud kanded','Ainult kinnitatud kanded. Mustandid ja tühistatud kanded ei sisaldu.'],
            ['Saldode suund','Vara ja kulu: deebet miinus kreedit. Kohustus, omakapital ja tulu: kreedit miinus deebet.'],
            ['Rea liik','Algsaldo, Kanne, Perioodi kokku, Lõppsaldo. Tegelikud kanderidad on liigiga Kanne.'],
            ['Täpsus','Summad arvutatakse täpselt sentides. Exceli 15 numbrit ületavad rahasummad säilitatakse tekstina.'],
        ] as $values) ledgerSheetRow($file,++$row,$values);
        ledgerSheetClose($file,$row,1,false); $file=null;
        $archivePath=$directory.'/workbook.zip'; $archive=new PharData($archivePath,0,null,Phar::ZIP);
        $types='<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $workbook='<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets>';
        $relations='<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach($sheets as $i=>$sheet) {
            $id=$i+1; $types.='<Override PartName="/xl/worksheets/sheet'.$id.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbook.='<sheet name="'.ledgerXml($sheet['name']).'" sheetId="'.$id.'" r:id="rId'.$id.'"/>';
            $relations.='<Relationship Id="rId'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$id.'.xml"/>';
            $archive->addFile($sheet['path'],'xl/worksheets/sheet'.$id.'.xml');
        }
        $archive->addFromString('[Content_Types].xml',$types.'</Types>');
        $archive->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $archive->addFromString('xl/workbook.xml',$workbook.'</sheets><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>');
        $archive->addFromString('xl/_rels/workbook.xml.rels',$relations.'<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $archive->addFromString('xl/styles.xml',ledgerXlsxStyles());
        $archive->compressFiles(Phar::GZ); unset($archive);
        if(!copy($archivePath,$path)) throw new RuntimeException('Exceli faili lõpetamine ebaõnnestus.');
    } finally { if(is_resource($file)) fclose($file); }
}
function ledgerXlsxStyles(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0.00;[Red]-#,##0.00;0.00"/><numFmt numFmtId="165" formatCode="yyyy-mm-dd"/></numFmts><fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF17324D"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="6"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment wrapText="1" vertical="center"/></xf><xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="164" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
}
function ledgerWritePdf(PDO $pdo,array $report,string $path,string $directory): void
{
    if(!class_exists(\Dompdf\Dompdf::class)) require_once __DIR__.'/vendor/autoload.php';
    $html='<!doctype html><html lang="et"><head><meta charset="utf-8"><style>@page{margin:25px 25px 35px}body{font-family:"DejaVu Sans",sans-serif;font-size:8px;color:#23364b}h1{font-size:21px;color:#17324d;margin:0 0 7px}.meta{font-size:9px;line-height:1.7;margin-bottom:15px}.note{color:#617084;font-size:8px}table{width:100%;border-collapse:collapse;margin:0 0 16px}thead{display:table-header-group;page-break-inside:avoid;page-break-after:avoid}tr{page-break-inside:avoid}th,td{padding:5px 5px;border-bottom:1px solid #dce4ed;vertical-align:top;word-wrap:break-word}th{background:#edf2f8;text-align:left;color:#17324d}.account-block{page-break-inside:avoid}.account{background:#17324d;color:white;font-size:10px;padding:8px}.num{text-align:right;white-space:nowrap}.summary td{background:#f0f4f8;font-weight:bold}.sub{font-size:7px;color:#617084}</style></head><body><h1>Pearaamat</h1><div class="meta"><strong>'.ledgerHtml($report['company']['name']).'</strong> · registrikood '.ledgerHtml((string)$report['company']['registry_code']).'<br>Periood: '.ledgerHtml(ledgerPeriod($report)).' · koostatud '.ledgerHtml($report['generated_at']).'<br>Kontosid: '.count($report['accounts']).' · kanderidu: '.$report['row_count'].' · valuuta: EUR · ainult kinnitatud kanded</div>';
    foreach(ledgerRecords($pdo,$report) as $record) {
        $account=$record['account'];
        if($record['kind']==='opening') $html.='<div class="account-block"><table><colgroup><col style="width:9%"><col style="width:6%"><col style="width:16%"><col style="width:36%"><col style="width:11%"><col style="width:11%"><col style="width:11%"></colgroup><thead><tr><th class="account" colspan="7">'.ledgerHtml($account['code'].' — '.$account['name']).' · saldo suund: '.($account['sign']===1?'deebet':'kreedit').'</th></tr><tr><th style="width:9%">Kuupäev</th><th style="width:6%">Kanne</th><th style="width:16%">Dokumendi nr</th><th style="width:36%">Kirjeldus</th><th style="width:11%" class="num">Deebet</th><th style="width:11%" class="num">Kreedit</th><th style="width:11%" class="num">Saldo</th></tr>';
        if($record['kind']==='entry') {
            $description=$record['line_description']?:$record['entry_description'];
            $html.='<tr><td>'.ledgerHtml($record['date']).'</td><td>#'.$record['entry_id'].'</td><td>'.ledgerHtml($record['document']).($record['corrected_document']!==''?'<div class="sub">Parandab: '.ledgerHtml($record['corrected_document']).'</div>':'').'</td><td>'.ledgerHtml($description).($record['entry_description']!==$description?'<div class="sub">'.ledgerHtml($record['entry_description']).'</div>':'').'</td><td class="num">'.ledgerHtml(ledgerMoney($record['debit'])).'</td><td class="num">'.ledgerHtml(ledgerMoney($record['credit'])).'</td><td class="num">'.ledgerHtml(ledgerMoney($record['balance'])).'</td></tr>';
        } else {
            $html.='<tr class="summary"><td colspan="4">'.['opening'=>'Perioodi algsaldo','summary'=>'Perioodi kokku','closing'=>'Lõppsaldo'][$record['kind']].'</td><td class="num">'.($record['debit']===null?'':ledgerHtml(ledgerMoney($record['debit']))).'</td><td class="num">'.($record['credit']===null?'':ledgerHtml(ledgerMoney($record['credit']))).'</td><td class="num">'.($record['kind']==='summary'?'':ledgerHtml(ledgerMoney($record['balance']))).'</td></tr>';
        }
        if($record['kind']==='opening') $html.='</thead><tbody>';
        if($record['kind']==='closing') $html.='</tbody></table></div>';
    }
    if(!$report['accounts']) $html.='<p>Valitud tingimustel kandeid ega algsaldosid ei ole.</p>';
    $html.='<p><strong>Perioodi deebet kokku: '.ledgerHtml(ledgerMoney($report['debit'])).' · kreedit kokku: '.ledgerHtml(ledgerMoney($report['credit'])).'</strong></p><p class="note">Vara ja kulu saldo = deebet miinus kreedit. Kohustuse, omakapitali ja tulu saldo = kreedit miinus deebet. Kõik summad on eurodes. Mustandid ja tühistatud kanded ei sisaldu.</p></body></html>';
    $options=new \Dompdf\Options(); $options->set('defaultFont','DejaVu Sans'); $options->set('isRemoteEnabled',false); $options->set('isPhpEnabled',false); $options->set('isJavascriptEnabled',false); $options->set('tempDir',$directory); $options->set('fontCache',$directory);
    $pdf=new \Dompdf\Dompdf($options); $pdf->loadHtml($html,'UTF-8'); $pdf->setPaper('A4','landscape'); $pdf->render();
    $canvas=$pdf->getCanvas(); $font=$pdf->getFontMetrics()->getFont('DejaVu Sans'); $canvas->page_text(690,572,'Leht {PAGE_NUM} / {PAGE_COUNT}',$font,7,[.35,.42,.5]);
    $canvas->page_text(25,572,(string)$report['company']['registry_code'].' · '.ledgerPeriod($report),$font,7,[.35,.42,.5]);
    if(file_put_contents($path,$pdf->output())===false) throw new RuntimeException('PDF faili ei saanud salvestada.');
}
function ledgerCleanup(string $directory): void
{
    if(!str_starts_with(basename($directory),'rm-ledger-') || !is_dir($directory)) return;
    foreach(glob($directory.'/*')?:[] as $path) if(is_file($path) && dirname($path)===$directory) @unlink($path);
    @rmdir($directory);
}
function ledgerExport(PDO $pdo,array $report,string $format): array
{
    if(!in_array($format,['csv','pdf','xlsx'],true)) throw new InvalidArgumentException('Vali CSV, PDF või XLSX.');
    $directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'rm-ledger-'.bin2hex(random_bytes(12));
    if(!mkdir($directory,0700)) throw new RuntimeException('Ekspordi ajutist kausta ei saanud luua.');
    $path=$directory.'/report.'.$format;
    try {
        if($format==='csv') ledgerWriteCsv($pdo,$report,$path);
        elseif($format==='xlsx') ledgerWriteXlsx($pdo,$report,$path,$directory);
        else ledgerWritePdf($pdo,$report,$path,$directory);
        $identity=preg_replace('/[^A-Za-z0-9_-]/','',(string)$report['company']['registry_code'])?:('ettevote_'.$report['company']['id']);
        $period=$report['filters']['scope']==='all'?'kogu_ajalugu':$report['filters']['start_date'].'_'.$report['filters']['end_date'];
        $account=$report['filters']['account_id']===null?'koik_kontod':'konto_'.preg_replace('/[^A-Za-z0-9_-]/','',(string)$report['accounts'][0]['code']);
        return ['path'=>$path,'directory'=>$directory,'filename'=>'pearaamat_'.$identity.'_'.$period.'_'.$account.'.'.$format,'mime'=>['csv'=>'text/csv; charset=UTF-8','pdf'=>'application/pdf','xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'][$format]];
    } catch(Throwable $e) { ledgerCleanup($directory); throw $e; }
}
