<?php
requireTenantContext();

$tenantId = currentTenantIdForData();
$currentUser = user();
$canDeleteForms = isSuperAdmin() || (isTenantUser() && ($currentUser['role'] ?? null) === 'admin');
$canCompleteForms = $canDeleteForms;
$pageTitle = 'Formularios';
$pageStyles = ['assets/forms-list.css'];
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$stmt = $pdo->prepare('SELECT slug FROM tenants WHERE id = ? LIMIT 1');
$stmt->execute([$tenantId]);
$tenantSlug = (string) $stmt->fetchColumn();

$stmt = $pdo->prepare('SELECT id, name, color FROM form_groups WHERE tenant_id = ? ORDER BY name ASC');
$stmt->execute([$tenantId]);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
$groupsById = [];
foreach ($groups as $group) {
    $groupsById[(int) $group['id']] = $group;
}

$selectedGroupId = filter_input(INPUT_GET, 'group_id', FILTER_VALIDATE_INT) ?: null;
if (shouldScopeTenantUserToGroup()) {
    $selectedGroupId = currentUserFormGroupId();
}
if ($selectedGroupId && !isset($groupsById[$selectedGroupId])) {
    $selectedGroupId = null;
}

$sql = '
    SELECT f.*, g.name AS group_name, g.color AS group_color
    FROM forms f
    LEFT JOIN form_groups g ON g.id = f.form_group_id AND g.tenant_id = f.tenant_id
    WHERE f.tenant_id = ?
';
$params = [$tenantId];
if ($selectedGroupId) {
    $sql .= ' AND f.form_group_id = ?';
    $params[] = $selectedGroupId;
}
$sql .= ' ORDER BY f.created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

function formActionIcon(string $icon): string
{
    $icons = [
        'edit' => '<path d="M4 20h4.5L18.7 9.8a2.1 2.1 0 0 0 0-3l-1.5-1.5a2.1 2.1 0 0 0-3 0L4 15.5V20Z"/><path d="M13.5 6.5l4 4"/>',
        'open' => '<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M20 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h4"/>',
        'chart' => '<path d="M4 19V5"/><path d="M4 19h16"/><path d="M8 16V9"/><path d="M12 16V6"/><path d="M16 16v-4"/>',
        'attendance' => '<path d="M8 7h10"/><path d="M8 12h10"/><path d="M8 17h6"/><path d="M4 7h.01"/><path d="M4 12h.01"/><path d="M4 17h.01"/>',
        'qr' => '<path d="M4 4h6v6H4V4Z"/><path d="M14 4h6v6h-6V4Z"/><path d="M4 14h6v6H4v-6Z"/><path d="M14 14h2v2h-2zM18 14h2v4h-2zM14 18h4v2h-4z"/>',
        'responses' => '<path d="M5 6h14"/><path d="M5 12h14"/><path d="M5 18h9"/>',
        'complete' => '<path d="M20 6 9 17l-5-5"/><path d="M4 20h16"/>',
        'reset' => '<path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M6 7l1 13h10l1-13"/><path d="M9 7V4h6v3"/>',
        'trash' => '<path d="M4 7h16"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M6 7l1 13h10l1-13"/><path d="M9 7V4h6v3"/>',
        'power' => '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.8 0"/>',
    ];

    return '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . ($icons[$icon] ?? '') . '</svg>';
}

require __DIR__ . '/../../../layouts/admin-header.php';
require __DIR__ . '/../../../layouts/admin-sidebar.php';
?>

<section class="forms-hero">
    <div>
        <span class="forms-kicker">Gest&atilde;o de formul&aacute;rios</span>
        <h1>Formul&aacute;rios</h1>
        <p>Gerencie inscri&ccedil;&otilde;es, pesquisas e outros formul&aacute;rios.</p>
    </div>

    <?php if (canManageTenantData()): ?>
        <a href="<?= htmlspecialchars(appUrl('form-create', $selectedGroupId ? ['group_id' => $selectedGroupId] : [])) ?>" class="forms-primary-action">+ Novo formul&aacute;rio</a>
    <?php endif; ?>
</section>

<?php if ($flashSuccess): ?><div class="alert alert-success forms-alert"><?= htmlspecialchars($flashSuccess) ?></div><?php endif; ?>
<?php if ($flashError): ?><div class="alert alert-danger forms-alert"><?= htmlspecialchars($flashError) ?></div><?php endif; ?>

