<?php
requireSuperAdmin();
$pageTitle = 'Dashboard';
$totals = [
    'Empresas' => (int) $pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn(),
    'Usuários' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role <> 'super_admin'")->fetchColumn(),
    'Formulários' => (int) $pdo->query('SELECT COUNT(*) FROM forms')->fetchColumn(),
    'Respostas' => (int) $pdo->query('SELECT COUNT(*) FROM form_responses')->fetchColumn(),
];
require __DIR__ . '/../../layouts/system-header.php';
require __DIR__ . '/../../layouts/system-sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4"><div><h1 class="h3 mb-1">Visão geral</h1><p class="text-muted mb-0">Indicadores de toda a plataforma.</p></div><div class="d-flex gap-2"><a href="system-tenants" class="btn btn-outline-primary">Ver empresas</a><a href="system-tenant-create" class="btn btn-primary">Criar empresa</a></div></div>
<div class="row g-4"><?php foreach ($totals as $label => $total): ?><div class="col-md-6 col-xl-3"><div class="card shadow-sm"><div class="card-body p-4"><div class="text-muted"><?= $label ?></div><div class="display-6 fw-semibold"><?= $total ?></div></div></div></div><?php endforeach; ?></div>
<?php require __DIR__ . '/../../layouts/system-footer.php'; ?>
