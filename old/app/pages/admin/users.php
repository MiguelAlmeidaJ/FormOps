<?php

requireTenantContext();

$currentUser = user();
if (!isSuperAdmin() && ($currentUser['role'] ?? null) !== 'admin') {
    redirectTo('painel');
}

$tenantId = currentTenantIdForData();
$pageTitle = 'Usuarios';
$scopedGroupId = shouldScopeTenantUserToGroup() ? currentUserFormGroupId() : null;

$stmt = $pdo->prepare('SELECT id, name FROM form_groups WHERE tenant_id = ? ORDER BY name ASC');
$stmt->execute([$tenantId]);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

$usersSql =
    'SELECT u.id, u.name, u.email, u.role, u.is_active, u.created_at, g.name AS group_name
     FROM users u
     LEFT JOIN form_groups g ON g.id = u.form_group_id AND g.tenant_id = u.tenant_id
     WHERE u.tenant_id = ?
     ' . ($scopedGroupId ? 'AND u.form_group_id = ?' : '') . '
     ORDER BY u.created_at DESC, u.id DESC'
;
$stmt = $pdo->prepare($usersSql);
$stmt->execute($scopedGroupId ? [$tenantId, $scopedGroupId] : [$tenantId]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$successMessages = [
    'user_created' => 'Usuario criado com sucesso.',
    'user_updated' => 'Usuario atualizado com sucesso.',
    'password_reset' => 'Senha resetada com sucesso.',
];
$successMessage = $successMessages[$_GET['success'] ?? ''] ?? null;

require __DIR__ . '/../../layouts/admin-header.php';
require __DIR__ . '/../../layouts/admin-sidebar.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Usuarios</h1>
        <p class="text-muted mb-0">Gerencie os acessos da sua organização.</p>
    </div>
    <a href="<?= htmlspecialchars(appUrl('user-create')) ?>" class="btn btn-primary">Novo usuario</a>
</div>

<?php if ($successMessage): ?><div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div><?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table align-middle">
                <thead><tr><th>Nome</th><th>E-mail</th><th>Grupo</th><th>Perfil</th><th>Status</th><th>Criado em</th><th class="text-end">Ações</th></tr></thead>
                <tbody>
                    <?php foreach ($users as $tenantUser): ?>
                        <tr>
                            <td><?= htmlspecialchars($tenantUser['name']) ?></td>
                            <td><?= htmlspecialchars($tenantUser['email']) ?></td>
                            <td><?= htmlspecialchars($tenantUser['group_name'] ?: 'Todos os grupos') ?></td>
                            <td><span class="badge bg-light text-dark"><?= htmlspecialchars($tenantUser['role']) ?></span></td>
                            <td><span class="badge <?= $tenantUser['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $tenantUser['is_active'] ? 'Ativo' : 'Inativo' ?></span></td>
                            <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($tenantUser['created_at']))) ?></td>
                            <td class="text-end text-nowrap">
                                <?php if (in_array($tenantUser['role'], ['admin', 'editor', 'viewer'], true)): ?>
                                    <a href="<?= htmlspecialchars(appUrl('user-edit', ['id' => (int) $tenantUser['id']])) ?>" class="btn btn-sm btn-outline-primary">Editar</a>
                                    <a href="<?= htmlspecialchars(appUrl('user-reset-password', ['id' => (int) $tenantUser['id']])) ?>" class="btn btn-sm btn-outline-warning">Senha</a>
                                <?php else: ?>
                                    <span class="text-muted small">Conta protegida</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$users): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhum usuario cadastrado.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../layouts/admin-footer.php'; ?>
