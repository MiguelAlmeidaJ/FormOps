<?php
requireSuperAdmin();
$pageTitle = 'Empresas';
$tenants = $pdo->query("SELECT t.*, (SELECT COUNT(*) FROM forms f WHERE f.tenant_id=t.id) total_forms, (SELECT COUNT(*) FROM form_responses r WHERE r.tenant_id=t.id) total_responses FROM tenants t ORDER BY t.name")->fetchAll(PDO::FETCH_ASSOC);
require __DIR__ . '/../../layouts/system-header.php';
require __DIR__ . '/../../layouts/system-sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4"><div><h1 class="h3 mb-1">Empresas</h1><p class="text-muted mb-0">Gerencie os tenants da plataforma.</p></div><a href="system-tenant-create" class="btn btn-primary">Criar empresa</a></div>
<div class="card shadow-sm"><div class="card-body"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Nome</th><th>Slug</th><th>E-mail</th><th>Status</th><th>Formulários</th><th>Respostas</th><th class="text-end">Ações</th></tr></thead><tbody>
<?php foreach ($tenants as $tenant): ?><tr><td><?= htmlspecialchars($tenant['name']) ?></td><td><code><?= htmlspecialchars($tenant['slug']) ?></code></td><td><?= htmlspecialchars($tenant['email'] ?? '-') ?></td><td><span class="badge <?= $tenant['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $tenant['is_active'] ? 'Ativo' : 'Inativo' ?></span></td><td><?= (int)$tenant['total_forms'] ?></td><td><?= (int)$tenant['total_responses'] ?></td><td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="system-tenant-edit?id=<?= (int)$tenant['id'] ?>">Editar</a> <a class="btn btn-sm btn-outline-secondary" href="system-users?tenant_id=<?= (int)$tenant['id'] ?>">Usuários</a> <a class="btn btn-sm btn-outline-dark" href="system-tenant-forms?tenant_id=<?= (int)$tenant['id'] ?>">Ver formulários</a> <a class="btn btn-sm btn-warning" href="system-maintenance-start?tenant_id=<?= (int)$tenant['id'] ?>">Entrar em manutenção</a></td></tr><?php endforeach; ?>
<?php if (!$tenants): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhuma empresa cadastrada.</td></tr><?php endif; ?></tbody></table></div></div></div>
<?php require __DIR__ . '/../../layouts/system-footer.php'; ?>
