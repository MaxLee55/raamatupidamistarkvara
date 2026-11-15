<?php
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__.'/manual_documents.php';
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kasutusjuhend</title>
    <style>
body { max-width: 1100px; margin: 36px auto; padding: 0 22px; background: #f4f7fb; color: #263746; font-family: Arial, sans-serif; }
        h1, h2 { color: #17324d; }
        .intro, .resource-card { margin: 18px 0; padding: 20px; border: 1px solid #dce3ec; border-radius: 12px; background: #fff; }
        .intro { background: #eaf3fb; color: #17324d; }
        .resource-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 14px; }
        .resource-card { display: flex; flex-direction: column; margin: 0; }
        .resource-card h3 { margin: 0 0 8px; color: #17324d; font-size: 18px; }
        .resource-card p { margin: 0 0 12px; line-height: 1.5; }
        .resource-type { color: #667788; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
        .resource-card a { color: #1769aa; font-weight: 700; text-decoration: none; margin-top: auto; }
        .resource-card a:hover { text-decoration: underline; }
        .button { display: inline-block; min-height: 42px; padding: 10px 16px; border: 0; border-radius: 7px; background: #1769aa; color: #fff; font: inherit; font-weight: 600; text-decoration: none; }
        .button:hover { background: #0f4f83; }
        .secondary { background: #657789; }
        .manual-card { margin: 18px 0 28px; padding: 22px; border: 1px solid #dce3ec; border-radius: 12px; background: #fff; }
        .manual-card > h2 { margin-top: 0; }
        .manual-step { margin: 12px 0; padding: 0; border: 1px solid #dce3ec; border-radius: 10px; background: #f7f9fc; overflow: hidden; }
        .manual-step summary { padding: 16px 18px; color: #17324d; font-size: 18px; font-weight: 700; cursor: pointer; }
        .manual-step summary:hover { background: #edf3f9; }
        .manual-step[open] summary { border-bottom: 1px solid #dce3ec; background: #edf3f9; }
        .manual-step > :not(summary) { margin-left: 18px; margin-right: 18px; }
        .manual-step > :last-child { margin-bottom: 18px; }
        .manual-step p, .manual-step li { line-height: 1.55; }
        .manual-step ol { padding-left: 22px; }
        .manual-step li { margin: 8px 0; }
        .example { margin-top: 18px; padding: 16px; border: 1px solid #dce3ec; border-radius: 9px; background: #fff; }
        .example h4 { margin: 0 0 10px; color: #17324d; }
        .example table { width: 100%; border-collapse: collapse; }
        .example th, .example td { padding: 9px 10px; border-bottom: 1px solid #e5eaf0; text-align: left; vertical-align: top; }
        .example th { width: 190px; color: #526273; }
        .tip { padding: 11px 14px; border-radius: 8px; background: #eaf3fb; color: #17324d; }
        .confirm-guide { margin: 18px 0; padding: 16px; border: 1px solid #cbdbea; border-radius: 9px; background: #fff; }
        .confirm-guide h4 { margin: 0 0 10px; color: #17324d; }
        .confirm-guide p, .confirm-guide li { line-height: 1.55; }
        .confirm-guide li { margin: 8px 0; }
        .journal-effect { margin: 12px 0; padding: 12px 14px; border-radius: 8px; background: #eaf7ef; color: #176b3a; }
        .note { margin-top: 20px; color: #667788; line-height: 1.5; }
        @media (max-width: 650px) { body { margin: 20px auto; padding: 0 14px; } .resource-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <h1>Kasutusjuhendid</h1>
    <p class="intro">Juhend täieneb sammhaaval ning selgitab rakenduse peamisi toiminguid ja nende mõju raamatupidamises.</p>
    <section class="manual-card">
        <h2>Programmi kasutusjuhend</h2>
        <details class="manual-step">
            <summary>1. Müügiarve sisestamine</summary>
            <p>
                Müügiarve loo avalehe nupust <strong>Uus müügiarve</strong>.
                Arvevorm seob arve kliendikonto ja pearaamatu kandega; sama müüki ära sisesta lisaks käsitsi pearaamatu kandena.
            </p>
            <ol>
                <li>
                    Vajuta avalehel <strong>Uus müügiarve</strong>.
                </li>
                <li>
                    Vali klient. Nimega kliendile arve tegemiseks peab ta olema kontaktide lehel aktiivne klient
                    (kontakti tüüp „Klient” või „Klient ja tarnija”). Kui klienti valikus pole, lisa ta esmalt lehel
                    <strong>Kliendid ja tarnijad</strong>. Kassamüügi puhul vali „Kassamüük” kontakt, kui see on loodud.
                </li>
                <li>
                    Sisesta arve number, arve kuupäev, teenuse osutamise või kauba üleandmise kuupäev ning
                    maksetähtaeg. Kasuta tegelikke kuupäevi ja ettevõttes kasutatavat arvenumbrite järjekorda.
                </li>
                <li>
                    Lisa vähemalt üks arverida: kirjeldus, kogus, ühikuhind ja tehingule õige maksukood.
                    Ühikuhind sisestatakse käibemaksuta; programm arvutab käibemaksu ja rea kogusumma.
                </li>
                <li>
                    Soovi korral lisa märkused ja vajuta <strong>Salvesta arve mustand</strong>.
                    Mustand ei ole veel pearaamatusse kinnitatud.
                </li>
                <li>
                    Ava salvestatud arve nupust <strong>Ava arve</strong> või müügiarvete nimekirjast.
                    Kontrolli andmed üle ja kinnita arve allolevate juhiste järgi.
                </li>
                <li>
                    Kui klient arve tasub, registreeri laekumine arve detailvaates nupuga
                    <strong>Registreeri laekumine</strong>. Nii väheneb arve tasumata saldo korrektselt.
                </li>
            </ol>

            <div class="confirm-guide">
                <h4>Kuidas arve kinnitada</h4>
                <ol>
                    <li>
                        Ava arve detailvaade ja kontrolli veel kord klienti, arve kuupäeva,
                        arveridu, käibemaksu ning arve kogusummat.
                    </li>
                    <li>
                        Liigu detailvaates jaotiseni <strong>Kinnita arve</strong>. Vali
                        <strong>Ostjate nõuete konto</strong> rippmenüüst aktiivne varakonto,
                        kuhu teie ettevõte ostjate võlad kirjendab.
                    </li>
                    <li>
                        Vali <strong>Müügitulu konto</strong> rippmenüüst selle müügiliigi jaoks
                        õige aktiivne tulukonto. Kui kasutate eri tulude jaoks erinevaid kontosid,
                        vali konkreetsele arvele sobiv konto.
                    </li>
                    <li>
                        Kui arvel on käibemaks, vali <strong>Käibemaksukohustuse konto</strong>
                        rippmenüüst aktiivne kohustuste konto. Kui arve käibemaksusumma on null,
                        ei ole selle konto valimine kohustuslik.
                    </li>
                    <li>
                        Vajuta <strong>Kinnita arve</strong> ja kinnita brauseri küsimuses toiming.
                        Rakendus märgib arve kinnitatuks ning loob pearaamatu kande.
                    </li>
                </ol>
                <p class="journal-effect">
                    Kande loogika: ostjate nõue läheb deebetisse arve kogusummas;
                    müügitulu läheb kreeditisse käibemaksuta summas ja käibemaksukohustus
                    kreeditisse käibemaksu summas. Käibemaksuta arvel läheb kogu summa
                    nõuete deebetisse ja müügitulu kreeditisse.
                </p>
                <p>
                    Pärast kinnitamist kontrolli, et arve olek oleks <strong>Kinnitatud</strong>.
                    Arve detailvaates saad avada seotud pearaamatukande lingist <strong>Ava kanne</strong>
                    ja kontrollida, et deebet ja kreedit oleksid võrdsed. Ära loo sama arve kannet
                    eraldi nupust <strong>Lisa uus kanne</strong>.
                </p>
            </div>

            <div class="example">
                <h4>Näide arverea täitmisest</h4>
                <table>
                    <tbody>
                        <tr><th>Klient</th><td>Arbuus (vali enda olemasolev aktiivne klient)</td></tr>
                        <tr><th>Arve number</th><td>Näiteks MYYK-2026-001, kui see number on vaba ja sobib sinu arvenumbrite järjekorraga</td></tr>
                        <tr><th>Arve kuupäev</th><td>Arve tegelik väljastamise kuupäev</td></tr>
                        <tr><th>Teenuse/kauba kuupäev</th><td>Tegelik teenuse osutamise või kauba üleandmise kuupäev</td></tr>
                        <tr><th>Maksetähtaeg</th><td>Kliendiga kokku lepitud maksetähtaeg</td></tr>
                        <tr><th>Kirjeldus</th><td>Kontoritöö teenus</td></tr>
                        <tr><th>Kogus</th><td>1</td></tr>
                        <tr><th>Ühikuhind</th><td>100,00 € (käibemaksuta)</td></tr>
                        <tr><th>Käibemaksu määr</th><td>Näide ei määra arvel kasutatavat käibemaksumäära; kontrolli sellele tehingule kohaldatavat määra.</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="tip">
                Enne kinnitamist kontrolli klienti, kuupäevi, arveridu, käibemaksu ja valitud kontosid.
                Kui annad teada, millised väljad või sammud vajavad täpsustamist, täiendame seda juhendipunkti enne järgmise punkti lisamist.
            </p>
        </details>

        <details class="manual-step">
            <summary>2. Ostuarve sisestamine ja kinnitamine</summary>
            <p>
                Tarnijalt saadud arve sisesta avalehe nupust <strong>Uus ostuarve</strong>.
                Ära sisesta ostuarvet käsitsi pearaamatu kandena, sest ostuarve vorm seob tarnija saldo,
                maksed ja pearaamatukande.
            </p>
            <ol>
                <li>
                    Vali aktiivne tarnija. Kui teda valikus pole, lisa ta lehel
                    <strong>Kliendid ja tarnijad</strong> tüübiga „Tarnija” või „Klient ja tarnija”.
                </li>
                <li>
                    Sisesta <strong>Tarnija arve number</strong> täpselt nii, nagu see on tarnija arvel.
                    Lisa arve kuupäev ja arvel olev maksetähtaeg.
                </li>
                <li>
                    Iga arverea puhul vali sobiv <strong>kulu- või varakonto</strong>, lisa kirjeldus,
                    kogus, käibemaksuta ühikuhind ja arvele kohaldatav maksukood.
                    Näiteks kontoritarbed lähevad vastavale kulukontole; seadme või muu vara soetus
                    võib minna sobivale varakontole.
                </li>
                <li>
                    Kontrolli arve ridu ja kogusummat ning vajuta <strong>Salvesta ostuarve mustand</strong>.
                    Mustand ei ole veel pearaamatusse kinnitatud.
                </li>
                <li>
                    Ava ostuarve nupust <strong>Ava ostuarve</strong> või ostuarvete nimekirjast.
                    Kinnitamise osas vali <strong>Tarnijavõla konto</strong> rippmenüüst ettevõtte aktiivne
                    kohustuste konto.
                </li>
                <li>
                    Kui ostuarvel on käibemaks, vali ka <strong>Sisendkäibemaksu konto</strong>
                    rippmenüüst aktiivne varakonto. Seejärel vajuta <strong>Kinnita ostuarve</strong>
                    ja kinnita brauseri küsimuses toiming. Käibemaksuta arve puhul sisendkäibemaksu kontot valida ei küsita.
                </li>
            </ol>
            <p class="tip">
                Kui arve käibemaks on ettevõttele täielikult mahaarvatav, vali kontoplaanis konto
                <strong>1600 — Sisendkäibemaks</strong>. See on varakonto, kuhu kajastub Maksu- ja Tolliametilt
                tagasi küsitav või järgmiste perioodide maksukohustustega tasaarvestatav sisendkäibemaks.
                Ära vali selleks panga- või kassakontot, näiteks <strong>1010 — Invest konto</strong>.
                Kui käibemaksu ei saa maha arvata või saab maha arvata ainult osaliselt, ära kanna kogu summat
                automaatselt kontole 1600; kontrolli tehingu õiget käsitlust enne arve kinnitamist.
                Kui kontot 1600 veel kontoplaanis pole, lisa see kontotüübiga <strong>Vara</strong>.
            </p>
            <p class="journal-effect">
                Kinnitamisel lähevad arveridade netosummad valitud kulu- või varakontodele deebetisse,
                sisendkäibemaks deebetisse ning kogu tarnijale võlgnetav summa kohustuste konto kreeditisse.
            </p>
            <h4>Tarnijale makse registreerimine</h4>
            <ol>
                <li>
                    Kinnitatud ostuarve detailvaates leia jaotis <strong>Registreeri tarnijale makse</strong>.
                </li>
                <li>
                    Sisesta tegelik makse kuupäev, vali pangakonto või kassakonto ning sisesta makstud summa.
                    Soovi korral lisa märkus.
                </li>
                <li>
                    Vajuta <strong>Salvesta makse</strong>. Osalise makse korral sisesta ainult tegelikult makstud summa;
                    allesjäänud võlg jääb arvele tasumata saldona.
                </li>
            </ol>
            <p class="tip">
                Makset registreerides kasuta ostuarve detailvaate maksevormi, mitte käsitsi kannet.
                Nii väheneb tarnijavõlg ning arve tasumata summa uueneb.
            </p>
        </details>

        <details class="manual-step">
            <summary>Käibemaksukoodid ja KMD kontroll</summary>
            <p><strong>Ostu- ja müügiarved välisvaluutas.</strong> Vali arve valuuta ja sisesta ühikuhinnad selles valuutas. Kursi aluseks märgi tehingu kuupäev. KMD-le mineval arvel kasuta käibe tekkimise kuupäeva ja EKP kurssi. Programm salvestab arve algsummad ja kursi ning kirjendab pearaamatusse eurosummad. Müügiarve PDF-is on tasutav summa arve valuutas ja käibemaks ka eurodes.</p>
            <p>Registreeri makse või laekumine arve juures. Sisesta arvest kaetav osa arve valuutas ning rahakande euroväärtus. Programm vähendab arve jääki mõlemas valuutas ja kannab kursivahe kontole 4160 või 5060. KMD-le mineval valuutaarvel on selles versioonis üks sündmuse kuupäev arve kuus. Valuutakreedid, kassapõhine käibemaks, pangaimport, Billberry e-arved ja automaatne ümberhindlus kuuluvad eraldi töövoogudesse.</p>
            <p><strong>Kreeditarved ja parandused.</strong> Kinnitatud arve vaates vali „Koosta kreeditarve”.
            Sisesta uus number, kreeditarve kuupäev, põhjus ja osakaal kogu algsest arvest. 100% tähendab täielikku krediteerimist;
            osaline protsent vähendab kõiki algse arve ridu ühtlaselt. Maksumäär ja maksukood säilivad algselt arvelt, sh ajaloolised määrad.
            Algne arve jääb alles ning kreeditile luuakse eraldi tasakaalus paranduskanded. Vigase kreeditarve saab avatud perioodis tühistada täpse vastukandega; see taastab algse arve krediteeritava jäägi. Juba krediteeritud osa uuesti krediteerida ei saa.
            Ostuarve puhul kinnita enne algse arve mahaarvatav käibemaks; kreedit vähendab sama proportsiooni.
            Algse ostuarve mahaarvatavat summat ja jaotust ei saa pärast kreediti koostamist eraldi muuta.
            Arve tasumata jääk väheneb kreediti võrra, kuid pangaliikumisi ja tagasimakseid automaatselt ei looda.</p>
            <p>Kreedit kajastub oma kuupäeva perioodi aruandes ja KMD6 failis miinusmärgiga. INF-i 1000-eurone piirmäär arvutatakse
            positiivsetele arvetele ja kreeditarvetele eraldi; kui üks neist jõuab piirmäärani, esitatakse partneri selle perioodi mõlemad.
            See töövoog toetab Eesti tavalise käibemaksuga laotooteta arveid. Laokauba tagastused, pöördmaksu, 0% ja maksuvabad kreeditarved
            ning uue XBRL GL vormingu kreeditarve liigitus vajavad eraldi arendust. Põhivarakaardi ja tagasimaksete parandused tehakse eraldi.</p>
            <p><strong>Põhivara ja sõiduautode käibemaks.</strong> KMD INF eelvaates vali ostuarve real „Käibemaksu jaotus”.
            Põhivara soetuse mahaarvatav summa läheb lahtrisse 5.2. Sõiduauto kulude puhul vali ainult ettevõtluses kasutamine (5.3)
            või segakasutus (5.4) ning lisa reg-märk või VIN. Kasuta sama auto kohta kõikidel arvetel ja kuunimekirjas sama tunnust.
            Põhivarana ostetud sõiduautole on eraldi valikud, mis täidavad korraga nii 5.2 kui ka 5.3 või 5.4.
            Segakasutuse puhul sisesta mahaarvatavaks summaks kuni 50% käibemaksust; erijuhtudel võib mahaarvatav summa olla veel väiksem.
            Alamlahtrite summad on lahtri 5 sees, mitte täiendav mahaarvamine. Ühe arve kohta saab valida ühe jaotuse.
            Erinevate jaotustega segaarve vajab eraldi käsitlust.</p>
            <p>Jaotuse all olevasse sõiduautode kuunimekirja lisa ka autod, mille kohta sellel kuul ostuarve puudub.
            Üks auto loetakse samas kuus ühe korra, sõltumata arvete arvust või nimekirja kandmisest.
            Nimekiri ei kandu automaatselt järgmisesse kuusse. Samal kuul mõlemas kasutusliigis olev auto peatab ekspordi kuni liigituse parandamiseni.
            Sõiduauto kuluta kuu võib sisaldada ainult autode arvusid ja null eurot mahaarvatavat käibemaksu.
            Valikud ei loo põhivarakaarti, kulumit ega pearaamatu parandusi. Neid jaotusi toetab KMD6 eksport; uue XBRL GL vormingu liigitus on veel eraldi arendatav.</p>
            <p><strong>EL kauba ja teenuse ostude pöördmaksustamine.</strong>
            Vali ostuarve real vastavalt „EL kauba ost — pöördmaks” või „EL teenuse ost — pöördmaks” ja õige Eesti maksumäär.
            Tarnija kontaktile määra teise EL riigi kood ja selle riigi KMKR number; numbri kehtivust programm VIES-ist ei kontrolli.
            Tarnija KM jääb nulliks. Arve kinnitamisel vali sisendkäibemaksu varakonto, tarnijavõla konto ja eraldi käibemaksukohustuse konto.
            Programm kirjendab pöördmaksu sisendkäibemaksu ja maksukohustuse kontole. Tarnijavõlg jääb tarnija arve summasse.
            KMD INF eelvaates kinnita tegelikult mahaarvatav summa iga rea kohta. Programm kannab mahaarvamata osa vastava rea kulule või soetusmaksumusse.
            KMD6 põhifailis sisaldub maksustatav summa vastava määra lahtris, sisendkäibemaks lahtris 5 ning ostud lahtris 6 ja kaup lisaks lahtris 6.1.
            EL-i pöördmaksurida kajastub XBRL GL ekspordis eraldi tehinguliigina. Samal arvel võib olla EL-i kaupu ja teenuseid, maksuvabu ridu ning Eestis registreeritud tarnija Eesti käibemaksuga ridu.
            <a href="#foreign-purchase-lines">Vaata segaarve sisestamist ja kontrolli.</a></p>
            <p id="foreign-purchase-lines"><strong>Välisostu segaarve.</strong>
            Sisesta tarnija kontaktile riigikood ja EL-i tehingu korral selle riigi KMKR number. Määra igale arvereale oma maksukood ning kinnita KMD INF eelvaates ostuliik ja mahaarvamine ridade kaupa.
            Eesti käibemaksuga rea jaoks lisa välistarnija kontaktile Eesti KMKR number eraldi väljal. INF B jaoks sisesta tarnija Eesti mitteresidendi kood ja vali tunnuse liigiks „Mitteresidendi kood”.
            Väljaspool Eesti KMD-d oleva rea hind sisesta koos võimaliku välisriigi käibemaksuga ja vali „Väljaspool Eesti KMD-d”.
            Kui real on maksuvaba kauba ühendusesisene soetamine, vali selleks ette nähtud EL-i kauba maksukood. See jõuab KMD lahtritesse 6 ja 6.1 ning XBRL-i liigiga S_106.
            Kui mahaarvamine kuulub hilisemasse kuusse, ava arve juures „Mahaarvamisperioodid”. Seal saab jaotada sisendkäibemaksu ridade kaupa ja määrata arve pöördmaksule ühise sündmuse kuupäeva.
            Kui kreeditarve koostamisel on osa mahaarvamisest kavandatud tulevastesse kuudesse, tuleb see jaotus enne kreediti kinnitamist üle vaadata.</p>
            <p><strong>Siseriiklik pöördmaksustamine (KMS § 41¹).</strong> Kasuta ainult vastava erikorra alla kuuluva kauba ostul või müügil,
            mõlemal osapoolel peab olema Eesti KMKR number. Vali vastavalt „Siseriiklik kaubaost — pöördmaks 24%”
            või „Siseriiklik kaubamüük — pöördmaks 24%”. Tavaline ehitusteenus ei muutu selle koodi valimisega pöördmaksustatavaks.
            Müüjal jääb nõue netosummasse; arvel kuvatakse pöördmaksustamise märkus ja arvestuslik käibemaks, mida ostja müüjale ei tasu.
            Müügisumma läheb KMD lahtrisse 9 ja INF A-ossa koodiga 02. Ostja arvestab käibemaksu ning valib samad kolm kontot nagu EL pöördmaksul.
            Ostusumma läheb lahtritesse 1, 7 ja 7.1, mahaarvatav summa lahtrisse 5 ja INF B-ossa koodiga 12 (osalise mahaarvamise korral 11,12).
            INF piirmäära kontroll kehtib mõlemale poolele. Eesti ostuarvel saab tavaostu ja pöördmaksu kinnitada ridade kaupa.</p>
            <p><strong>EL-välise teenuse ost.</strong> Vali „EL-välise teenuse ost — pöördmaks” ainult Eestis pöördmaksustatava teenuse korral.
            Määra kontaktile tegelik EL-väline riigikood; välisriigi KMKR number ei ole selle valiku eeltingimus.
            Tarnija KM jääb nulliks ja tarnijavõlg netosummasse; ostusumma läheb KMD maksustatava määra lahtrisse ja lahtrisse 7.
            Mahaarvatav summa kinnitatakse eraldi ning läheb lahtrisse 5. INF-i seda välisostu ei kanta.
            Kauba impordi käibemaksu alus on eraldi tollidokument. EL-välise teenuse mahaarvamata osa kajastub pearaamatu paranduskandes.</p>
            <p>
                Müügi- ja ostuarve real vali maksukood rippmenüüst. Programm võtab käibemaksumäära koodist;
                protsenti eraldi sisestama ei pea. Arve kuupäev piirab ajalooliste määrade valikut.
            </p>
            <ul>
                <li><strong>24%</strong> on tavapärane Eesti standardmäär alates 01.07.2025.</li>
                <li><strong>13%</strong> käibemaksukood on majutusele ja majutusele koos hommikusöögiga.</li>
                <li><strong>9%</strong> käibemaksukood on ajakirjandusväljaannetele.</li>
                <li>9% ja 5% üleminekukoodid on mõeldud üksnes MTA üleminekusätte tingimuste täitumisel. Kontrolli enne valimist tingimusi MTA juhendist.</li>
                <li><strong>0%</strong> ja <strong>maksuvaba</strong> ei tähenda sama asja. Piiriülese käibe, pöördmaksustamise või erikorra puhul ära vali üldist 0% koodi ilma tehingu liiki kontrollimata.</li>
            </ul>
            <p>
                Käibemaksu aruandes kuvatakse kinnitatud arved koodide kaupa ning müügiarvete juures ka vana KMD vormi
                vastav lahter, kui see on üheselt määratav. Varem sisestatud koodita arveread kuvatakse vana arvereana;
                nende maksukoodi ei muudeta automaatselt, sest ainult protsendist ei pruugi tehingu liigi tuvastamiseks piisata.
            </p>
            <p>
                Enne 2027. aasta aprilli perioodi saad KMD INF A- ja B-osa kandidaate vaadata lehel
                <a href="kmd_inf_preview.php">KMD INF eelvaade</a>. Seadista partneri tunnus ja osapoole liik
                nupust <strong>Partnerite KMD andmed</strong>. Müügi A-osa CSV sisaldab kontrollitud arveid,
                mille partneri perioodi maksustatav netosumma ületab 1000 eurot. Ostude B-osa CSV jaoks
                peab tarnija olema Eestis käibemaksukohustuslasena registreeritud ning arve käibemaks peab
                kuuluma valitud perioodi KMD lahtris 5 osaliselt või täielikult mahaarvamisele. Arve real kinnitatud mahaarvatavat summat kasutavad nii käibemaksuaruanne, KMD lahter 5 kui ka INF B-osa.
            </p>
            <ul>
                <li>A- ja B-osa failid laaditakse alla eraldi. Vaata fail üle ning laadi see siis ise e-MTAsse; programm ei esita deklaratsiooni sinu eest.</li>
                <li>B-osa jaoks kinnita arve mahaarvatav summa eurodes; vajadusel määra käsitlus ridade kaupa. Kinnita ka INF piirmäärast allpool olevad ostud. Mahaarvamisperioodide lehel saab valida kuude jaotuse või kassapõhise ostu, sh põhivara, auto ja segaarve. Ettevõtte kassapõhise tasumata ostu INF-käsitlus võib sisaldada nullmahaarvamist. Mahaarvamata käibemaks võib tekitada eraldi paranduskande. Vaata <a href="manual_document.php?id=xbrl-cash">kassapõhise arvestuse juhendit</a>.</li>
                <li>Kui partneri või arve juures on kontrollmärkus, lahenda see enne eksporti. Eraisikule väljastatud arvet KMD INF-i ei kanta.</li>
            </ul>
            <p class="tip">
                Alates 2027. aasta aprilli perioodist asendub senine KMD INF A/B andmepõhise KMD-ga, mis jaguneb müügi,
                pöördmaksustatava soetuse, arvestuslike kannete ja ostu andmeteks. Uue perioodi jaoks kasuta
                <a href="kmd_export.php">andmepõhise KMD ettevalmistust</a>; vana KMD INF CSV selle perioodi jaoks ei kehti.
                Praegune uus eksport toetab maksukoodiga Eesti 24%, 13% ja 9% standard-/vähendatud määraga tavalisi müügi- ja ostuarveid.
                Koodita read, 0% ja maksuvabad tehingud, pöördmaksustamine, impordid, põhivara ostud ning muud erijuhud peatavad faili koostamise,
                kuni nende tehinguliigid on programmis toetatud.
                Vaata ka <a href="tax_codes.php">maksukoodide loendit</a> ja <a href="vat_report.php">käibemaksu aruannet</a>.
                Määrade ning üleminekusätete ajakohased tingimused leiad
                <a href="https://www.emta.ee/ariklient/maksud-ja-tasumine/kaibemaks/kaibemaksumaarad-ja-maksuvaba-kaive" target="_blank" rel="noopener">MTA käibemaksumäärade juhendist</a>.
            </p>
        </details>

        <details class="manual-step">
            <summary>3. Käsikande lisamine</summary>
            <p>
                Käsikannet kasuta tehingu jaoks, millel pole rakenduses eraldi vormi, näiteks teatud korrigeerimiste
                või muude pearaamatukannete puhul. Müügi- ja ostuarve ning nende laekumised ja maksed tee vastavatest arvevormidest.
            </p>
            <ol>
                <li>Vajuta avalehel <strong>Lisa uus kanne</strong>.</li>
                <li>
                    Sisesta tehingu kuupäev, selge kirjeldus ja võimalusel alusdokumendi number.
                </li>
                <li>
                    Lisa kande read. Vali igal real konto, lisa lühike rea kirjeldus ning sisesta summa
                    ainult deebet- või kreeditlahtrisse.
                </li>
                <li>
                    Veendu, et deebet ja kreedit oleksid võrdsed ning kogusumma oleks nullist suurem.
                    Vajuta <strong>Salvesta mustand</strong>.
                </li>
                <li>
                    Ava salvestatud kanne, kontrolli ridu ja vajuta <strong>Kinnita kanne</strong>.
                    Kinnitatud kanne mõjutab pearaamatu saldosid.
                </li>
            </ol>
            <p class="tip">
                Kui käsikandes valid ostjate nõuete konto, ei uuenda see kliendiarve saldot.
                Tarnijavõla käsikanne ei uuenda samuti ostuarve tasumata saldot.
                Kasuta arvete ja maksete jaoks vastavaid vorme.
            </p>
        </details>

        <details class="manual-step">
            <summary>4. Pangaväljavõtte CSV importimine ja sidumine</summary>
            <ol>
                <li>
                    Vajuta avalehel <strong>Impordi CSV</strong>. Vali kontoplaanis olemasolev pangakonto,
                    CSV väljade eraldaja ning Swedbanki, SEB, LHV või Coopi CSV-fail. Sinu LHV failis vali koma
                    ja Coopi failis semikoolon.
                </li>
                <li>
                    Vajuta <strong>Näita eelvaadet</strong>. Kontrolli, et kuupäevad, D/C suund,
                    summa, selgitus ja teenustasu oleksid õigesti loetud.
                </li>
                <li>
                    Kui eelvaade on õige, vajuta <strong>Impordi need read</strong>.
                    Import lisab pangatehingud pangaväljavõtte lehele, kuid ei loo nende põhjal pearaamatu kandeid.
                </li>
                <li>
                    Kajasta tehingud raamatupidamises sobiva vormiga: arvega seotud laekumine või makse registreeri
                    vastava arve juures; muu tehingu jaoks kasuta vajadusel käsikannet.
                    Kinnita loodud kanne.
                </li>
                <li>
                    Ava <strong>Pangaväljavõte</strong> ja vajuta <strong>Sobita kanded uuesti</strong>.
                    Rakendus püüab siduda pangarea olemasoleva kinnitatud kandega.
                    Pangast välja läinud raha märgitakse D-ga ning kontole laekunud raha C-ga.
                </li>
            </ol>
            <p class="tip">
                CSV import üksi ei muuda pangakonto pearaamatusaldot. Saldo muutub siis, kui tehing on
                raamatupidamises sobiva kandena kajastatud ja kinnitatud.
            </p>
        </details>

        <details class="manual-step">
            <summary>5. Dividendide maksmine</summary>
            <p>
                Enne dividendide arvestamist peab olema olemas pädev dividendide jaotamise otsus ning piisav jaotuskõlblik kasum.
                Kasuta selleks loodud kontosid. Konto <strong>4250 — Dividenditulu</strong> tähistab ettevõttele endale laekuvat
                dividenditulu; seda ei kasutata omanikele dividendide maksmiseks.
            </p>
            <ol>
                <li>
                    Dividendide väljakuulutamisel tee kanne:
                    <strong>D 3020 — Eelmiste perioodide jaotamata kasum</strong> /
                    <strong>K 2300 — Dividendivõlad</strong>.
                    Summa on osanikele maksmiseks otsustatud dividendisumma.
                </li>
                <li>
                    Dividendide tegelikul väljamaksmisel tee kanne:
                    <strong>D 2300 — Dividendivõlad</strong> /
                    <strong>K 1010 — Invest konto</strong> (või muu konto, millelt makse tehti, näiteks
                    <strong>1030 — LHV pank</strong>).
                </li>
                <li>
                    Kui dividend makstakse Eesti residendist äriühingu poolt tavakorras, arvesta väljamaksega seotud
                    ettevõtte tulumaks:
                    <strong>D 6500 — Ettevõtte tulumaks dividendidelt</strong> /
                    <strong>K 2110 — Dividendide tulumaksu kohustus</strong>.
                    MTA praeguse üldmäära järgi arvutatakse maks väljamakstud dividendilt määraga 22/78.
                </li>
                <li>
                    Maksu tasumisel tee kanne:
                    <strong>D 2110 — Dividendide tulumaksu kohustus</strong> /
                    <strong>K 1010 — Invest konto</strong> (või muu tegelik pangakonto).
                </li>
                <li>
                    Koosta ja esita dividendide väljamakse kohta nõutud TSD andmed. Maksustamine ja deklareerimine
                    võivad erineda näiteks saaja residentsuse või erandi tõttu; kontrolli konkreetset olukorda
                    <a href="https://www.emta.ee/ariklient/maksud-ja-tasumine/tulumaks-ja-sotsiaalmaks/dividendide-ja-omakapitali-valjamaksete" target="_blank" rel="noopener">MTA dividendide juhendist</a>.
                </li>
            </ol>
            <p class="tip">
                Esita iga toiming eraldi kande või pangatehinguna selle tegelikul kuupäeval. Kanded ei asenda dividendide
                jaotamise otsust ega TSD esitamist. Kui väljamakse saaja või kasumi maksustamine ei vasta tavakorrale,
                kontrolli enne kannete tegemist raamatupidajaga sobivat käsitlust.
            </p>
        </details>

        <details class="manual-step">
            <summary>6. Palgamoodul: töötaja lisamisest TSD ekspordini</summary>
            <p>
                Palgaarvestus tehakse järjekorras: töötaja andmed, töösuhe, maksuseaded, palgaperiood,
                brutotasud, arvutuse kontroll, palgaarvestuse kinnitamine, tegelik väljamakse ning TSD fail.
                Enne pärisandmete sisestamist võib harjutada ainult väljamõeldud proovikirjega.
            </p>

            <h4>1. Lisa töötaja</h4>
            <ol>
                <li>Ava peamenüüst <strong>Palgad</strong> ja vali töötajate leht.</li>
                <li>
                    Sisesta töötaja ees- ja perekonnanimi. Kui isikukood on olemas, sisesta see;
                    sünnikuupäeva väli on vajalik isikukoodi puudumisel.
                </li>
                <li>
                    Päris töötaja puhul jäta märkeruut <strong>Proovikirje</strong> tühjaks.
                    Harjutamisel märgi see ära ja kasuta väljamõeldud andmeid. Proovikirjeid TSD faili
                    ei lisata ning proovikirje saab koos palgaandmetega kustutada.
                </li>
                <li>Vajuta <strong>Salvesta töötaja</strong>.</li>
            </ol>
            <p class="tip">
                Töötaja lisamine sellesse rakendusse ei registreeri töösuhet Maksu- ja Tolliameti töötamise registris.
                Töösuhte alustamine, muutmine ja lõpetamine registreeri vajadusel MTA-s eraldi ning kasuta õiget
                tegelikku alust ja kuupäeva.
            </p>

            <h4>2. Lisa töösuhe</h4>
            <ol>
                <li>Ava <strong>Töösuhted</strong> ja vali <strong>Lisa töösuhe</strong>.</li>
                <li>
                    Vali töötaja ning sisesta tegelik tööle asumise kuupäev, tööaja määr, ametinimetus
                    ja töökoha aadress. Täistööaja määr on <strong>1,00</strong>; osalise koormuse puhul
                    sisesta kokkulepitud osa, näiteks 0,50 poole koormuse korral.
                </li>
                <li>Vajuta <strong>Salvesta töösuhe</strong>. Kontrolli, et arvestatava kuu ajal töösuhe kehtis.</li>
            </ol>
            <p>
                Tingimuste muutmisel ava vastava töösuhte haldus ja salvesta uus rida tegeliku jõustumise kuupäevaga.
                Ära kirjuta varasemat ajalugu uute tingimustega üle. Peatamine ja lõpetamine sisesta töösuhte halduse
                vastavates jaotistes ning kooskõlasta kuupäev ja alus MTA registriandmetega.
            </p>

            <h4>3. Määra töötaja maksuseaded</h4>
            <ol>
                <li>Ava töötaja juurest <strong>Maksuseaded</strong> ja vajuta <strong>Lisa maksuseaded</strong>.</li>
                <li>
                    <strong>Kehtib alates</strong> tähendab esimest väljamakse kuupäeva, millal neid seadeid
                    rakendatakse. See peab olema kooskõlas väljamaksega, mitte ainult arvestuskuu algusega.
                </li>
                <li>
                    Vali maksuvaba tulu liik ja sisesta kuus rakendatav summa töötaja kirjaliku avalduse järgi.
                    Sisesta avalduse kuupäev. Ära rakenda summat või liiki, mille kohta avaldus puudub;
                    piirmäärad võivad ajas muutuda.
                </li>
                <li>
                    Kontrolli II samba maksemäär kehtiva ametliku info järgi ja märgi kontrollimise kuupäev.
                    0% vali ainult siis, kui töötaja kehtiv määr ongi 0%.
                </li>
                <li>
                    Märgi töötuskindlustuse või sotsiaalmaksu miinimumkohustuse erand ainult siis,
                    kui see on konkreetsele töötajale ja perioodile kontrollitud.
                </li>
                <li>Vajuta <strong>Salvesta maksuseaded</strong>. Muutuse korral lisa uus, uue jõustumiskuupäevaga seadekirje.</li>
            </ol>

            <h4>4. Loo palgaperiood ja sisesta brutotasud</h4>
            <ol>
                <li>Ava <strong>Palgaperioodid</strong> ja jaotis <strong>Loo palgaperioodi mustand</strong>.</li>
                <li>
                    Vali <strong>Arvestuskuu</strong> ehk kuu, mille töö eest tasu arvestad, ning sisesta
                    <strong>Kavandatud väljamakse kuupäev</strong>. Kui tegelik maksekuupäev hiljem muutub,
                    märgi pärast makset õige kuupäev.
                </li>
                <li>Vajuta <strong>Loo mustand</strong> ja ava tekkinud periood.</li>
                <li>
                    Lisa töötaja ja kehtiv töösuhe, vali palgaliik ning sisesta <strong>brutosumma</strong>:
                    näiteks põhipalk, ületunnitöö, öötöö, riigipühal töötamine, puhkusetasu, lisatasu või muu töötasu.
                    Lisa iga inimese tasud õigesse perioodi.
                </li>
                <li>
                    Vaata brutotasude tabel üle. Mustandis saab palgaliiki muuta, eemaldada või töötaja read eemaldada.
                    Kui ühes kuus on mitu väljamakset samale töötajale, koonda need selle mooduli piirangu tõttu üheks
                    palgaarvestuse reaks.
                </li>
            </ol>

            <h4>5. Arvuta ja kontrolli netopalk</h4>
            <ol>
                <li>Vajuta palgaperioodi ülaosas <strong>Arvuta netopalk</strong>.</li>
                <li>
                    Kontrolli iga töötaja brutotasu, maksuvaba tulu, II samba makset, töötaja kinnipidamisi,
                    tulumaksu, netosummat ning tööandja makse. Loe läbi kõik vead ja hoiatused.
                </li>
                <li>
                    Kui maksuseaded või kuupäev on valed, paranda algandmed enne kinnitamist ja arvuta uuesti.
                    Arvestuse kuupäev ei tohi olla väljamakse kuupäevast hilisem. Sotsiaalmaksu miinimumkohustus
                    ja erandid võivad vajada eraldi kontrolli.
                </li>
                <li>
                    Salvesta arvutus ning ava vajadusel <strong>Palgaleht</strong>. Prindi see või vali brauseris
                    <strong>Salvesta PDF</strong>, et anda töötajale arvestuse kokkuvõte.
                </li>
            </ol>
            <p class="tip">
                Palgaarvestuse arvutus on abivahend. Kontrolli enne kinnitamist erandeid, miinimumkohustusi,
                töötaja avaldusi ja kehtivaid maksumäärasid ametlikest allikatest või raamatupidajaga.
            </p>

            <h4>6. Kinnita arvestus ja märgi tegelik väljamakse</h4>
            <ol>
                <li>
                    Kui summad ja kuupäevad on kontrollitud, naase palgaperioodi detailvaatesse ja vajuta
                    <strong>Kinnita palgaarvestus</strong>. Kinnitamine lukustab brutotasud ja neid ei saa enam
                    mustandina muuta.
                </li>
                <li>
                    Tee töötajale ülekanne tavapärases pangas. Rakendus ei saada palgaraha panka.
                </li>
                <li>
                    Pärast päris makse tegemist sisesta selle töötaja juures tegelik väljamakse kuupäev
                    ja vajuta <strong>Märgi makstuks</strong>. Ära märgi makstuks enne, kui ülekanne on tehtud.
                    Kui töötajatele makstakse eri päevadel, salvesta igaühele oma kuupäev.
                </li>
                <li>
                    Kui makse ebaõnnestus või kuupäev sisestati valesti, ära märgi uut makset oletuse põhjal;
                    kontrolli tegelikku pangatehingut ja paranda kanne kehtiva rakenduse võimaluse järgi.
                </li>
            </ol>

            <h4>7. Koosta TSD XBRL GL fail ja lõpeta töösuhe vajadusel</h4>
            <ol>
                <li>
                    Pärast väljamaksete märkimist ava <strong>Palgaperioodid</strong> ja jaotis
                    <strong>Koosta TSD XBRL GL fail</strong>. Vali väljamakse kuu, mitte üksnes töö eest arvestatud kuu.
                    Näiteks oktoobri töö eest novembris makstud tasu kuulub ekspordi väljamaksekuu järgi novembrisse.
                </li>
                <li>
                    Vajuta <strong>Laadi alla TSD XBRL GL fail</strong>. Faili jõuavad valitud kuul tegelikult
                    makstuks märgitud ja kinnitatud palgaarvestused; proovikirjeid ekspordis ei kasutata.
                </li>
                <li>
                    Kontrolli fail enne kasutamist. Programm laadib faili sinu arvutisse ega esita seda ise MTA-le.
                    Esitamine ja MTA tagasiside kontroll tehakse e-MTA-s eraldi. Kui MTA annab veateate, paranda
                    lähteandmed ja koosta fail uuesti.
                </li>
                <li>
                    Töösuhte lõppedes ava <strong>Töösuhted</strong>, vali töötaja töösuhte haldus ning sisesta
                    tegelik lõppkuupäev ja õige lõpetamise alus. Peatamise korral kasuta peatamise jaotist.
                    Tee vajalik tööregistri toiming MTA-s eraldi ning arvesta viimase tasu ja võimalike muude
                    väljamaksetega nende tegeliku kuupäeva järgi.
                </li>
            </ol>
            <p class="tip">
                Praegu ei loo Palgamoodul palgaarvestusest pearaamatukannet automaatselt. Kui pead palgakulu ja
                maksukohustused pearaamatus kajastama, kontrolli summad ning koosta vastav raamatupidamiskanne
                eraldi, kasutades kontoplaani palgakontosid. Ära aja TSD faili allalaadimist segamini TSD esitamisega.
            </p>
        </details>

        <details class="manual-step">
            <summary>7. Aastavahetus ja majandusaasta sulgemine</summary>
            <p>
                Aastavahetuse leht kontrollib majandusaasta sulgemiseks vajalikke tingimusi, näitab enne kinnitamist
                tekkivaid kandeid ning loob kinnitamisel tulude-kulude sulgemiskande ja järgmise majandusaasta algsaldo.
                Toiming koosneb alati eelvaatest ja eraldi kinnitamisest.
            </p>

            <h4>Enne aastavahetuse lehele minekut</h4>
            <ol>
                <li>
                    Tee andmebaasist SQL-varukoopia ja kontrolli, et fail on olemas ning selle suurus pole null.
                    Aastavahetus loob pearaamatusse kinnitatud kanded, seega peab taastamisvõimalus olema olemas.
                </li>
                <li>
                    Veendu, et kõik majandusaasta tehingud on sisestatud: müügi- ja ostuarved, laekumised,
                    tarnijatele tehtud maksed, pangatehingud, palgad, varud ja muud vajalikud kanded.
                    Kinnita või eemalda arvestusaasta mustandid.
                </li>
                <li>
                    Võrdle pangakontod ja kassa pangaväljavõtete ning kassadokumentidega. Vaata üle ostjate nõuded,
                    tarnijate võlad, maksud ja palgaarvestus. Kontrolli varasid ja varusid ning sisesta vajalikud
                    amortisatsiooni-, inventuuri- ja muud aasta lõpu korrigeerimised.
                </li>
                <li>
                    Ava <strong>Käibeandmik</strong>, <strong>Kasumiaruanne</strong> ja <strong>Bilanss</strong>
                    ning kontrolli valitud majandusaasta kuupäevadega, et saldod ja tulemus oleksid ootuspärased.
                    Uuri enne sulgemist kõik ebatavalised või seletamata saldod läbi.
                </li>
                <li>
                    Ava <strong>Seaded → Perioodi sulgemine</strong> ja sulge ükshaaval kõik 12 majandusaasta kuud.
                    Aastavahetuse leht ei luba jätkata, kui mõni kuu on sulgemata.
                </li>
            </ol>

            <h4>Koosta sulgemise eelvaade</h4>
            <ol>
                <li>Ava <strong>Seaded → Aastavahetus</strong> ja vali majandusaasta algusaasta.</li>
                <li>
                    Kontrolli kuvatud perioodi algus- ja lõpukuupäeva. Aastavahetuse leht eeldab, et majandusaasta
                    algab kuu esimesel päeval, ning sulgemist saab teha alles pärast majandusaasta viimast päeva.
                    Kalendriaasta puhul on periood tavaliselt 1. jaanuarist 31. detsembrini.
                </li>
                <li>
                    Vaata kontrollplokist, et kõik 12 kuud oleksid suletud, majandusaastas poleks mustandeid
                    ja tasakaalustamata kinnitatud kandeid poleks. Kontrolli ka lehel kuvatud arvutusvigu.
                    Kui järgmise aasta kuupäevadega kanded või arved on juba sisestatud, kontrolli need enne läbi;
                    need võivad sulgemise peatada.
                </li>
                <li>
                    Loe neli kontrollpunkti päriselt läbi ja märgi need ainult siis, kui vastav ülevaatus on tehtud:
                    pangakontod ja kassad; ostjate nõuded ja tarnijate võlad; maksud ja palgaarvestus; varad, varud
                    ja aasta lõpu korrigeerimised. Lisa vajadusel märkused.
                </li>
                <li>Vajuta <strong>Valmista sulgemine ette</strong>.</li>
            </ol>

            <h4>Kontrolli kandeid enne kinnitamist</h4>
            <ol>
                <li>
                    Vaata üle kasumiaruande tulemus ning sulgemiskande read. Programm sulgeb perioodi tulu- ja
                    kulukontode saldod kontole <strong>3100 — Aruandeaasta kasum või kahjum</strong>.
                    Kasum või kahjum ei liigu automaatselt kontole 3020.
                </li>
                <li>
                    Vaata üle uue majandusaasta algsaldo kanne. Sellesse kantakse edasi bilansikontode saldod
                    (varad, kohustused ja omakapital); tulu- ja kulukontosid algsaldona ei jätkata.
                    Sulgemise ja algsaldo kande kuupäevad ning deebet-kreedit summad kuvatakse eelvaates.
                </li>
                <li>
                    Kui kasumiaruande tulemus on nullist erinev, peab kontoplaanis olema omakapitali konto koodiga
                    <strong>3100</strong>. Kui eelvaade näitab viga või summad ei vasta sinu aruannetele, ära kinnita;
                    kontrolli kontotüüpe, kannete kuupäevi ja kontosaldosid.
                </li>
                <li>
                    Kui mõlemad kanded ja kuupäevad on õiged, vajuta <strong>Kinnita aastavahetus</strong>
                    ning kinnita brauseri küsimus. Programm postitab nähtavad kanded ja salvestab aastavahetuse ajalukku.
                    Kalendriaasta puhul on järgmise aasta algsaldo kuupäev 1. jaanuar.
                </li>
            </ol>
            <p class="tip">
                Aasta kasumi hilisem jaotamine eelmiste perioodide jaotamata kasumisse, dividendide otsustamine,
                aastaaruande koostamine ja esitamine ei toimu selle nupuga. Need toimingud tuleb teha eraldi
                vastavalt ettevõtte otsustele ja kohaldatavatele nõuetele.
            </p>

            <h4>Kui pärast kinnitamist avastad vea</h4>
            <ol>
                <li>
                    Ava uuesti <strong>Aastavahetus</strong>, vali lõpetatud aasta ja kasuta jaotist
                    <strong>Ava aasta uuesti</strong>. Sisesta konkreetne põhjus ning kinnita toiming.
                </li>
                <li>
                    Programm loob enda loodud sulgemis- ja algsaldo kannetele pöördkanded ning märgib aasta uuesti avatuks.
                    Kuu perioodide sulgemised avatakse vajadusel eraldi lehel <strong>Perioodi sulgemine</strong>.
                </li>
                <li>
                    Paranda algsed andmed, sulge kuud uuesti ning koosta ja kontrolli uus aastavahetuse eelvaade.
                    Ära kustuta pöördkandeid käsitsi; säilita toimingute ajalugu.
                </li>
            </ol>
            <p class="tip">
                Aastavahetus ei koosta ega esita automaatselt ettevõtte majandusaasta aruannet ega MTA deklaratsioone.
                See toiming sulgeb pearaamatus tulu- ja kulukontod ning kannab bilansisaldod järgmise aasta algusesse.
            </p>
        </details>

        <details class="manual-step">
            <summary>8. Laomoodul: seadistamisest laoseisu ja pearaamatu kontrollini</summary>
            <p>
                Laoarvestuse aluseks on aktiivsed tootekaardid, laod ja kinnitatud kaubaliikumised.
                Omahind arvutatakse FIFO meetodil: väljaminekul kasutatakse kõigepealt vanimaid allesolevaid
                soetuspartiisid. Laokogus on iga toote ja lao kohta eraldi.
            </p>

            <h4>1. Kontrolli kontoplaani</h4>
            <ol>
                <li>
                    Enne toodete loomist veendu, et kontoplaanis on varude jaoks sobiv <strong>varakonto</strong>
                    (näiteks 1250 — Varud) ning müüdud kauba omahinna jaoks sobiv <strong>kulukonto</strong>
                    (näiteks 5000 — Kaubad ja materjalid).
                </li>
                <li>
                    Inventuuri ülejäägi kajastamiseks vali vajadusel sobiv <strong>tulukonto</strong>.
                    Küsi kontode valik raamatupidajaga kooskõlla, eriti kui kasutatakse mitut varukontot.
                </li>
            </ol>

            <h4>2. Lisa laod ja tooted</h4>
            <ol>
                <li>
                    Ava menüüst <strong>Ladu</strong> → <strong>Laod ja asukohad</strong> ning lisa iga füüsiline
                    või virtuaalne ladu oma koodi ja nimega. Määra üks aktiivne ladu vaikimisi laoks, kui see sobib.
                    Ladude muutmine passiivseks ei kustuta nende varasemat ajalugu.
                </li>
                <li>
                    Ava <strong>Tootekaardid</strong> → <strong>Lisa toode</strong>. Sisesta ettevõtte enda tootekood,
                    nimetus, mõõtühik (näiteks tk, kg või m), minimaalne laojääk ning soovi korral ribakood ja kirjeldus.
                </li>
                <li>
                    Seo toode varude varakonto ja müüdud kauba kulu kontoga. Vali inventuuri ülejäägi tulukonto,
                    kui ettevõtte arvestuses seda kasutatakse. Salvesta toode.
                </li>
                <li>
                    Minimaalne laojääk on hoiatuspiir: Lao ülevaade näitab tooteid, mille kogus kõigis ladudes
                    kokku on miinimumis või sellest väiksem. See ei reserveeri kaupa ega takista müüki.
                </li>
            </ol>

            <h4>3. Võta kaup lattu vastu</h4>
            <p>
                Tavapärase tarnijaostu puhul alusta alati <strong>Ost → Uus ostuarve</strong> lehelt.
                Lisa arverida tootena, vali ladu ning määra arverea kontoks tootekaardil valitud varude konto.
                Ostuarve kinnitamisel tekivad tarnijavõlg ja varude kanne ning kaup lisatakse valitud lattu.
                Sama arvet ei tohi lisaks käsitsi vastuvõtuna teist korda lattu kanda.
            </p>
            <ol>
                <li>
                    Kui sisestad kauba liikumise lehel käsitsi, ava <strong>Ladu → Kauba liikumised</strong> ja
                    täida plokk <strong>Kauba vastuvõtt</strong>: kuupäev, ladu, dokumendi number, toode, kogus
                    ja ühe ühiku soetusmaksumus.
                </li>
                <li>
                    Sisesta tegelik FIFO soetusmaksumus ühiku kohta. Mahaarvatav käibemaks jäta välja;
                    mahaarvamatu käibemaks ning kaubale jaotatud otsesed soetuskulud arvesta omahinda vastavalt
                    ettevõtte arvestuspõhimõttele.
                </li>
                <li>
                    Vajuta <strong>Salvesta mustand</strong>, vaata loodud liikumine üle ning vajuta selle real
                    <strong>Kinnita</strong>. Käsitsi loodud vastuvõtt muudab laoseisu, kuid ei tekita ise ostuarve
                    ega pearaamatu kannet; kajasta selle raamatupidamislik vastaspool eraldi.
                </li>
            </ol>

            <h4>4. Müü kaup või vormista muu väljaminek</h4>
            <ol>
                <li>
                    Müügi puhul koosta arve lehel <strong>Kliendid ja müük → Uus müügiarve</strong> ning vali
                    arvereale lao toode ja ladu. Arve kinnitamisel kontrollib programm laojääki, vähendab seda
                    FIFO järgi ja lisab kinnitatud müügiarve pearaamatukandesse müüdud kauba omahinna.
                </li>
                <li>
                    Praagi, omatarbe, kao või muu arvega sidumata väljamineku puhul ava
                    <strong>Ladu → Kauba liikumised</strong> → <strong>Kauba väljaminek</strong>. Sisesta kuupäev,
                    ladu, toode, kogus, dokumendi number ja põhjus; salvesta mustand ning kinnita see liikumiste tabelist.
                </li>
                <li>
                    Kinnitamisel arvutab programm FIFO omahinna ja loob pearaamatukande automaatselt.
                    Ava seotud kanne ning kontrolli summat ja kontosid; kasutatakse tootekaardil määratud
                    kulu- ja varude kontot. Kui vanal kinnitatud real kannet pole, kuvatakse vajadusel eraldi
                    nupp selle loomiseks.
                </li>
                <li>
                    Programm ei luba kinnitada väljaminekut, mis viiks valitud lao ja toote FIFO jäägi negatiivseks.
                    Kui jääk ei klapi, kontrolli esmalt varasemate vastuvõttude kuupäevi, koguseid ning seda,
                    kas kaup on lisatud õigesse lattu.
                </li>
            </ol>

            <h4>5. Liiguta kaupa ladude vahel</h4>
            <ol>
                <li>
                    Ava <strong>Kauba liikumised</strong> → <strong>Laosiire</strong>. Vali kuupäev, toode,
                    lähtekoha ladu, sihtkoha ladu ja kogus; lisa siirde dokumendi number ja selgitus.
                </li>
                <li>
                    Vajuta <strong>Salvesta siirde mustand</strong> ja kinnita õige rida. Kinnitamisel kontrollitakse
                    lähtekoha jääki ning FIFO soetusmaksumus liigub kaubaga sihtkoha lattu kaasa.
                </li>
                <li>
                    Kontrolli mõlema lao jääki pärast siiret. Siire muudab ladude jaotust, kuid ei ole ost ega müük.
                </li>
            </ol>

            <h4>6. Tee inventuur ja korrigeeri vahe</h4>
            <ol>
                <li>
                    Ava <strong>Ladu → Inventuur</strong>. Vali inventuuri tegelik kuupäev, ladu ja toode.
                    Leht kuvab süsteemi koguse valitud kuupäeva seisuga.
                </li>
                <li>
                    Sisesta füüsiliselt loendatud kogus, inventuuri akti number ja selgitus. Kui loendatud kogus
                    on süsteemi jäägist suurem, sisesta ka ülejäägi ühiku soetusmaksumus.
                </li>
                <li>
                    Vajuta <strong>Koosta korrigeerimise mustand</strong>. Kontrolli süsteemi koguse ja loenduse vahet;
                    inventuuri kuupäev ei tohi olla varasem kui sama toote ja lao hilisemad kinnitatud liikumised.
                </li>
                <li>
                    Ava <strong>Kauba liikumised</strong> ning kontrolli inventuuri mustandit. Kinnitamisel arvestatakse
                    puudujääk FIFO järgi vanimatest soetuskihtidest maha; ülejääk lisatakse sisestatud soetusmaksumusega.
                    Kinnitamisel luuakse korrigeerimise pearaamatukanne automaatselt.
                </li>
                <li>
                    Ava seotud kanne liikumiste tabeli pearaamatulingilt ja kontrolli summad ning kontod.
                    Kui vanemal kinnitatud liikumisel on kanne puudu ja kuvatakse nupp <strong>Loo pearaamatukanne</strong>,
                    kasuta seda ainult pärast liikumise ja kuupäeva kontrolli.
                </li>
            </ol>

            <h4>7. Kontrolli laokaarti ja võrdle pearaamatuga</h4>
            <ol>
                <li>
                    Ava <strong>Laokaart</strong>, vali toode, ladu ning perioodi algus- ja lõpukuupäev ning vajuta
                    <strong>Näita laokaarti</strong>. Vaata algsaldo, sissetulekuid, väljaminekuid, lõppkogust,
                    iga liikumise FIFO ühikuhinda ja FIFO väärtust.
                </li>
                <li>
                    Ava <strong>Laovaru võrdlus</strong>, vali seisuga kuupäev ja vajuta võrdluse nuppu.
                    Võrdlus koondab kinnitatud laoliikumiste FIFO väärtuse tootekaartidel määratud varukontode
                    pearaamatu saldodega.
                </li>
                <li>
                    Kui erinevus pole null, vaata üle kinnitamata või pearaamatusse kandmata väljaminekud ja
                    inventuurid, tootekaardi varukonto, käsitsi lisatud vastuvõtud ning kuupäevad. Ära paranda
                    erinevust suvalise käsikandega enne, kui põhjus on leitud.
                </li>
            </ol>
            <p class="tip">
                Laoliikumise kinnitamine ja pearaamatu kanne on eri sammud nendel käsitsi loodud väljaminekutel
                ning inventuurikorrigeerimistel, kus liikumiste tabel pakub nuppu „Loo pearaamatukanne”.
                Ostuarve või müügiarvega seotud kauba liikumine tekib vastava arve kinnitamisel; käsitsi loodud
                vastuvõtt ei loo ise ostuarve ega pearaamatu kannet.
            </p>
        </details>

        <details class="manual-step">
            <summary>9. Müügiarve tühistamine ja laoseisu parandamine</summary>
            <p>
                Kasuta neid juhiseid siis, kui kinnitatud arvel või laoliikumisel on viga. Kinnitatud dokumenti
                ei kustutata lihtsalt selleks, et pearaamatu kanne kaoks: kõigepealt tuleb teha ja kinnitada
                sobiv paranduskäik. Arve, pearaamatu ja lao muudatused peavad omavahel klappima.
            </p>

            <h4>A. Kinnitatud müügiarve tühistamine</h4>
            <ol>
                <li>
                    Ava müügiarve ja kontrolli, et arve tuleb päriselt tühistada. Kui arve on saadetud kliendile
                    e-postiga või Billberry kaudu, vormista kliendile enne parandusdokument ning lahenda väline
                    arvekorrektsioon. Programmis tühistamine ei saada kliendile kreeditarvet ega kustuta arvet Billberryst.
                </li>
                <li>
                    Kontrolli, kas arvega on seotud laekumine. Kui on, paranda või tühista laekumine eraldi enne
                    müügiarve tühistamist. Ära jäta pangakande ja arve laekumise seost lahendamata.
                </li>
                <li>
                    Ava pearaamatus arve algne kanne ja koosta sellele täielik vastupidine paranduskande kanne.
                    Kandel peavad olema samad kontod ja täpselt samad summad vastaskülgedel; näiteks algse kande
                    deebet muutub paranduskande kreeditiks ja algse kande kreedit paranduskande deebetiks.
                    Kinnita paranduskande kanne ning kasuta kuupäeva, mis sobib avatud arvestusperioodi.
                </li>
                <li>
                    Mine tagasi müügiarve detailvaatesse. Jaotises <strong>Tühista müügiarve</strong> vali rippmenüüst
                    just selle arve kande täielik vastukanne, lisa soovi korral märkus ja vajuta
                    <strong>Tühista müügiarve</strong>. Programm kontrollib, et kanne pöörab algse kande täpselt
                    ümber, ning säilitab tühistamise seose ja põhjuse.
                </li>
                <li>
                    Kui arvel oli laoartikkel, märgib sama toiming ka arvega seotud laost väljastamise tühistatuks
                    ja taastab laokoguse. Uut pearaamatukannet toiming ise ei loo, sest pearaamatu paranduskande
                    tegid juba eelmises sammus.
                </li>
                <li>
                    Kontrolli pärast toimingut arve olekut, paranduskande viidet ja vajadusel toote laokaarti.
                    Tühistatud arvet ning selle algset kannet ei kustutata; ajalugu jääb alles.
                </li>
            </ol>
            <p class="tip">
                Tühistamine ei ole lubatud, kui arvel on laekumine, arvestusperiood on suletud, arve on väliselt
                saadetud ilma parandusdokumendita või sama kauba hilisem väljaminek muudaks FIFO arvestust.
                Kui programm vastava takistuse kuvab, lahenda see enne; ära vali teist juhuslikku kannet.
            </p>

            <h4>B. Vale laovastuvõtu parandamine</h4>
            <ol>
                <li>
                    Ava <strong>Ladu → Kauba liikumised</strong> ja vaata üle vastuvõtu kuupäev, toode, ladu,
                    kogus, omahind ning seosed ostuarve ja pearaamatukandega.
                </li>
                <li>
                    Kui tegemist on mustandi või käsitsi lisatud vastuvõtuga, millel puudub ostuarve ja pearaamatukande
                    seos, kasuta selle rea kustutamisvalikut. Kinnitatud vastuvõttu saab kustutada ainult siis, kui
                    sellest partiist pole sama kauba hilisemat väljaminekut; muidu võib muutuda FIFO omahind või jääk.
                    Kustutamine muudab laoseisu kohe ja on mõeldud ainult valele, arvestamata vastuvõtule.
                </li>
                <li>
                    Kui vastuvõtt tekkis kinnitatud ostuarve kinnitamisel, ära kasuta lao vastuvõtu kustutamist.
                    Ava ostuarve ja kasuta sealset tühistamise toimingut: kõigepealt peab ostuarve algne kanne olema
                    täielikult vastupidise kinnitatud paranduskandega tühistatud. Programm kontrollib ka makset ja
                    hilisemaid lao väljaminekuid ning tühistab ostuarve ja vastuvõtu koos.
                </li>
                <li>
                    Kui kaup jõudis lattu, aga kogus või tegelik loendus erineb süsteemi jäägist, ära kustuta õiget
                    algset vastuvõttu. Tee tegeliku kuupäeva ja loenduse alusel <strong>Ladu → Inventuur</strong> lehel
                    korrigeerimine, kinnita selle mustand ning kontrolli loodud pearaamatukannet ja laokaarti.
                </li>
                <li>
                    Kui kaup ei saabunudki ja ostuarve on juba kinnitatud, paranda ostuarve raamatupidamine ning kasuta
                    ostuarve tühistamist. Kui ostuarve jääb kehtima, kuid kaup puudub füüsiliselt, kajasta tegelik
                    puudujääk inventuurikorrigeerimisega vastavalt ettevõtte arvestuspõhimõttele.
                </li>
                <li>
                    Pärast parandust vaata <strong>Laokaart</strong> üle ning ava <strong>Laovaru võrdlus</strong>.
                    Veendu, et kogus, FIFO väärtus ja pearaamatu varukonto saldo on kooskõlas.
                </li>
            </ol>
            <p class="tip">
                Ära kustuta ostuarvega seotud laovastuvõttu eraldi ega tee laojäägi parandamiseks juhuslikku
                pearaamatukannet. Arvega seotud muudatused tuleb teha arve tühistamise kaudu; füüsilise koguse
                erinevus parandatakse inventuuriga. Süsteem võib hilisemate liikumiste või seoste tõttu toimingu
                blokeerida, et varasemate FIFO partiide ajalugu ei muutuks valesti.
            </p>
        </details>

        <details class="manual-step">
            <summary>10. E-posti saatmise seadete juhend</summary>
            <p>
                E-posti seadeid kasutatakse müügiarvete ja palgateatiste saatmiseks Gmaili kaudu.
                Seadistuse teeb administraator lehel <strong>Seaded → E-posti saatmine</strong>.
                Seadeid ei pea iga kirja saatmisel uuesti sisestama.
            </p>
            <h4>1. Valmista Gmaili konto ette</h4>
            <ol>
                <li>
                    Kasuta Gmaili aadressi, mille kaudu ettevõte soovib kirju saata. Lülita Google'i konto
                    turvaseadetes sisse kaheastmeline kinnitamine.
                </li>
                <li>
                    Loo Google'i konto lehel rakenduse parool. See on Gmaili jaoks eraldi loodud 16-märgiline
                    parool; programmi ei sisestata Gmaili tavalist parooli. Rakenduse parooli loomise võimalus
                    eeldab tavaliselt kaheastmelise kinnitamise sisselülitamist.
                    Vaata Google'i ametlikku juhendit
                    <a href="https://support.google.com/accounts/answer/185833?hl=et" target="_blank" rel="noopener noreferrer">rakenduse parooli loomise kohta</a>.
                </li>
                <li>
                    Kopeeri rakenduse parool kohe turvalisse kohta. Ära jaga seda vestluses ega lisa juhendisse.
                    Kui Google rakenduse parooli enam ei näita, loo vajadusel uus.
                </li>
            </ol>
            <h4>2. Sisesta andmed programmi</h4>
            <ol>
                <li>Ava <strong>Seaded → E-posti saatmine</strong>. Selle lehe seadete muutmiseks peab olema administraatori õigusega kasutaja.</li>
                <li>
                    Väljale <strong>Gmaili aadress</strong> sisesta saatmiseks kasutatav Gmaili aadress.
                    Väljale <strong>Arvetel kuvatav saatja nimi</strong> sisesta ettevõtte või saatja nimi,
                    mida kirja saaja näeb.
                </li>
                <li>
                    Väljale <strong>Gmaili rakenduse parool</strong> sisesta loodud 16-märgiline rakenduse parool.
                    Tühikuid ei ole vaja lisada. Väljal <strong>Testkirja saaja</strong> kasuta enda kontrollitavat
                    e-posti aadressi.
                </li>
                <li>
                    Vajuta <strong>Salvesta ja saada testkiri</strong>. Kontrolli, et programm kinnitab saatmise
                    ning kiri jõuab testkirja saaja postkasti. Vaata vajadusel ka rämpsposti kausta.
                </li>
            </ol>
            <h4>3. Saada arve või palgateatis</h4>
            <ol>
                <li>
                    Müügiarve saatmiseks peab arvel olema saaja kontakt ja selle e-posti aadress. Ava arve,
                    vaata eelvaade üle ning saada kiri arve lehe saatmisnupust.
                </li>
                <li>
                    Palgateatise saatmiseks peab töötaja kaardil olema tema õige e-posti aadress.
                    Ava palgaarvestus või palgateatis ning kasuta selle saatmise nuppu.
                </li>
                <li>
                    Kui kiri ei jõua kohale, kontrolli esmalt saaja aadressi, Gmaili aadressi ja rakenduse parooli.
                    Vaata seejärel programmi <strong>E-kirjade logi</strong> lehte, kust näeb saatmiskatsete tulemust.
                </li>
            </ol>
            <div class="tip">
                Kui vahetad saatja Gmaili kontot, kirjuta uus Gmaili aadress ja loo sellele kontole uus rakenduse
                parool. Salvesta muudatused ja saada uus testkiri. Rakenduse parool kuvatakse pärast salvestamist
                tühja väljana; kui väli ütleb, et salvestatud parooli säilitamiseks jäta see tühjaks, ei pea
                olemasolevat parooli uuesti sisestama. Parooli vahetamiseks sisesta uus rakenduse parool.
            </div>
            <p class="tip">
                Gmaili aadress, saatja nimi ja rakenduse parool salvestatakse selle programmikoopia juurkausta
                faili <strong>.env</strong>. Fail luuakse seadete avamisel või esimesel salvestamisel. Iga koopia
                loeb ja muudab ainult enda faili. .env sisaldab parooli loetaval kujul: ära saada seda kellelegi
                ega lisa avalikku pilve või avalikku lähtekoodihoidlasse. Programm keelab faili veebist avamise.
                Programmi kopeerimisel võta .env kaasa ainult siis, kui soovid kopeerida ka samad saatjaseaded.
            </p>
        </details>

        <details class="manual-step">
            <summary>11. E-arvete seadete juhend (Billberry)</summary>
            <p>
                Billberry ühendus võimaldab saata müügiarveid e-arvena ja tuua ettevõttele saabunud e-arveid
                programmi. Seadistuse teeb administraator lehel <strong>Seaded → E-arvete seaded</strong>.
                Praegune seadete leht on päriskeskkonna jaoks: salvestatud tunnustega tehtud päringud ja saatmised
                lähevad Billberry päristeenusesse.
            </p>
            <h4>1. Leia organisatsiooni API ID ja võti</h4>
            <ol>
                <li>
                    Logi sisse Billberrysse ning ava oma organisatsiooni leht konto ja seadete kaudu.
                    API võtmeid hallatakse konkreetse organisatsiooni juures, mitte programmi kasutajakonto üldseadetes.
                </li>
                <li>
                    Loo organisatsioonile uus API võti. Billberry kuvab võtme loomisel võtme <strong>ID</strong>
                    ja võtme ehk salajase koodi. Kopeeri mõlemad kohe turvalisse kohta: ID sisestatakse programmi
                    väljale <strong>Billberry ID</strong> ning salajane kood väljale <strong>Billberry võti</strong>.
                    Billberry ametlik juhend selgitab võtme loomist ja organisatsioonipõhist autentimist
                    <a href="https://billberry.ee/help/dev/billberry-client-api/" target="_blank" rel="noopener noreferrer">kliendi API juhendis</a>.
                </li>
                <li>
                    Kui võti läheb kaduma, loo Billberrys uus võti ja uuenda programmis mõlemat välja.
                    Ära kasuta siia näidetes nähtud ID-d ega võtit ning ära jaga päris võtit e-kirja või vestlusega.
                </li>
            </ol>
            <h4>2. Salvesta tunnused programmi</h4>
            <ol>
                <li>Ava <strong>Seaded → E-arvete seaded</strong>. Seadeid saab muuta administraator.</li>
                <li>
                    Sisesta organisatsiooni võtme identifikaator väljale <strong>Billberry ID</strong> ja salajane
                    võti väljale <strong>Billberry võti</strong>. Kontrolli, et tunnused kuuluvad sellele ettevõttele,
                    mille nimel soovid arveid saata või vastu võtta.
                </li>
                <li>
                    Vajuta <strong>Salvesta seaded</strong>. Ühenduse kontrollimiseks kasuta nuppu
                    <strong>Salvesta ja kontrolli pärisühendust</strong>. See teeb päriskeskkonna päringu;
                    see ei saada iseenesest arvet.
                </li>
            </ol>
            <div class="tip">
                Testkeskkonna ID ja võti on päriskeskkonnast eraldi. Testkeskkonda pääsemiseks tuleb Billberryga
                kokku leppida testorganisatsioonid ja nende vastuvõtuseaded. Praegune seadete leht salvestab
                päriskeskkonna tunnused ning päris saatmisnupp edastab arve päriselt. Ära sisesta testvõtit
                päriskeskkonna väljale. Testkeskkonna ja võtmete halduse kohta vaata samuti
                <a href="https://billberry.ee/help/dev/billberry-client-api/" target="_blank" rel="noopener noreferrer">Billberry API juhendit</a>.
            </div>
            <h4>3. Kontrolli ettevõtte ja kontaktide andmeid enne saatmist</h4>
            <ol>
                <li>
                    Ettevõtte andmetes peavad olema õiged nimi, registrikood ja pangakonto. Registrikood peab
                    vastama Billberry organisatsioonile.
                </li>
                <li>
                    Müügiarve saajal peab kontaktide lehel olema õige nimi, registrikood ja e-arve saajale sobiv
                    kontakt. Arvel kontrolli arve numbrit, kuupäeva, maksetähtaega, valuutat, ridu, koguseid,
                    hindu ja käibemaksu.
                </li>
                <li>
                    Ava arve juures <strong>E-arve eelvaade</strong>. Veendu, et müüja ja ostja on õiged ning
                    summa ja makseandmed klapivad. Seejärel vajuta saatmisnuppu ainult siis, kui arve on valmis
                    päris saajale edastamiseks.
                </li>
            </ol>
            <h4>4. Too saabunud e-arved programmi</h4>
            <ol>
                <li>
                    Veendu Billberrys, et ettevõte on e-arvete vastuvõtjana seadistatud. Ava programmis
                    <strong>Ost → Saabunud e-arved</strong> ja vajuta <strong>Otsi uusi e-arveid Billberryst</strong>.
                </li>
                <li>
                    Saabunud arve salvestub kõigepealt vaatamiseks. Ava <strong>Loo ostuarve mustand</strong>,
                    kontrolli tarnijat, registrikoodi, kuupäevi ja arveridu. Vali igale reale õige kulu- või
                    varukonto. Laokauba korral vali lisaks tootekaart ja ladu.
                </li>
                <li>
                    Soovi korral märgi reale <strong>Jäta meelde</strong>, et sama tarnija sama kirjeldusega rea
                    konto või laovalik pakutaks järgmisel korral vaikimisi. Kontrolli seda soovitust alati arve
                    tegeliku sisu järgi.
                </li>
                <li>
                    Loo ostuarve mustand. See ei ole veel pearaamatusse kantud ega lao vastuvõtuna kinnitatud.
                    Ava ostuarve, vali tarnijavõla ja vajadusel sisendkäibemaksu konto ning kinnita alles pärast
                    arve kontrollimist. Kinnitamisel tekivad pearaamatu kanne ja valitud laokauba puhul laoliikumine.
                </li>
            </ol>
            <h4>5. Saada panga e-arve eraisikule</h4>
            <ol>
                <li>
                    Billberrys peab ettevõtte seadetes olema panka saatmine aktiveeritud ning pankade kinnitus
                    saadud. Pangale saadetav e-arve on tasuline; kontrolli kehtivat hinda Billberry kontolt.
                    Billberry selgitab aktiveerimist ja pankade tuge oma
                    <a href="https://billberry.ee/help/estonian-bank-einvoices/" target="_blank" rel="noopener noreferrer">panga e-arvete juhendis</a>.
                </li>
                <li>
                    Ava <strong>Kliendid ja tarnijad</strong> ning lisa või muuda eraisiku kontakti. Sisesta
                    saaja nimi, isikukood ja vali <strong>Panga e-arve saaja pank</strong>. Sisesta saaja IBAN,
                    välja arvatud Luminori puhul, kus saaja suunatakse isikukoodiga. Kasuta ainult saaja antud
                    andmeid ja hoia isikukoodi ning pangakonto konfidentsiaalsena.
                </li>
                <li>
                    Ava <strong>Seaded → Ettevõtte andmed</strong> ning kontrolli ettevõtte ametlikku nime,
                    pangakonto / IBAN-it ja ettevõtte pangakonto BIC-i. Pangale saadetaval arvel peab müüja nimi
                    vastama ettevõtte ametlikule nimele ja makse saaja nimele.
                </li>
                <li>
                    Koosta ja kinnita müügiarve tavapäraselt. Ava arve juurest Billberry eelvaade ning kontrolli,
                    et eelvaade ütleb <strong>panga e-arve</strong>, näitab õiget panka ja saaja andmeid. Enne
                    saatmist kontrolli ka arve numbrit, summat, tähtaega ja ettevõtte makseandmeid.
                </li>
                <li>
                    Vajuta <strong>Saada panka e-arvena</strong> ainult siis, kui oled arve üle kontrollinud
                    ja nõustud Billberry pangakanali tasuga. Saaja pank peab suutma e-arvet kuvada; vigase või
                    puuduva suunamisaadressi korral võib pank arve tagasi lükata.
                </li>
            </ol>
            <p class="tip">
                Sama Billberry arvet ei impordita korduvalt uueks kirjeks; sünkroonimine jätab juba imporditud
                arved vahele. Kui tarnija puudub või ei ole aktiivse tarnija tüübiga, lisa või muuda kontakt
                enne ostuarve mustandi loomist.
            </p>
            <p class="tip">
                Billberry ID ja võti salvestatakse selle programmikoopia juurkausta faili <strong>.env</strong>.
                Fail luuakse seadete avamisel või esimesel salvestamisel. Iga koopia loeb ja muudab ainult enda
                faili. .env sisaldab võtit loetaval kujul: ära saada seda kellelegi ega lisa avalikku pilve või
                avalikku lähtekoodihoidlasse. Programm keelab faili veebist avamise. Programmi kopeerimisel võta
                .env kaasa ainult siis, kui soovid kopeerida ka samad Billberry tunnused. Kui võti satub võõra
                kätte, tühista see Billberrys ja loo uus.
            </p>
        </details>

        <details class="manual-step" id="fixed-assets">
            <summary>12. Põhivara arvelevõtt, amortisatsioon, müük ja parandamine</summary>
            <p>
                Põhivara moodul asub avalehe menüüs <strong>Põhivara</strong>. See juhend katab vara arvelevõtu,
                igakuise lineaarse amortisatsiooni, müügi või mahakandmise ning eksliku amortisatsioonikande parandamise.
                Programm peab põhivara registrit ja seob iga arvestuse pearaamatu kannetega.
            </p>

            <h4>1. Määra kontod</h4>
            <ol>
                <li>Ava <strong>Põhivara → Kontode seaded</strong>. Vali põhivara bilansikonto, akumuleeritud kulumi konto ja amortisatsioonikulu konto.</li>
                <li>Kontrolli kontoplaanist, et kontod kuuluvad samale ettevõttele, on aktiivsed ja sobiva tüübiga. Salvesta seaded; iga programmikoopia kasutab oma andmebaasi kontoseoseid.</li>
            </ol>

            <h4>2. Kinnita ostuarve ja lisa vara mustand</h4>
            <ol>
                <li>Sisesta ostuarve tavapäraselt ning kanna vara netosumma põhivara kontole. Mahaarvatav sisendkäibemaks jääb sisendkäibemaksu kontole, mitte vara soetusmaksumusse. Kui käibemaksu ei saa maha arvata, lähtu ettevõtte raamatupidamise arvestuspõhimõttest ja lisa see vajaduse korral vara maksumusse.</li>
                <li>Kinnita ostuarve ning kontrolli, et pearaamatusse jõudnud põhivara rea summa on see, mida soovid registrisse võtta.</li>
                <li>Ava <strong>Põhivara register → Lisa vara mustand</strong>. Sisesta kordumatu vara number, nimetus, kategooria, soetamise kuupäev, kasutusvalmis kuupäev, soetusmaksumus, hinnanguline lõppväärtus ja kasulik eluiga kuudes. Uue vara puhul jäta „Olemasoleva vara algseis” märkimata.</li>
                <li>Väljale <strong>Algdokumendi viide</strong> sisesta täpselt kinnitatud ostuarve number. Mustandi summa peab klappima ostuarve põhivara reaga; kontroll hõlmab ka valitud põhivara kontot.</li>
                <li>Salvesta mustand, vaata andmed uuesti üle ja kasuta registris selle rea <strong>Kinnita</strong> tegevust. Enne kinnitamist saab mustandit muuta; aktiivse vara muutmine on piiratud, et juba alustatud arvestus ei muutuks märkamatult.</li>
            </ol>

            <h4>3. Lisa juba kasutuses oleva põhivara algseis</h4>
            <ol>
                <li>Kasuta seda võimalust ainult siis, kui ettevõte kasutab põhivara juba enne selle mooduli kasutuselevõttu ja vara soetusmaksumus on pearaamatus kajastatud.</li>
                <li>Lisa registris vara mustandina, sisestades algse soetamise ja kasutusvalmis kuupäeva, algse soetusmaksumuse, hinnangulise lõppväärtuse ning kogu kasuliku eluea.</li>
                <li>Märgi <strong>Olemasoleva vara algseis</strong>. Sisesta vara kohta pearaamatus juba arvestatud akumuleeritud kulum ning enne mooduli kasutuselevõttu arvestatud amortisatsioonikuude arv. Lisa algdokumendi väljale viide algsele ostudokumendile või algseisuaruandele.</li>
                <li>Kontrolli enne kinnitamist, et põhivara konto saldo sisaldab soetusmaksumust ja akumuleeritud kulumi konto saldo sisaldab sama vara varasemat kulumit. Algseisu sisestamine registrisse ise kannet ei loo ega muuda pearaamatu saldosid.</li>
                <li>Kinnita vara registrisse. Amortisatsioon jätkub järgmisest arvestamata kuust. Kui varem arvestatud kuude arv või kulum on ebatäpne, ära kinnita enne pearaamatu saldode ja algdokumentide kontrollimist.</li>
            </ol>

            <h4>4. Arvesta igakuine amortisatsioon</h4>
            <ol>
                <li>Ava <strong>Põhivara amortisatsioon</strong>, vali arvestuskuu ja vajuta <strong>Näita eelvaadet</strong>. Arvestus algab vara kasutusvalmis kuust.</li>
                <li>Kontrolli iga vara soetusmaksumust, lõppväärtust, kuukulu ja kulukontosid. Kuuarvestus on üldjuhul (soetusmaksumus − lõppväärtus) / kasulik eluiga kuudes; viimase kuu summa korrigeeritakse ümarduse võrra.</li>
                <li>Kui vara lisati hiljem, arvesta selle varasemad kuud järjest. Programm ei lase hilisemat kuud arvestada, kui varasem kuu on puudu. Juba arvestatud vara samal kuul uuesti ei kanta.</li>
                <li>Kui eelvaade klapib, vajuta <strong>Loo ja postita amortisatsioonikanne</strong>. Kanne postitatakse kohe: deebet amortisatsioonikulu, kreedit akumuleeritud kulum. Kuu viimane kuupäev on kande kuupäev.</li>
                <li>Ava vara kaart ja kontrolli, et amortisatsioonikuu ning pearaamatu kande link on liikumiste ajaloos olemas. Võrdle vajaduse korral ka pearaamatu saldosid.</li>
            </ol>

            <h4>5. Müü vara või kanna see maha</h4>
            <ol>
                <li>Enne müügi toimingut koosta ja kinnita müügiarve. Müügiarve kajastab ostjale esitatud hinda ja käibemaksu; põhivara kõrvaldamine sulgeb vara soetusmaksumuse ning akumuleeritud kulumi.</li>
                <li>Ava registris vara juurest <strong>Müü või kanna maha</strong>. Vali müük või mahakandmine, sisesta tegelik kuupäev, kontrolli jääkmaksumuse kulu kontot ja lisa vajaduse korral selgitus.</li>
                <li>Müügil seo toiming kinnitatud müügiarvega. Müügiarve tulu ja käibemaks jäävad müügiarve kandele; kõrvaldamiskanne ei lisa neid summasid teist korda.</li>
                <li>Enne kinnitamist kontrolli, et vara bilansiline jääkmaksumus vastab arvestatud kulumile ja valitud kuupäev on õige. Kinnitamisel postitatakse põhivara kõrvaldamise kanne ning vara olekuks saab <strong>Kõrvaldatud</strong>.</li>
            </ol>

            <h4>6. Paranda ekslikku müüki või mahakandmist</h4>
            <ol>
                <li>Ava vara kaart ja kontrolli kõrvaldamise kannet. Müügi korral tühista kõigepealt seotud müügiarve programmi müügiarve tühistamise juhendi järgi; müügiarve ja selle maksed peavad olema lahendatud.</li>
                <li>Loo kõrvaldamise algsest kandest täielik vastupidine paranduskande mustand, kontrolli ridu ning kinnita vastukanne. Seejärel kasuta vara kaardil <strong>Paranda kõrvaldamist</strong> ja sisesta põhjus.</li>
                <li>Programm kontrollib vastukande ridu ja summasid. Eduka paranduse järel märgitakse kõrvaldamine tühistatuks ning vara taastatakse aktiivseks. Algne kanne ja paranduse ajalugu jäävad alles.</li>
            </ol>

            <h4>7. Paranda vale amortisatsioonikanne</h4>
            <ol>
                <li>Ava <strong>Põhivara amortisatsioon</strong> ja vali vigase kande arvestuskuu. Jaotises <strong>amortisatsioonikande parandamine</strong> leia algne kanne ning ava selle number.</li>
                <li>Algse kande lehel vajuta <strong>Loo paranduskande mustand</strong>. Ava mustandi muutmine ja kontrolli kuupäeva: see peab olema algse amortisatsioonikande kuupäevaga sama või hilisem. Näiteks kuu lõppu kuupäevastatud amortisatsioonikande paranduskande kuupäevaks ei sobi sama kuu varasem päev. Kontrolli, et kõik sama konto summad on vastupidises suunas, seejärel kinnita paranduskande kanne. Ära muuda algset kannet ega kasuta osalist vastukannet.</li>
                <li>Mine amortisatsiooni lehele tagasi, vali kinnitatud vastukanne, kirjuta paranduse põhjus ja vajuta <strong>Kinnita parandus</strong>. Programm kontrollib, et vastukanne pöörab algse kande täielikult ümber.</li>
                <li>Paranduse järel eemaldatakse vigane kuu aktiivsest amortisatsiooniarvestusest, kuid paranduse ajalugu jääb alles vara kaardile ja amortisatsiooni lehele. Nüüd saad sama kuu eelvaadet uuesti kontrollida ja vajaduse korral õige kande postitada.</li>
                <li>Paranda kuud kronoloogilises järjekorras. Kui sama vara kohta on hilisem amortisatsioon või kinnitatud müük/mahakandmine, tuleb see kõigepealt parandada; programm blokeerib varasema kuu paranduse, mis muidu muudaks järgnevaid saldosid valeks.</li>
            </ol>
            <p class="tip">
                Parandustoiming ei kustuta ega muuda pearaamatu kandeid. See seob kinnitatud täieliku vastukande põhivara amortisatsiooniarvestusega,
                jätab algse ja parandava kande ajalukku ning lubab kuukulu pärast uuesti arvestada. Kasuta parandust ainult siis, kui kanne on
                tegelikult vale; tavalise uue vara lisamisel pole varasemate kuude parandamine vajalik.
            </p>

            <h4>8. Kui kinnitatud kandel on vale kuupäev</h4>
            <ol>
                <li>Kontrolli kõigepealt algdokumenti ja seda, kas vale kuupäevaga kanne on juba kinnitatud. Mustandit saab muuta; kinnitatud kande kuupäeva ära kirjuta otse üle ega kustuta kannet.</li>
                <li>Loo vale kuupäevaga kinnitatud kande kohta täielik vastukanne: samad kontod ja summad, kuid deebet ja kreedit vastupidi. Pane vastukande kuupäevaks kuupäev, mil paranduse tegelikult teed, ning lisa selgitusse algse kande number ja vea põhjus.</li>
                <li>Loo seejärel uus kanne õige kuupäevaga ja õigete summadega. Kontrolli enne kinnitamist, et vastukanded viitavad parandatavale kandele ning et sama tehing ei jää topelt arvestusse.</li>
                <li>Kui vale kuupäevaga oli juba kinnitatud ka vastukanne, tuleb ka see parandada: tee selle kohta täielik vastukanne ning seejärel uus vastukanne õige kuupäevaga. Nii jääb paranduste ahel pearaamatus jälgitavaks.</li>
                <li>Põhivara amortisatsioonikande puhul kasuta esmalt selle lehe parandamise jaotist, sest programm seob vastukande põhivara arvestusega ja lubab kuu uuesti arvestada. Kui juba kinnitatud vastukande kuupäev ise on vale, ära postita sama kuu amortisatsiooni uuesti enne, kui kõik seotud paranduskanded ja vara arvestuse seis on kontrollitud.</li>
                <li>Lisa paranduse alus ja põhjus raamatupidamisõiendisse või kande selgitusse ning viita parandatava kande numbrile. Kui periood on suletud või deklaratsioon esitatud, kooskõlasta paranduse kuupäev ja kajastamine raamatupidajaga enne postitamist.</li>
            </ol>
            <p class="tip">
                Näide: kui amortisatsioonikanne on kinnitatud vale kuupäevaga, pööratakse algne kanne täielikult tagasi ja amortisatsioon kajastatakse uuesti õige kuupäevaga. Kui ka tagasipöörav kanne kinnitati vale kuupäevaga, tuleb kõigepealt parandada ka see kanne. Kinnitatud kanded jäävad ajalukku; muudatus peab olema dokumenteeritud ja algse kirjendiga seostatav.
                Raamatupidamise seaduse § 10 järgi tuleb registri parandamisel säilitada jälgitavus ning parandust selgitav dokument.
            </p>
            <h4>9. Muuda aktiivse vara arvestushinnangut</h4>
            <ol>
                <li>Ava vara kaart ja vali <strong>Muuda arvestushinnangut</strong>. Kasuta seda siis, kui vara kasutusea või eeldatava lõppväärtuse hinnang muutub; see ei ole viis algse ostuhinna või juba postitatud kande parandamiseks.</li>
                <li>Sisesta uus lõppväärtus, järelejäänud kasulik eluiga ja muudatuse põhjus. Muudatus jõustub esimesel arvestamata kuul, et varasemaid kinnitatud amortisatsioonikandeid ei arvutataks ümber.</li>
                <li>Programm arvestab edaspidise kuukulu muudatuse hetke bilansilisest jäägist, uuest lõppväärtusest ja uuest järelejäänud elueast. Muudatus salvestatakse vara liikumiste ajaloos; varasemad kanded ja nende summad jäävad alles.</li>
            </ol>
            <h4>10. Ekspordi register ja amortisatsioonigraafik</h4>
            <ol>
                <li>Põhivara registris vali <strong>Laadi register CSV-na alla</strong>, et saada varade loend koos soetusmaksumuse, algseisu kulumi ja bilansilise jäägiga.</li>
                <li>Vali amortisatsiooni kuu ja vajuta <strong>Laadi graafik CSV-na alla</strong>. Failis on iga aktiivse vara kuu amortisatsioon ja olek, sealhulgas märge siis, kui varasem kuu tuleb enne arvestada.</li>
                <li>CSV-faili saab avada Excelis. Kontrolli impordi viisardis eraldajaks semikoolonit ja komakohtade kuvamist; enne aruande või deklaratsiooni esitamist võrdle summad pearaamatuga.</li>
            </ol>
            <p class="tip">
                Enne uute algseisu ja hinnangumuudatuste funktsioonide kasutamist impordi phpMyAdminis fail
                <strong>pohivara/migration_006_opening_balances_and_asset_changes.sql</strong>. See lisab põhivara algseisu väljad ja hinnangumuudatuste ajalootabeli olemasolevaid kandeid kustutamata.
                Kui amortisatsiooniparanduste migratsioon 005 pole veel rakendatud, impordi see enne migratsiooni 006. Ära impordi <strong>schema.sql</strong> uuesti olemasolevasse andmebaasi.
            </p>
        </details>
        <div id="lisajuhendid">
            <?php foreach (manualDocuments() as $id => $guide): ?>
                <?php if ($guide['group'] !== 'Kasutusjuhendid') continue; ?>
                <details class="manual-step" id="guide-<?= manualEscape($id) ?>">
                    <summary><?= manualEscape($guide['title']) ?></summary>
                    <p><?= manualEscape($guide['description']) ?></p>
                    <?php if (!empty($guide['notice'])): ?><p class="tip"><?= manualEscape($guide['notice']) ?></p><?php endif; ?>
                    <div>
                        <?php
                        $guideText = file_get_contents(__DIR__ . '/manual_documents/' . $guide['file']);
                        echo $guideText === false
                            ? '<p>Juhendi avamine ebaõnnestus.</p>'
                            : (str_ends_with($guide['file'], '.txt')
                                ? '<pre>' . manualEscape($guideText) . '</pre>'
                                : manualMarkdown($guideText));
                        ?>
                    </div>
                    <p><a href="manual_document.php?id=<?= rawurlencode($id) ?>">Ava eraldi / prindi / salvesta PDF</a></p>
                </details>
            <?php endforeach; ?>
        </div>
    </section>

    <p><a class="button secondary" href="index.php">Tagasi avalehele</a></p>
</body>
</html>
