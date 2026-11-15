# Sisendkäibemaksu parandused ja XBRL GL

Valmis 07.11.2026. Sama paranduste register teenindab vana KMD perioode kuni märtsini 2027 ning XBRL GL perioode alates aprillist 2027.

## Kust alustada

- Ava käibemaksu aruandest või XBRL ettevalmistusest **Sisendkäibemaksu parandused**.
- Seotud põhivara puhul saab alustada põhivarakaardi lingilt **Sisendkäibemaksu ümberarvestus**.
- Vali paranduse õige kuu. Aastane proportsioon ja aastane vara ümberarvestus arvutatakse detsembrisse; esmane kasutuselevõtt ning toetatud vara müügi ümberarvestus kasutavad vastavat sündmuse kuud.

## Arvutusest ekspordini

1. Vali arvutuse liik ja sisesta alusandmed. Arvutusabi toetab aastast proportsiooni, esmast kasutuselevõttu, põhivara 1/5 ja kinnisasja 1/10 aastast ümberarvestust ning toetatud vara müügi ülejäänud aastate korrigeerimist.
2. Kui põhivarakaart on üheselt seotud kinnitatud ostuarvega, kuvatakse ostuarve käibemaks ja soetamisel kinnitatud mahaarvamine. Kontrolli võrdlussummat, arvestades vahepealseid parandusi. Arvutusabi ei vali sinu eest kasutusprotsenti ega järelejäänud aastate arvu.
3. Koosta ja kinnita pearaamatus vastav paranduskande dokument. Arvutamine ei kirjenda summat iseseisvalt; nii ei teki arvutuse proovimisel topeltkannet.
4. Registri osas **Seo olemasolev pearaamatukanne** sisesta selle kuu kande ID, valitud käibemaksukonto ning paranduse alus ja dokumendiviide. Oodatava netosumma lahtrisse saab võtta arvutusabi tulemuse; see peab klappima pearaamatuga.
5. XBRL perioodil vali algse sisendkäibemaksu tegelik maksumäär ja sisesta paranduse summa. Mitme määra korral lisa eraldi read. Märk on oluline; ka miinussumma on lubatud. Kontrollitud ajaloolise sisendkäibemaksu jaoks on olemas 22%, 20% ja 5% määrad.
6. Kinnita alus ja seo kanne. Salvestamine ei kirjenda pearaamatut teist korda. Sama kande korduv sidumine on tõkestatud.
7. Ava sama kuu XBRL ettevalmistus. Paranduse summa ja sisendkäibemaks pärast parandusi on kokkuvõttes nähtavad. XML-i lähevad seotud parandused automaatselt liigiga **O_601**, määrade kaupa, koos kuupäeva ja viitega registrile ning pearaamatukandele.
8. Käibemaksu aruande lõppsaldo võtab sama paranduse arvesse. Arvete mahaarvamised ja O_601 parandus säilivad eraldi, et summad ei dubleeruks.

## Märgid ja näited

O_601 puhul tähendab plussmärk sisendkäibemaksu vähenemist ning miinusmärk selle suurenemist. Pearaamatust kasutatakse valitud konto **kreedit miinus deebet** summat. Maksumäärade jaotus peab vastama nii konto netosummale kui ka kande suundadele.

- Põhivara sisendkäibemaks oli 1 000 eurot ja soetamisel arvati maha 1 000 eurot. Aastase arvestuse uus osatähtsus on 50%. 1/5 aastane parandus vähendab mahaarvamist 100 eurot: kulu deebet 100 eurot, sisendkäibemaksu kreedit 100 eurot; O_601 on **+100,00**.
- Aastase proportsiooni aluseks olev käibemaks on 1 000 eurot, seni maha arvatud 600 eurot ja lõplik osatähtsus 64%. Täiendav mahaarvamine on 40 eurot: sisendkäibemaksu deebet 40 eurot ning vastaskonto kreedit 40 eurot; O_601 on **−40,00**.
- Kui ühe kande 24% alusega parandus on +100 eurot ja 13% alusega parandus −100 eurot, säilivad failis mõlemad määrad, kuigi netosumma on null. Vastavad kreediti- ja deebetisummad peavad olema ka valitud pearaamatukontol.

Maksubaasi ei esitata O_601 summana: selle rea summa on sisendkäibemaksu parandus ise. Arve kreeditarve, omatarve või uus kauba müük ei muutu selle valiku abil sisendkäibemaksu ümberarvestuseks.

## Kontrollid ja parandamine

- Arve, arve makse, impordi ja tavapärase mahaarvamise automaatkannet ei saa selle registri abil uuesti deklareerida. Vali eraldi paranduskande dokument.
- Konto peab kuuluma ettevõttele ning olema aktiivne sobiv bilansikonto. Panga- ja kulukontot käibemaksukontoks valida ei saa. Maksumäära kontonumbri põhjal ei oletata.
- Kinnitamisel säilitatakse kande sisu kontrolljälg. Kui kanne muutub, muutub mustandiks või sellest tehakse paranduskanded, peatatakse mõjutatud deklaratsiooni koostamine selge veateatega.
- Vale seose korral kasuta registris **Eemalda deklaratsiooni arvestusest**. Seos jääb ajalukku; pearaamatukanne jääb alles. Vajadusel paranda raamatupidamine nõuetekohaselt ning seo õige paranduskande number.
- Suletud perioodi seost ei saa lisada ega eemaldada. Juba salvestatud õige seose eksport ja vaatamine on lubatud ka pärast perioodi sulgemist.
- Eksport sisaldab iga kord kogu valitud kuu kehtivaid andmeid. Faili uuesti laadimine ei loo uut paranduskannet ega uut registrikirjet.

Sõiduauto esimese kahe aasta erakasutuse erijuht jääb soetamiskuu paranduseks. Lehe arvutusabi näitab selle kuud ja summat; seda ei kanta automaatselt jooksva kuu O_601 reale. Sõiduauto tavaline aastane 1/5 ümberarvestus kasutab eespool kirjeldatud töövoogu.

## Kontrolli tulemus ja allikad

Kohalikud kontrollid katavad mõlemad märgid, mitu maksumäära, nullneto, ajaloolised määrad, põhivarakaardi eeltäitmise, vana registri säilimise, tegeliku vormi ja aruande, tühistamise ning vigaste alusandmete tõkked. XBRL kontrollitakse kohaliku MTA skeemi järgi; MTA teenuse vastuvõtutesti ei ole tehtud.

- [Ametlik KMD tehinguliikide klassifikaator KMDTYYP2026ap](https://cl2.stat.ee/codelists/codelist/KMDTYYP2026ap): O_601 ja selle märgireegel.
- [MTA käibemaksu korrigeerimise juhend](https://emta.ee/ariklient/maksud-ja-tasumine/kaibemaks/kaibemaksu-arvutamine-ja-tagastamine/kaibemaksu-korrigeerimine): paranduste alused.
- [MTA andmepõhise KMD juhised](https://www.emta.ee/ariklient/maksud-ja-tasumine/kaibemaks/kaibedeklaratsiooni-ja-aruannete-esitamine/kaibedeklaratsiooni-esitamine-muutub-2027-aastal-andmepohiseks).
