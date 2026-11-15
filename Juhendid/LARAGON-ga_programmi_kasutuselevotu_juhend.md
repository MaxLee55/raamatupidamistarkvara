# Raamatupidamisprogrammi kasutuselevõtt Laragoniga(kohalik server, mis tuleb oma arvutisse tõmmata).
  Probeemide ilmnemisel aitab alati AI.

Koostatud 14.11.2026. Juhend kirjeldab Windowsi arvutis kohalikult töötava programmi paigaldamist, olemasoleva programmi üleviimist ja uue eraldi koopia seadistamist.

Näidiskaust on `C:\laragon\www\raamatupidamistarkvara`, näidisaadress `http://localhost/raamatupidamistarkvara/` ja uue andmebaasi näidisnimi `raamatupidamistarkvara`. Asenda need oma valitud nimedega. 

## 1. Mida paigaldamiseks vaja läheb

- Windowsi arvuti ja Laragon koos Apache veebiserveri, PHP ja MySQL-iga.
- Programmi terviklik kaust koos alamkaustadega.
- Selle programmiversiooniga sobiv andmebaasi SQL-eksport. Olemasoleva ettevõtte üleviimiseks peab see sisaldama tabelite struktuuri ja andmeid. Uue ettevõtte alustamiseks on vaja selleks ette valmistatud algandmebaasi.
- Sisselogimiseks SQL-ekspordis oleva administraatori konto või võimalus luua administraator pärast andmebaasi importimist.
- Internetiühendus EKP kursside, Äriregistri otsingu, e-posti ja Billberry kasutamiseks.

Programmi failid ja andmebaas on eraldi osad. Kausta kopeerimine annab uuele koopiale samad failid. Uus andmebaas tekib siis, kui lood selle andmebaasihalduris ja impordid sinna SQL-faili. Andmebaasivaliku määrab programmi `db.php`.

Enne alustamist säilita toimiva programmi kaustast ja andmebaasist eraldi varukoopia. Paigaldamiseks kasuta kontrollitud SQL-faili, mille päritolu ja sisu on teada.

## 2. Laragoni paigaldamine

1. Laadi Laragon alla ametlikult lehelt.
2. Vali paigalduskohaks näiteks `C:\laragon`.
3. Kasuta komplekti, milles on Apache, PHP ja MySQL. Puuduvaid komponente saab lisada Laragoni menüüst.
4. Käivita Laragon ning vajuta `Start All`.
5. Kontrolli, et Apache ja MySQL on käivitunud.
6. Ava brauseris `http://localhost/`. Peaks avanema Laragoni või kohaliku serveri avaleht.
7. Ava brauseris `http://localhost/raamatupidamistarkvara`. Avaneb raamatupidamistarkvara sisselogimisleht.

