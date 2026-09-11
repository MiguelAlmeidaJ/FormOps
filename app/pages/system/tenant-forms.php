<?php
requireSuperAdmin();
$tenantId=filter_input(INPUT_GET,'tenant_id',FILTER_VALIDATE_INT);
$stmt=$pdo->prepare('SELECT id,name,slug FROM tenants WHERE id=?');$stmt->execute([$tenantId]);$tenant=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$tenant){http_response_code(404);require __DIR__.'/../errors/404.php';exit;}
$stmt=$pdo->prepare('SELECT f.*, (SELECT COUNT(*) FROM form_responses r WHERE r.tenant_id=f.tenant_id AND r.form_id=f.id) total_responses FROM forms f WHERE f.tenant_id=? ORDER BY f.created_at DESC');$stmt->execute([$tenantId]);$forms=$stmt->fetchAll(PDO::FETCH_ASSOC);
$pageTitle='Formulários de '.$tenant['name'];require __DIR__.'/../../layouts/system-header.php';require __DIR__.'/../../layouts/system-sidebar.php';
?>
<div class="mb-4"><a href="system-tenants" class="text-decoration-none">← Voltar</a><h1 class="h3 mt-3 mb-1">Formulários de <?= htmlspecialchars($tenant['name']) ?></h1></div>
<div class="card shadow-sm"><div class="card-body"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Título</th><th>Slug</th><th>Status</th><th>Respostas</th><th class="text-end">Ações</th></tr></thead><tbody><?php foreach($forms as $form): ?><tr><td><?= htmlspecialchars($form['title']) ?></td><td><code><?= htmlspecialchars($form['slug']) ?></code></td><td><?= $form['is_active']?'Ativo':'Inativo' ?></td><td><?= (int)$form['total_responses'] ?></td><td class="text-end"><a class="btn btn-sm btn-outline-success" target="_blank" href="<?= htmlspecialchars(appUrl('f/' . rawurlencode($tenant['slug']) . '/' . rawurlencode($form['slug']))) ?>">Abrir público</a> <a class="btn btn-sm btn-outline-dark" href="system-tenant-responses?tenant_id=<?= $tenantId ?>&form_id=<?= (int)$form['id'] ?>">Respostas</a> <a class="btn btn-sm btn-warning" href="system-maintenance-start?tenant_id=<?= $tenantId ?>&redirect=forms">Editar em manutenção</a></td></tr><?php endforeach; ?><?php if(!$forms): ?><tr><td colspan="5" class="text-center text-muted py-4">Nenhum formulário.</td></tr><?php endif; ?></tbody></table></div></div></div>
<?php require __DIR__.'/../../layouts/system-footer.php'; ?>
