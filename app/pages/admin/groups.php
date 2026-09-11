<?php
requireTenantContext();

$tenantId = currentTenantIdForData();
$currentUser = user();
$pageTitle = 'Grupos';
$scopedGroupIds = shouldScopeTenantUserToGroup() ? currentUserFormGroupIds() : [];

$sql = "
    SELECT
        g.*,
        COUNT(DISTINCT f.id) AS total_forms,
        COUNT(DISTINCT r.id) AS total_responses
    FROM form_groups g
    LEFT JOIN forms f
        ON f.form_group_id = g.id
       AND f.tenant_id = g.tenant_id
    LEFT JOIN form_responses r
        ON r.form_id = f.id
       AND r.tenant_id = g.tenant_id
    WHERE g.tenant_id = ?
    " . ($scopedGroupIds ? 'AND ' . currentUserFormGroupScopeSql('g.id') : '') . "
    GROUP BY g.id
    ORDER BY g.name ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($scopedGroupIds ? array_merge([$tenantId], $scopedGroupIds) : [$tenantId]);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/../../layouts/admin-header.php';
require __DIR__ . '/../../layouts/admin-sidebar.php';
?>

<div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-end gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Grupos</h1>
        <p class="text-muted mb-0">Organize formulários por área, departamento, ministério ou pasta.</p>
    </div>

    <?php if (canManageTenantData() && !$scopedGroupIds): ?>
        <a href="<?= htmlspecialchars(appUrl('group-create')) ?>" class="btn btn-primary">Novo grupo</a>
    <?php endif; ?>
</div>

<?php if (isset($_GET['success'])): ?>
    <?php
    $messages = [
        'created' => 'Grupo criado com sucesso.',
        'updated' => 'Grupo atualizado com sucesso.',
    ];
    ?>
    <?php if (isset($messages[$_GET['success']])): ?>
        <div class="alert alert-success"><?= htmlspecialchars($messages[$_GET['success']]) ?></div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($groups): ?>
    <div class="row g-3">
        <?php foreach ($groups as $group): ?>
            <div class="col-md-6 col-xl-4">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                            <div class="d-flex align-items-center gap-3">
                                <span class="rounded-circle d-inline-flex align-items-center justify-content-center text-white fw-semibold"
                                      style="width:42px;height:42px;background:<?= htmlspecialchars($group['color'] ?: '#212121') ?>">
                                    <?= htmlspecialchars(mb_strtoupper(mb_substr($group['icon'] ?: $group['name'], 0, 1, 'UTF-8'), 'UTF-8')) ?>
                                </span>
                                <div>
                                    <h2 class="h5 mb-1"><?= htmlspecialchars($group['name']) ?></h2>
                                    <div class="small text-muted">/<?= htmlspecialchars($group['slug']) ?></div>
                                </div>
                            </div>

                            <?php if ((int) ($group['is_active'] ?? 1) === 1): ?>
                                <span class="badge bg-success">Ativo</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inativo</span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($group['description'])): ?>
                            <p class="text-muted small mb-3"><?= htmlspecialchars($group['description']) ?></p>
                        <?php endif; ?>

                        <div class="d-flex gap-3 small text-muted mb-4">
                            <span><strong class="text-dark"><?= (int) $group['total_forms'] ?></strong> formulários</span>
                            <span><strong class="text-dark"><?= (int) $group['total_responses'] ?></strong> respostas</span>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                            <a href="<?= htmlspecialchars(appUrl('forms', ['group_id' => (int) $group['id']])) ?>" class="btn btn-sm btn-outline-primary">Ver formulários</a>
                            <?php if (canManageTenantData() && !$scopedGroupIds): ?>
                                <a href="<?= htmlspecialchars(appUrl('group-edit', ['id' => (int) $group['id']])) ?>" class="btn btn-sm btn-outline-secondary">Editar</a>
                            <?php endif; ?>
                            <a href="<?= htmlspecialchars(appUrl('responses', ['group_id' => (int) $group['id']])) ?>" class="btn btn-sm btn-outline-dark">Respostas</a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="card shadow-sm border-0">
        <div class="card-body text-center py-5">
            <h2 class="h5 mb-2">Nenhum grupo criado ainda.</h2>
            <p class="text-muted mb-3">Crie grupos para separar formulários por área: Mulheres, Secretaria, RH, Eventos, Clínicas e por aí vai.</p>
            <?php if (canManageTenantData() && !$scopedGroupIds): ?>
                <a href="<?= htmlspecialchars(appUrl('group-create')) ?>" class="btn btn-primary">Criar primeiro grupo</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../layouts/admin-footer.php'; ?>
