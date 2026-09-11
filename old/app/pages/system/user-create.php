<?php
requireSuperAdmin();

$tenantId = filter_input(INPUT_GET, 'tenant_id', FILTER_VALIDATE_INT);
if (!$tenantId) {
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

$allowedRoles = ['admin', 'editor', 'viewer'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        $errors[] = 'O nome é obrigatório.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Informe um e-mail válido.';
    }
    if ($password === '') {
        $errors[] = 'A senha inicial é obrigatória.';
    }
    if (!in_array($role, $allowedRoles, true)) {
        $errors[] = 'Selecione uma role válida.';
    }

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'Este e-mail já está cadastrado.';
        }
    }

    if (!$errors) {
        try {
            $stmt = $pdo->prepare('
                INSERT INTO users (tenant_id, name, email, password, role, is_active)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                (int) $tenantId,
                $name,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $role,
                $isActive,
            ]);

            redirectTo('system-users', ['tenant_id' => (int) $tenantId, 'success' => 'user_created']);
        } catch (PDOException $e) {
            $errors[] = $e->getCode() === '23000'
                ? 'Este e-mail já está cadastrado.'
                : 'Não foi possível criar o usuário.';
        }
    }
}

$pageTitle = 'Novo usuário';
require __DIR__ . '/../../layouts/system-header.php';
require __DIR__ . '/../../layouts/system-sidebar.php';
?>

<div class="mb-4">
    <a href="system-users?tenant_id=<?= (int) $tenantId ?>" class="btn btn-outline-secondary mb-3">Voltar</a>
    <h1 class="h3 mb-1">Novo usuário</h1>
    <p class="text-muted mb-0">Empresa: <?= htmlspecialchars($tenant['name']) ?></p>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body p-4">
        <form method="post">
            <div class="row g-3">
                <div class="col-md-6"><label for="name" class="form-label">Nome</label><input id="name" name="name" class="form-control" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required></div>
                <div class="col-md-6"><label for="email" class="form-label">E-mail</label><input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required></div>
                <div class="col-md-6"><label for="password" class="form-label">Senha inicial</label><input type="password" id="password" name="password" class="form-control" required></div>
                <div class="col-md-6"><label for="role" class="form-label">Role</label><select id="role" name="role" class="form-select" required><?php foreach ($allowedRoles as $allowedRole): ?><option value="<?= $allowedRole ?>" <?= ($_POST['role'] ?? 'viewer') === $allowedRole ? 'selected' : '' ?>><?= ucfirst($allowedRole) ?></option><?php endforeach; ?></select></div>
                <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= !isset($_POST['name']) || isset($_POST['is_active']) ? 'checked' : '' ?>><label class="form-check-label" for="is_active">Usuário ativo</label></div></div>
            </div>
            <button type="submit" class="btn btn-primary mt-4">Criar usuário</button>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../layouts/system-footer.php'; ?>
