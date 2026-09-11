<?php

if (isset($_SESSION['user'])) {
    redirectTo(isSuperAdmin() ? 'system-dashboard' : 'painel');
}

$email = mb_strtolower(trim((string) ($_SESSION['password_reset_email'] ?? '')), 'UTF-8');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirectTo('recuperar-acesso');
}

$errors = [];
$notice = $_SESSION['password_reset_notice'] ?? null;
unset($_SESSION['password_reset_notice']);
$csrfToken = $_SESSION['password_reset_confirm_csrf'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = normalizedPasswordResetCode((string) ($_POST['code'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    $postedCsrf = (string) ($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $postedCsrf)) {
        $errors[] = 'A sessão expirou. Atualize a página e tente novamente.';
    }
    if (!preg_match('/^[A-Z0-9]{8}$/', $code)) {
        $errors[] = 'Informe o código de 8 caracteres recebido por e-mail.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'A nova senha deve ter pelo menos 8 caracteres.';
    }
    if ($password !== $confirmation) {
        $errors[] = 'A confirmação da senha não confere.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'SELECT u.id
             FROM users u
             LEFT JOIN tenants t ON t.id = u.tenant_id
             WHERE LOWER(u.email) = LOWER(?) AND u.is_active = 1
               AND (u.role = \'super_admin\' OR t.is_active = 1)
             LIMIT 1'
        );
        $stmt->execute([$email]);
        $userId = (int) ($stmt->fetchColumn() ?: 0);

        try {
            $pdo->beginTransaction();
            $reset = null;
            if ($userId) {
                $stmt = $pdo->prepare(
                    'SELECT * FROM password_reset_codes
                     WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW() AND attempts < 5
                     ORDER BY id DESC LIMIT 1 FOR UPDATE'
                );
                $stmt->execute([$userId]);
                $reset = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }

            if (!$reset || !password_verify($code, (string) $reset['code_hash'])) {
                if ($reset) {
                    $stmt = $pdo->prepare('UPDATE password_reset_codes SET attempts = attempts + 1 WHERE id = ?');
                    $stmt->execute([(int) $reset['id']]);
                }
                $pdo->commit();
                usleep(random_int(120000, 220000));
                $errors[] = 'Código inválido, expirado ou com limite de tentativas excedido.';
            } else {
                $stmt = $pdo->prepare('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?');
                $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
                $stmt = $pdo->prepare('UPDATE password_reset_codes SET used_at = NOW() WHERE id = ?');
                $stmt->execute([(int) $reset['id']]);
                $stmt = $pdo->prepare('DELETE FROM password_reset_codes WHERE user_id = ? AND id <> ?');
                $stmt->execute([$userId, (int) $reset['id']]);
                $pdo->commit();

                unset($_SESSION['password_reset_email'], $_SESSION['password_reset_confirm_csrf']);
                session_regenerate_id(true);
                $_SESSION['login_success'] = 'Senha alterada com sucesso. Entre com sua nova senha.';
                redirectTo('login');
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('FormOps password reset confirmation error: ' . $exception->getMessage());
            $errors[] = 'Não foi possível redefinir sua senha. Tente novamente.';
        }
    }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Criar nova senha · FormOps</title>
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
                <h1 class="h4 mb-2">Criar nova senha</h1>
                <p class="small text-muted mb-0">Código enviado para <?= htmlspecialchars(maskedEmailAddress($email)) ?></p>
            </div>

            <?php if ($notice): ?><div class="alert alert-info"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
            <?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div><?php endif; ?>

            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="mb-3">
                    <label class="form-label" for="code">Código de 8 caracteres</label>
                    <input class="form-control formops-reset-code" id="code" name="code" value="<?= htmlspecialchars($_POST['code'] ?? '') ?>" maxlength="8" pattern="[A-Za-z0-9]{8}" placeholder="AB12CD34" autocomplete="one-time-code" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Nova senha</label>
                    <input class="form-control" type="password" id="password" name="password" minlength="8" autocomplete="new-password" required>
                </div>
                <div class="mb-4">
                    <label class="form-label" for="password_confirmation">Confirmar nova senha</label>
                    <input class="form-control" type="password" id="password_confirmation" name="password_confirmation" minlength="8" autocomplete="new-password" required>
                </div>
                <button class="btn btn-primary w-100" type="submit">Salvar nova senha</button>
                <a class="btn btn-link w-100 mt-2 text-decoration-none" href="<?= htmlspecialchars(appUrl('recuperar-acesso')) ?>">Enviar outro código</a>
            </form>
        </div>
    </section>
</main>
</body>
</html>

