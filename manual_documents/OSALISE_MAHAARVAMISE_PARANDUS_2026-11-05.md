# Osalise mahaarvamise automaatne parandus

Paigaldatud ja failide sisu järgi kontrollitud 05.11.2026 kaustas **C:/laragon/www/raamatupidamine**. Paigaldamisel lisati ainult programmi muudatused ning paranduste seisu/ajaloo tabelid. Olemasolevatele arvetele ega maksetele kandeid ei tehtud; andmeid ei lähtestatud.

## Kasutamine

1. Ava KMD INF eelvaade, vali periood ja kontrolli ostuarve mahaarvatav summa.
2. Vajuta **Kinnita kontroll** või **Salvesta muudatus**. Sama tehing salvestab nii KMD kinnituse kui ka vajaliku pearaamatu paranduse.
3. Mahaarvamata osa läheb arve algsetele kulu-/varakontodele. Mitme rea korral jaotatakse see käibemaksu osakaalude järgi täpselt sentides. Tarnijavõlg ja arve summad ei muutu.
4. Muuda summat vajadusel samas vormis. Programm kannab ainult erinevuse. Sama kinnituse korduv salvestamine ei loo uut kannet.
5. Rea juurest avaneb **Paranduste ajalugu**: eelmine/uus mahaarvamine, kontode jaotus, muutja, aeg ning paranduskande link.

Varem kinnitatud osalise mahaarvamisega arvetel, millel paranduste ajalugu veel pole, vajuta üks kord **Salvesta muudatus**. Eelvaade näitab, kui pearaamatu parandus vajab salvestamist. Kui sama parandus on juba käsitsi tehtud, ei tohi seda teist korda lisada: sama dokumendinumbriga KM käsiparanduse leiab programm automaatselt ning palub selle esmalt sama dokumendinumbriga vastukandega tagasi pöörata. Muu dokumendinumbriga või seostamata käsikandeid ei saa automaatselt kindlalt ära tunda; need tuleb enne kontrollida.

## Näited

Tavaline ost: neto 100 €, käibemaks 24 €, mahaarvatav 12 €. Parandus on **kulu/vara deebet 12 € ja sisendkäibemaksu kreedit 12 €**. Kulu/vara maksumus on 112 €, sisendkäibemaks 12 € ja tarnijavõlg endiselt 124 €. Kui mahaarvatav summa muutub 18 eurole, kantakse tagasi ainult 6 €.

Pöördmaksu ost: tarnijaarve 100 €, arvestatud pöördmaks 24 €, mahaarvatav 12 €. Kulu/vara maksumus on 112 € ja sisendkäibemaks 12 €. **Pöördmaksu kohustus on endiselt 24 € ning tarnijavõlg 100 €.**

Kreeditarve parandab ka algse ostu mahaarvamata osa. Täielik kreedit taastab nii maksud kui ka kulu/vara ümardussendid. Kreedi tühistamisel pööratakse selle hetkel kehtiv käibemaksuparandus tagasi. Kulukonto arvel arvutatakse aktiivsed kreedid uuesti ka siis, kui algse arve mahaarvatavat summat muudetakse.

## Vara ja ajaloolised toimingud

- Laokauba puhul suurendatakse sama tehinguga nii varude kontot kui ka seotud FIFO vastuvõtu maksumust. Edasised müügid ja laoväljaminekud tarbivad tegelikud allesjäänud sendid; eraldi väljastamiste järel ei jää nullkogusega partiisse käibemaksu ümardusjääki.
- Kui kaubal on juba väljaminekuid, tuleb need enne algse mahaarvamise muutmist tagurpidi järjekorras parandada. Ajalooliste väljastamiste omahinda ei kirjutata vaikselt ümber.
- Olemasoleva põhivarakaardi soetusmaksumus muutub automaatselt, kui kaart on sama ettevõtte oma, algdokumendiks on ostuarve number ning konto/maksumus klapivad üheselt. Kaarti ise juurde ei looda. Hiljem loodava kaardi maksumus peab sisaldama mahaarvamata käibemaksu.
- Juba arvestatud amortisatsioon või arvestushinnang tuleb enne algse soetusmaksumuse muutmist parandada. Aktiivsed varakreeditid tuleb vajadusel enne tühistada ning pärast uut kinnitust uuesti koostada. Üldised kasutuse/proportsiooni ja aastased korrigeerimised on endiselt tööjärje punkt 7.
- Suletud algse või mõjutatud kreeditarve perioodi rahalist muudatust ei lubata. Ebaõnnestumine tühistab kogu salvestuse, kaasa arvatud juba alustatud seotud arve/kulu/vara parandused.
- Lähtestusnupp eemaldab ka paranduste seisu ja ajaloo ning jätab kontaktid ja seaded alles.

## Kontrollid ja failid

`vat_integration_test/purchase_vat_adjustment_test.php` kontrollib ajutistes tabelites 0/50/100% mahaarvamist, mitut kulukontot, pöördmaksu kohustuse säilimist, kordussalvestamist, muutmise vahesummasid, aktiivseid kreediteid ja tühistamist, suletud kreediperioodi tagasipööramist, laovara/põhivara maksumust, kolme eraldi FIFO väljamineku senditäpsust, varasema käsiparanduse tõket ja selle vastukannet, vananenud kinnituse ning teise ettevõtte tõrjet. Kõik kanded peavad tasakaalus olema.

Varasemad KMD/INF, sõiduautode ja kreeditarvete kontrollid läbisid samuti; ekspordifailid valideeriti MTA skeemiga. Lähtestamist kontrolliti ühendusepõhistes ajutistes tabelites, mitte tegelike andmete kustutamisega.

Põhifailid: `purchase_vat_adjustment.php`, `purchase_vat_adjustment_history.php`, `kmd_inf_confirmation.php`, `kmd_inf_preview.php`. Paigaldatud muudatused hõlmavad ka kreediteid, lao FIFO-d, ostuarve tühistamist ning lähtestamist. Varasemad failid asuvad töökausta `vat_adjustment_before` kaustas.

Järgmine tööjärje punkt on **3 — kaupade import**.
