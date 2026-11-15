# Kassapõhine arvestus XBRL GL failis

Täiendatud 07.11.2026. Uut eksporti kasutatakse alates 2027. aasta aprilli perioodist.

## Ettevõtte enda kassapõhine arvestus

1. Määra ettevõtte seadetes kassapõhise arvestuse algus- ja vajadusel lõpukuu.
2. Eesti 24% müügiarvel kasuta maksukoodi „Kassapõhine arvestus” (M_CASH24).
3. Registreeri tegelikud laekumised ja kinnita vastavad kanded. XBRL GL kasutab valitud kuu laekumisele vastavat maksustatavat väärtust koodiga M_102. Arve väljastamise kuus tasumata osa müügikäibesse ei lähe.
4. Ostul kinnita esmalt mahaarvatav kogusumma. Seejärel vali arve mahaarvamisperioodide lehel ettevõtte enda kassapõhine arvestus ja kinnita alus. Pelgalt ettevõtte seadistusest ostule maksepõhist jaotust ei teki.
5. Registreeri tegelikud ostumaksed ja kinnita kanded. Toetatud tavaostu mahaarvamine läheb maksekuusse koodiga O_104. Kui kinnitatud kogumahaarvamine on arve käibemaksust väiksem, kasutatakse koodi O_105.
6. Ava valitud kuu XBRL GL ettevalmistus, lahenda võimalikud märkused ja laadi fail alla.

Toetatud ost on eraldi Eesti ühe maksumääraga tavaost. Kassapõhise jaotusega põhivara, sõiduauto, pöördmaksu ja segaarve käsitlust see täiendus ei lisa.

## Osalise makse näide

Müügiarve neto 2 000 eurot ja KM 480 eurot, kokku 2 480 eurot. Esimesel kuul laekub 620 eurot: selle kuu M_102 summa on 500 eurot. Ülejäänud 1 860 euro laekumisel on teise kuu summa 1 500 eurot. Arve number, algne kuupäev ja arve täielik maksustatav väärtus säilivad failis.

Ostuarve neto 1 000 eurot ja KM 240 eurot, kokku 1 240 eurot. Kui mahaarvatavaks on kinnitatud 120 eurot ning esimesel kuul tasutakse 310 eurot, on selle kuu O_105 summa 30 eurot. Ülejäänud 930 euro maksmisel on teise kuu summa 90 eurot.

Maksega seotud pearaamatukande kinnituse tühistamisel seda makset arvutusse ei kaasata. Kui varasem deklaratsioon on juba esitatud, tuleb selle parandamine korraldada eraldi.

## Kassapõhise tarnija ost üldkorda kasutaval ettevõttel

1. Kinnita arve mahaarvatav kogusumma.
2. Ava mahaarvamisperioodide leht, märgi arve saamise kuupäev ja kassapõhise arvestuse alus ning vajuta „Kassapõhise tarnija ost”. Selle valiku jaoks ei pea ostja ise kassapõhist erikorda kasutama.
3. Registreeri tegelikud maksed ja kinnita kanded. Tasumata arvelt XBRL-faili mahaarvamist ei teki.
4. Laadi alla maksekuu XBRL GL fail. Kui kogu arve mahaarvamine toimub ühe maksekuuga, kasutatakse tavaostu koodi O_101. Mitme kuu osamaksete korral kasutatakse O_102. Kui osa arve käibemaksust ei kuulu mahaarvamisele, kasutatakse O_103 ning iga kuu kohta ainult selle kuu maksele vastavat mahaarvatavat summat.

Näiteks 1 240-euroselt arvelt, mille käibemaks 240 eurot on täielikult mahaarvatav, tasutakse mais 310 eurot ja juunis 930 eurot. Mai O_102 summa on 60 eurot ning juuni O_102 summa 180 eurot. Arve täielikku käibemaksu kummalgi kuul uuesti ei lisata.

