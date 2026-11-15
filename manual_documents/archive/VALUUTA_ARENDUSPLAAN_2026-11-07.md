# Valuutaarvete ja valuutas aktsiatehingute arendusplaan

Koostatud 07.11.2026. Kasutaja valis esimeseks väärtpaberite töövoo. Käsitsi sisestatava ostu ja müügi juhend on programmi Kasutusjuhendites pealkirjaga „Väärtpaberite ost ja müük valuutas”.

## Eesmärk ja esimese versiooni piir

Lisada välisvaluutas ostu- ja müügiarved ning ettevõtte välisvaluutas aktsiate/ETF-ide ost ja müük. Eeldus on, et „valuuta ostu-müügi arved” tähendab välisvaluutas väljastatud või saadud arveid. Pangakontode vaheline valuutavahetus lisatakse rahaliikumisena, mitte kauba ostu või müügiarvena.

Ettevõtte arvestusvaluutaks jääb EUR. Algdokumendi valuuta ja summa säilivad koos vastava euroväärtusega. Olemasolevad euroarved ja kanded peavad toimima muutumatult. Töö tehakse kaustas C:\laragon\www\raamatupidamine; olemasolevaid tehinguid automaatselt ei kustutata.

Esimese versiooni piiridesse kuuluvad EUR ja EKP avaldatavad vajalikud valuutad, tavalised välisvaluutaarved, täis- ja osamaksed, kreeditarved, valuutakontod, kursivahed, perioodilõpu ümberhindlus ning aktsiate/ETF-ide lihtsad ostud ja müügid. Optsioonid, futuurid, lühikeseks müük, võimendus, krüptovara ja keerukad ettevõttesündmused on eraldi töö. EUR-ist erinev ettevõtte arvestusvaluuta ei kuulu esimesse versiooni.

## Praegune lähtekoht

Arvete sisestus ja senised ekspordid on eurokesksed. Investeeringute ülevaates on olemas FIFO soetusmaksumuse arvestus ning algsaldo ja pangaridade sidumine. Selle kuvatavad summad on eurodes. Teenustasuga investeerimistehing vajab praegu eraldi kontrolli. Olemasolevat FIFO-d kasutatakse lähtekohana; seda ei tohi laiendada pelgalt valuutatähise lisamisega.

## 1. Ühine valuutaarvestus ja algandmed

- Valuutaregister ja valuuta täpsus: näiteks EUR/USD kaks rahalist komakohta ning JPY null; aktsiakoguse ja hinna täpsus säilitatakse eraldi.
- Kõigil kurssidel üks suund: **1 EUR = X ühikut välisvaluutat**. Euroväärtus = valuutasumma / X. EUR kurss on 1.
- EKP ajalooliste kursside laadimine koos kohaliku vahemäluga. Salvestada allikas, kursi tegelik kuupäev ja laadimise aeg. Nädalavahetuse või puuduva päevakursi käsitlus määratakse üheselt, kontrollitakse kohaldatavate reeglite järgi ja näidatakse kasutajale.
- Käsitsi sisestatud kurss peab säilitama põhjuse ja ajaloo. Raamatupidamiskurss, käibemaksu arvestuse kurss ja panga tegelik vahetuskurss on eristatavad.
- Puuduv või vigane kurss peatab kinnitamise. Vaikimisi kurssi 1 ei kasutata välisvaluuta puhul.
- Summade ja kursside arvutus tehakse täpsete kümnendarvude või täisarvulise aritmeetikaga. Ümardusreegel on ühine kogu programmile; viimane osamakse sulgeb ümardusjäägi.
- Kinnitatud dokumendi kurss ja euroväärtused säilivad dokumendi juures. Kursitabeli hilisem värskendamine ei muuda vanu kandeid.

Valmisolek: üks ühine arvutuslahendus annab sama tulemuse arvel, pangamakses, portfellis ja aruandes.

## 2. Ostuarved ja müügiarved

- Vormile valuuta, kurss, kursi kuupäev ja selge eurokokkuvõte. Põhisummad sisestatakse arve valuutas.
- Säilitada nii algsummad kui euroväärtused arve ja arveridade tasandil. Olemasolevate eurosummade tähendust ei muudeta.
- Raamatupidamise kuupäev ning käibe tekkimise kuupäev tuleb vajadusel eristada. Käibemaksu kursi alus ei tohi automaatselt muutuda makse kuupäevaks.
- Välisvaluuta ei määra maksukäsitlust: Eesti ost, EL soetus, import, pöördmaks, eksport ja maksuvaba tehing kasutavad jätkuvalt õiget maksukoodi.
- Arve väljatrükk, PDF, muud kasutatavad arveväljundid ja võlgnevuste loend peavad näitama õiget valuutat; käibemaksu euroandmed peavad olema kättesaadavad.
- Kreeditarve pärib algse valuuta ja seose. Algse tehingu käibemaksuparandus ning tagasimakse kursivahe arvutatakse eraldi.
- Andmebaasi täiendus peab olema korduskäivitatav. Vanad dokumendid käsitletakse EUR-dokumentidena; ajaloolisi summasid ei arvutata ümber.

