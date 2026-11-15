# Kassapõhine arvestus: ettemaksed, üleminekud ja segaarved

Uuendatud 08.11.2026. See juhend asendab 07.11.2026 põhivoo juhendi. Kirjeldatud töövood on rakendatud aruandes, vana KMD/INF arvutuses ja XBRL GL ekspordis. Proovitehinguid ettevõtte tegelikesse andmetesse ei lisata.

## Ettevõtte arvestuskorra seadistamine

Seaded → Ettevõtte andmed → Kassapõhise käibemaksuarvestuse periood. Määra esimene kuu ja vajadusel viimane kuu. Algus- ja lõppkuu kuuluvad erikorra perioodi sisse. MTA teavitamine ja õiguse olemasolu kontroll on ettevõtja toiming; seadete salvestamine ei saada teadet MTA-le.

Üldkorrale üleminekuks määra viimane kassapõhine kuu. Varem kassapõhisena toimunud tehingu tasumata osa jääb maksepõhiseks ka pärast viimast kuud. Uue perioodi tavaline üldkorra arve ei muutu hilisema makse tõttu kassapõhiseks.

Kui alustad pärast varasema erikorra lõppu uuesti kassapõhist arvestust, salvesta uus esimene kuu. Varasem lõpetatud periood säilib ajaloos; vahepealne üldkorra aeg jääb üldkorraks. Ajaloo kaudu saab ka varasema perioodi arveid õigesti käsitleda. Seaded tõkestavad muudatuse, mis jätaks kinnitatud kassapõhise dokumendi ilma varasema perioodita. Suletud tarneperioodi käsitlust muutvad muudatused on tõkestatud.

Arve kuupäev ja kauba üleandmise/teenuse osutamise kuupäev võivad erineda. Kasuta müügiarve tarnekuupäeva ning ostu juures kauba või teenuse saamise kuupäeva. Kui kassapõhisel ajal väljastatud ettemaksuarve tarne toimub alles üldkorra ajal, kajastatakse ettemakse varem saadud osas maksepõhiselt ning ülejäänud osa tarnekuus. Hilisem laekumine sama osa enam ei suurenda. Enne erikorra algust toimunud üldkorra tarne säilib oma tarneperioodis.

## Müügiarved ja ettemaksed

1. Vali arvele õige kuupäev ja vajadusel tulevane tarnekuupäev.
2. Kassapõhise Eesti müügi jaoks on 24%, 13% ja 9% koodid. Sama arve võib sisaldada neid määrasid eri ridadel. Vähendatud määra sisuline alus peab olema õige.
3. Ettevõtte erikorra perioodi Eesti tavaline 24%, 13% või 9% kood teisendatakse salvestamisel vastavaks kassapõhiseks koodiks ning märkustesse lisatakse kassapõhise arvestuse tekst.
4. Kinnita arve ja registreeri tegelik laekumine. Ettemakse on selle sama arve osaline või täielik laekumine enne kauba üleandmist. Seostatud tegelik makse võib olla ka arve kuupäevast varasem, kui maksukood kehtib makse ajal.
5. Käive kajastub maksekuus. Tarne või ülejäänud raha laekumine kajastab ainult veel deklareerimata osa. Ära sisesta sama müügi kohta teist iseseisvat arvet: selle töövoo ettemakse ja lõpptasumine kuuluvad sama alusdokumendi juurde.

Näide: arve neto on 1 000 eurot ja KM 240 eurot. Märtsis laekub enne aprilli tarnet 620 eurot. Märtsi maksustatav väärtus on 500 eurot ja KM 120 eurot. Ülejäänud 620 euro laekumisel juunis lisandub 500 eurot ja KM 120 eurot. Aprilli tarne ise sama käivet uuesti ei lisa, kui tehing jääb kassapõhiseks.

Maksumäärade muutuse piiril ei kasutata uue määra koodi automaatselt varasema ettemakse maksustamiseks. Kui makse ajal arverea kood ei kehti, annab programm kontrollmärkuse; ettemaksu algdokument peab kasutama selle tehingu õiget määra.

## Ostud, põhivara ja autod

1. Kinnita ostuarve mahaarvatav kogusumma ning õige kasutusliik.
2. Põhivara puhul vali põhivara; auto puhul õige soetuse või jooksva kulu liik, kasutusviis ja reg-märk/VIN. Segakasutuse piirang jääb jõusse ka osamaksete korral.
3. Mitmerealise ostu puhul kinnita mahaarvamine arveridade kaupa. Igal real võib olla oma maksumäär, kasutusliik, auto ja mahaarvatav summa. Üldine arve jaotus ei asenda segaarve reajaotust.
4. Ava mahaarvamisperioodide leht. Sisesta arve saamise kuupäev, kauba/teenuse saamise kuupäev, kassapõhise käsitluse alus ja kinnitus.
5. Vali **Ettevõtte kassapõhine ost** või **Kassapõhise tarnija ost**. Tarnija valik sobib ka siis, kui ostja ise kasutab üldkorda.
6. Registreeri tegelikud tasumised. Makse jaotatakse proportsionaalselt arve allesjäänud ridade brutosummadega. Täistasumine sulgeb ümardusjäägid; mahaarvamine ei või ületada kinnitatud õigust.

Põhivara ja auto mahaarvamine liigub maksete järgi ka KMD vastavatesse alajaotustesse. XBRL säilitab kinnitatud vara/autoliigi: kassapõhine ajastus ei muuda põhivara tavaostuks. Auto kasutuse ja mahaarvamise õigus kontrollitakse enne kinnitamist. Vajadusel hoia kuu kasutuses olevate autode loendit ka eraldi kuuregistris.

