<?php
requireSuperAdmin();

$tenantId = filter_input(INPUT_GET, 'tenant_id', FILTER_VALIDATE_INT);
$userId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$tenantId || !$userId) {
    http_response_code(404);
    require __DIR__ . '/../errors/404.php';
    exit;
}

$stmt = $pdo->prepare('SELECT id, name FROM tenants WHERE id = ?');
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tenant) {
    http_response_code(404);
    require __DIR__ . '/../errors/404.php';
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND tenant_id = ?');
$stmt->execute([$userId, $tenantId]);
$tenantUser = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tenantUser || !in_array($tenantUser['role'], ['admin', 'editor', 'viewer'], true)) {
    http_response_code(404);
    require __DIR__ . '/../errors/404.php';
    exit;
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $passwordConfirmation = $_POST['password_confirmation'] ?? '';

    if ($password === '') {
        $errors[] = 'A nova senha é obrigatória.';
    } elseif (strlen($password) < 8) {
        $errors[] = 'A nova senha deve ter no mínimo 8 caracteres.';
    }
    if ($password !== $passwordConfirmation) {
        $errors[] = 'A confirmação da senha não confere.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare("
            UPDATE users
            SET password = ?, updated_at = NOW()
            WHERE id = ? AND tenant_id = ? AND role IN ('admin', 'editor', 'viewer')
        ");
        $stmt->execute([
            password_hash($password, PASSWORD_DEFAULT),
            $userId,
            $tenantId,
        ]);

        if ($stmt->rowCount() !== 1) {
            $errors[] = 'Não foi possível resetar a senha deste usuário.';
        } else {
            redirectTo('system-users', ['tenant_id' => (int) $tenantId, 'success' => 'password_reset']);
        }
    }
}

$pageTitle = 'Resetar senha';
require __DIR__ . '/../../layouts/system-header.php';
require __DIR__ . '/../../layouts/system-sidebar.php';
?>

<div class="mb-4">
    <a href="system-users?tenant_id=<?= (int) $tenantId ?>" class="btn btn-outline-secondary mb-3">Voltar</a>
    <h1 class="h3 mb-1">Resetar senha de <?= htmlspecialchars($tenantUser['name']) ?></h1>
    <p class="text-muted mb-0">Empresa: <?= htmlspecialchars($tenant['name']) ?></p>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body p-4">
        <form method="post">
            <div class="row g-3">
                <div class="col-md-6"><label for="password" class="form-label">Nova senha</label><input type="password" id="password" name="password" class="form-control" minlength="8" required></div>
                <div class="col-md-6"><label for="password_confirmation" class="form-label">Confirmar nova senha</label><input type="password" id="password_confirmation" name="password_confirmation" class="form-control" minlength="8" required></div>
            </div>
            <div class="d-grid d-sm-block mt-4"><button type="submit" class="btn btn-warning">Resetar senha</button></div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../layouts/system-footer.php'; ?>
