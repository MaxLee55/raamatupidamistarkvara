# Käibemaksu valmidus ja lõpetamise piir

Uuendatud 09.11.2026.

## Mida senine kontroll tähendab

Olemas on arvestatav põhivoog: Eesti tavaarved, seotud kreeditarved, sisendkäibemaksu kinnitamine ja osaline mahaarvamine, mitmed pöördmaksu- ja impordijuhud, 0% ja maksuvaba müügi liigitus, VD ning XBRL GL eksport toetatud tehingutele. Kassapõhiste tavaarvete ja kreeditide viimane kontrollijooks sisaldas 73 läbivat stsenaariumi.

Varasem sõna „lõppkontroll” tähistas selleks hetkeks teostatud võimaluste kohalikku kontrolli. See ei tähendanud kogu käibemaksuvaldkonna ega MTA vastuvõtu lõplikku kinnitamist. Edaspidi nimetatakse sellist kontrolli etapikontrolliks; kogu toetatud versiooni vastuvõtt on eraldi viimane etapp.

## Valmis arendusplokid

1. **XBRL-i sisendkäibemaksu paranduskanded — valmis 07.11.2026.** Register, olemasolev arvutusabi, põhivarakaardi eeltäitmine, pearaamatukande kontroll ja maksumäärade jaotus moodustavad O_601 töövoo. Kaasatud on käibemaksuaruanne, XBRL-i eelvaade ja eksport, mõlemad parandussuunad ning ajalugu. Täpne kasutusjuhend on programmis „Sisendkäibemaksu parandused ja XBRL GL”.
2. **Kassapõhise arvestuse erijuhud — valmis 08.11.2026.** Ettemaksed, arvestuskorra vahetamine ja ajalugu, 24/13/9% müük, põhivara-, auto-, sega- ja Eesti pöördmaksuostud. Kreeditid ja tegelikud ettemakse tagastused kasutavad sama arvestust aruandes, KMD/INF-is ja XBRL-is. Läbisid 149 kontrollstsenaariumi. Juhend: „Kassapõhine arvestus, ettemaksed ja segaarved”.
3. **Välisostu pöördmaksuga segaarved — valmis 09.11.2026.** Ühel arvel saab kasutada eri pöördmaksuliike ja määrasid ning määrata mahaarvamise ridade kaupa. Toetatud on ka maksuvaba EL-i kauba soetus ja Eesti käibemaksuga rida, kui välistarnijal on Eesti KMKR number. Reajaotus läheb pearaamatusse, seotud kreeditarvetele, KMD/INF-i ja XBRL-i.

Nende järel on eraldi **kogu toetatud versiooni integratsioonikontroll ja MTA vastuvõtutest**. Uus valuutamoodul vajab lisaks oma KM/XBRL-kontrolli; selle testimine ei ole senise euroarvestuse korduv lõpetamine, vaid uue võimaluse kontroll.

Kogu toetatud versiooni vastuvõtt põhineb tehingute läbimängul ja MTA koolituskeskkonna tagasisidel.

## Kuidas lõpetada esimene kasutatav versioon

- Määrata toetatud tehingute loend ja kirja pandud erandid. Toetamata tehing peab olema selgelt piiratud või vajama käsitsi läbivaadatud töövoogu.
- Viia lõpuni toetatud tehingute XBRL-kajastus, sh vajalikud paranduskanded.
- Teha ühe fikseeritud prooviandmestiku võrdlus: algdokumendid, pearaamat, KM-aruanne ja XBRL; vana perioodi jaoks KMD/INF. Kontrollida ka tühistamisi ja failide korduskoostamist.
- Läbida MTA vastuvõtukontroll ning lahendada selle tegelikud veateated. Kohaliku XML-skeemi läbimine üksi ei kinnita MTA ärireegleid.
- Märkida versiooni piirid juhendis. Uued harvad erijuhud lähevad järgmise versiooni tööjärge, et lõpp-punkt ei nihkuks iga lisasooviga.

Sisendkäibemaksu XBRL-paranduste etapp on lõpetatud. Soovitus on hoida esimese versiooni piir selge. Kõigi harvaesinevate käibemaksuerandite lisamine ei pea eelnema valuutaarvete arendamisele. Vana KMD uusi erijuhte lisatakse vajaduse järgi; põhitähelepanu jääb XBRL-ile ja tegelikult kasutatavatele töövoogudele.

MTA eestikeelse juhendi järgi avaneb andmepõhise KMD koolituskeskkond 11.01.2027; ligipääs vajab ettevõtte esindaja digiallkirjastatud taotlust. [MTA testimise juhend](https://www.emta.ee/ariklient/andmepohise-kmd-testimine).
