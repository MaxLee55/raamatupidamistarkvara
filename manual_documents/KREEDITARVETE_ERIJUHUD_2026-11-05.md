# Kreeditarvete erijuhud — 05.11.2026

Muudatused on paigaldatud töötavasse rakendusse C:/laragon/www/raamatupidamine. Püsivaid arveid, makseid ega kontakte ei muudetud ega kustutatud. Uued kontrollid kasutavad ainult ühendusepõhiseid ajutisi tabeleid. e-MTA-le midagi ei saadetud.

## Kasutamine

1. Ava kinnitatud ostu- või müügiarve ja vali kreeditarve koostamine.
2. Sisesta number, kuupäev ja põhjus. Iga soovitud rea juurde sisesta positiivne krediteeritav **netosumma**; teistele ridadele jäta 0. Vaikimisi pakutakse kogu krediteerimata summat. Protsendinupp rakendab osakaalu allesjäänud summadele.
3. Maksukood, maksumäär, käibemaks ja pöördmaks tulevad algselt arvelt. Maksumäära ei valita uuesti. Viimase krediidiga taastuvad ümardussendid; rea kogusummat ei saa ületada.
4. Ostuarve sisendkäibemaks peab enne krediteerimist olema INF eelvaates kontrollitud. Null-käibemaksuga ostul ei ole eraldi mahaarvamise kinnitust vaja. Kreediti mahaarvamine arvutatakse algse arve kinnitatud osakaalust.
5. Laokauba krediteerimine tähendab selles vormis **füüsilist tagastust**. Kogus arvutatakse algse rea netosummaga samas proportsioonis, täpsusega 0,001. Müügitagastus taastab algse väljamineku partiid ja omahinna. Ostutagastus vähendab FIFO ladu; arvehinna ja FIFO väärtuse erinevus korrigeeritakse toote kaubakulu kontol. Vajalikud on aktiivsed toote varude ja kaubakulu kontod. Hilisemaid laoliikumisi ei kirjutata ümber ning negatiivne laojääk on keelatud.
6. Põhivara korral vali rea juurde õige kaart. Kaardi algdokument peab olema algse ostuarve number ning varakonto peab klappima. Osaline vähendus parandab kaarti ja edasise amortisatsiooni alust; nullini vähendamisel saab kaart oleku „Tarnijale tagastatud”. Varem arvestatud kulumist allapoole vähendamiseks tuleb kulum esmalt põhivara moodulis parandada. Varasemaid kulumikandeid kreedit ise ümber ei kirjuta.
7. Tegeliku raha tagastuse korral ava kreeditarvel **„Kreeditarve tagasimaksed”**. Sisesta makse kuupäev, summa ja konto. See loob arvelduskonto ning panga/kassa kande ja vähendab tagastatavat jääki. Pangale makset ei saadeta. Juba käsitsi pearaamatus kirjendatud sama tagasimakse tuleb enne parandada, et topeltkannet ei tekiks.
8. Kreeditarve tühistamine teeb vastukande ning taastab seotud lao-/põhivara muutused. Tagasimaksed tuleb enne tühistada. Lao hilisemad liikumised, põhivara hilisemad muudatused ja suletud periood takistavad sobimatut tühistamist.

## Toetatud maksud ja piirid

- Tavapärased ja ajaloolised 24/22/20/13/9/5% määrad.
- EL kauba-/teenuseost 24/13/9%, Eesti pöördmaksu ost/müük 24%, EL-väline teenuseost 24/9%.
- Olemasolevad Eesti 0% ja maksuvaba ost/müük; müügi KMD6 lahtrid 3/8, ainult sellised tehingud INF-i ei kuulu.
- Eesti pöördmaksu negatiivne INF A 02 ning B 12 / osalisel mahaarvamisel 11,12. Välisostu pöördmaks INF B-ossa ei lähe. INF arve ja kreeditarve piirmäärad arvutatakse eraldi.
- Käive, pöördmaks ja mahaarvamine liiguvad kreeditarve kuupäeva perioodi. Kontrolliti ka ainult negatiivsete summadega järgmist kuud.
- Ekspordi, EL müügi ja VD täielik liigitus jääb nimekirja punkti 4; segatud tavalise ja pöördmaksuga arved punkti 9. Vastava liigituseta tehingut ekspordis oletuse põhjal ei täideta.
- Laokauba hinnaalandus ilma tagastuseta ning algseisu põhivara parandamine vajavad eraldi maksumuse korrigeerimise töövoogu.
- Osalise sisendkäibemaksu mahaarvamise pearaamatu korrigeerimine on samuti rakendatud 05.11.2026. Kreedit pöörab mahaarvamata osa automaatselt tagasi. Juhis: OSALISE_MAHAARVAMISE_PARANDUS_2026-11-05.md.
- XBRL GL eksport jääb kreeditarvete korral tõkestatuks kuni punkti 8 laienduseni. Kontrollitud eksport on KMD6 ja INF CSV.
- Tagasimakseid saab siduda kreeditarvega; eri arvete tasaarveldus ja pangaimpordi automaatne sobitamine on veel eraldi töö.

## Kontrollid

Põhikontroll `vat_integration_test/credit_special_check.ps1`: kõik kümme pöördmaksukoodi, ühe sendi ümardus, eri summadega osakreedid, korduv kreedit/tühistamine, 0%/maksuvaba, FIFO ja erinev tagastushind, lao/pearaamatu vastavus, põhivarakaart ja edasine amortisatsioon, tagasimakse ja selle tühistamine, summapiirid ning kuvatavad vormid. XML ja mõlema INF CSV veerud valideeriti MTA KMD6 skeemiga. Varasemad käibemaksu kontrollid ning ajutistes tabelites lähtestamise kontroll läbisid samuti.

Paigaldatud failide sisu kontrolliti töökaustaga võrdseks. Olemasolevate arvete, ridade, maksete, kannete, laoliikumiste, põhivarade ja kontaktide arv jäi paigaldamisel samaks. Varasemad failid: `credit_special_before`.

Arvestuse alusena kontrollitud MTA juhised:

- [Käibemaksu korrigeerimine](https://emta.ee/ariklient/maksud-ja-tasumine/kaibemaks/kaibemaksu-arvutamine-ja-tagastamine/kaibemaksu-korrigeerimine)
- [Siseriikliku pöördmaksustamise deklareerimine](https://www.emta.ee/ariklient/maksud-ja-tasumine/kaibemaks/erisatted-ja-kinnisasja-maksustamine/siseriikliku-poordmaksustamise-deklareerimine-kaibedeklaratsioonil-kmd-ja-selle-lisas-kmd-inf)
