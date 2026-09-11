<?php

if (isset($_SESSION['user'])) {
    redirectTo(isSuperAdmin() ? 'system-dashboard' : 'painel');
}

$errors = [];
$csrfToken = $_SESSION['password_reset_request_csrf'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')), 'UTF-8');
    $postedCsrf = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $postedCsrf)) {
        $errors[] = 'A sessão expirou. Atualize a página e tente novamente.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Informe um e-mail válido.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'SELECT u.*, t.name AS tenant_name, t.email AS tenant_email,
                    t.is_active AS tenant_is_active, t.primary_color
             FROM users u
             LEFT JOIN tenants t ON t.id = u.tenant_id
             WHERE LOWER(u.email) = LOWER(?) LIMIT 1'
        );
        $stmt->execute([$email]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $eligible = $account
            && (int) $account['is_active'] === 1
            && ($account['role'] === 'super_admin' || ((int) ($account['tenant_is_active'] ?? 0) === 1));

        if ($eligible) {
            $stmt = $pdo->prepare('SELECT created_at FROM password_reset_codes WHERE user_id = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([(int) $account['id']]);
            $lastRequest = $stmt->fetchColumn();
            $canSend = !$lastRequest || strtotime((string) $lastRequest) <= time() - 60;

            if ($canSend) {
                $code = passwordResetCode();
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare('DELETE FROM password_reset_codes WHERE user_id = ?');
                    $stmt->execute([(int) $account['id']]);
                    $stmt = $pdo->prepare(
                        'INSERT INTO password_reset_codes (user_id, code_hash, expires_at, requested_ip)
                         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), ?)'
                    );
                    $stmt->execute([
                        (int) $account['id'],
                        password_hash($code, PASSWORD_DEFAULT),
                        substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
                    ]);
                    $resetId = (int) $pdo->lastInsertId();
                    $pdo->commit();

                    if (!sendPasswordResetCodeEmail($account, $code)) {
                        $stmt = $pdo->prepare('DELETE FROM password_reset_codes WHERE id = ?');
                        $stmt->execute([$resetId]);
                        error_log('FormOps password reset email was not accepted by SMTP for user #' . (int) $account['id']);
                    }
                } catch (Throwable $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('FormOps password reset request error: ' . $exception->getMessage());
                }
            }
        }

        usleep(random_int(120000, 220000));
        $_SESSION['password_reset_email'] = $email;
        $_SESSION['password_reset_notice'] = 'Se o e-mail estiver cadastrado e ativo, enviaremos um código de 8 caracteres. Verifique também a caixa de spam.';
        redirectTo('redefinir-senha');
    }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recuperar acesso · FormOps</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="assets/clients/formops/favicon-formops.png">
    <link rel="stylesheet" href="assets/brand.css">
</head>
<body class="formops-login-shell">
<main class="container min-vh-100 d-flex align-items-center justify-content-center py-5">
    <section class="card formops-login-card">
        <div class="card-body p-4 p-md-5">
            <div class="text-center mb-4">
                <img class="formops-logo formops-login-logo" src="assets/clients/formops/logo-formops.png" alt="FormOps">
                <h1 class="h4 mb-2">Recuperar acesso</h1>
                <p class="small text-muted mb-0">Informe o e-mail cadastrado para receber seu código.</p>
            </div>

            <?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div><?php endif; ?>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="mb-4">
                    <label class="form-label" for="email">E-mail</label>
                    <input class="form-control" type="email" id="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" placeholder="seuemail@exemplo.com" autocomplete="email" required autofocus>
                </div>
                <button class="btn btn-primary w-100" type="submit">Enviar código de acesso</button>
                <a class="btn btn-link w-100 mt-2 text-decoration-none" href="<?= htmlspecialchars(appUrl('login')) ?>">Voltar para o login</a>
            </form>
        </div>
    </section>
</main>
</body>
</html>

