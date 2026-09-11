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

$allowedRoles = ['admin', 'editor', 'viewer'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? '';
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        $errors[] = 'O nome é obrigatório.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Informe um e-mail válido.';
    }
    if (!in_array($role, $allowedRoles, true)) {
        $errors[] = 'Selecione uma role válida.';
    }

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
        $stmt->execute([$email, $userId]);
        if ($stmt->fetch()) {
            $errors[] = 'Este e-mail já está cadastrado.';
        }
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare("
                UPDATE users
                SET name = ?, email = ?, role = ?, is_active = ?, updated_at = NOW()
                WHERE id = ? AND tenant_id = ? AND role IN ('admin', 'editor', 'viewer')
            ");
            $stmt->execute([$name, $email, $role, $isActive, $userId, $tenantId]);

            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare("SELECT id FROM users WHERE id = ? AND tenant_id = ? AND role IN ('admin', 'editor', 'viewer')");
                $check->execute([$userId, $tenantId]);
                if (!$check->fetch()) {
                    throw new RuntimeException('Usuário não editável.');
                }
            }

            redirectTo('system-users', ['tenant_id' => (int) $tenantId, 'success' => 'user_updated']);
        } catch (Throwable $e) {
            $errors[] = $e instanceof PDOException && $e->getCode() === '23000'
                ? 'Este e-mail já está cadastrado.'
                : 'Não foi possível atualizar o usuário.';
        }
    }
}

$fieldName = $_POST['name'] ?? $tenantUser['name'];
$fieldEmail = $_POST['email'] ?? $tenantUser['email'];
$fieldRole = $_POST['role'] ?? $tenantUser['role'];
$fieldActive = $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['is_active']) : (bool) $tenantUser['is_active'];

$pageTitle = 'Editar usuário';
require __DIR__ . '/../../layouts/system-header.php';
require __DIR__ . '/../../layouts/system-sidebar.php';
?>

<div class="mb-4"><a href="system-users?tenant_id=<?= (int) $tenantId ?>" class="btn btn-outline-secondary mb-3">Voltar</a><h1 class="h3 mb-1">Editar usuário</h1><p class="text-muted mb-0">Empresa: <?= htmlspecialchars($tenant['name']) ?></p></div>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div><?php endif; ?>
<div class="card shadow-sm"><div class="card-body p-4"><form method="post"><div class="row g-3">
    <div class="col-md-6"><label for="name" class="form-label">Nome</label><input id="name" name="name" class="form-control" value="<?= htmlspecialchars($fieldName) ?>" required></div>
    <div class="col-md-6"><label for="email" class="form-label">E-mail</label><input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($fieldEmail) ?>" required></div>
    <div class="col-md-6"><label for="role" class="form-label">Role</label><select id="role" name="role" class="form-select" required><?php foreach ($allowedRoles as $allowedRole): ?><option value="<?= $allowedRole ?>" <?= $fieldRole === $allowedRole ? 'selected' : '' ?>><?= ucfirst($allowedRole) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-6 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= $fieldActive ? 'checked' : '' ?>><label class="form-check-label" for="is_active">Usuário ativo</label></div></div>
</div><div class="d-grid d-sm-block mt-4"><button type="submit" class="btn btn-primary">Salvar alterações</button></div></form></div></div>
<?php require __DIR__ . '/../../layouts/system-footer.php'; ?>