## Ostuarve ettemakse ja arve saamine

Enne arve kättesaamist tehtud makse säilib tegelikul kuupäeval, kuid mahaarvamiseks on vaja arve alust. Tühja deklaratsiooni esitamise kuupäeva korral algab automaatne mahaarvamine kõige varem arve saamise kuust.

Kui ettemakse tehti eelmisel kuul ning arve jõudis enne selle kuu deklaratsiooni tegelikku esitamist ja esitamise tähtaega, sisesta kassapõhise jaotuse vormile selle deklaratsiooni tegelik esitamise kuupäev. Programm kontrollib ajalist piiri ja saab mahaarvamise siduda varasema maksekuuga. Juba varem esitatud või tähtajaks arvet mitte sisaldanud perioodi ei nihutata selle valikuga automaatselt tagasi.

Näide: ettemakse 31. märtsil, arve saadud 12. aprillil. Kui märtsi deklaratsioon esitatakse 20. aprillil ja alus on selleks olemas, saab makse kuuluda märtsi mahaarvamisse. Kui märtsi deklaratsioon esitati enne arve saabumist, kasuta arve saamise kuud. Andmed peavad vastama tegelikule esitamisele.

## Segaarvete ja pöördmaksu käsitlus

Toetatud on Eesti erinevate maksumäärade, tavaliste kulude, põhivara ja autokuludega osturidade jaotus. Maksustamata osturida võib olla samal arvel; see osaleb makse brutosumma jaotuses, kuid ei tekita mahaarvatavat käibemaksu.

Siseriikliku pöördmaksu ja tavarea segaarvel tuleb esmalt kinnitada mõlema rea mahaarvamine. Kassapõhiselt arvutatakse siseriikliku pöördmaksu alus, kohustus ja mahaarvamine makse osale vastavalt; hilisem makse või arve kuu ei korda sama summat.

Import, kauba EL-soetus ja välisriigi isikult saadud Eestis pöördmaksustatav teenus kuuluvad KMS § 44 erandite alla ning kasutavad oma üldkorra käsitlust. Neid ei muudeta ettevõtte kassapõhise seadistuse tõttu maksepõhiseks. Välisostu segaarvete täpne üldkorra jaotus on eraldi tööjärje punkt.

## Kreeditarved ja ettemakse tagastamine

Tarnitud kauba või osutatud teenuse kreedit vähendab kõigepealt deklareerimata jääki ning parandab juba arvestatud osa vajalikus ulatuses. Järgnev makse arvestab vähendatud arve jääki. See toimib ka osaliselt tasutud põhivara-, auto- ja segaarve puhul.

Täitmata tarne ettemakse tagastus on eraldi ajastus: sisesta müügiarvele tegelik tulevane tarnekuupäev. Enne tarnet tehtud kreeditarve ei vähenda ettemaksult arvestatud käibemaksu enne raha tegelikku tagastamist. Registreeri pangatagastus kreeditarve tagasimaksete lehel. Osaline tagasimakse korrigeerib vastavat osa; ülejäänu jääb alles kuni järgmise tagasimakseni. Pärast toimunud tarnet koostatud tavaline hinnaparandus kajastub kreeditarve kuu arvestuses.

Makse, kreeditarve ja tagasimakse tühistamisel arvutatakse jaotus kehtivate sündmuste järgi uuesti. Juba MTA-le esitatud perioodi parandamise korral tuleb esitada selle perioodi täielik parandatud andmestik eraldi.

## Väljundid ja kontroll

Käibemaksuaruanne, INF-i eelvaade ja XBRL kasutavad sama arvutust. XBRL-is kasutatakse kassapõhise müügi, tavaostu, põhivara, auto ja siseriikliku pöördmaksu vastavaid liike. Üldkorra tarnele ülemineku osa säilib üldkorra liigina. Fail säilitab arve tegeliku numbri, kuupäeva ja täieliku maksustatava väärtuse; maksekuu summa on eraldi deklareeritav osa.

Vana KMD XML ning INF CSV on samuti kontrollitud. INF-i piirmäär ja arvete/krediitide eraldi käsitlus kehtivad jätkuvalt. XBRL-i kohalik skeemikontroll ei asenda MTA teenuses vastuvõtmise kontrolli.

## Õiguslikud kontrollallikad

- [Rahandusministeeriumi käibemaksuseaduse kommentaarid, jaanuar 2026](https://www.fin.ee/sites/default/files/documents/2026-01/K%C3%A4ibemaksuseaduse%20kommentaarid%20jaanuar%202026.pdf), § 44: üleminekul säilib varasema tehingu arvestuskord ning seaduses nimetatud välistehingutele erikorda ei rakendata.
- [MTA kassapõhise käibe tekkimise aeg](https://www.emta.ee/node/67/chapter/83808/pdf): alates 01.07.2022 ei rakendata vanades näidetes leiduvat kolmanda kuu sundmaksustamist.
- [MTA sisendkäibemaksu mahaarvamise aeg](https://emta.ee/ariklient/maksud-ja-tasumine/kaibemaks/kaibemaksu-arvutamine-ja-tagastamine/sisendkaibemaksu-mahaarvamise-aeg).
- [MTA käibemaksu korrigeerimine](https://emta.ee/ariklient/maksud-ja-tasumine/kaibemaks/kaibemaksu-arvutamine-ja-tagastamine/kaibemaksu-korrigeerimine), ettemakse tagastamise alus KMS § 29 lg 9.
