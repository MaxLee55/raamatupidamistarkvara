<?php
session_start();
if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/tax_code_lib.php';
ensureTaxCodeSchema($pdo);

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$stmt = $pdo->prepare(
    'SELECT code, label, invoice_type, rate, tax_category, valid_from, valid_to, kmd_box
     FROM tax_codes WHERE company_id = ?
     ORDER BY invoice_type, CASE tax_category WHEN "standard" THEN 0 WHEN "reduced" THEN 1 WHEN "zero_rated" THEN 2 ELSE 3 END,
              rate DESC, code'
);
$stmt->execute([(int) $_SESSION['company_id']]);
$codes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="et">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Käibemaksukoodid</title>
    <style>
        body{font:16px Arial,sans-serif;background:#f3f6fa;color:#20364d;margin:0;padding:28px}
        main{max-width:1100px;margin:auto}h1{margin-top:0}.muted{color:#61758b;line-height:1.5}
        section{background:#fff;border:1px solid #d7e1ed;border-radius:12px;padding:22px;margin:18px 0}
        table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:11px;border-bottom:1px solid #dce4ed}
        th{background:#f1f5f9}a.button{display:inline-block;background:#647789;color:#fff;padding:10px 14px;border-radius:7px;text-decoration:none;font-weight:bold}
        .notice{background:#eaf3fb;padding:13px;border-radius:8px;color:#294b69}
        @media(max-width:700px){body{padding:14px}section{overflow-x:auto}table{min-width:700px}}
    </style>
</head>
<body>
<main>
    <h1>Käibemaksukoodid</h1>
    <p class="muted">Vali ostu- või müügiarve real maksukood. Määr on koodiga seotud ning seda ei sisestata arvele käsitsi. Arve kuupäev määrab, kas ajalooline maksumäär on valitav.</p>
    <section>
        <h2>Kasutatavad koodid</h2>
        <div style="overflow-x:auto">
            <table>
                <thead><tr><th>Kood</th><th>Kirjeldus</th><th>Arve liik</th><th>Määr</th><th>Kehtivus</th><th>Käsitlus</th><th>Vana KMD lahter / käsitlus</th></tr></thead>
                <tbody>
                <?php foreach ($codes as $code): ?>
                    <tr>
                        <td><?= h($code['code']) ?></td>
                        <td><?= h($code['label']) ?></td>
                        <td><?= $code['invoice_type'] === 'sales' ? 'Müügiarve' : 'Ostuarve' ?></td>
                        <td><?= number_format((float) $code['rate'], 2, ',', ' ') ?>%</td>
                        <td><?= h($code['valid_from'] ?: '—') ?> – <?= h($code['valid_to'] ?: 'kehtiv') ?></td>
                        <td><?= match ($code['tax_category']) { 'standard' => 'Tavamäär', 'reduced' => 'Vähendatud määr', 'zero_rated' => '0% maksustatav', default => 'Maksuvaba' } ?></td>
                        <td><?= $code['invoice_type'] === 'purchase' ? 'Sisendkäibemaks (lahter 5; mahaarvamine kontrolli eraldi)' : h((string) ($code['kmd_box'] ?: 'Vajab tehingu liigi määramist')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <p class="notice">13% kood on majutusele ja 9% kood ajakirjandusväljaannetele. 9% ja 5% üleminekukoodid kehtivad ainult MTA üleminekusätete tingimuste täitumisel. 0% ja maksuvaba tehingu täpne KMD käsitlus sõltub tehingu liigist, seetõttu ei määra need koodid lahtrit automaatselt.</p>
    <p><a class="button" href="vat_report.php">Käibemaksu aruanne</a> <a class="button" href="index.php">Avalehele</a></p>
</main>
</body>
</html>