Toetatud on Eesti ühe maksumääraga eraldi tavaostud. Mahaarvamise aeg lähtub [MTA sisendkäibemaksu mahaarvamise aja juhendist](https://www.emta.ee/ariklient/maksud-ja-tasumine/kaibemaks/kaibemaksu-arvutamine-ja-tagastamine/sisendkaibemaksu-mahaarvamise-aeg); XBRL-koodide valik lähtub allpool viidatud klassifikaatorist.

## Kassapõhise müügi- ja ostuarve kreedit

Eesti 24% kassapõhisele müügiarvele ning toetatud kassapõhisele tavaostule saab koostada osalise või täieliku kreedi ka enne täielikku tasumist. Ost võib olla kas ettevõtte enda kassapõhine ost või üldkorda kasutava ettevõtte ost kassapõhiselt tarnijalt. Koosta kreedit tavapärasel kreeditarve lehel. Kreedit säilitab algse arve seose, maksumäära ja ostu mahaarvamise osakaalu.

Arvutus kasutab kinnitatud makseid kuupäevade järjekorras. Kreedit vähendab esmalt veel deklareerimata jääki. Kui vähendatud arve summa jääb juba tasutud osast väiksemaks, korrigeeritakse selle vahe ulatuses varem arvestatud käivet või mahaarvamist kreeditarve kuul. Tasumata arve kreedit ei tekita käibemaksu tagastust. Järgmiste kuude maksed arvestavad vähendatud arve jääki.

Müügikäibe tegelik vähendus läheb XBRL GL faili negatiivse M_102 reana koos algse arve kuupäevaga. Ostukreedit kasutab sama kassapõhist ostuliiki ja mahaarvamise osakaalu nagu algne ost. Nullparandust XBRL-i ei lisata. Sama kuupõhine arvutus on vana KMD ning INF A/B aluseks; INF-i piirmäära hinnatakse eraldi. Hilisem pangatagastus ei vähenda käibemaksu teist korda.

Näiteks netosummaga 2 000 eurot ja käibemaksuga 480 eurot arve on täielikult tasutud. Oktoobris koostatakse 60% kreeditarve: oktoobri XBRL M_102 on −1 200 eurot ja käibemaksu vähendus 288 eurot. Raha tagastamisel novembris sama käivet uuesti ei vähendata.

Osalise tasumise näide: ostuarve neto on 1 000 eurot, KM 240 eurot ja kokku 1 240 eurot. Tasutud on 310 eurot. Kui mahaarvatavaks kogusummaks on kinnitatud 120 eurot, on makse alusel maha arvatud 30 eurot. 90% kreeditarve järel jääb arve kogusummaks 124 eurot ja mahaarvamise õiguseks 12 eurot. Kreeditarve kuu parandus on −18 eurot. Ülejäänud 186 euro tagastamine ostjale ei põhjusta uut mahaarvamise vähendust.

Tühistatud kreeditarve ei lähe eksporti. Makse, kreeditarve või tagasimakse tühistamisel arvutatakse kuude jaotus uuesti kehtivate sündmuste järgi. Tagasimakse jäägi arvutus kasutab ainult kinnitatud maksete kandeid. Juba esitatud deklaratsioonide parandamine tuleb korraldada eraldi.

Käibemaksuparanduse perioodi alus: [MTA käibemaksu korrigeerimise juhend](https://emta.ee/ariklient/maksud-ja-tasumine/kaibemaks/kaibemaksu-arvutamine-ja-tagastamine/kaibemaksu-korrigeerimine). See töövoog eeldab toimunud kauba või teenuse käivet; ettemaksu tagastamine ei kuulu selle lahenduse toesse.

## Praegused piirangud

- Ettemaksed ja kassapõhiselt üldkorrale ülemineku erijuhud vajavad eraldi käsitlust.
- Kassapõhine müük toetab praegu Eesti 24% eraldi tavaarvet.
- Kassapõhiselt tarnijalt ostetud põhivara, sõiduauto, segaarve ja pöördmaksu automaatne maksepõhine jaotus ei kuulu selle lahenduse toesse.
- Kohalik XML-skeemi kontroll on läbitud. MTA teenuses faili vastuvõtmist pole selle täienduse käigus kontrollitud.

Ametlike koodide masinloetav kontrollallikas: [Statistikaameti KMDTYYP2026ap klassifikaator](https://cl2.stat.ee/codelists/codelist/KMDTYYP2026ap).
