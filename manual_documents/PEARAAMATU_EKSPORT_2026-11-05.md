# Pearaamatu allalaadimine — 05.11.2026

Paigaldatud kausta **C:/laragon/www/raamatupidamine**. Pearaamatu lehel on nupp **Lae alla**, mille alammenüüs on **CSV**, **PDF** ja **XLSX**.

## Kasutamine

- Terve pearaamat: vali **Kõik kontod** ja **Kogu ajalugu**, seejärel soovitud failivorming.
- Valitud periood: vali algus- ja lõpukuupäev; vajadusel vali üks konto. Allalaadimisnupud kasutavad vormis olevaid valikuid ka siis, kui enne ei vajutata „Näita”.
- Kõigi kontode vaade näitab kokkuvõtet. Konto lingilt saab avada selle detailread. Kõigi kontode allalaadimine sisaldab siiski kõiki detailridu.
- Arvesse lähevad kinnitatud kanded. Mustandid ja tühistatud kanded ei kuulu pearaamatusse. Mitteaktiivsete kontode andmed ning perioodi algsaldoga kontod on kaasatud.

## Failid

**CSV** on UTF-8 BOM-iga, semikooloniga eraldatud ja punktiga kümnenderaldajaga. Sisaldab konto andmeid, saldo suunda, rea liiki, kuupäeva, kande ja rea ID-d, dokumendinumbrit, teadaolevat parandatava dokumendi viidet, mõlemat selgitust ning deebetit, kreeditit ja saldot. Igal real on ka ettevõte, registrikood, periood ja koostamisaeg. Tekstiväljad on kaitstud tabelarvutuses valemina tõlgendamise eest.

**XLSX** on päris Exceli töövihik: „Pearaamat”, „Kontode kokkuvõte” ja „Andmed”. Kuupäevad ja tavalised summad on arvulised, kontokoodide algusnullid säilivad. Teksti ei kirjutata Exceli valemiteks. Exceli 15 numbrit ületavad rahasummad säilitatakse täpse tekstina. Töölehe reapiiri ületamisel lisatakse detailide jaoks järgmine tööleht, ridu ei lõigata ära.

**PDF** on A4 rõhtpaigutuses konto kaupa aruanne. Sisaldab ettevõtte andmeid, perioodi, algsaldosid, kanderidu, kokkuvõtteid ja lõppsaldosid; pikkadel tabelitel korduvad konto päis ja perioodi algsaldo ning lehed on nummerdatud. Välisressursse ja PDF-i skripte ei kasutata.

Summad arvutatakse sentides. Vara ja kulu saldo on deebet miinus kreedit; kohustuse, omakapitali ja tulu saldo on kreedit miinus deebet. Kontoüleselt liidetakse deebet/kredit ja D/K saldod, mitte erineva suunaga normaalsaldosid.

## Kontrollid

- `ledger_export_test/check.php`: tegelik SQL ainult ühendusepõhistes ajutistes tabelites; alg-, jooksvad ja lõppsaldod, kogu ajalugu, mitteaktiivsed kontod, filtrid, ettevõtete/olekute eraldatus, vigase kontoseose tõke, negatiivsed ja suured summad, tühjad aruanded ning töölehtede jagamine.
- `ledger_export_test/read_check.py`: CSV ja Exceli summade võrdlus eraldi lugejaga, päris OOXML/ZIP, kuupäevad, kontokoodide nullid, Unicode, valemikaitse, PDF-i tekst ning mitmeleheküljelise aruande kõik proovikirjed ja lehenumbrid.
- `ledger_export_test/route_check.php`: tegelik sisselogitud kasutaja allalaadimistee kõigis kolmes vormingus, ajutise andmestikuga.
- PDF-i leheküljed renderdati piltideks ja vaadati üle. Tabelipäiste paigutus ning veerulaiused korrigeeriti kontrolli käigus.
- Kõik neli paigaldatud PHP faili läbisid süntaksi- ja sisuvastavuse kontrolli. Püsivate kannete arvud ja pearaamatu summad jäid samaks. Ekspordi ajutised failid koristatakse pärast allalaadimist või vea korral.

Failid: `ledger.php`, `ledger_view.php`, `ledger_data.php`, `ledger_exports.php`. Varasem pearaamatu leht: `ledger_export_before/ledger.php`. Paigaldaja: `install_ledger_export.ps1`. Skeemi ega olemasolevaid arvestusandmeid ei muudetud.