<section class="forms-filter-card">
    <div>
        <h2>Organiza&ccedil;&atilde;o por grupo</h2>
        <p>Filtre os formul&aacute;rios por &aacute;rea, departamento ou pasta.</p>
    </div>
    <form method="get" action="<?= htmlspecialchars(appUrl('forms')) ?>" class="forms-filter-form">
        <select name="group_id" class="form-select" onchange="this.form.submit()">
            <option value="">Todos os grupos</option>
            <?php foreach ($groups as $group): ?>
                <option value="<?= (int) $group['id'] ?>" <?= $selectedGroupId === (int) $group['id'] ? 'selected' : '' ?>><?= htmlspecialchars($group['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <a href="<?= htmlspecialchars(appUrl('groups')) ?>" class="btn btn-outline-secondary">Gerenciar grupos</a>
    </form>
</section>

<section class="forms-table-card">
    <?php if ($forms): ?>
        <div class="table-responsive">
            <table class="table forms-table align-middle mb-0">
                <thead><tr><th>T&iacute;tulo</th><th>Grupo</th><th>Tipo</th><th>Status</th><th>Criado em</th><th class="text-end">A&ccedil;&otilde;es</th></tr></thead>
                <tbody>
                    <?php foreach ($forms as $form): ?>
                        <?php
                            $publicUrl = absoluteAppUrl('f/' . rawurlencode($tenantSlug) . '/' . rawurlencode($form['slug']));
                            $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=' . urlencode($publicUrl);
                        ?>
                        <tr>
                            <td><div class="form-title-cell"><strong><?= htmlspecialchars($form['title']) ?></strong><span><?= htmlspecialchars($form['slug']) ?></span></div></td>
                            <td>
                                <?php if (!empty($form['group_name'])): ?>
                                    <span class="forms-group-badge" style="--group-color: <?= htmlspecialchars($form['group_color'] ?: '#2563eb') ?>"><?= htmlspecialchars($form['group_name']) ?></span>
                                <?php else: ?>
                                    <span class="forms-muted-badge">Sem grupo</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="forms-type-badge"><?= htmlspecialchars($form['type']) ?></span></td>
                            <td><span class="forms-status-badge <?= htmlspecialchars(formStatusClass($form)) ?>"><?= htmlspecialchars(formStatusLabel($form)) ?></span></td>
                            <td><?= date('d/m/Y H:i', strtotime($form['created_at'])) ?></td>
                            <td>
                                <div class="form-actions">
                                    <?php if (canManageTenantData()): ?>
                                        <a href="form-edit?id=<?= (int)$form['id'] ?>" class="icon-action" title="Editar formulário" aria-label="Editar formulário"><?= formActionIcon('edit') ?></a>
                                    <?php endif; ?>
                                    <a href="<?= htmlspecialchars($publicUrl) ?>" target="_blank" rel="noopener" class="icon-action open" title="Abrir formulário" aria-label="Abrir formulário"><?= formActionIcon('open') ?></a>
                                    <button type="button" class="icon-action js-qr-modal" title="Ver QR Code" aria-label="Ver QR Code" data-form-title="<?= htmlspecialchars($form['title']) ?>" data-public-url="<?= htmlspecialchars($publicUrl) ?>" data-qr-url="<?= htmlspecialchars($qrUrl) ?>"><?= formActionIcon('qr') ?></button>
                                    <a href="<?= htmlspecialchars(appUrl('form-dashboard', ['id' => (int) $form['id']])) ?>" class="icon-action" title="Dashboard do formulário" aria-label="Dashboard do formulário"><?= formActionIcon('chart') ?></a>
                                    <?php if (isFormCompleted($form)): ?>
                                        <a href="<?= htmlspecialchars(appUrl('form-attendance', ['id' => (int) $form['id']])) ?>" class="icon-action open" title="Gerar lista de presença" aria-label="Gerar lista de presença"><?= formActionIcon('attendance') ?></a>
                                    <?php endif; ?>
                                    <a href="<?= htmlspecialchars(appUrl('responses', array_filter(['form_id' => (int) $form['id'], 'group_id' => !empty($form['form_group_id']) ? (int) $form['form_group_id'] : null]))) ?>" class="icon-action" title="Ver respostas" aria-label="Ver respostas"><?= formActionIcon('responses') ?></a>
                                    <?php if (canManageTenantData()): ?>
                                        <form method="post" action="<?= htmlspecialchars(appUrl('form-toggle-status')) ?>" onsubmit="return confirm('Tem certeza que deseja <?= $form['is_active'] ? 'inativar' : 'ativar' ?> este formulário?');">
                                            <input type="hidden" name="form_id" value="<?= (int) $form['id'] ?>">
                                            <input type="hidden" name="is_active" value="<?= $form['is_active'] ? 0 : 1 ?>">
                                            <button type="submit" class="icon-action <?= $form['is_active'] ? 'warning' : 'open' ?>" title="<?= $form['is_active'] ? 'Inativar formulário' : 'Ativar formulário' ?>" aria-label="<?= $form['is_active'] ? 'Inativar formulário' : 'Ativar formulário' ?>"><?= formActionIcon('power') ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canCompleteForms && !isFormCompleted($form)): ?>
                                        <form method="post" action="<?= htmlspecialchars(appUrl('form-mark-completed')) ?>" onsubmit="return confirm('Marcar este formulário como concluído? Ele não aceitará novas respostas e a lista de presença será liberada.');">
                                            <input type="hidden" name="form_id" value="<?= (int) $form['id'] ?>">
                                            <button type="submit" class="icon-action open" title="Marcar como concluído" aria-label="Marcar como concluído"><?= formActionIcon('complete') ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (isSuperAdmin()): ?>
                                        <form method="post" action="<?= htmlspecialchars(appUrl('form-reset-responses')) ?>" onsubmit="return confirm('Tem certeza que deseja resetar todas as respostas deste formulário? Essa ação não poderá ser desfeita.');">
                                            <input type="hidden" name="form_id" value="<?= (int) $form['id'] ?>">
                                            <button type="submit" class="icon-action danger" title="Resetar respostas" aria-label="Resetar respostas"><?= formActionIcon('reset') ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if ($canDeleteForms): ?>
                                        <form method="post" action="<?= htmlspecialchars(appUrl('form-delete')) ?>" onsubmit="return confirm('Tem certeza que deseja apagar este formulário? Todas as respostas e campos também serão removidos. Essa ação não poderá ser desfeita.');">
                                            <input type="hidden" name="form_id" value="<?= (int) $form['id'] ?>">
                                            <button type="submit" class="icon-action danger" title="Apagar formulário" aria-label="Apagar formulário"><?= formActionIcon('trash') ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="forms-empty-state">
            <div class="forms-empty-icon">FRM</div>
            <h2>Nenhum formul&aacute;rio encontrado</h2>
            <p>Crie seu primeiro formul&aacute;rio ou ajuste os filtros.</p>
            <?php if (canManageTenantData()): ?><a href="<?= htmlspecialchars(appUrl('form-create', $selectedGroupId ? ['group_id' => $selectedGroupId] : [])) ?>" class="forms-primary-action">+ Novo formul&aacute;rio</a><?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<div class="modal fade" id="qrCodeModal" tabindex="-1" aria-labelledby="qrCodeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content qr-modal-content">
            <div class="modal-header"><div><h2 class="modal-title" id="qrCodeModalLabel">QR Code do formul&aacute;rio</h2><p class="qr-modal-subtitle" id="qrCodeModalSubtitle"></p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body"><div class="qr-modal-image-wrap"><img id="qrCodeModalImage" src="" alt="QR Code do formulário"></div><a id="qrCodeModalPublicUrl" href="#" target="_blank" rel="noopener" class="qr-modal-url"></a></div>
            <div class="modal-footer"><a id="qrCodeModalDownload" href="#" download="qrcode-formulario.png" class="btn btn-primary">Baixar imagem</a><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button></div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.js-qr-modal').forEach((button) => {
    button.addEventListener('click', () => {
        const modalElement = document.getElementById('qrCodeModal');
        document.getElementById('qrCodeModalImage').src = button.dataset.qrUrl;
        document.getElementById('qrCodeModalSubtitle').textContent = button.dataset.formTitle || '';
        document.getElementById('qrCodeModalPublicUrl').href = button.dataset.publicUrl;
        document.getElementById('qrCodeModalPublicUrl').textContent = button.dataset.publicUrl;
        document.getElementById('qrCodeModalDownload').href = button.dataset.qrUrl;
        bootstrap.Modal.getOrCreateInstance(modalElement).show();
    });
});
</script>

<?php require __DIR__ . '/../../../layouts/admin-footer.php'; ?>
