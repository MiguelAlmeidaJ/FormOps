<?php

// $host = 'localhost';
// $dbname = 'forms_church';
// $user = 'root';
// $password = '';

$host = 'localhost';
$dbname = 'anoa0880_formops';
$user = 'anoa0880_formops';
$password = 'M8PAp2D7WMfggBTRZ5wP';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $user,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro ao conectar com o banco de dados: " . $e->getMessage());
}