<?php
requireTenantContext();

$tenantId = currentTenantIdForData();
$currentUser = user();
$tenantName = isSuperAdmin() ? ($_SESSION['maintenance_tenant_name'] ?? '') : ($currentUser['tenant_name'] ?? '');

$pageTitle = 'Painel';

function dashboardCount(PDO $pdo, string $sql, array $params): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function dashboardTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function dashboardColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

$hasGroupsTable = dashboardTableExists($pdo, 'form_groups');
$hasFormGroupColumn = dashboardColumnExists($pdo, 'forms', 'form_group_id');
$scopedGroupIds = shouldScopeTenantUserToGroup() ? currentUserFormGroupIds() : [];

if ($scopedGroupIds && $hasFormGroupColumn) {
    $scopeParams = array_merge([$tenantId], $scopedGroupIds);
    $totalGroups = $hasGroupsTable ? dashboardCount($pdo, 'SELECT COUNT(*) FROM form_groups WHERE tenant_id = ? AND ' . currentUserFormGroupScopeSql('id'), $scopeParams) : 0;
    $totalForms = dashboardCount($pdo, 'SELECT COUNT(*) FROM forms WHERE tenant_id = ? AND ' . currentUserFormGroupScopeSql('form_group_id'), $scopeParams);
    $activeForms = dashboardCount($pdo, 'SELECT COUNT(*) FROM forms WHERE tenant_id = ? AND ' . currentUserFormGroupScopeSql('form_group_id') . ' AND is_active = 1', $scopeParams);
    $totalResponses = dashboardCount($pdo, 'SELECT COUNT(*) FROM form_responses r INNER JOIN forms f ON f.id = r.form_id AND f.tenant_id = r.tenant_id WHERE r.tenant_id = ? AND ' . currentUserFormGroupScopeSql('f.form_group_id'), $scopeParams);
    $responsesToday = dashboardCount($pdo, 'SELECT COUNT(*) FROM form_responses r INNER JOIN forms f ON f.id = r.form_id AND f.tenant_id = r.tenant_id WHERE r.tenant_id = ? AND ' . currentUserFormGroupScopeSql('f.form_group_id') . ' AND DATE(r.created_at) = CURDATE()', $scopeParams);
} else {
    $totalGroups = $hasGroupsTable ? dashboardCount($pdo, 'SELECT COUNT(*) FROM form_groups WHERE tenant_id = ?', [$tenantId]) : 0;
    $totalForms = dashboardCount($pdo, 'SELECT COUNT(*) FROM forms WHERE tenant_id = ?', [$tenantId]);
    $activeForms = dashboardCount($pdo, 'SELECT COUNT(*) FROM forms WHERE tenant_id = ? AND is_active = 1', [$tenantId]);
    $totalResponses = dashboardCount($pdo, 'SELECT COUNT(*) FROM form_responses WHERE tenant_id = ?', [$tenantId]);
    $responsesToday = dashboardCount($pdo, 'SELECT COUNT(*) FROM form_responses WHERE tenant_id = ? AND DATE(created_at) = CURDATE()', [$tenantId]);
}

$recentFormsSql = $hasGroupsTable && $hasFormGroupColumn
    ? 'SELECT f.id, f.title, f.slug, f.is_active, f.updated_at, COALESCE(g.name, "Sem grupo") AS group_name, COUNT(r.id) AS responses_count
       FROM forms f
       LEFT JOIN form_groups g ON g.id = f.form_group_id AND g.tenant_id = f.tenant_id
       LEFT JOIN form_responses r ON r.form_id = f.id AND r.tenant_id = f.tenant_id
       WHERE f.tenant_id = ?
       GROUP BY f.id, f.title, f.slug, f.is_active, f.updated_at, g.name
       ORDER BY f.updated_at DESC, f.id DESC
       LIMIT 6'
    : 'SELECT f.id, f.title, f.slug, f.is_active, f.updated_at, "Sem grupo" AS group_name, COUNT(r.id) AS responses_count
       FROM forms f
       LEFT JOIN form_responses r ON r.form_id = f.id AND r.tenant_id = f.tenant_id
       WHERE f.tenant_id = ?
       GROUP BY f.id, f.title, f.slug, f.is_active, f.updated_at
       ORDER BY f.updated_at DESC, f.id DESC
       LIMIT 6';