Valmisolek: USD ostu- ja müügiarve kinnitamisel tekib tasakaalus eurokanne ning algne USD-dokument säilib.

## 3. Tasumised, valuutakontod ja kursivahed

- Pangakontol või selle valuutaalamkontol on määratud valuuta. Säilitada saldo nii algvaluutas kui eurodes.
- Makse võib olla arve valuutas või teises valuutas. Säilitada arve valuutas kaetud summa, panga valuutas liikumine, euroväärtus ja teenustasu.
- Täis- ja osamakse sulgeb vastava osa nõude või kohustise eurojäägist. Vahe läheb määratud kursikasumi või -kahjumi kontole.
- Arvestada varasemaid ümberhindlusi: juba kajastatud kursivahet ei tohi tasumisel uuesti kirjendada.
- Panga teenustasu on eraldi kanne. Valuutavahetus seob kahe valuutakonto liikumised ühe sündmusena; mõlemat jalga eraldi tuluks või kuluks ei loeta.
- Pangaimpordi kordamine ei tohi tekitada uut makset. Olemasolevale arvele peab saama siduda imporditud rea ka siis, kui dokumendi ja panga valuuta erinevad.
- Makse ja tagasimakse tühistamine taastab õiged mõlema valuuta jäägid ning seotud kursivahe.
- Kassapõhise käibemaksu puhul kontrollida maksele vastava maksustatava osa ja kursivahe eristamist. Valuutakasum ei tohi muutuda automaatselt müügikäibeks.

Valmisolek: tasutud arve jääk on null nii arve valuutas kui eurodes ning panga saldo ja kursivahe on põhjendatavad.

## 4. Välisvaluutas aktsiate ja ETF-ide tehingud

- Tehingule väärtpaberi tunnus (võimalusel ISIN), sümbol, maakler/konto, kogus, hind, tehinguvaluuta, tehingupäev ja arvelduspäev.
- Maakleri rahakontod valuutade kaupa ning vajadusel arveldamata ostude/müükide vahekontod. Tehing ja hilisem rahaline arveldus seotakse; arvelduse kuupäev ei kirjuta tehingu kuupäeva üle.
- Ost säilitab partii algse valuutasumma, kasutatud kursi ja eurodes soetusmaksumuse. Olemasoleva FIFO laiendus peab eristama ettevõtet, kontot ja väärtpaberit ning toetama osalist müüki ja koguse ümardust.
- Müügil arvutatakse eurodes laekumine ja eemaldatav bilansiline maksumus. Ajaloolist ostu ei teisendata müügipäeva kursiga.
- Teenustasu võib olla eraldi valuutas. Selle arvestus sõltub valitud finantsvara kajastamise põhimõttest; kõiki tasusid ei lisata automaatselt soetusmaksumusse.
- Investeeringu hinna/õiglase väärtuse muutus, valuutakonto kursivahe ning müügitulemus peavad kokku andma pearaamatus õige kogutulemuse. Sama kursimõju ei tohi kasumisse kaks korda jõuda.
- FIFO partiiarvestus ei asenda perioodilõpu nõutavat finantsvara hindamist. Kajastamispõhimõte ja 2026/2027 aruandeperioodi reeglid kontrollitakse RTJ 3 järgi.
- Maakleri tehingufail ja pangaväljavõte seotakse sama sündmusega. Tehingu identifikaator ja impordiajalugu takistavad topeltkirjendamist; ainult kuupäeva ja summa võrdlusest ei piisa.
- Algpositsioonide ülevõtul säilitada kogused, ajaloolised eurodes maksumused, valuutajäägid ja varasemad ümberhindlused. Olemasolevat portfelli automaatselt ei nullita.
- Dividendid, kinnipeetud maksud ja muud maaklerikulud kaardistatakse eraldi, kui neid kasutatavas proovifailis esineb. Puudulike kogustega müüki automaatselt ei kinnitata.

Valmisolek: maakleri positsioonid ja valuutajäägid klapivad programmi ning pearaamatuga; ost, osamüük ja tühistamine taastavad õiged partiid.

## 5. Perioodilõpp, aruanded ja käibemaksu väljundid

