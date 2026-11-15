# Käibemaksu tööjärg 05.11.2026

Seis 04.11.2026. See on senise töö käigus teada olevate lünkade nimekiri, mitte kogu rakenduse sõltumatu lõppaudit. Tegemist on arendusprogrammiga ja proovandmetega. Päris e-MTAsse deklaratsioone ei esitata.

## Rakenduse asukoht

- Töötav rakendus: C:/laragon/www/raamatupidamine
- Töökaust ja kontrollid: C:/Users/Max Lee/Documents/RM
- Muudatused tuleb pärast kontrollimist viia ka töötava rakenduse kausta; ainult töökausta muutmisest ei piisa.
- Tänaste failide varukoopiad: eu_reverse_vat_before, reverse_vat_extended_before, asset_car_vat_before, credit_notes_before.

## Täna lisatud ja kontrollitud

- Ostude mahaarvatava käibemaksu püsiv kinnitus ja summa; sama summa aruandes, KMD lahtris 5 ja INF B-osas.
- Reapõhine ja ühine kinnitamine; arve/partneri muutus muudab kinnituse kehtetuks.
- EL kauba- ja teenuseostude, siseriiklike ostu-/müügitehingute ning EL-väliste teenuste ostu pöördmaksustamine.
- Pöördmaksu kanded, KMD6 lahtrid ja asjakohased INF erisused; siseriikliku pöördmaksu märkus prinditaval arvel.
- Põhivara ja sõiduautode jaotus KMD 5.2/5.3/5.4; autode kuunimekiri, korduste eemaldamine ja segakasutuse 50% kontroll.
- Eesti tavalise käibemaksuga laotooteta arvete täielik või ühtlase protsendiga osaline kreedit, algse arve/rea viited, vastukanded, tasumata jäägid ja tühistamine.
- Arvete ja kreeditarvete eraldi INF piirmäärad, negatiivsed KMD6/INF summad.
- Kohalikud arvutus-, SQL-, perioodi-/ettevõttepiiri- ja MTA skeemi kontrollid. Salvestamist prooviti ühendusepõhistes ajutistes tabelites; püsivaid prooviarveid test ei loonud.

## Allesjäänud tööd soovitatavas järjekorras

1. **Kreeditarvete erijuhud — rakendatud ja paigaldatud 05.11.2026.**
   - Kõigi kümne olemasoleva pöördmaksukoodi ostu-/müügikreedit, sh EL kaubad/teenused, Eesti ost/müük ning EL-väline teenus. Algse rea ümardus ja mahaarvamise osakaal säilivad.
   - Negatiivsed KMD6 lahtrid ka algsest arvest erinevas kuus; Eesti pöördmaksu INF 02/12 ja vajadusel 11,12.
   - Olemasolevate 0% / maksuvabade koodide kreedit; Eesti 0% müük KMD 3 ja maksuvaba müük KMD 8. Ekspordi/EL müügi eraldi liigitamine ja VD jäävad punkti 4.
   - Füüsilised laokauba ostu-/müügitagastused koos koguste, FIFO maksumuse, algse müügi partiide ja pearaamatukannetega. Hinnaalandus ilma kaubatagastuseta vajab eraldi lao maksumuse korrigeerimist ega ole selle vormi tagastus.
   - Üksikute ridade ja eri netosummade krediteerimine, lisaks allesjäänud summale protsendi rakendamine. Kumulatiivne rea jääk ja algse rea maksud piiravad krediiti.
   - Valitud põhivarakaardi soetusmaksumuse parandus, muudatuse ajalugu, edasise amortisatsiooni alus ja tühistamisel taastamine. Kaart valitakse sõnaselgelt; algdokument peab viitama ostuarvele. Arvestatud kulumit ületava vähenduse / täieliku tagastuse eel tuleb kulum põhivara moodulis parandada. Algseisu varasid automaatselt ei muudeta.
   - Tegeliku panga-/kassatagastuse seos algse arve ja kreeditarvega, automaatne pearaamatukanne, summapiir ja tühistamine. Maksekorraldust pangale ei saadeta. Üldine arvetevaheline tasaarveldus jääb punkti 10.
   - Lähtestamine kustutab ka kõik uued seosed ja tagasimaksed; kontaktid ning seaded säilivad. Testiti ajutistes tabelites, tegelikke andmeid ei lähtestatud.

