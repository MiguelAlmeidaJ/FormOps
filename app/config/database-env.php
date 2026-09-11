<?php

require_once __DIR__ . '/env.php';

loadFormOpsEnv();

$db = [
    'host' => (string) formOpsEnv('DB_HOST', 'localhost'),
    'port' => (int) formOpsEnv('DB_PORT', 3306),
    'name' => (string) formOpsEnv('DB_DATABASE', ''),
    'user' => (string) formOpsEnv('DB_USERNAME', ''),
    'secret' => (string) formOpsEnv('DB_PASSWORD', ''),
];

if ($db['name'] === '' || $db['user'] === '') {
    http_response_code(500);
    die('Configuração de banco ausente no ambiente.');
}

try {
    $pdo = new PDO(
        "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
        $db['user'],
        $db['secret'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    error_log('FormOps database connection error: ' . $e->getMessage());
    http_response_code(500);
    die('Erro ao conectar com o banco de dados.');
}
