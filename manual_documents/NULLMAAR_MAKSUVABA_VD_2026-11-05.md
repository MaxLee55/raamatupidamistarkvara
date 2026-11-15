# 0%, maksuvaba müük ja VD aruanne — 05.11.2026

Rakendus: C:/laragon/www/raamatupidamine. Avalehe aruannete menüüs on **VD — EL müügi aruanne**. Käesolev juhend on ka programmi kasutusjuhendite lehel.

## Uue arve koostamine

1. Ava **Uus müügiarve** ja vali arverea maksukood tegeliku tehingu järgi. Ainult 0% määr või kliendi riik ei määra tehingu maksukäsitlust.
2. Ava vormi jaotis **0% / maksuvaba käibe alus**. Täida käibe maksustamiskuupäev, vajadusel tõendi viide ja täpne seaduslik alus. Tühi maksustamiskuupäev kasutab arve kuupäeva; sisesta tegelik maksustamiskuupäev, kui see on erinev. See määrab sama perioodi nii KMD kui VD jaoks.
3. EL kaubamüügil täida arvel tegelik kauba lähetamise/üleandmise kuupäev. Ettemaks ei ole iseenesest ühendusesisene kaubakäive. Kontrolli KMS § 11 alusel õige maksustamiskuupäev; seda ei tuletata lihtsalt maksepäevast.
4. Ekspordi, EL kaubamüügi ja 0% kaubaveo puhul lisa kauba väljaviimist/vedu tõendava dokumendi viide. Ekspordi tõend võib olla näiteks tollideklaratsiooni MRN. Originaaltõendid tuleb alles hoida; see väli salvestab viite.
5. Muu 0%, muu maksuvaba käibe ja 0% kaubaveo puhul täpsusta seaduslik alus. Maksuvabastus võib sõltuda tehingu eranditest või valiku alusel maksustamisest — vali maksuvaba kood ainult tegeliku maksuvabastuse korral.
6. Kinnita tingimuste kontroll. EL müügil peab kliendil olema riigiga sobiv KMKR number ning see peab olema ka tegelikult kehtiv. Programm kontrollib vormingut, mitte VIES-i kehtivust. EL põhireegli teenusemüügi VD-kood sobib ainult teenusele, mis kuulub saaja riigis maksustamisele ning pole seal 0% ega maksuvaba.
7. Salvesta mustand ja kinnita arve tavapäraselt. Kinnitamata või vigase käsitlusega arvet ei kinnitata. Maksukäsitluse märkus lisandub arve prindivaatele ja e-arve andmetesse automaatselt; e-arve märkus on esimese rea kirjelduses. Midagi automaatselt kliendile ega MTA-le ei saadeta.

## Maksukoodid ja KMD

- **M_EXPORT0** — kauba eksport: KMD 3 ja 3.2; VD-s ei kajastu. Teenuse osutamine välismaale ei ole kauba eksport.
- **M_EU_GOODS0** — EL kauba ühendusesisene müük: KMD 3, 3.1 ja 3.1.1 ning VD kaubad. Vajab teise liikmesriigi KMKR numbrit, kauba üleandmise/lähetamise kuupäeva ja tõendit. Põhja-Iirimaa XI numbrit toetatakse ainult kaubamüügil.
- **M_EU_SERVICES0** — teise EL liikmesriigi B2B põhireegli teenus: KMD 3 ja 3.1 ning VD teenused. Arvel märkus **Pöördmaksustamine / Reverse charge**. Asukohaga seotud teenus, saaja riigis maksuvaba või 0% teenus ei sobi automaatselt selle koodi alla.
- **M_NON_EU_SERVICES0** — EL-välisele ettevõtlusega tegelevale isikule osutatud põhireegli teenus: KMD 3, VD-s ei kajastu.
- **M_TRANSPORT0** — ekspordi/impordiga seotud 0% kaubavedu: KMD 3, VD-s ei kajastu. Vajab veotõendit ja täpsustatud alust; vajadusel lisandub pöördmaksustamise märkus.
- **M_EE_OTHER0** — muu seaduslikult 0% tehing: KMD 3; täpne alus on kohustuslik. Ära kasuta seda koodi toetamata erikorra peitmiseks.
- **M_EXEMPT_RENT** — maksuvaba kinnisasja üür/rent, KMS § 16 lg 2 p 2: KMD 8.
- **M_EXEMPT_PROPERTY** — maksuvaba kinnisasja müük, KMS § 16 lg 2 p 3: KMD 8.
- **M_EXEMPT_OTHER** — muu maksuvaba käive: KMD 8; täpne maksuvabastuse alus on kohustuslik.

KMD 3.1/3.1.1/3.2 on lahtri 3 alajaotused, neid ei liideta uuesti kogusummale. Maksuvaba käive ei lähe lahtrisse 3. KMD arvestus ja KMD6 XML kasutavad sama arvutust kui VD. 0% ja maksuvaba müük ei tekita eraldi müüja käibemaksukohustuse kannet; tavapärane nõuete/tulu ning vajadusel lao kanne säilivad.

## Varasem üldine 0% või maksuvaba arve

Vanu **M_EE0** ja **M_EXEMPT** arveid ei liigitata paigalduse käigus oletuse alusel ümber.

