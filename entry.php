<?php
session_start();

if (empty($_SESSION['user_id']) || empty($_SESSION['company_id'])) {
    header('Location: login.php');
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/db.php';
require_once __DIR__.'/journal_draft_lib.php';
ensureJournalCorrectionSchema($pdo);

$entryId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$entryId) {
    http_response_code(400);
    exit('Vigane kande ID.');
}

// Kontrollime ettevõtte ID-d, et kasutaja näeks ainult oma ettevõtte kannet.
$entryStmt = $pdo->prepare(
    'SELECT id, entry_date, description, document_number, status,posted_at,corrects_entry_id
     FROM journal_entries
     WHERE id = :id AND company_id = :company_id'
);
$entryStmt->execute([
    'id' => $entryId,
    'company_id' => $_SESSION['company_id'],
]);
$entry = $entryStmt->fetch();

if (!$entry) {
    http_response_code(404);
    exit('Kannet ei leitud.');
}
$correctionSource=journalCorrectionSource($pdo,(int)$_SESSION['company_id'],$entry);

$linesStmt = $pdo->prepare(
    'SELECT
        jl.description,
        jl.debit,
        jl.credit,
        a.code,
        a.name
     FROM journal_lines jl
     JOIN accounts a ON a.id = jl.account_id
     WHERE jl.entry_id = :entry_id
     ORDER BY jl.id'
);
$linesStmt->execute(['entry_id' => $entryId]);
$lines = $linesStmt->fetchAll();

$debitTotal = 0;
$creditTotal = 0;
foreach ($lines as $line) {
    $debitTotal += (float) $line['debit'];
    $creditTotal += (float) $line['credit'];
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kanne #<?= (int) $entry['id'] ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 32px; }
        table { border-collapse: collapse; margin-top: 16px; }
        th, td { padding: 8px 12px; border: 1px solid #ccc; text-align: left; }
        .number { text-align: right; }
		.reversal-card {
    max-width: 680px;
    margin: 24px 0;
    padding: 20px 22px;
    background: #fffaf0;
    border: 1px solid #f0d9a8;
    border-radius: 12px;
}

.reversal-card h2 {
    margin-top: 0;
    color: #684b16;
}

.reversal-card p {
    color: #665b46;
}

.reversal-button {
    padding: 10px 16px;
    border: 0;
    border-radius: 7px;
    background: #9a6811;
    color: white;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
}

.reversal-button:hover {
    background: #79520d;
}
.button {
        display: inline-block;
        padding: 10px 16px;
        background-color: #1769aa;
        color: white;
        text-decoration: none;
        border-radius: 6px;
        font-weight: bold;
    }

    .button:hover {
        background-color: #0f4f83;
    }
    </style>
</head>
<body>
    <h1>Kanne #<?= (int) $entry['id'] ?></h1>
    <?php if(!empty($_SESSION['journal_draft_notice'])): ?><p style="padding:12px;background:#e9f6ed;color:#205c31;border-radius:7px"><?= h($_SESSION['journal_draft_notice']) ?></p><?php unset($_SESSION['journal_draft_notice']); endif; ?>

    <p><strong>Kuupäev:</strong> <?= h($entry['entry_date']) ?></p>
    <p><strong>Kirjeldus:</strong> <?= h($entry['description']) ?></p>
    <p><strong>Dokumendi nr:</strong> <?= h($entry['document_number'] ?? '') ?></p>
    <?php if($correctionSource): ?><p><strong>Parandatav dokument:</strong> <a href="entry.php?id=<?= (int)$correctionSource['id'] ?>"><?= h($correctionSource['document_number']?:('Kanne #'.$correctionSource['id'])) ?></a></p><?php endif; ?>
    <p><strong>Olek:</strong> <?= h(['draft'=>'Mustand','posted'=>'Kinnitatud','cancelled'=>'Tühistatud'][$entry['status']]??$entry['status']) ?></p>

    <h2>Kande read</h2>

    <table>
        <thead>
            <tr>
                <th>Konto</th>
                <th>Kirjeldus</th>
                <th>Deebet</th>
                <th>Kreedit</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $line): ?>
                <tr>
                    <td><?= h($line['code'] . ' — ' . $line['name']) ?></td>
                    <td><?= h($line['description'] ?? '') ?></td>
                    <td class="number"><?= number_format((float) $line['debit'], 2, ',', ' ') ?></td>
                    <td class="number"><?= number_format((float) $line['credit'], 2, ',', ' ') ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <th colspan="2">Kokku</th>
                <th class="number"><?= number_format($debitTotal, 2, ',', ' ') ?></th>
                <th class="number"><?= number_format($creditTotal, 2, ',', ' ') ?></th>
            </tr>
        </tbody>
    </table>
<?php if ($entry['status'] === 'posted' && journalBelongsToSecurityFx($pdo,(int)$_SESSION['company_id'],$entry)): ?>
    <section class="reversal-card"><a href="security_fx.php#tehingud">Vaata või tühista tehing väärtpaberiregistris</a></section>
<?php elseif ($entry['status'] === 'posted'): ?>
     <section class="reversal-card">
        <h2>Paranduskande loomine</h2>
        <p>
            Luuakse mustand vastupidiste deebet- ja kreeditsummadega.
            Algne kanne jääb alles.
        </p>

        <form
            method="post"
            action="reverse_entry.php"
            onsubmit="return confirm('Kas luua sellest kandest vastupidine mustand?')"
        >
            <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= h($_SESSION['csrf_token']) ?>"
            >
            <button class="reversal-button" type="submit">
                Loo paranduskande mustand
            </button>
        </form>
    </section>
<?php endif; ?>

<?php if ($entry['status'] === 'draft'): ?>
    <form method="post" action="post_entry.php"
          onsubmit="return confirm('Kas kinnitad selle kande? Kinnitatud kanne mõjutab pearaamatu saldosid.');">
        <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
        <input
            type="hidden"
            name="csrf_token"
            value="<?= h($_SESSION['csrf_token']) ?>"
        >
		<?php if ($entry['status'] === 'draft'): ?>
    <p><a href="edit_entry.php?id=<?= (int) $entry['id'] ?>">Muuda mustandit</a></p>
<?php endif; ?>
        <button class="button" type="submit">Kinnita kanne</button>
    </form>
<?php endif; ?>
<?php if(in_array($entry['status'],['draft','cancelled'],true) && $entry['posted_at']===null): ?>
<section style="display:flex;gap:12px;flex-wrap:wrap;margin:20px 0">
<?php if($entry['status']==='draft'): ?><form method="post" action="draft_entry_action.php" onsubmit="return confirm('Kas tühistad selle kinnitamata mustandi?')"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>"><input type="hidden" name="action" value="cancel"><button class="button" style="background:#657789;border:0;cursor:pointer">Tühista mustand</button></form><?php endif; ?>
<form method="post" action="draft_entry_action.php" onsubmit="return confirm('Kas kustutad kinnitamata mustandi ja selle read jäädavalt? Algne kanne jääb alles.')"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>"><input type="hidden" name="entry_id" value="<?= (int)$entry['id'] ?>"><input type="hidden" name="action" value="delete"><button class="button" style="background:#a83232;border:0;cursor:pointer">Kustuta mustand</button></form>
</section><?php endif; ?>
    <p><a href="index.php">Tagasi kannete nimekirja</a></p>
</body>
</html>
