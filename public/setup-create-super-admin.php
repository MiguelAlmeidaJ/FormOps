<?php
require_once __DIR__ . '/../app/config/database.php';

$email = 'admin@sistema.com.br';
$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->execute([$email]);

if ($stmt->fetch()) {
    $message = 'O super admin já existe. Apague este arquivo agora.';
} else {
    $stmt = $pdo->prepare("INSERT INTO users (tenant_id, name, email, password, role, is_active) VALUES (NULL, ?, ?, ?, 'super_admin', 1)");
    $stmt->execute([
        'Administrador do Sistema',
        $email,
        password_hash('S3nh@F0rte', PASSWORD_DEFAULT),
    ]);
    $message = 'Super admin criado com sucesso. Apague este arquivo agora.';
}
?>
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="assets/brand.css"></head><body class="bg-light"><main class="container min-vh-100 d-flex align-items-center justify-content-center"><div class="alert alert-warning shadow-sm"><?= htmlspecialchars($message) ?></div></main></body></html>