1. Ava arve ja vajuta **0% / maksuvaba käibe käsitlus**.
2. Ühtse 0%/maksuvaba arve puhul vali arvepõhine täpsustus. Täida alus, tõend, maksustamiskuupäev ning kinnita tingimused.
3. Salvestamine säilitab summad ja pearaamatukande; muutub aruande liigitus ning lisandub kontrolli ajalugu. Kui puudulik arve on juba esitatud aruandes, tuleb hinnata KMD/VDP parandamist eraldi.

Üldise koodi arve, mille käsitlus pole kinnitatud, peatab puuduliku KMD/VD faili. Suletud arve- või maksustamisperiood peatab käsitluse muutmise. Aktiivse kreeditseosega algarve ega kreedi käsitlust eraldi ümber ei valita: esmalt tuleb lahendada kreeditarve.

## VD aruanne

1. Ava avalehelt **VD — EL müügi aruanne** ning vali aasta ja kuu.
2. Kontrolli partnereid ja arveid. Sama KMKR numbriga kliendi kaubad ja teenused liidetakse eraldi, sõltumata sellest, mitmel kontaktikaardil sama number on. Summeeritakse sentides ja alles lõppsumma ümardatakse täiseurodeks. KMD säilib senditäpsusega.
3. **Laadi VD XML** annab ametlikule MTA VD XSD-le vastava faili käsitsi e-MTAsse importimiseks. **Kontrolltabel CSV** on enda kontrolliks, mitte MTA VD impordifail. Lehte saab ka printida / PDF-ina salvestada.
4. Kui tehinguid pole, tühja VD XML-i ei koostata. Vea korral puudulikku faili ei väljastata.

Eksporti, importi, Eesti müüki, maksuvaba müüki ega EL-väliseid teenuseid VD-s ei näidata. Kreeditarve kajastub negatiivsena kreedi väljastamise kuu KMD-s ja VD-s; algne kuu ei muutu. Kreedi tühistamisel jäävad kanded ajalukku ning aktiivne arvestus taastub.

Kinnituse juures säilivad kliendi nimi, aadress, registrikood, riik ja KMKR ning müüja KMKR. Kliendikaardi hilisem muutmine ei kirjuta nende arvete KMD/VD andmeid ega prinditavaid partneriandmeid üle. Mustandi kinnitamisel kontrollitakse partnerit uuesti; kinnitatud arve täpsustamisel säilib tema senine ajalooline partner.

## Ulatus ja piirangud

- Kolmnurktehing, nõudmiseni varu, tax-free müük reisijale, uue transpordivahendi erijuht, OSS/IOSS ja välisriigi registreeringuga kohalik käive vajavad eraldi töövoogu. Neid ei tohi sisestada tavalise EL müügi koodiga.
- VDP ehk varasema VD andmete parandamise faili see leht ei koosta. Tavaline hilisem kreeditarve kuulub jooksva kuu VD-sse, mitte automaatselt VDP-sse.
- Maksuvabastuse õigus ja tõendite piisavus on kasutaja kontrollitav alus. VIES-i automaatset päringut, tollidokumendi lugemist ja deklaratsiooni edastamist ei lisatud.
- XBRL GL erijuhtude laiendamine on endiselt eraldi tööjärje punkt. Seni kasutatakse KMD6 XML-i ja vajadusel VD XML-i; toetamata uue vormingu eksport peatatakse.
- Lähtestus kustutab uue käsitluse ja kontrolli ajaloo koos tööandmetega. Kontaktid ja seaded säilivad.

## Kontrollid ja ametlikud alused

Kontrolliti ühendusepõhistes ajutistes tabelites liigitusi, samu KMD/VD summasid, täiseurode ümardamist, XI kaup/teenus eristust, partneri ajaloolisi andmeid, negatiivseid kreediteid ja nende tühistamist, aruandeperioodi, vana üldkoodi täpsustust, ettevõtte/piiratud perioodi kaitseid ning tegelikku arve POST-salvestamist/kinnitamist ja prindivaadet. KMD6 ja VD XML kontrolliti ametlike XSD-dega; e-arve andmetes kontrolliti märkust ja kliendi KMKR-i ilma arvet välja saatmata. Varasemad impordi-, krediidi- ja osalise mahaarvamise kontrollid läbivad samuti testid.

- [MTA: KMD täitmine](https://www.emta.ee/en/admin/content/handbook_article/56)
- [MTA: VD üldpõhimõtted, ümardus ja kreeditarved](https://www.emta.ee/node/337/chapter/10405/pdf)
- [MTA: arvele märgitavad viited](https://www.emta.ee/ariklient/maksud-ja-tasumine/kaibemaks/kaibemaksuarvestus-ja-arved/arvele-margitavad-viited)
- [MTA: KMKR numbrite vormingud](https://www.emta.ee/ariklient/maksud-ja-tasumine/kaibemaks/paringud-load-ja-teavitused/kmkr-numbrite-struktuurid-ja-formaadid)
- [MTA: teenuste tehniline info ja VD XSD](https://www.emta.ee/ariklient/e-teenused-koolitused/e-teenuste-kasutamine/teenuste-tehniline-info)

Olemasolevatele arvetele paigalduse käigus liigitusi ega kandeid ei lisata. Päris deklaratsioone ei saadeta.
