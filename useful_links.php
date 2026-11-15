<?php
session_start();

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$sections = [
    [
        'title' => 'Ametlikud juhendid ja registrid',
        'items' => [
            [
                'title' => 'Maksu- ja Tolliamet (MTA)',
                'url' => 'https://www.emta.ee/ariklient/e-teenused-koolitused/e-teenuste-kasutamine',
                'description' => 'Ettevõtja e-teenused, e-MTA kasutamine ja ametlikud maksujuhendid.',
                'type' => 'Ametlik allikas',
            ],
            [
                'title' => 'Raamatupidamise seadus',
                'url' => 'https://www.riigiteataja.ee/akt/125052012016?leiaKehtiv=',
                'description' => 'Seaduse kehtiv terviktekst Riigi Teatajas.',
                'type' => 'Ametlik õigusakt',
            ],
            [
                'title' => 'Raamatupidamise Toimkonna juhendid (RTJ)',
                'url' => 'https://www.riigiteataja.ee/akt/119022025012',
                'description' => 'Eesti hea raamatupidamistava juhendid Riigi Teatajas.',
                'type' => 'Ametlik juhend',
            ],
            [
                'title' => 'e-Äriregister',
                'url' => 'https://ariregister.rik.ee/est',
                'description' => 'Ettevõtte andmed ja majandusaasta aruande esitamise keskkond.',
                'type' => 'Ametlik register',
            ],
            [
                'title' => 'RIK e-arveldaja',
                'url' => 'https://e-arveldaja.rik.ee/',
                'description' => 'Registrite ja Infosüsteemide Keskuse raamatupidamis- ja arveldustarkvara.',
                'type' => 'Riiklik teenus',
            ],
        ],
    ],
    [
        'title' => 'Õppematerjalid ja erialainfo',
        'items' => [
            [
                'title' => 'Pilvebüroo',
                'url' => 'https://pilvebyroo.ee/category/raamatupidamine/',
                'description' => 'Praktilised artiklid raamatupidamisest, maksudest ja ettevõtlusest.',
                'type' => 'Õppematerjalid',
            ],
            [
                'title' => 'Raamatupidajate Profiklubi',
                'url' => 'https://pilvekool.ee/courses/raamatupidajate-profiklubi/',
                'description' => 'Raamatupidamise ja finantsjuhtimise kursused ning õppematerjalid.',
                'type' => 'Koolitused',
            ],
            [
                'title' => 'Eesti Raamatupidajate Kogu',
                'url' => 'https://erk.ee/',
                'description' => 'Erialaliidu uudised, kutsestandardid ja valdkonna tegevused.',
                'type' => 'Erialaliit',
            ],
            [
                'title' => 'Raamatupidaja.ee',
                'url' => 'https://www.raamatupidaja.ee/',
                'description' => 'Erialauudised, maksuteemad ning lugejate küsimused ja vastused.',
                'type' => 'Erialaportaal',
            ],
        ],
    ],
    [
        'title' => 'Arutelud ja kogukonnad',
        'items' => [
            [
                'title' => 'Pilvebüroo Facebooki grupp',
                'url' => 'https://www.facebook.com/groups/pilvebyroo/',
                'description' => 'Ettevõtluse, finantsteemade ja raamatupidamise arutelud.',
                'type' => 'Kogukonna arutelu',
            ],
            [
                'title' => 'Raamatupidaja.ee küsimused ja vastused',
                'url' => 'https://www.raamatupidaja.ee/',
                'description' => 'Lugejate küsimused ja erialased vastused; osa sisust võib nõuda kontot.',
                'type' => 'Küsimused ja vastused',
            ],
        ],
    ],
];
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kasulik teada</title>
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
        .manual-step { padding: 18px; border-radius: 10px; background: #f7f9fc; }
        .manual-step h3 { margin-top: 0; color: #17324d; }
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
    <h1>Kasulik teada</h1>
    <p class="intro">Valik Eesti raamatupidamisalaseid ametlikke allikaid, õppematerjale ja arutelukohti. Välislingid avanevad uues vahelehes.</p>


    <?php foreach ($sections as $section): ?>
        <section>
            <h2><?= h($section['title']) ?></h2>
            <div class="resource-grid">
                <?php foreach ($section['items'] as $item): ?>
                    <article class="resource-card">
                        <div class="resource-type"><?= h($item['type']) ?></div>
                        <h3><?= h($item['title']) ?></h3>
                        <p><?= h($item['description']) ?></p>
                        <a href="<?= h($item['url']) ?>" target="_blank" rel="noopener noreferrer">
                            Ava leht uues vahelehes ↗
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <p class="note">Arutelud ja blogipostitused on abimaterjalid, mitte ametlikud juhised. Maksukohustuse või raamatupidamisreegli kontrollimisel lähtu kehtivast õigusaktist ja vastava asutuse juhendist.</p>
    <p><a class="button secondary" href="index.php">Tagasi avalehele</a></p>
</body>
</html>