if ($scopedGroupIds && $hasFormGroupColumn) {
    $recentFormsSql = str_replace('WHERE f.tenant_id = ?', 'WHERE f.tenant_id = ? AND ' . currentUserFormGroupScopeSql('f.form_group_id'), $recentFormsSql);
}
$stmt = $pdo->prepare($recentFormsSql);
$stmt->execute($scopedGroupIds && $hasFormGroupColumn ? array_merge([$tenantId], $scopedGroupIds) : [$tenantId]);
$recentForms = $stmt->fetchAll(PDO::FETCH_ASSOC);

$latestResponsesSql = 'SELECT r.id, r.created_at, f.title AS form_title
     FROM form_responses r
     INNER JOIN forms f ON f.id = r.form_id AND f.tenant_id = r.tenant_id
     WHERE r.tenant_id = ?
     ' . ($scopedGroupIds && $hasFormGroupColumn ? 'AND ' . currentUserFormGroupScopeSql('f.form_group_id') : '') . '
     ORDER BY r.created_at DESC, r.id DESC
     LIMIT 6';
$stmt = $pdo->prepare($latestResponsesSql);
$stmt->execute($scopedGroupIds && $hasFormGroupColumn ? array_merge([$tenantId], $scopedGroupIds) : [$tenantId]);
$latestResponses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cards = [
    ['label' => 'Grupos', 'value' => $totalGroups, 'text' => 'Áreas organizadas no tenant', 'icon' => '⌁'],
    ['label' => 'Formulários', 'value' => $totalForms, 'text' => 'Formulários criados', 'icon' => '▦'],
    ['label' => 'Ativos', 'value' => $activeForms, 'text' => 'Formulários publicados', 'icon' => '✓'],
    ['label' => 'Respostas', 'value' => $totalResponses, 'text' => $responsesToday . ' recebidas hoje', 'icon' => '↗'],
];

require __DIR__ . '/../../layouts/admin-header.php';
require __DIR__ . '/../../layouts/admin-sidebar.php';
?>

