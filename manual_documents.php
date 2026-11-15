<?php
/** Explicit catalogue: request parameters never become filesystem paths. */
function manualDocuments(): array
{
    return [
        'purchase-vat'=>['file'=>'OSTUARVE_KAIBEMAKSU_VALIKUD_2026-11-10.md','title'=>'Ostuarve käibemaksu valikud','description'=>'Milline maksukood valida Eesti ostu, EL kauba ja teenuse, impordi ning teiste erijuhtude puhul.','group'=>'Kasutusjuhendid','date'=>'10.11.2026'],
        'xbrl-adjustments'=>['file'=>'XBRL_PARANDUSKANDED_2026-11-07.md','title'=>'Sisendkäibemaksu parandused ja XBRL GL','description'=>'Põhivara ja aastased ümberarvestused: arvutus, pearaamat, maksumäärade jaotus ning O_601 eksport.','group'=>'Kasutusjuhendid','date'=>'07.11.2026'],
        'vat-readiness'=>['file'=>'archive/KAIBEMAKSU_VALMIDUS_2026-11-07.md','title'=>'Käibemaksu valmidus ja lõpetamise piir','description'=>'Valmis paranduskanded, allesjäänud erijuhud ning esimese versiooni vastuvõtutingimused.','group'=>'Tööjärg ja varasemad juhendid','date'=>'07.11.2026'],
        'security-fx'=>['file'=>'VAARTPABERID_VALUUTAS_2026-11-09.md','title'=>'Väärtpaberite ost ja müük valuutas','description'=>'Maakleri tehing, EKP või käsikurss, arveldus, FIFO ja pearaamat.','group'=>'Kasutusjuhendid','date'=>'09.11.2026'],
        'currency-plan'=>['file'=>'archive/VALUUTA_ARENDUSPLAAN_2026-11-07.md','title'=>'Valuutaarvete ja aktsiatehingute arendusplaan','description'=>'Kursid, arved, maksed, aktsiad, ümberhindlus ja kontrollid.','group'=>'Tööjärg ja varasemad juhendid','date'=>'07.11.2026','notice'=>'Väärtpaberite käsitsi sisestamise esimene töövoog on kasutusel. Vaata ka väärtpaberite kasutusjuhendit.'],
        'xbrl-cash'=>['file'=>'KASSAPOHINE_ERIJUHUD_2026-11-08.md','title'=>'Kassapõhine arvestus, ettemaksed ja segaarved','description'=>'Ettemaksed, arvestuskorra vahetamine, põhivara, autod, segaarved, kreeditarved ja XBRL.','group'=>'Kasutusjuhendid','date'=>'08.11.2026'],
        'xbrl-cash-previous'=>['file'=>'archive/XBRL_KASSAPOHINE_2026-11-07.md','title'=>'Kassapõhise arvestuse varasem juhend','description'=>'07.11.2026 tööetapi kirjeldus.','group'=>'Tööjärg ja varasemad juhendid','date'=>'07.11.2026','notice'=>'Ajalooline juhend. Uus juhend „Kassapõhine arvestus, ettemaksed ja segaarved” asendab selles toodud piirangud.'],
        'zero-vd'=>['file'=>'NULLMAAR_MAKSUVABA_VD_2026-11-05.md','title'=>'0%, maksuvaba müük ja VD aruanne','description'=>'Ekspordi ja EL müügi maksukoodid, arve märkused, KMD lahtrid ning VD XML.','group'=>'Kasutusjuhendid','date'=>'05.11.2026'],
        'import'=>['file'=>'KAUPADE_IMPORT_2026-11-05.md','title'=>'Kaupade import ja tollidokumendid','description'=>'Impordi KM, seotud ostuarved, pangamaksed, KMD ja praegused piirangud.','group'=>'Kasutusjuhendid','date'=>'05.11.2026'],
        'credits'=>['file'=>'KREEDITARVETE_ERIJUHUD_2026-11-05.md','title'=>'Kreeditarvete erijuhud','description'=>'Osaline kreedit, kaubatagastus, põhivara, pöördmaks ja raha tagastamine.','group'=>'Kasutusjuhendid','date'=>'05.11.2026'],
        'deductions'=>['file'=>'OSALISE_MAHAARVAMISE_PARANDUS_2026-11-05.md','title'=>'Käibemaksu osaline mahaarvamine','description'=>'Mahaarvatava summa kinnitamine, automaatsed paranduskanded ja ajalugu.','group'=>'Kasutusjuhendid','date'=>'05.11.2026'],
        'ledger'=>['file'=>'PEARAAMATU_EKSPORT_2026-11-05.md','title'=>'Pearaamatu allalaadimine','description'=>'Kogu pearaamatu või valitud perioodi eksport CSV-, PDF- ja XLSX-failina.','group'=>'Kasutusjuhendid','date'=>'05.11.2026'],
        'entries-payroll'=>['file'=>'KASUTUSMUGAVUSE_PARANDUSED_2026-11-05.md','title'=>'Kanded ja töötajate pangarekvisiidid','description'=>'Parandatava dokumendi viide, mustandi kustutamine ning töötaja IBAN, pank ja BIC.','group'=>'Kasutusjuhendid','date'=>'05.11.2026'],
        'vat-roadmap'=>['file'=>'archive/KAEIBEMAKS_HOMME_2026-11-05.md','title'=>'Käibemaksu tööjärg ja piirangud','description'=>'Tehtud tööd ja veel arendamist vajavad käibemaksu erijuhud.','group'=>'Tööjärg ja varasemad juhendid','date'=>'05.11.2026','notice'=>'See on arenduse tööjärg. Kõik siin loetletud võimalused ei ole veel programmis olemas.'],
        'setup-xampp'=>['file'=>'archive/Programmi_kasutuselevotu_juhend.txt','title'=>'Programmi kasutuselevõtt — varasem XAMPP-i juhend','description'=>'Varasem Windowsi ja XAMPP-i paigaldus-, varundus- ja üleviimisjuhend.','group'=>'Tööjärg ja varasemad juhendid','date'=>'08.10.2026','notice'=>'See juhend kirjeldab varasemat XAMPP-i paigaldust. Praegune programm asub kaustas C:\\laragon\\www\\raamatupidamine; juhendis toodud XAMPP-i kaustad ja pordid ei kirjelda praegust paigaldust.'],
    ];
}
function manualEscape(string $text): string { return htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
/** Text-only Markdown subset. Embedded HTML is always escaped; links allow only web URLs. */
function manualInline(string $text): string
{
    $tokens=[];
    $put=static function(string $html) use(&$tokens): string { $key='@@MANUALTOKEN'.count($tokens).'@@'; $tokens[$key]=$html; return $key; };
    $text=preg_replace_callback('/`([^`]+)`/u',static fn(array $m):string=>$put('<code>'.manualEscape($m[1]).'</code>'),$text);
    $text=preg_replace_callback('/\[([^\]\r\n]+)\]\((https?:\/\/[^\s<>\)]+)\)/u',static fn(array $m):string=>$put('<a href="'.manualEscape($m[2]).'" target="_blank" rel="noopener noreferrer">'.manualEscape($m[1]).'</a>'),$text);
    $text=manualEscape($text);
    $text=preg_replace('/\*\*([^*\r\n]+)\*\*/u','<strong>$1</strong>',$text);
    return strtr($text,$tokens);
}
function manualMarkdown(string $text): string
{
    $html=''; $paragraph=[]; $list=null; $code=null;
    $flush=static function() use(&$html,&$paragraph): void { if($paragraph) { $html.='<p>'.manualInline(implode(' ',$paragraph)).'</p>'; $paragraph=[]; } };
    $close=static function() use(&$html,&$list): void { if($list) { $html.='</'.$list.'>'; $list=null; } };
    foreach(preg_split('/\r\n|\r|\n/',$text) as $line) {
        if(preg_match('/^\s*```/',$line)) { $flush(); $close(); if($code===null) $code=[]; else { $html.='<pre><code>'.manualEscape(implode("\n",$code)).'</code></pre>'; $code=null; } continue; }
        if($code!==null) { $code[]=$line; continue; }
        if(trim($line)==='') { $flush(); $close(); continue; }
        if(preg_match('/^(#{1,6})\s+(.+)$/u',$line,$m)) { $flush(); $close(); $level=min(6,strlen($m[1])+1); $html.='<h'.$level.'>'.manualInline($m[2]).'</h'.$level.'>'; continue; }
        if(preg_match('/^\s*(?:([-*])\s+|(\d+)\.\s+)(.+)$/u',$line,$m)) {
            $flush(); $type=$m[1]!==''?'ul':'ol';
            if($list!==$type) { $close(); $list=$type; $html.='<'.$list.($type==='ol'?' start="'.max(1,(int)$m[2]).'"':'').'>'; }
            $html.='<li>'.manualInline($m[3]).'</li>'; continue;
        }
        if(preg_match('/^\s*([-*_])(?:\s*\1){2,}\s*$/',$line)) { $flush(); $close(); $html.='<hr>'; continue; }
        $close(); $paragraph[]=trim($line);
    }
    $flush(); $close(); if($code!==null) $html.='<pre><code>'.manualEscape(implode("\n",$code)).'</code></pre>'; return $html;
}