2. **Osalise mahaarvamise automaatne pearaamatu parandus — rakendatud ja paigaldatud 05.11.2026.**
   - KMD INF kontrolli salvestamine kannab mahaarvamata osa algsetele kulu-/varakontodele ning vähendab sisendkäibemaksu kontot. Pöördmaksu kohustus säilib täies summas.
   - Mitme rea / kulukonto jaotus sentide täpsusega, kordussalvestamise idempotentsus, summa muutmisel üksnes vahe kanne.
   - Kreedit pöörab ka mahaarvamata osa tagasi; kulukonto arvel saab algset mahaarvamist muuta ka aktiivsete kreeditidega ning seotud kreedid arvutatakse uuesti. Tühistamine taastab kehtiva seisu.
   - Laokauba maksumus suureneb ka FIFO vastuvõtus. Müügi ja lao väljamineku FIFO tarbib tegelikud allesjäänud sendid, vältides käibemaksu ümardusjääki.
   - Üheselt ostuarvega seotud, amortiseerimata põhivarakaardi maksumus parandatakse automaatselt. Kaarti ei looda ise; hiljem loodud kaart peab sisaldama kapitaliseeritud käibemaksu.
   - Suletud algse või mõjutatud kreediti periood peatab rahalise muudatuse ning kõik osalised muudatused võetakse tagasi. Paranduste ajalugu ja kandeviited on KMD INF eelvaatest avatavad.
   - Juba toimunud laoväljaminekute, arvestatud amortisatsiooni või aktiivsete varakreeditide korral tuleb need enne algse soetusmaksumuse muutmist parandada / tühistada. Ajaloolist FIFO-d ja amortisatsiooni ei kirjutata tagantjärele ümber; laiemad kasutuse ja aastased parandused jäävad punkti 7.
   - Sama arvenumbriga varasema KM käsiparanduse tuvastamisel peatatakse automaatne kanne topeltarvestuse vältimiseks.
   - Lähtestamine eemaldab uue paranduste seisu ja ajaloo, jättes kontaktid/seaded alles. Olemasolevatele arvetele paigalduse käigus kandeid ei tehtud; varem kinnitatud osalise mahaarvamisega arvel vajuta üks kord „Salvesta muudatus”.

3. **Kaupade import — põhiosa rakendatud 05.11.2026, erijuhud jäävad avatuks.**
   - Tollidokumentide register, MRN/tolliotsus, vabastamise kuu ja ostuarve O_IMPORT0 seos; eraldi KM kanne, tolli-/muude tasude arve kontrollviide.
   - Ühine arvestus KMD 5/5.1, täielikult mahaarvataval põhivaral 5.2; loaga KMD-l tasutava impordi 4.1 võrdlussumma. MTA täidab 4.1 tolliandmetest ise.
   - Korduva MRN-i tõke, olemasoleva KM kande sidumine, pangamakse/osasumma kontroll ja sidumine uut mahaarvamist tegemata; tühistamine, vastukanne, ajalugu ja lähtestuse tugi.
   - Veel teha: sõiduautode import; lao-/põhivara maksumusse jaotatav mahaarvamata impordi KM; hilisem tolliparandus eraldi perioodis; mitu kaubaarvet ühel tollidokumendil; koondmakse jaotus ning ettemaksed. Vastavad piirangud on vormil nähtavad.
   - Kasutusjuhis ja kontrollid: KAUPADE_IMPORT_2026-11-05.md. Päris andmeid ei kustutatud ega deklaratsioone saadetud.

4. **0% ja maksuvaba käibe liigitus ning VD — tavatehingute ulatuses rakendatud 05.11.2026.**
   - Eraldi maksukoodid: eksport, EL kaup, EL B2B põhireegli teenus, EL-väline B2B teenus, 0% kaubavedu, muu täpsustatud 0%, maksuvaba üür/rent, kinnisasja müük ning muu täpsustatud maksuvabastus.
   - KMD 3/3.1/3.1.1/3.2/8, arve automaatsed märkused prindil ja e-arve andmetes, tõendi/aluse ning maksustamiskuupäeva kontroll; kliendi ja müüja KMKR ajalooline seis.
   - VD eelvaade, kontroll-CSV ja ametlikule XSD-le vastav XML; KMKR-põhine liitmine ja täiseurode ümardus, jooksva kuu kreeditarved. KMD ja VD kasutavad sama arvestust.
   - Vanad üldised koodid tuleb arve juures täpsustada; paigaldus neid ise ümber ei liigita. Perioodi-/ettevõtte-/allikamuutuse kaitsed ning lähtestuse tugi.
   - Eraldi tulevikutööd: VDP, kolmnurktehing, nõudmiseni varu, tax-free, uue transpordivahendi erijuht ja OSS/IOSS; VIES-i automaatkontroll. XBRL GL laiendus jääb punkti 8.
   - Kasutusjuhis: NULLMAAR_MAKSUVABA_VD_2026-11-05.md; juhend on ka programmi Kasutusjuhendites.

5. **Mahaarvamine arve kuupäevast erineval kuul — tavapärase Eesti ostuarve töövoog lisatud 07.11.2026.**
   - Ava kinnitatud ostuarvel „Sisendkäibemaksu jaotus kuude vahel“. Sisesta arve saamise kuupäev, mahaarvamise alus ja kuu või kuude summad. Jaotus peab kokku võrduma kogu mahaarvatava summaga.
   - KMD6 lahter 5, INF B-osa ja käibemaksuaruanne kasutavad sama jaotust. INF-is säilib arve algne kuupäev. Kuude jaotus ise ei tähenda osalise mahaarvamise koodi 11.
   - Varasema kuu mahaarvamisel märgi maksustatava sündmuse ja deklaratsiooni esitamise kuupäev. Pöördmaksu kohustus kajastub sündmuse kuus. Põhivara ja sõiduauto kuu summad jõuavad vastavatesse KMD alamlahtritesse.
   - Tavapärase Eesti ostuarve kuude jaotust saab eksportida ka XBRL GL vormingus. Pöördmaksu, põhivara, sõiduauto ja kreedi XBRL-kirjed vajavad veel eraldi liigitust.
   - Jaotatud ostuarve kreeditarve saab teha alates viimasest jaotuskuust. Varasema kreedi puhul vajab tulevaste summade ümberjaotamine eraldi käsitlust. Jaotuse muutmiseks peavad mõjutatud perioodid olema avatud.

