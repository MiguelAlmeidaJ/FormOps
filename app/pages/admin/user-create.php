<?php

requireTenantContext();

$currentUser = user();
if (!isSuperAdmin() && ($currentUser['role'] ?? null) !== 'admin') {
    redirectTo('painel');
}

redirectTo('users', ['modal' => 'create']);

$tenantId = currentTenantIdForData();
$scopedGroupId = shouldScopeTenantUserToGroup() ? currentUserFormGroupId() : null;
$allowedRoles = ['admin', 'editor', 'viewer'];
$errors = [];
$pageTitle = 'Novo usuario';

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
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'viewer';
    $groupId = filter_input(INPUT_POST, 'form_group_id', FILTER_VALIDATE_INT) ?: null;
    if ($scopedGroupId) {
        $groupId = $scopedGroupId;
    }
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') $errors[] = 'O nome e obrigatorio.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Informe um e-mail valido.';
    if (strlen($password) < 8) $errors[] = 'A senha inicial deve ter pelo menos 8 caracteres.';
    if (!in_array($role, $allowedRoles, true)) $errors[] = 'Selecione um perfil valido.';
    if ($groupId && !isset($groupsById[$groupId])) $errors[] = 'Grupo invalido.';

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) $errors[] = 'Este e-mail já está cadastrado.';
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare('INSERT INTO users (tenant_id, form_group_id, name, email, password, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$tenantId, $groupId, $name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $isActive]);
            redirectTo('users', ['success' => 'user_created']);
        } catch (Throwable $exception) {
            $errors[] = 'Não foi possível criar o usuário.';
        }
    }
}

require __DIR__ . '/../../layouts/admin-header.php';
require __DIR__ . '/../../layouts/admin-sidebar.php';
?>

<div class="mb-4">
    <a href="<?= htmlspecialchars(appUrl('users')) ?>" class="btn btn-outline-secondary mb-3">Voltar</a>
    <h1 class="h3 mb-1">Novo usuario</h1>
    <p class="text-muted mb-0">Crie um acesso para sua organização.</p>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="card shadow-sm border-0"><div class="card-body p-4">
    <form method="post">
        <div class="row g-3">
            <div class="col-md-6"><label for="name" class="form-label">Nome</label><input id="name" name="name" class="form-control" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required></div>
            <div class="col-md-6"><label for="email" class="form-label">E-mail</label><input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required></div>
            <div class="col-md-6"><label for="password" class="form-label">Senha inicial</label><input type="password" id="password" name="password" class="form-control" minlength="8" required></div>
            <div class="col-md-3"><label for="role" class="form-label">Perfil</label><select id="role" name="role" class="form-select" required><?php foreach ($allowedRoles as $allowedRole): ?><option value="<?= $allowedRole ?>" <?= ($_POST['role'] ?? 'viewer') === $allowedRole ? 'selected' : '' ?>><?= ucfirst($allowedRole) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label for="form_group_id" class="form-label">Grupo</label><select id="form_group_id" name="form_group_id" class="form-select" <?= $scopedGroupId ? 'disabled' : '' ?>><option value="">Todos os grupos</option><?php $selectedGroup = $scopedGroupId ?: (filter_input(INPUT_POST, 'form_group_id', FILTER_VALIDATE_INT) ?: null); foreach ($groups as $group): ?><option value="<?= (int) $group['id'] ?>" <?= (int) $selectedGroup === (int) $group['id'] ? 'selected' : '' ?>><?= htmlspecialchars($group['name']) ?></option><?php endforeach; ?></select><?php if ($scopedGroupId): ?><input type="hidden" name="form_group_id" value="<?= (int) $scopedGroupId ?>"><?php endif; ?></div>
            <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= !isset($_POST['name']) || isset($_POST['is_active']) ? 'checked' : '' ?>><label class="form-check-label" for="is_active">Usuario ativo</label></div></div>
        </div>
        <button type="submit" class="btn btn-primary mt-4">Criar usuario</button>
    </form>
</div></div>

<?php require __DIR__ . '/../../layouts/admin-footer.php'; ?>
