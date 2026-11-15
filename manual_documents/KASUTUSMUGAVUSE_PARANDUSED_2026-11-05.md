# Kanded ja töötajate pangarekvisiidid — 05.11.2026

Kõik viis kasutaja soovitud muudatust on paigaldatud ning failide sisu järgi kontrollitud kaustas **C:/laragon/www/raamatupidamine**.

1. Avalehel kuvatakse paranduskande PAR-numbri kõrval parandatava dokumendi number ja link. Algdokumendi järgi saab ka otsida. Uuel vastukandel salvestatakse püsiv algkande viide; vanu `PAR-<kande ID>` kirjeid tuvastatakse samuti. Mustandi dokumendinumbri muutmine algviidet ei kaota.
2. Kinnitamata mustandi saab tühistada ja kustutada nii kande vaatest kui ka muutmisvaatest. Kustutatakse ka selle read. Kinnitatud kandeid ja teiste ettevõtete andmeid need nupud ei muuda. Panga impordirea seos vabastatakse, pangarida säilib. Muude dokumentidega seotud mustandil palutakse seos esmalt selle dokumendi vaates lahendada. Suletud periood ei takista kinnitamata mustandi koristamist, sest pearaamatu saldo ei muutu.
3. Töötaja lisamisel on IBAN, panga nimi ja BIC. Pangarekvisiite saab hiljem töötajate loendis muuta või tühjendada. IBAN normaliseeritakse ja kontrollitakse kontrollnumbrit; BIC-i vormingut kontrollitakse. Väljad on vabatahtlikud; makseid see muudatus pangale ei saada.
4. Proovikirje linnuke ja märgis on töötajate vaatest eemaldatud. Uus töötaja ei saa POST-i peidetud proovikirje lipust sõltuvaks. Varasemate töötajate ajaloolisi lippe ega TSD ekspordipiiranguid paigaldamisel ümber ei kirjutatud.
5. `edit_entry.php` kasutab uut vaadet: selgemad vormikaardid, algdokumendi viide, ridade lisamine/eemaldamine, jooksvad täpsed sendisummad ja tasakaalu näit. Lubatud on kuni 20 rida. Komaga summad salvestatakse õigesti. Salvestamine jätab kande mustandiks; kinnitamine toimub kande vaates.

## Kontrollid

- `usability_test/database_check.php`: tegelik SQL ühendusepõhistes ajutistes tabelites; vastukande viited, tühistamine/kustutamine, kaitsed ja pangaseose vabastamine; töötaja pangarekvisiitide valideerimine/salvestamine/tühjendamine, tegeliku töötaja lisamise vorm ning eemaldatud proovikirje valik.
- `usability_test/edit_check.php`: tegeliku muutmisvormi POST salvestab komaga summad ja säilitab algkande viite ka uue dokumendinumbri korral.
- `usability_test/view_check.php` ja `edit_script_check.cjs`: vormielemendid, sõltumatud toiminguvormid ning tegeliku JavaScripti summad, vahe, ridade lisamine/eemaldamine, 20 rea piir ja suurte summade senditäpsus.
- Kõik paigaldatud PHP failid läbisid süntaksikontrolli; avalehe algdokumendi JOIN/otsingu SQL kontrolliti päris skeemil ainult lugemisega. Lähtestamise kontroll läbis ajutistes tabelites ka uute väljadega.
- Tegelike kannete, töötajate, panga impordiridade ja pearaamatu summade seis jäi paigaldamisel samaks. Püsivaid proovikirjeid ei loodud ega kustutatud.
- Visuaalne brauserieelvaade jäi tegemata: brauseri turvareegel blokeeris kohaliku `file:` eelvaate. Reeglist ei mindud mööda; tehti vormi ja skripti automaatkontrollid.

Varasemad failid: `usability_before`. Paigaldaja: `install_usability.ps1`. Lisatud on `journal_entries.corrects_entry_id` ning `payroll_employees.bank_iban`, `bank_name`, `bank_bic`.
