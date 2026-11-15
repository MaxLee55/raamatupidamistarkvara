<?php

$host = 'localhost';
$dbname = 'raamatupidamistarkvara';
$username = 'root';
$password = '';

$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    // Ära kuva kasutajale andmebaasi tehnilisi andmeid.
    error_log($e->getMessage());
    exit('Andmebaasiga ühendamine ebaõnnestus.');
}