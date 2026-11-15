# Kaupade import — 05.11.2026

Rakendus: C:/laragon/www/raamatupidamine. Avalehelt ja ostuarvete nimekirjast avaneb **Tollidokumendid ja impordi KM**.

## Kasutamine

1. Sisesta ja kinnita kauba ostuarve koodiga **O_IMPORT0 — Kauba import — tarnija KM 0; KM tollidokumendilt**. Kõik selle arve read peavad olema sama käsitlusega. Kauba hind ja tarnijavõlg kirjendatakse ainult sellest arvest.
2. Loo impordi registris tollidokument: MRN/tolliotsuse number, vabastamise kuupäev, seotud kaubaarve, tolli maksustatav väärtus, tegelik impordi KM ja mahaarvatav summa. Ettevõte peab olema importija. Maksustatav tolliväärtus ei ole uus kauba ostuhind ega tarnijavõlg. Tollidokumendi originaal tuleb alles hoida.
3. Vali sisendkäibemaksu konto ning kauba tarnijavõlast eraldi impordi KM kohustuse konto. Kinnitus teeb D mahaarvatav sisend-KM / D vajadusel mahaarvamata KM kulukonto / K impordi KM kohustus. Kulukontole ostetud kaubal toetatakse 0–100% mahaarvamist.
4. Tollimaks ja muud tasud sisesta eraldi ostuarvel. Impordi registri summad ja arveviide on kontrollandmed: neist ei tehta teist kulu- ega võlakannet. Maakleri arvel ei tohi sama impordi KM-i uuesti tavalise ostuarve sisendkäibemaksuna kajastada. Tollimaksu ja soetusega seotud kulude õige jaotus kulu-, lao- või põhivara maksumusse peab olema tehtud algarvestuses.
5. Kui impordi KM on juba käsitsi kirjendatud, sisesta olemasoleva kinnitatud kande number. Programm kontrollib kuupäeva, kontosid ja summasid ning seob kande uut tegemata. Sama tollinumbriga leitud kanne/mustand peatab uue loomise. Viiteta või teise numbriga varasema käsikande tähendust ei saa programm ise ära arvata.
6. Tollile/agentuurile KM maksmisel vali dokumendi juures pangarida. Kirjendamata rea puhul tehakse **D impordi KM kohustus / K pank**, juba kirjendatud rea puhul seotakse olemasolev kanne. Alternatiivina saab sisestada olemasoleva maksekande numbri. Osamaksed ja jäägipiir on toetatud. Makse ei tekita uut KM mahaarvamist.
7. KMD-l tasumise valik vajab kehtiva loa viidet. Programm näitab lahtri **4.1** võrdlussummat; MTA täidab selle tolliandmetest ise ja XML seda elementi ei saada. Maksed tehakse koos deklaratsiooniga, mitte üksikdokumendi kaudu.

## Aruanded, parandamine ja kaitsed

- Vabastamise kuu järgi jõuab mahaarvatav impordi KM ühisest arvutusest aruandesse ning KMD **5 ja 5.1**. Täielikult mahaarvataval põhivaral ka **5.2**. Alajaotusi ei liideta teist korda lahtrile 5.
- Tollile tasutav import ei suurenda KMD arvestatud KM-i. Loaga KMD-l tasutav KM sisaldub aruande tasumisele kuuluvas saldos ja seda võrreldakse MTA lahtriga 4.1. Import ei lähe lahtritesse 6/6.1 ega 7/7.1.
- Kauba tarnijaarve O_IMPORT0 ja tollidokument ei kuulu INF B-ossa. Arve juures on vastav staatus; impordi KM kontrollitakse registris.
- KMD6 XML sisaldab `inputVatTotal`, `importVat` ja vajadusel `fixedAssetsVat`. XBRL GL peatatakse impordi puhul selge põhjusega kuni selle eraldi laiendamiseni.
- Tühistamine avatud algperioodis teeb KM vastukande ja säilitab ajaloo. Seejärel saab sama MRN-iga parandatud dokumendi luua. Makselingid tuleb enne eemaldada; päris pangakanded ja pangarea seosed jäävad alles ning on uuesti seotavad uut kannet tegemata.
- Registri KM-/maksekannete üldine PAR-vastukanne on tõkestatud. Seotud kauba-/kulude ostuarve tühistamine peatatakse. Pearaamatukande muutus tuvastatakse aruandes ja peatab puuduliku deklaratsiooni väljastamise.
- Lähtestus kustutab ka impordidokumendid, makselingid ja sündmused; kontaktid/seaded säilivad. Selle töö käigus tegelikke andmeid ei lähtestatud.

## Veel eraldi lahendada

- Sõiduautode import, 5.3/5.4 ja kuunimekiri.
- Lao-/põhivara maksumusse jaotatav mahaarvamata impordi KM. Täielik mahaarvamine on toetatud; osaline varakonto kauba mahaarvamine peatatakse, sest see peab muutma ka FIFO-d/põhivarakaarti ja arvestama krediite/kulumit.
- Hilisem tolliparandus eraldi jooksvas perioodis, sh tollideklaratsiooni muutmata maksustatava väärtuse parandus. Algperioodi vea tühistamine seda ei asenda.
- Mitu kaubaarvet ühel tollidokumendil: praegu valitakse üks arve. Ühe arvega võib siduda mitu erinevat MRN-i vastavalt tegelikele osasaadetistele.
- Koondpangamakse jaotamine KM, tollimaksu, teenustasude või mitme tollidokumendi vahel; ettemaksed enne kauba vabastamist. Jaotamata koondmakset ei loeta ühe dokumendi KM tasumiseks.
- Tollidokumendi faili üleslaadimine/automaatne lugemine; praegu sisestatakse andmed käsitsi. e-MTA ühendust ei lisatud.

## Kontrollid

- `import_vat_test.php`: 92 tegeliku SQL-i kontrolli ühendusepõhistes ajutistes tabelites: summad, 0/50/100%, arve- ja vabastamiskuu, ettevõte, MRN, olemasolevad kanded, tollitasud, osamaksed ja uuestisidumine, tühistamine, perioodilukud, muutunud allikas ja tasakaalus pearaamat. Tegeliku KMD6 kontrolleri XML vastab MTA XSD-le.
- Režiimid `ui`, `inf`, `xbrl`: tegeliku kontrolleri kuvamine ja vormid, INF kandidaatide välistamine, puuduliku uue ekspordi tõke.
- Varasemad `purchase_vat_adjustment_test.php` ja `credit_special_database_test.php` läbivad samuti kontrollid.
- Paigaldus kontrollib varukoopiate räsisid ning tegelike kontaktide, kannete ja pangaridade arvu ja pearaamatu summasid enne/pärast. Uued registrid jäävad tühjaks, olemasolevatele arvetele kandeid ei looda.

## Ametlikud alused

- [MTA: impordi sisendkäibemaks](https://emta.ee/node/339/chapter/30180/pdf): tollidokument ja vabastamise kuu.
- [MTA: impordi KMD-l deklareerimise luba](https://www.emta.ee/node/338/chapter/10926/pdf): MTA täidab 4.1 ise.
- [MTA: kauba import](https://www.emta.ee/node/339/main_chapter/30159/pdf): maksustatav väärtus ja paranduste erisused.

Päris deklaratsioone ei saadetud. Ülal loetletud impordi erijuhud jäävad avatuks.