- Valuutapanga, nõuete, võlgade ja muude toetatud rahaliste saldode ümberhindlus koos kuupäeva, kursi, alusjäägi ja paranduskandega.
- Ümberhindluse kordamine peab olema ohutu. Lukustatud perioodi muutmine on tõkestatud; tühistamisel säilib ajalugu.
- Aktsiate perioodilõpu hindamine tehakse sobiva finantsvara põhimõtte järgi; neile ei rakendata pimesi valuutapanga ümberhindlusloogikat.
- Pearaamat jääb eurodesse, algdokumendi valuuta on jälgitav. Võlgnevused ja rahakontod näitavad mõlemat summat; portfell näitab kogust, euroväärtust ja algvaluuta andmeid.
- XBRL GL, KMD, INF ja VD kasutavad nende nõuetele vastavaid euroväärtusi. Valuutaväli ei tohi muuta olemasolevat maksukoodi, mahaarvamisõigust ega aruandeperioodi.
- Impordi käibemaksu puhul säilib tollidokumendi alus. Ostuarve EKP kurss ei asenda automaatselt tolli määratud euroandmeid.

## 6. Vastuvõtukontroll

1. Senine EUR arve, makse ja aktsiatehing annavad sama tulemuse kui enne täiendust.
2. Lihtne KM-ta arve 1 100 USD kursiga 1 EUR = 1,10 USD annab 1 000 EUR. Kui ostja tasub hiljem eurokontole 1 100 EUR, on nõude kursikasum 100 EUR. Ostuarve vastupidises näites on kursikahjum 100 EUR; pangatasu arvestatakse eraldi.
3. Arve 550 USD osamakse sulgeb täpselt poole 1 100 USD arvest. Lõppmakse järel ei jää ümardussenti nõudesse ega kohustisse.
4. Aktsiate lihtnäide enne tasusid ja ümberhindlusi: 10 aktsiat hinnaga 100 USD, kurss 1,25 → ost 800 EUR. Nelja aktsia müük hinnaga 120 USD, kurss 1,20 → müük 400 EUR, FIFO maksumus 320 EUR, tulem 80 EUR; alles kuus aktsiat ajaloolise maksumusega 480 EUR. Rahakonto edasine kursimõju kontrollitakse eraldi.
5. Testida USD, SEK ja JPY, puhkepäeva, puuduva kursi, käsikursi, tasu teises valuutas ning makse arve valuutast erinevas valuutas.
6. Testida kreeditarvet enne ja pärast osamakset, pangatagastust, makse/tehingu tühistamist, kassapõhist arvestust ja pöördmaksu.
7. Importida sama panga- ja maaklerifail uuesti: uusi kandeid ei teki. Maakleritehing ning selle arveldus ei dubleeri aktsiaostu või -müüki.
8. Võrrelda arvet, mõlema valuuta jääke, pearaamatut, portfelli ja XBRL-i. Kontrollida skeemi kõrval ka summade majanduslikku kooskõla.
9. Kontrollida andmete varundamist, tagasipöördumist, ettevõtete eraldatust ning lukustatud perioode eraldatud prooviandmetega.

## Töö korraldus järgmisel nädalal

Järjekord on 1 → 2 → 3 → 4 → 5 → 6. Arvete töövoog lõpetatakse ja kontrollitakse enne aktsiate lisamist. Igal etapil märgitakse üles tehtud töö, katmata juhud ja läbimise tingimused. Uusi erijuhte lisatakse eraldi tööjärge, et esimese versiooni lõpp-punkt ei nihkuks pidevalt.

Enne maaklerifaili impordi arendust on vaja otsustada kasutatavad pangad/maaklerid, esimesed valuutad ning finantsvara kajastamise põhimõte. Koostatakse anonüümsed näidisfailid või kasutatakse kasutaja antud proovifaile. Need valikud ei takista ühise valuutaarvestuse ja arvete töö alustamist. Päevade kaupa tähtaeg määratakse pärast nende lähteandmete ülevaatust; käesolev plaan ei luba kõigi etappide valmimist ühe nädalaga.

## Kontrollallikad

- [EKP euro vahetuskursid ja ajaloolised andmed](https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html): kavandatav kursiandmete allikas.
- [Rahandusministeeriumi Raamatupidamise Toimkonna juhendid](https://fin.ee/finantspoliitika-valissuhted/arvestusvaldkond/raamatupidamise-toimkond): RTJ 1 ja RTJ 3 ning aruandeperioodile kohaldatava versiooni kontroll.
- [MTA käibemaksusumma arvutamise ja tasumise juhend](https://www.emta.ee/node/338/main_chapter/10931/pdf): käibemaksu valuutakursi kuupäev ja impordi erand. Raamatupidamise ning KM euroarvestus ei pruugi alati kasutada sama kuupäeva.
