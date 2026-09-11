<?php

if (isset($_SESSION['user'])) {
    redirectTo(isSuperAdmin() ? 'system-dashboard' : 'painel');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '') {
        $errors[] = 'Informe seu e-mail.';
    }

    if ($password === '') {
        $errors[] = 'Informe sua senha.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            SELECT 
                users.*,
                tenants.name AS tenant_name,
                tenants.slug AS tenant_slug,
                tenants.is_active AS tenant_is_active,
                tenants.primary_color,
                tenants.secondary_color
            FROM users
            LEFT JOIN tenants ON tenants.id = users.tenant_id
            WHERE users.email = ?
            LIMIT 1
        ");

        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password'])) {
            $errors[] = 'E-mail ou senha inválidos.';
        } elseif (!$user['is_active']) {
            $errors[] = 'Este usuário está inativo.';
        } elseif ($user['role'] !== 'super_admin' && (!$user['tenant_id'] || !$user['tenant_name'] || !$user['tenant_is_active'])) {
            $errors[] = 'A empresa vinculada a este usuário não está disponível.';
        } else {
            session_regenerate_id(true);
            clearMaintenanceTenant();
            $_SESSION['user'] = [
                'id' => $user['id'],
                'tenant_id' => $user['tenant_id'],
                'tenant_name' => $user['tenant_name'],
                'tenant_slug' => $user['tenant_slug'],
                'form_group_id' => $user['form_group_id'] ?? null,
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'primary_color' => $user['primary_color'],
                'secondary_color' => $user['secondary_color']
            ];

            redirectTo($user['role'] === 'super_admin' ? 'system-dashboard' : 'painel');
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Entrar · FormOps</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link 
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" 
        rel="stylesheet"
    >
    <link rel="icon" href="assets/clients/formops/favicon-formops.jpg">
    <link rel="stylesheet" href="assets/brand.css">
</head>
<body class="formops-login-shell">

<div class="container min-vh-100 d-flex align-items-center justify-content-center py-5">

    <div class="card formops-login-card">
        <div class="card-body p-4 p-md-5">

            <div class="text-center mb-4">
                <img class="formops-logo formops-login-logo" src="assets/clients/formops/logo-formops.png" alt="FormOps">
                <p class="formops-tagline mb-0">
                    Crie. Organize. Colete.
                </p>
                <p class="small text-muted mb-0">
                    Sua operação de formulários em um só lugar.
                </p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST">

                <div class="mb-3">
                    <label for="email" class="form-label">E-mail</label>
                    <input 
                        type="email" 
                        name="email" 
                        id="email" 
                        class="form-control"
                        placeholder="seuemail@exemplo.com"
                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label">Senha</label>
                    <input 
                        type="password" 
                        name="password" 
                        id="password" 
                        class="form-control"
                        placeholder="Digite sua senha"
                        required
                    >
                </div>

                <button type="submit" class="btn btn-primary w-100">
                    Entrar
                </button>

            </form>

        </div>
    </div>

</div>

</body>
</html>


