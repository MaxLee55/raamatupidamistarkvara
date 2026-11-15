<?php

$email = '';
$password = '';
$companyId = 1; // Asenda companies tabelis oleva ettevõtte ID-ga

if ($email === '' || $password === '') exit('Enne kasutaja loomist sisesta sellesse faili uue kasutaja e-post ja parool. Pärast kasutamist eemalda fail veebikaustast.');
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

$pdo = new PDO(
    'mysql:host=localhost;dbname=raamatupidamine;charset=utf8mb4',
    'root',
    '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$sql = 'INSERT INTO users (company_id, email, password_hash, role)
        VALUES (:company_id, :email, :password_hash, :role)';

$stmt = $pdo->prepare($sql);
$stmt->execute([
    'company_id' => $companyId,
    'email' => $email,
    'password_hash' => $passwordHash,
    'role' => 'admin',
]);

echo 'Kasutaja lisati.';