Laragoni kasutamiseks ettevõttes kontrolli valitud versiooni litsentsitingimusi. Praegustes tingimustes eristatakse ettevõtlusega seotud kasutust ja mitteärilist kasutust. [Laragoni allalaadimine](https://laragon.org/download), [paigaldusjuhend](https://laragon.org/docs/install), [litsentsitingimused](https://laragon.org/eula)

Kui Apache ei käivitu, kontrolli, kas samal pordil töötab XAMPP-i Apache, IIS või teine veebiserver. Kui MySQL ei käivitu, võib sama porti kasutada teine andmebaasiserver. Lõpeta konkureeriv teenus selle enda juhtpaneelist või lase paigaldajal valida eraldi pordid.

Kui veebiserverile valitakse näiteks port 8080, kasuta aadressi `http://localhost:8080/raamatupidamistarkvara/`. MySQL-i pordi muutmine vajab vastavat muudatust ka andmebaasiühenduse seadistuses.

## 3. PHP ja andmebaasihalduri kontroll

Senist programmi on kontrollitud PHP 8.3 ja MySQL 8.4 keskkonnas. Uue paigalduse jaoks on need sobiv lähtekoht. Teise PHP versiooni kasutamisel kontrolli kogu vajalikku töövoogu uuesti.

PHP versiooni saad valida Laragoni menüüs `PHP → Version`. phpMyAdmini saab vajadusel lisada menüüst `Tools → Quick add → phpMyAdmin`. Menüü nimetused võivad Laragoni versiooniti veidi erineda. [Laragoni komponentide haldamine](https://laragon.org/docs/operations)

Programmi ja kaasasolevate teekide jaoks kontrolli järgmiste PHP laienduste olemasolu.

- `pdo_mysql` andmebaasiga suhtlemiseks.
- `mbstring` eestikeelse teksti ja paroolireeglite jaoks.
- `dom`, `SimpleXML`, `xml` ja `libxml` XML-failide ning skeemide kontrollimiseks.
- `bcmath` valuuta- ja väärtpaberiarvutusteks.
- `curl` ja `openssl` väliste teenuste ning turvaliste ühenduste jaoks.
- `gd`, `iconv` ja `zlib` PDF-ide, piltide ja tekstide töötlemiseks.
- `zip` ja `Phar` arhiivide ning ekspordifailide jaoks.

Laragoni terminalis saab aktiivset käsurea PHP-d kontrollida järgmiste käskudega.

```text
php -v
php --ini
php -m
```

Veebiserveri PHP ja terminali PHP võivad osutada erinevatele versioonidele. Vajadusel kontrolli mõlemat. Muuda Laragonis valitud PHP versiooni seadistusfaili ning taaskäivita pärast muudatusi Apache.

## 4. Programmi failide paigutamine

1. Ava `C:\laragon\www`.
2. Kui oled GitHub-st alla laadinud "raamatupidamistarkvara" zip faili, paki see lahti otse `C:\laragon\www` kausta
   ja siis pole vaja järgida punkte 3, 4, 5 ja 6. Tulemus C kettal peaks välja nägema selline: `C:/laragon/www/raamatupidamistarkvara/(siin kõik kauta sees olevad failid)`


3. Loo uuele koopiale kaust, näiteks `raamatupidamistarkvara`.
4. Kopeeri sinna programmi sisu. `index.php`, `login.php` ja `db.php` peavad asuma otse selles kaustas.
5. Kontrolli, et pole tekkinud topeltkausta, näiteks `raamatupidamistarkvara\raamatupidamistarkvara\index.php`.
6. Kopeeri kaasa kõik funktsioonide jaoks vajalikud alamkaustad.

Olulised kaustad on muu hulgas `vendor`, `palk`, `palk\xsd`, `ladu`, `pohivara`, `annual_report_templates`, `mta_vd_validation`, `manual_documents` ja `pildid`.

`vendor` sisaldab PDF-i ja e-posti teeke. `palk\xsd`, `annual_report_templates` ja `mta_vd_validation` sisaldavad ekspordifailide kontrollimiseks vajalikke skeeme. Säilita ka `composer.json`, `composer.lock` ja `.htaccess`.

`.git` on arenduse versiooniajalugu. `.bak` ja `.codex-backup` on vanad failivariandid. Töötav koopia saab kasutada aktiivseid faile. SQL-migratsioonid ja juhendid tasub säilitada hilisema hoolduse jaoks.

Laragoni tavapärane veebikaust on `C:\laragon\www`. Selle all asuv programmikaust avaneb näiteks aadressil `http://localhost/raamatupidamistarkvara/`. [Laragoni kaustade selgitus](https://laragon.org/docs/directory-structure)

## 5. Õige SQL-faili valimine

Täielik SQL-eksport peab sisaldama programmi põhitabeleid, näiteks `companies`, `users`, `accounts`, `contacts`, `journal_entries` ja `journal_lines`, ning kasutatavate moodulite tabeleid.

Faili nimi üksi ei tõenda, et andmebaas on tühi või uuele ettevõttele sobiv. Ka nimega „tühi” eksport võib sisaldada ettevõtte nime, kontakte, kasutajaid, palgaandmeid, proovikandeid ja seadistusi. Enne teisele kasutajale üleandmist kontrolli selle sisu.

Programmi kaustas olevad eri moodulite `.sql`-failid on enamasti skeemid või muudatused konkreetsete funktsioonide jaoks. Näiteks `palk\schema.sql` loob palgamooduli struktuuri ja `pohivara\migration_006_opening_balances_and_asset_changes.sql` täiendab põhivaramoodulit. Need ei moodusta iseenesest kogu programmi algandmebaasi.

Selle juhendiga SQL-algandmebaasi kaasa ei genereerita. Kasuta programmiversiooniga sobivat terviklikku eksporti või selleks eraldi koostatud algandmebaasi.

## 6. Uue andmebaasi loomine ja import

1. Ava Laragonist phpMyAdmin või muu paigaldatud andmebaasihaldur.
2. Logi sisse selle kohaliku MySQL-i kasutajaga, mille paigaldamisel määrasid. Kasuta enda paigalduse parooli.
3. Vali uue andmebaasi loomine.
4. Pane nimeks näiteks `raamatupidamistarkvara`.
5. Vali UTF-8 tuge pakkuv `utf8mb4` märgistik. Olemasoleva MySQL 8.4 ekspordiga sobib näiteks `utf8mb4_unicode_ci`; impordis olevad tabelimääratlused võivad määrata täpsema võrdlusreegli.
6. Ava vasakul just loodud andmebaas.
7. Vali `Import` ja oma SQL-fail.
8. Selle leiad programmi kaustast SQL(raamatupidamistarkvara.sql).
8. Käivita import ning oota lõpuni.
9. Veendu, et import lõppes veateateta ja tabelid ilmusid valitud andmebaasi.
   Kui tekib veateateid, kasuta AI abi.

Enne importimist vaata SQL-faili algust. Kui selles on `CREATE DATABASE`, `USE` või `DROP DATABASE`, kontrolli, millist nime need kasutavad. `USE mglassets` võib suunata impordi MGL Assetsi andmebaasi ka siis, kui phpMyAdminis oli valitud uus nimi. Uue koopia jaoks peab import jõudma uude andmebaasi.

Kui import katkeb, säilita veateade. Uues andmebaasis võib pärast katkestust olla ainult osa tabeleid. Lõpeta vea põhjuse lahendamine enne programmi kasutamist. Olemasoleva ettevõtte andmebaasi importimisel tee enne taastatav varukoopia.

Kui SQL-fail on brauseri üleslaadimispiirangust suurem, lase tõsta aktiivse PHP `upload_max_filesize` ja `post_max_size` väärtusi või kasuta andmebaasihalduri failipõhist importi. Mahukama impordi puhul võib vaja minna ka pikemat täitmisaega. Taaskäivita Apache pärast `php.ini` muutmist.

## 7. Programmi ühendamine uue andmebaasiga

Ava uue programmikausta `db.php` tekstiredaktoris. Muuda ainult selle koopia ühenduse andmeid.
Momendil on vaikimisi selline:

```php
$host = 'localhost';
$dbname = 'raamatupidamistarkvara';
$username = 'root';
$password = '';
```
Kui kasutusele võtad salasõna siis selline:
```php
$host = 'localhost';
$dbname = 'raamatupidamistarkvara';
$username = 'SINU_MYSQL_KASUTAJA';
$password = 'SINU_MYSQL_PAROOL';
```

Näites olevad kasutajanimi ja parool on kohatäited. Asenda need tegeliku kohaliku andmebaasikonto andmetega.

`$dbname` peab vastama eelmises sammus loodud andmebaasile. Programmi kaustanimi ja andmebaasinimi võivad olla erinevad. Eriti oluline on kontrollida andmebaasinime siis, kui kopeerid toimivat programmi samas arvutis uude kausta.

Praeguses programmis loetakse andmebaasi ühenduse andmed failist `db.php`. `.env` hoiab e-posti ja Billberry seadistust.

Kui MySQL kasutab eraldi porti, peab see kajastuma PDO ühenduses, näiteks `mysql:host=localhost;port=3307;dbname=raamatupidamistarkvara;charset=utf8mb4`. Kasuta sama porti, millel soovitud andmebaasiserver tegelikult töötab.

## 8. Esimene sisselogimine ja administraator

1. Ava `http://localhost/raamatupidamistarkvara/login.php`.
2. Logi sisse imporditud andmebaasis oleva administraatori kontoga.
3. Ava `Seaded → Sisselogimise andmed`.
4. Määra enda e-posti aadress ja uus parool.
5. Logi välja ning kontrolli uut sisselogimist.

Praeguses programmiversioonis on uue parooli miinimum 6 märki. Parool peab sisaldama suurt tähte, väikest tähte ja numbrit.

Kui algandmebaasis pole kasutajat või vana parool pole teada, tuleb administraator luua või parool lähtestada paigaldajal. Tabeli `users.password_hash` väärtuseks käib PHP `password_hash` funktsiooniga loodud räsi.

Programmikaustas võib olla varasem abifail `loo_kasutaja.php`. Selle sees võivad olla kindel ettevõtte ID ja eraldi kõvakodeeritud andmebaasiühendus. Enne kasutamist peab paigaldaja suunama selle õigesse uude andmebaasi ja kontrollima konto andmed. Seda faili brauseris lihtsalt avades võib kasutaja tekkida varasema programmi andmebaasi.

Kasutaja seotakse `users.company_id` kaudu ettevõttega. Administraatori roll on vajalik muu hulgas palgamooduli avamiseks. Kontot luues kontrolli nii ettevõtte olemasolu kui ka õiget rolli.

## 9. Ettevõtte andmed ja algseadistus

Pärast sisselogimist ava `Seaded` ja kontrolli järgmisi andmeid.

- Ettevõtte nimi, registrikood, aadress ja kontaktandmed.
- KMKR number ning käibemaksukohustuslase staatus. Märkeruut „Mittekäibemaksukohustuslane” mõjutab arvete käibemaksuosa kuvamist.
- Panga nimi, IBAN ja BIC.
- Majandusaasta algus ja kasutatav arvestusperiood.
- Kontoplaan ja moodulites kasutatavad vaikekontod.
- Majandusaasta aruande profiil ning kontode aruanderidade seosed.

Uue ettevõtte alustamisel kontrolli ka kontaktide loendit, kasutajaid, lao- ja põhivaraandmeid ning palgamooduli töötajaid. Imporditud SQL võib sisaldada varasema ettevõtte andmeid.

Algsaldode sisestamisel vali üks selge alguskuupäev. Võrdle algbilanssi eelmise arvestuse lõppsaldodega. Pangakonto, nõuded, kohustised, laovarud, põhivara ja väärtpaberite soetusandmed vajavad omavahel klappivaid algandmeid.

Andmete tühjendamise nupp on mõeldud valitud andmete eemaldamiseks juba seadistatud ettevõttes. See säilitab vastavalt nupu kirjeldusele seaded ja võib säilitada kontaktid. Uue sõltumatu ettevõtte jaoks kontrolli kogu alles jääv algandmestik eraldi.

## 10. E-posti ja Billberry uuesti seadistamine

Kui annad programmi teisele kasutajale või lood uue sõltumatu koopia, kasuta selle koopia jaoks tühje ühenduse tunnuseid. `.env` näidis võib olla järgmine.

```text
APP_GMAIL_ADDRESS=""
APP_GMAIL_SENDER_NAME=""
APP_GMAIL_APP_PASSWORD=""
APP_BILLBERRY_1_PRODUCTION_API_ID=""
APP_BILLBERRY_1_PRODUCTION_API_KEY=""
APP_BILLBERRY_1_PRODUCTION_UPDATES_LINK=""
APP_BILLBERRY_1_DEVELOPMENT_API_ID=""
APP_BILLBERRY_1_DEVELOPMENT_API_KEY=""
APP_BILLBERRY_1_DEVELOPMENT_UPDATES_LINK=""
```

Billberry võtmenimes olev `1` tähistab ettevõtte ID-d. Programmi seadete kaudu salvestamine kasutab aktiivse ettevõtte õiget ID-d.

E-posti jaoks ava `Seaded → E-posti saatmise seaded`, sisesta Gmaili aadress, saatja nimi ja Gmaili rakenduse parool ning salvesta. Seejärel saada enda kontrollitud aadressile üks näidisdokument.

Billberry jaoks ava `Seaded → E-arvete seaded`, sisesta oma ettevõtte ühenduse ID ja võti ning salvesta. Ühenduse kontroll ja arve tegelik saatmine on eraldi toimingud.

Mõnes varasemas koopias on e-posti ja Billberry tunnuste ülevõtmine kaustast `C:\laragon\raamatupidamine_private`. Kui samas arvutis on mitu programmi, kontrolli pärast tühja `.env`-failiga koopia avamist, et vanad tunnused ei taastuks automaatselt. Paigaldaja peab sellises koopias automaatse ülevõtmise lõpetama või kohandama seda tühje kohalikke seadeid arvestama.

Säilita `.htaccess`, mis keelab Apache kaudu `.env`-faili lugemise. Kontrolli brauseris `http://localhost/raamatupidamistarkvara/.env`. Vastuseks peaks tulema ligipääsukeeld. Kui avaneb faili sisu, tuleb ligipääsupiirang enne kasutamist korda teha.

## 11. Vajalikud failid ja teegid

Kui programmiga tuli `vendor`-kaust kaasa ja PDF-id ning e-post toimivad, on teegid juba olemas.

Kui `vendor` puudub, ava Laragoni terminal, liigu programmi kausta ja taasta sõltuvused lukufaili järgi.

```text
cd C:\laragon\www\raamatupidamistarkvara
composer install --no-dev --prefer-dist
composer check-platform-reqs
```

Selle programmi `composer.json` kasutab Dompdf-i PDF-ide tegemiseks ja PHPMailer-it e-posti saatmiseks. Kontrolli Composeriga võimalikke puuduvaid PHP laiendusi. Hoia `composer.lock` sama programmiversiooni juures.

PDF-ide puhul kontrolli ka täpitähti, euro- ja miinusmärke. Senises palgalehe paranduses kasutatakse PDF-i jaoks DejaVu Sans fonti.

## 12. Programmi aadress ja kopeeritud kaustanimed

Uus koopia peab avanema selle enda aadressilt. Kui kaust on `C:\laragon\www\raamatupidamistarkvara`, kasuta sama kaustanimega veebiaadressi.

Mõnes olemasolevas lehes on piltide või ikoonide aadressides veel `/raamatupidamine/`. Uues kaustas võivad need näida töötavat nii kaua, kuni vana koopia on samas arvutis olemas. Eraldi arvutis võivad ikoonid või pildid puududa.

Kontrolli uue koopia avalehe logosid, leheikooni ja navigeerimislinke. Kui link avab vana programmi või pilt tuleb vanast kaustast, peab paigaldaja kohandama selle uue koopia asukohale. Käesoleva juhendi lisamine kaustanimesid programmis ümber ei kirjuta.

## 13. Esimene kontroll pärast paigaldamist

Tee kontroll uues testandmebaasis ja enda valitud näidisandmetega.

1. Logi sisse, ava seaded ja veendu, et ettevõte on õige.
2. Lisa üks klient ja tarnija.
3. Salvesta müügiarve ja ostuarve mustand, ava need uuesti ning kontrolli sisestatud andmeid.
4. Kinnita näidisarved ja kontrolli tekkinud pearaamatukandeid.
5. Ava pearaamat, päevaraamat, bilanss ning kasumiaruanne. Kontrolli perioodi ja ettevõtet.
6. Proovi PDF-i allalaadimist ja kontrolli selle sisu.
7. Impordi väike panga CSV ning kontrolli faili vormingut, maksete suunda ja pangakontot. Seo üks näidismakse arvega.
8. Kui kasutad välisvaluutat, laadi EKP kurss ja kontrolli kuupäeva ning euroarvestust.
9. Lao kasutamisel kontrolli kauba vastuvõttu, müüki ja laojääki. Põhivara kasutamisel kontrolli kaarti ja amortisatsiooni eelvaadet.
10. Palgamoodulis kontrolli töötajat, töösuhet, maksuseadeid ja ühte palgaperioodi. Ava palgalehe PDF.
11. Kontrolli e-posti enda aadressile saadetud näidisdokumendiga.
12. Kontrolli vajalikke deklaratsiooni- ja aruandeeksporte ning nende kohalikku valideerimist.

Vali kontrolliks just need moodulid, mida hakkad kasutama. Ekspordifaili loomine, kohalik skeemikontroll ja vastuvõtja süsteemi kontroll on eraldi etapid.

## 14. Varundamine ja teise arvutisse viimine

Regulaarne varukoopia peaks hõlmama programmi faile ning täielikku SQL-eksporti. Ühenduse tunnuseid sisaldavat `.env`-faili hoia privaatselt ja otsusta selle kaasavõtmine vastavalt sellele, kellele koopia läheb.

Programmi andmete tühjendamise toimingud loovad enne kustutamist SQL-varukoopia. Tavapärase `C:\laragon\www\PROGRAMM` paigutuse korral kasutab praegune kood kausta `C:\laragon\varukoopiad\raamatupidamine`. Mitme programmikoopia varukoopiad võivad jõuda samasse kausta, seega kontrolli varukoopia andmebaasi ja loomise aega.

`database_backup.php` vajab kohalikku `mysqldump.exe` või vastavat MariaDB tööriista ning võimalust seda PHP kaudu käivitada. Kui varundamine ebaõnnestub, lahenda see enne andmete eemaldamist.

Teise arvutisse üleviimise järjekord on järgmine.

1. Koosta olemasolevast andmebaasist täielik SQL-eksport.
2. Kopeeri programmi kaust ja SQL-fail turvaliselt uude arvutisse.
3. Paigalda ja käivita uues arvutis Laragon.
4. Loo uus andmebaas ja impordi SQL sinna.
5. Kohanda uue koopia `db.php`.
6. Määra vajalikud ühenduse tunnused programmi seadetes.
7. Võrdle kontrollkuupäeval bilanssi, pangajääke, lahtiseid arveid ning kasutatavate moodulite jääke vana arvutiga.
8. Katseta ka ühe varukoopia taastamist eraldi andmebaasi, et kinnitada selle kasutatavus.

[Laragoni üleviimise juhend](https://laragon.org/docs/how-to)

## 15. Levinud probleemid

### Brauser ei ava localhost-aadressi

Ava Laragon, kontrolli Apache olekut ja kasutatavat porti. Kontrolli, et brauseri aadress vastaks tegelikule kaustanimele. Arvutis kohalikult avamisel tähendab `localhost` seda sama arvutit.

### Kuvatakse „Andmebaasiga ühendamine ebaõnnestus”

Kontrolli MySQL-i olekut ning `db.php` andmebaasinime, serverit, kasutajat, parooli ja vajadusel porti. Veendu andmebaasihalduris, et just selle nimega andmebaas on olemas ja import lõppes edukalt.

### Programm avaneb vana ettevõtte andmetega

Kontrolli uue koopia `db.php` väärtust `$dbname`. Seejärel kontrolli, millise ettevõtte andmed imporditud SQL sisaldas. Omaette veebikaust võib olla ühendatud sama andmebaasiga nagu vana programm.

### Mõni tabel või veerg puudub

Võrdle programmiversiooni ja andmebaasiekspordi versiooni. Puuduv moodulimigratsioon või poolik import võib anda sellise vea. Rakenda ainult sellele paigaldusele vajalik muudatus õiges järjekorras ja pärast varundamist.

### PDF-i või e-posti teek puudub

Kontrolli `vendor\autoload.php` olemasolu. Vajadusel taasta sõltuvused `composer install` käsuga ning kontrolli PHP laiendusi.

### EKP või Äriregistri päring ebaõnnestub

Kontrolli internetiühendust, arvuti kellaaega, `curl`-i, `openssl`-i ja PHP sertifikaadiseadistust. Vaata teenuse veateadet ning Laragoni PHP/Apache logi. Turvalise ühenduse sertifikaadikontroll peab töötama.

### E-posti saatmine ei õnnestu

Kontrolli saatja aadressi ja Gmaili rakenduse parooli programmi seadetes. Veendu, et uus koopia kasutab enda soovitud tunnuseid. Saatmise veateade või logi aitab eristada puuduvaid tunnuseid võrguühenduse veast.

### Palgamoodul teatab ligipääsupiirangust

Kontrolli, et sisse logitud kasutajal on administraatori roll. Pärast rolli muutmist logi välja ja uuesti sisse.

### Kujundus näitab varasemat versiooni

Värskenda brauserit klahvidega `Ctrl + F5`. Kui muutus puudub endiselt, kontrolli aadressiribal programmikausta ja seda, millisesse kausta fail tegelikult paigaldati.

## 16. Igapäevane käivitamine

1. Ava Laragon ja käivita Apache ning MySQL.
2. Ava programmi järjehoidja.
3. Logi sisse ja kontrolli aktiivset ettevõtet.
4. Sisesta andmed ning tee tavapärased kontrollid.
5. Koosta kokkulepitud sagedusega varukoopia.
6. Töö lõpetamisel logi välja. Sulge Laragoni teenused siis, kui salvestamised ja ekspordid on lõppenud.

Paigaldus on kasutuselevõtuks kontrollitud siis, kui sisselogimine, andmete salvestamine, vajalikud ekspordid, ühendused ja varundamine töötavad ning algsaldod klapivad.
