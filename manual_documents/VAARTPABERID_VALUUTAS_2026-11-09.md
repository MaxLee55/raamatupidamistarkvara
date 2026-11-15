# Väärtpaberite ost ja müük valuutas

Ava **Pank → Väärtpaberitehingud**. Sisestada saab arveldatud aktsia, ETF-i, fondiosaku, võlakirja ja muu väärtpaberi oste ning müüke.

1. Sisesta maaklerikonto, maakleri tehinguviide ja väärtpaberi ISIN. ISIN-i puudumisel kasuta sama püsivat tunnust igal sama väärtpaberi tehingul. Vali liik, lisa kogus ja võta tehingu kogusumma maakleri kinnituselt enne teenustasu. Teenustasu sisesta eraldi samas valuutas.
2. Sisesta tehingu ja arvelduse kuupäevad. **Too EKP kurss** näitab kurssi ja selle kuupäeva. Kui päeval kurssi ei avaldatud, kasutatakse kuni seitse päeva varasemat viimast avaldatud kurssi. EUR kurss on 1. Käsikursi puhul sisesta, mitu välisvaluuta ühikut vastab ühele eurole, ja lisa alus.
3. Vali väärtpaberikonto ning sellele valuutale mõeldud maakleri 10xx rahakonto. Ostu jaoks peab rahakontol olema piisav eurodes kirjendatud jääk. Kui kulutatud valuuta bilansiline euromaksumus erineb arvelduskursiga arvutatud summast, sisesta see ja alus valikus „Teenustasu ja kursivahe kontod”.
4. Eri kuupäevade korral vali arvelduste vahekonto. Ostul kasuta kohustise kontot, müügil nõude kontot. Programm kirjendab tehingu tehingupäeval ja sulgeb vahekonto arvelduspäeval. Kursivahe kajastub eraldi kanderidadel.
5. Määra ostu teenustasu käsitlus oma arvestuspõhimõtte järgi. Vaikimisi läheb see kulusse. Kui valid lisamise soetusmaksumusele, liigub tasu koos ostupartiiga FIFO arvestusse. Müügi tulemus sisaldab müügi teenustasu.

Müük kasutab sama maaklerikonto, väärtpaberitunnuse ja väärtpaberikonto vanimaid avatud ostupartiisid. Sisesta sama positsiooni tehingud kuupäeva järjekorras. Viimase tehingu saab tühistada, kui selle kandeperioodid on avatud. Tühistamisel tekivad vastukanded ja müügiga kasutatud ostupartiid taastuvad. Sama maakleri tehinguviidet saab kasutada üks kord ühe maaklerikonto kohta.

Näide. 10 aktsiat maksavad 1 000 USD kursiga 1 EUR = 1,25 USD. Ostu soetusmaksumus on 800 €. Nelja aktsia müük 480 USD eest kursiga 1 EUR = 1,20 USD annab 400 € müügitulu. FIFO maksumus on 320 € ja müügikasum enne tasu 80 €. Alles jääb kuus aktsiat soetusmaksumusega 480 €.

Väärtpaberileht peab oma FIFO partiisid käsitsi sisestatud tehingutest. Varasem pangaimpordi portfell kuvatakse Pangaväljavõtte lehel. Kasuta ühe maakleritehingu kirjendamiseks üht töövoogu. Maaklerifailide import, olemasolevate partiide ülevõtmine, valuutakonto automaatne jäägihaldus, perioodilõpu ümberhindlus, eri valuutas teenustasud ning võlakirja intressi ja optsioonide eriarvestus kuuluvad järgmistesse etappidesse.

[EKP viitekursid](https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html) · [Raamatupidamise Toimkonna juhendid](https://fin.ee/finantspoliitika-valissuhted/arvestusvaldkond/raamatupidamise-toimkond)