<style>
    .dashboard-hero{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:26px}.dashboard-kicker{color:#212121;font-weight:800;font-size:12px;text-transform:uppercase;letter-spacing:.12em}.dashboard-title{font-size:34px;font-weight:850;letter-spacing:-.04em;color:#212121;margin:4px 0}.dashboard-subtitle{color:#555555;max-width:680px;margin:0}.dashboard-primary{display:inline-flex;align-items:center;gap:8px;min-height:46px;border-radius:14px;background:linear-gradient(135deg,#212121,#555555);color:#fff !important;text-decoration:none;font-weight:800;padding:0 18px;box-shadow:0 16px 30px rgba(37,99,235,.24)}.dashboard-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;margin-bottom:22px}.metric-card{background:#fff;border:1px solid #CECECE;border-radius:20px;padding:20px;box-shadow:0 18px 40px rgba(15,23,42,.06)}.metric-top{display:flex;justify-content:space-between;align-items:center}.metric-label{color:#555555;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}.metric-icon{width:42px;height:42px;border-radius:14px;display:grid;place-items:center;background:rgba(37,99,235,.10);color:#212121;font-size:20px;font-weight:900}.metric-value{font-size:34px;font-weight:850;color:#212121;margin:14px 0 2px}.metric-text{color:#555555;font-size:14px}.dashboard-columns{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(340px,.65fr);gap:20px}.dashboard-card{background:#fff;border:1px solid #CECECE;border-radius:22px;box-shadow:0 18px 40px rgba(15,23,42,.06);overflow:hidden}.dashboard-card-head{display:flex;justify-content:space-between;align-items:center;padding:20px 22px;border-bottom:1px solid #F3F3F3}.dashboard-card-title{font-size:18px;font-weight:850;color:#212121;margin:0}.dashboard-table{width:100%;border-collapse:collapse}.dashboard-table th{font-size:12px;color:#555555;text-transform:uppercase;letter-spacing:.08em;font-weight:850;padding:14px 22px;background:#F3F3F3}.dashboard-table td{padding:16px 22px;border-top:1px solid #F3F3F3;vertical-align:middle}.dashboard-table tr:first-child td{border-top:0}.status-pill{display:inline-flex;align-items:center;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:800}.status-pill.active{background:rgba(34,197,94,.12);color:#16a34a}.status-pill.inactive{background:rgba(100,116,139,.12);color:#555555}.dashboard-action{font-weight:800;text-decoration:none;color:#212121}.empty-state{padding:28px 22px;color:#555555}.response-list{display:grid}.response-item{display:flex;justify-content:space-between;gap:14px;padding:16px 22px;border-top:1px solid #F3F3F3}.response-item:first-child{border-top:0}.response-title{font-weight:800;color:#212121}.response-date{color:#555555;font-size:13px;margin-top:2px}@media(max-width:1199px){.dashboard-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.dashboard-columns{grid-template-columns:1fr}}@media(max-width:767px){.dashboard-hero{display:block}.dashboard-primary{margin-top:16px}.dashboard-grid{grid-template-columns:1fr}.dashboard-table{min-width:720px}.dashboard-card{overflow:auto}}
</style>

<section class="dashboard-hero">
    <div>
        <div class="dashboard-kicker"><?= htmlspecialchars($tenantName) ?></div>
        <h1 class="dashboard-title">Painel</h1>
        <p class="dashboard-subtitle">Acompanhe os formulários, grupos e respostas da sua organização.</p>
    </div>
    <?php if (canManageTenantData()): ?>
        <a class="dashboard-primary" href="form-create">+ Novo formulário</a>
    <?php endif; ?>
</section>

<section class="dashboard-grid">
    <?php foreach ($cards as $card): ?>
        <article class="metric-card">
            <div class="metric-top"><span class="metric-label"><?= htmlspecialchars($card['label']) ?></span><span class="metric-icon"><?= htmlspecialchars($card['icon']) ?></span></div>
            <div class="metric-value"><?= number_format((int)$card['value'], 0, ',', '.') ?></div>
            <div class="metric-text"><?= htmlspecialchars($card['text']) ?></div>
        </article>
    <?php endforeach; ?>
</section>

<section class="dashboard-columns">
    <article class="dashboard-card">
        <div class="dashboard-card-head"><h2 class="dashboard-card-title">Formulários recentes</h2><a class="dashboard-action" href="forms">Ver todos</a></div>
        <?php if ($recentForms): ?>
            <div class="table-responsive">
                <table class="dashboard-table">
                    <thead><tr><th>Nome</th><th>Grupo</th><th>Status</th><th>Respostas</th><th>Atualização</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($recentForms as $form): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($form['title']) ?></strong></td>
                                <td><?= htmlspecialchars($form['group_name']) ?></td>
                                <td><span class="status-pill <?= (int)$form['is_active'] === 1 ? 'active' : 'inactive' ?>"><?= (int)$form['is_active'] === 1 ? 'Ativo' : 'Inativo' ?></span></td>
                                <td><?= (int)$form['responses_count'] ?></td>
                                <td><?= $form['updated_at'] ? date('d/m/Y', strtotime($form['updated_at'])) : '-' ?></td>
                                <td><a class="dashboard-action" href="form-edit?id=<?= (int)$form['id'] ?>">Editar</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">Nenhum formulário criado ainda.</div>
        <?php endif; ?>
    </article>

    <article class="dashboard-card">
        <div class="dashboard-card-head"><h2 class="dashboard-card-title">Últimas respostas</h2><a class="dashboard-action" href="responses">Ver respostas</a></div>
        <?php if ($latestResponses): ?>
            <div class="response-list">
                <?php foreach ($latestResponses as $response): ?>
                    <div class="response-item">
                        <div><div class="response-title"><?= htmlspecialchars($response['form_title']) ?></div><div class="response-date"><?= date('d/m/Y H:i', strtotime($response['created_at'])) ?></div></div>
                        <a class="dashboard-action" href="response-view?id=<?= (int)$response['id'] ?>">Ver</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">Nenhuma resposta recebida ainda.</div>
        <?php endif; ?>
    </article>
</section>

<?php require __DIR__ . '/../../layouts/admin-footer.php'; ?>
