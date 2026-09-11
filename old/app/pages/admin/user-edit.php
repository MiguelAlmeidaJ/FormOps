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

$allowedRoles = ['admin', 'editor', 'viewer'];
$errors = [];
$pageTitle = 'Editar usuario';

$stmt = $pdo->prepare('SELECT id, name FROM form_groups WHERE tenant_id = ? AND is_active = 1 ORDER BY name ASC');
$stmt->execute([$tenantId]);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
$groupsById = [];
foreach ($groups as $group) {
    $groupsById[(int) $group['id']] = $group;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'viewer';
    $groupId = filter_input(INPUT_POST, 'form_group_id', FILTER_VALIDATE_INT) ?: null;
    if ($scopedGroupId) {
        $groupId = $scopedGroupId;
    }
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') $errors[] = 'O nome e obrigatorio.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Informe um e-mail valido.';
    if (!in_array($role, $allowedRoles, true)) $errors[] = 'Selecione um perfil valido.';
    if ($groupId && !isset($groupsById[$groupId])) $errors[] = 'Grupo invalido.';

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
        $stmt->execute([$email, $userId]);
        if ($stmt->fetch()) $errors[] = 'Este e-mail já está cadastrado.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ?, form_group_id = ?, is_active = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ? AND role IN ('admin', 'editor', 'viewer')");
        $stmt->execute([$name, $email, $role, $groupId, $isActive, $userId, $tenantId]);
        redirectTo('users', ['success' => 'user_updated']);
    }
}

$fieldName = $_POST['name'] ?? $tenantUser['name'];
$fieldEmail = $_POST['email'] ?? $tenantUser['email'];
$fieldRole = $_POST['role'] ?? $tenantUser['role'];
$fieldGroupId = filter_input(INPUT_POST, 'form_group_id', FILTER_VALIDATE_INT);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $fieldGroupId = (int) ($tenantUser['form_group_id'] ?? 0);
}
$fieldActive = $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['is_active']) : (bool) $tenantUser['is_active'];

require __DIR__ . '/../../layouts/admin-header.php';
require __DIR__ . '/../../layouts/admin-sidebar.php';
?>

<div class="mb-4">
    <a href="<?= htmlspecialchars(appUrl('users')) ?>" class="btn btn-outline-secondary mb-3">Voltar</a>
    <h1 class="h3 mb-1">Editar usuario</h1>
    <p class="text-muted mb-0"><?= htmlspecialchars($tenantUser['email']) ?></p>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="card shadow-sm border-0"><div class="card-body p-4">
    <form method="post">
        <div class="row g-3">
            <div class="col-md-6"><label for="name" class="form-label">Nome</label><input id="name" name="name" class="form-control" value="<?= htmlspecialchars($fieldName) ?>" required></div>
            <div class="col-md-6"><label for="email" class="form-label">E-mail</label><input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($fieldEmail) ?>" required></div>
            <div class="col-md-6"><label for="role" class="form-label">Perfil</label><select id="role" name="role" class="form-select" required><?php foreach ($allowedRoles as $allowedRole): ?><option value="<?= $allowedRole ?>" <?= $fieldRole === $allowedRole ? 'selected' : '' ?>><?= ucfirst($allowedRole) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label for="form_group_id" class="form-label">Grupo</label><select id="form_group_id" name="form_group_id" class="form-select" <?= $scopedGroupId ? 'disabled' : '' ?>><option value="">Todos os grupos</option><?php foreach ($groups as $group): ?><option value="<?= (int) $group['id'] ?>" <?= (int) $fieldGroupId === (int) $group['id'] ? 'selected' : '' ?>><?= htmlspecialchars($group['name']) ?></option><?php endforeach; ?></select><?php if ($scopedGroupId): ?><input type="hidden" name="form_group_id" value="<?= (int) $scopedGroupId ?>"><?php endif; ?></div>
            <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= $fieldActive ? 'checked' : '' ?>><label class="form-check-label" for="is_active">Usuario ativo</label></div></div>
        </div>
        <button type="submit" class="btn btn-primary mt-4">Salvar alteracoes</button>
    </form>
</div></div>

<?php require __DIR__ . '/../../layouts/admin-footer.php'; ?>
