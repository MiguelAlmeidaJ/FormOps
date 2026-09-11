<?php

requireTenantContext();

$currentUser = user();
if (!isSuperAdmin() && ($currentUser['role'] ?? null) !== 'admin') {
    redirectTo('painel');
}

$tenantId = currentTenantIdForData();
$scopedGroupId = shouldScopeTenantUserToGroup() ? currentUserFormGroupId() : null;
$userId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$userId) redirectTo('users');

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND tenant_id = ? LIMIT 1');
$stmt->execute([$userId, $tenantId]);
$tenantUser = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tenantUser || !in_array($tenantUser['role'], ['admin', 'editor', 'viewer'], true)) {
    redirectTo('users');
}
if ($scopedGroupId && (int) ($tenantUser['form_group_id'] ?? 0) !== (int) $scopedGroupId) {
    redirectTo('users');
}

$errors = [];
$pageTitle = 'Resetar senha';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirmation = $_POST['password_confirmation'] ?? '';

    if (strlen($password) < 8) $errors[] = 'A nova senha deve ter pelo menos 8 caracteres.';
    if ($password !== $confirmation) $errors[] = 'A confirmação da senha não confere.';

    if (!$errors) {
        $stmt = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ? AND role IN ('admin', 'editor', 'viewer')");
        $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId, $tenantId]);
        redirectTo('users', ['success' => 'password_reset']);
    }
}

require __DIR__ . '/../../layouts/admin-header.php';
require __DIR__ . '/../../layouts/admin-sidebar.php';
?>

<div class="mb-4">
    <a href="<?= htmlspecialchars(appUrl('users')) ?>" class="btn btn-outline-secondary mb-3">Voltar</a>
    <h1 class="h3 mb-1">Resetar senha</h1>
    <p class="text-muted mb-0"><?= htmlspecialchars($tenantUser['name']) ?></p>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="card shadow-sm border-0"><div class="card-body p-4">
    <form method="post">
        <div class="row g-3">
            <div class="col-md-6"><label for="password" class="form-label">Nova senha</label><input type="password" id="password" name="password" class="form-control" minlength="8" required></div>
            <div class="col-md-6"><label for="password_confirmation" class="form-label">Confirmar nova senha</label><input type="password" id="password_confirmation" name="password_confirmation" class="form-control" minlength="8" required></div>
        </div>
        <button type="submit" class="btn btn-warning mt-4">Resetar senha</button>
    </form>
</div></div>

<?php require __DIR__ . '/../../layouts/admin-footer.php'; ?>
