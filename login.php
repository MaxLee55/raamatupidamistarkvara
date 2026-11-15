<?php
session_start();
require_once __DIR__ . '/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare(
        'SELECT id, company_id, email, password_hash, role
         FROM users
         WHERE email = :email
         LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['company_id'] = $user['company_id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];

        header('Location: index.php');
        exit;
    }

    $error = 'E-posti aadress või parool on vale.';
}
?>
<!doctype html>
<html lang="et">
<head>
    <link rel="icon" type="image/png" href="favicon.png?v=1">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sisselogimine | Raamatupidamine</title>
    <style>
        * { box-sizing: border-box; }

        body {
            display: grid;
            min-height: 100vh;
            place-items: center;
            margin: 0;
            padding: 24px;
            background:
                radial-gradient(circle at top left, #e4eef8 0, transparent 38%),
                #f4f7fb;
            color: #243447;
            font-family: Arial, sans-serif;
        }

        .login-card {
            width: 100%;
            max-width: 420px;
            padding: 36px;
            background: white;
            border: 1px solid #dce3ec;
            border-radius: 16px;
            box-shadow: 0 14px 40px rgba(23, 50, 77, .10);
        }

        .brand-mark {
            display: grid;
            width: 200px;
            height: 48px;
            margin-bottom: 22px;
            place-items: center;
            border-radius: 13px;
            background: #1769aa;
            color: white;
            font-size: 23px;
            font-weight: 700;
        }
		
		.brand-mark2 {
            
            width: 400px;
            height: 68px;
            
            font-size: 33px;
            font-weight: 700;
        }
		

        h1 {
            margin: 0 0 8px;
            color: #17324d;
            font-size: 27px;
        }

        .subtitle {
            margin: 0 0 26px;
            color: #68798b;
            line-height: 1.5;
        }

        .login-field {
            display: block;
            margin: 18px 0;
            color: #33475b;
            font-size: 14px;
            font-weight: 600;
        }

        .login-field input {
            display: block;
            width: 100%;
            min-height: 46px;
            margin-top: 7px;
            padding: 11px 13px;
            border: 1px solid #b8c4d1;
            border-radius: 8px;
            background: #fff;
            color: #243447;
            font: inherit;
        }

        .login-field input:focus {
            outline: 3px solid #d7e9f8;
            border-color: #1769aa;
        }

        .login-button {
            width: 100%;
            min-height: 46px;
            margin-top: 8px;
            border: 0;
            border-radius: 8px;
            background: #1769aa;
            color: white;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
        }

        .login-button:hover { background: #0f4f83; }

        .login-error {
            margin: 0 0 18px;
            padding: 11px 13px;
            border: 1px solid #e5a1a1;
            border-radius: 8px;
            background: #fdecec;
            color: #a32323;
            font-size: 14px;
        }

        @media (max-width: 480px) {
            .login-card { padding: 28px 22px; }
        }
    </style>
</head>
<body>
    <main class="login-card">
        <!--<div class="brand-mark" aria-hidden="true">MLM-Tech</div><img src="favicon.png?v=1" alt="" width="50" height="50">
		</div>-->
		
		<div class="brand-mark2" aria-hidden="true" style="display: flex;align-items: center; gap: 50px; margin-left:40px; ">
           <span>MLM-Tech</span>
           <img src="favicon.png?v=1" alt="Logo" width="60">
		</div>
		
        <h1 style="color:white">Raamatupidamine</h1>
        <p class="subtitle">Logi sisse oma kontole.</p>

        <?php if ($error !== ''): ?>
            <p class="login-error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php endif; ?>

        <form method="post">
            <label class="login-field">
                E-posti aadress
                <input
                    type="email"
                    name="email"
                    autocomplete="username"
                    required
                >
            </label>

            <label class="login-field">
                Parool
                <input
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    required
                >
            </label>

            <button class="login-button" type="submit">Logi sisse</button>
        </form>
    </main>
</body>
</html>