6. **Kassapõhine käibemaksuarvestus.**
   - Tasumised, osalised tasumised ja kassapõhise tarnija käsitlus.
   - Õige periood ja summad KMD-s/INF-is. Seda ei saa praegu lugeda toetatuks.

7. **Muud käibemaksu korrigeerimised.**
   - Põhivara ja kinnisasja kasutuse/proportsiooni muutus, aastased ümberarvestused.
   - Sõiduauto kasutuse muutus ja varasema mahaarvamise parandamine.
   - Käsitsi paranduskanded ning vajadusel KMD 10/11; selge alus ja ajalugu.
   - Lootusetud nõuded, omatarve ja muud erijuhud vajavad eraldi nõuete hindamist, kui need lisatakse rakenduse ulatusse.

8. **Uue XBRL GL ekspordi laiendamine.**
   - Kõik uued pöördmaksu liigid, põhivara/sõiduautod, kreeditarve algse arve viide ja osaline mahaarvamine.
   - 0%, maksuvabad tehingud, import ja segakäsitlusega arved.
   - Enne toetamist peavad need juhtumid andma selge tõkke, mitte puuduliku faili.

9. **Segaarvete täpsem jaotus.**
   - Ühel arvel tavaline ja pöördmaksu tehing, eri mahaarvamise õigused, põhivara ja autokulu koos.
   - Praegune mahaarvamise/põhivara/autokulu jaotus on arvepõhine; eri jaotused vajavad täpsemat arvestust.

10. **Tagasimaksete ja tasaarvelduste töövoog.**
    - Tegeliku kreeditarve tagasimakse seos, kanded, summapiirid ja tühistamine on punktiga 1 lisatud.
    - Alles: eri arvete / kreeditarvete vaheline tasaarveldus ja pangaväljavõtte impordi automaatne sobitamine; ära kirjenda juba käsitsi kantud tagasimakset teist korda.

11. **Kasutusmugavus ja andmete ühtsus.**
    - Autode kuunimekirja kopeerimine eelmisest kuust koos ülevaatamisega.
    - Jaotuse lukustuse selgem kuvamine, kui algsel ostuarvel on aktiivne kreedit.
    - Partneri muutmise mõju varasematele arvetele: hoiatus või ajaloolise partneriandmestiku säilitamine.
    - Ühtlased mõisted, tühjade/keelatud valikute selgitused ja paranduste nähtav ajalugu.

12. **Puhas kordustest ja lõppaudit.**
    - Kasutaja loob hiljem uue proovandmestiku. Ära kustuta olemasolevaid andmeid ilma eraldi juhiseta.
    - Eesti ja välispartnerid eraldi kontaktid; varem muudeti HEAL-i kontakt FI-ks ning see mõjutas tema varasemaid Eesti arveid.
    - Võrdle arvet, pearaamatut, aruannet, KMD6 ja INF-i ning hiljem laiendatud XBRL GL-i.
    - Kontrolli nii positiivseid kui ka negatiivseid, 0/50/100% mahaarvamist, ajaloolisi määrasid, korduvaid parandusi, lukustatud perioode ja ettevõtete eraldatust.
    - Testimine kohalike proovandmete ja ametlike skeemidega. e-MTA vastuvõttu pole kontrollitud; päris deklaratsioone ei saadeta.

## Kasulikud failid ja kontrollid

- credit_note.php, credit_note_lib.php, void_credit_note.php
- eu_reverse_vat.php, tax_code_lib.php
- kmd_inf_data.php, kmd_inf_confirmation.php, kmd_asset_car_vat.php, kmd_inf_preview.php
- kmd_export_legacy.php, kmd_export.php, vat_report.php
- vat_integration_test/check.ps1, eu_check.ps1, extended_check.ps1, asset_car_check.ps1, credit_check.ps1
- vat_integration_test/credit_database_test.php: tegelik SQL ajutistes tabelites, püsivaid arveid ei muudeta
- mta_kmd_validation/vatdeclaration.xsd: MTA KMD6 skeem

Järgmine töö: punkt 5 — sisendkäibemaksu mahaarvamine arve kuust erinevas kuus; lisaks punktide 3/4 loetletud erijuhud. Punkti 1 kasutusjuhis: KREEDITARVETE_ERIJUHUD_2026-11-05.md. Punkti 2 kasutusjuhis: OSALISE_MAHAARVAMISE_PARANDUS_2026-11-05.md. Põhikontrollid: vat_integration_test/credit_special_check.ps1 ja vat_integration_test/purchase_vat_adjustment_test.php.
