<?php
requireSuperAdmin();

$tenantId = filter_input(INPUT_GET, 'tenant_id', FILTER_VALIDATE_INT);

if (!$tenantId) {
    http_response_code(404);
    require __DIR__ . '/../errors/404.php';
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM tenants WHERE id = ?');
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    http_response_code(404);
    require __DIR__ . '/../errors/404.php';
    exit;
}

$stmt = $pdo->prepare('
    SELECT id, name, email, role, is_active, created_at
    FROM users
    WHERE tenant_id = ?
    ORDER BY created_at DESC
');
$stmt->execute([$tenantId]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$successMessages = [
    'user_created' => 'Usuário criado com sucesso.',
    'user_updated' => 'Usuário atualizado com sucesso.',
    'password_reset' => 'Senha resetada com sucesso.',
];
$successMessage = $successMessages[$_GET['success'] ?? ''] ?? null;

$pageTitle = 'Usuários de ' . $tenant['name'];
require __DIR__ . '/../../layouts/system-header.php';
require __DIR__ . '/../../layouts/system-sidebar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Usuários de <?= htmlspecialchars($tenant['name']) ?></h1>
        <p class="text-muted mb-0">Gerencie os acessos vinculados a esta empresa.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="system-tenants" class="btn btn-outline-secondary">Voltar para empresas</a>
        <a href="system-user-create?tenant_id=<?= (int) $tenantId ?>" class="btn btn-primary">Novo usuário</a>
    </div>
</div>

<?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>Nome</th><th>E-mail</th><th>Role</th><th>Status</th><th>Criado em</th><th class="text-end">Ações</th></tr></thead>
                <tbody>
                <?php foreach ($users as $tenantUser): ?>
                    <tr>
                        <td><?= htmlspecialchars($tenantUser['name']) ?></td>
                        <td><?= htmlspecialchars($tenantUser['email']) ?></td>
                        <td><span class="badge bg-light text-dark"><?= htmlspecialchars($tenantUser['role']) ?></span></td>
                        <td><span class="badge <?= $tenantUser['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $tenantUser['is_active'] ? 'Ativo' : 'Inativo' ?></span></td>
                        <td><?= date('d/m/Y H:i', strtotime($tenantUser['created_at'])) ?></td>
                        <td class="text-end text-nowrap">
                            <?php if (in_array($tenantUser['role'], ['admin', 'editor', 'viewer'], true)): ?>
                                <a href="system-user-edit?id=<?= (int) $tenantUser['id'] ?>&amp;tenant_id=<?= (int) $tenantId ?>" class="btn btn-sm btn-outline-primary">Editar</a>
                                <a href="system-user-reset-password?id=<?= (int) $tenantUser['id'] ?>&amp;tenant_id=<?= (int) $tenantId ?>" class="btn btn-sm btn-outline-warning">Resetar senha</a>
                            <?php else: ?>
                                <span class="text-muted small">Conta protegida</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?><tr><td colspan="6" class="text-center text-muted py-4">Nenhum usuário cadastrado.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../layouts/system-footer.php'; ?>
