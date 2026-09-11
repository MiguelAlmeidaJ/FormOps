<?php

requireTenantContext();

$currentUser = user();
if (!isSuperAdmin() && ($currentUser['role'] ?? null) !== 'admin') {
    redirectTo('painel');
}

$tenantId = (int) currentTenantIdForData();
$pageTitle = 'Usuários';
$allowedRoles = ['admin', 'editor', 'viewer'];
$roleLabels = ['admin' => 'Administrador', 'editor' => 'Editor', 'viewer' => 'Visualizador'];
$scopeGroupIds = shouldScopeTenantUserToGroup() ? currentUserFormGroupIds() : [];
$errors = [];
$openUserModal = false;
$modalMode = 'create';
$modalValues = [];

$groupsSql = 'SELECT id, name FROM form_groups WHERE tenant_id = ? AND is_active = 1';
$groupsParams = [$tenantId];
if ($scopeGroupIds) {
    $groupsSql .= ' AND ' . currentUserFormGroupScopeSql('id');
    $groupsParams = array_merge($groupsParams, $scopeGroupIds);
}
$groupsSql .= ' ORDER BY name ASC';
$stmt = $pdo->prepare($groupsSql);
$stmt->execute($groupsParams);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
$groupsById = [];
foreach ($groups as $group) {
    $groupsById[(int) $group['id']] = $group;
}

$stmt = $pdo->prepare(
    "SELECT id, name, email, role, is_active, form_group_id, created_at
     FROM users
     WHERE tenant_id = ? AND role IN ('admin', 'editor', 'viewer')
     ORDER BY created_at DESC, id DESC"
);
$stmt->execute([$tenantId]);
$allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare(
    'SELECT ufg.user_id, ufg.form_group_id, g.name AS group_name
     FROM user_form_groups ufg
     INNER JOIN form_groups g ON g.id = ufg.form_group_id AND g.tenant_id = ufg.tenant_id
     WHERE ufg.tenant_id = ?
     ORDER BY g.name ASC'
);
$stmt->execute([$tenantId]);
$userGroupIds = [];
$userGroupNames = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $userGroup) {
    $mappedUserId = (int) $userGroup['user_id'];
    $userGroupIds[$mappedUserId][] = (int) $userGroup['form_group_id'];
    $userGroupNames[$mappedUserId][] = (string) $userGroup['group_name'];
}
foreach ($allUsers as $tenantUser) {
    $mappedUserId = (int) $tenantUser['id'];
    if (empty($userGroupIds[$mappedUserId]) && !empty($tenantUser['form_group_id'])) {
        $legacyGroupId = (int) $tenantUser['form_group_id'];
        $userGroupIds[$mappedUserId] = [$legacyGroupId];
        if (isset($groupsById[$legacyGroupId])) {
            $userGroupNames[$mappedUserId] = [(string) $groupsById[$legacyGroupId]['name']];
        }
    }
}

$canManageUser = static function (array $tenantUser) use ($scopeGroupIds, $userGroupIds): bool {
    if (!$scopeGroupIds) {
        return true;
    }
    $assignedIds = $userGroupIds[(int) $tenantUser['id']] ?? [];
    return $assignedIds !== [] && array_diff($assignedIds, $scopeGroupIds) === [];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_user') {
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT) ?: null;
    $modalMode = $userId ? 'edit' : 'create';
    $openUserModal = true;
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $role = (string) ($_POST['role'] ?? 'viewer');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $allGroups = !$scopeGroupIds && isset($_POST['all_groups']);
    $selectedGroupIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['group_ids'] ?? [])), static fn (int $id): bool => $id > 0)));
    if ($allGroups) {
        $selectedGroupIds = [];
    }

    $targetUser = null;
    if ($userId) {
        foreach ($allUsers as $candidate) {
            if ((int) $candidate['id'] === $userId) {
                $targetUser = $candidate;
                break;
            }
        }
        if (!$targetUser || !$canManageUser($targetUser)) {
            $errors[] = 'Usuário não encontrado ou fora do seu escopo de acesso.';
        }
    }

    if ($name === '') $errors[] = 'O nome é obrigatório.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Informe um e-mail válido.';
    if (!$userId && strlen($password) < 8) $errors[] = 'A senha inicial deve ter pelo menos 8 caracteres.';
    if ($userId && $password !== '' && strlen($password) < 8) $errors[] = 'A nova senha deve ter pelo menos 8 caracteres.';
    if (!in_array($role, $allowedRoles, true)) $errors[] = 'Selecione um perfil válido.';
    if (!$allGroups && !$selectedGroupIds) $errors[] = 'Selecione pelo menos um grupo ou libere o acesso a todos os grupos.';
    foreach ($selectedGroupIds as $groupId) {
        if (!isset($groupsById[$groupId])) {
            $errors[] = 'Um dos grupos selecionados é inválido ou está fora do seu acesso.';
            break;
        }
    }

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $emailSql = 'SELECT id FROM users WHERE email = ?' . ($userId ? ' AND id != ?' : '') . ' LIMIT 1';
        $stmt = $pdo->prepare($emailSql);
        $stmt->execute($userId ? [$email, $userId] : [$email]);
        if ($stmt->fetch()) $errors[] = 'Este e-mail já está cadastrado.';
    }

    $modalValues = [
        'id' => $userId,
        'name' => $name,
        'email' => $email,
        'role' => $role,
        'is_active' => $isActive === 1,
        'all_groups' => $allGroups,
        'group_ids' => $selectedGroupIds,
    ];

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $primaryGroupId = $selectedGroupIds[0] ?? null;
            if ($userId) {
                $sql = 'UPDATE users SET name = ?, email = ?, role = ?, form_group_id = ?, is_active = ?';
                $params = [$name, $email, $role, $primaryGroupId, $isActive];
                if ($password !== '') {
                    $sql .= ', password = ?';
                    $params[] = password_hash($password, PASSWORD_DEFAULT);
                }
                $sql .= " , updated_at = NOW() WHERE id = ? AND tenant_id = ? AND role IN ('admin', 'editor', 'viewer')";
                $params[] = $userId;
                $params[] = $tenantId;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $savedUserId = $userId;
            } else {
                $stmt = $pdo->prepare('INSERT INTO users (tenant_id, form_group_id, name, email, password, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$tenantId, $primaryGroupId, $name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $isActive]);
                $savedUserId = (int) $pdo->lastInsertId();
            }

            $stmt = $pdo->prepare('DELETE FROM user_form_groups WHERE tenant_id = ? AND user_id = ?');
            $stmt->execute([$tenantId, $savedUserId]);
            if ($selectedGroupIds) {
                $groupInsert = $pdo->prepare('INSERT INTO user_form_groups (tenant_id, user_id, form_group_id) VALUES (?, ?, ?)');
                foreach ($selectedGroupIds as $groupId) {
                    $groupInsert->execute([$tenantId, $savedUserId, $groupId]);
                }
            }
            $pdo->commit();

            if ((int) ($currentUser['id'] ?? 0) === $savedUserId && !isSuperAdmin()) {
                $_SESSION['user']['name'] = $name;
                $_SESSION['user']['email'] = $email;
                $_SESSION['user']['role'] = $role;
                $_SESSION['user']['form_group_id'] = $primaryGroupId;
                $_SESSION['user']['form_group_ids'] = $selectedGroupIds;
            }

            redirectTo('users', ['success' => $userId ? 'user_updated' : 'user_created']);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('FormOps user save error: ' . $exception->getMessage());
            $errors[] = 'Não foi possível salvar o usuário.';
        }
    }
}

$users = array_values(array_filter($allUsers, $canManageUser));
$successMessages = [
    'user_created' => 'Usuário criado com sucesso.',
    'user_updated' => 'Usuário atualizado com sucesso.',
    'password_reset' => 'Senha redefinida com sucesso.',
];
$successMessage = $successMessages[$_GET['success'] ?? ''] ?? null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($_GET['modal'] ?? '') === 'create') {
    $openUserModal = true;
    $modalMode = 'create';
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($_GET['modal'] ?? '') === 'edit') {
    $requestedUserId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    foreach ($users as $tenantUser) {
        if ((int) $tenantUser['id'] === (int) $requestedUserId) {
            $openUserModal = true;
            $modalMode = 'edit';
            $modalValues = [
                'id' => (int) $tenantUser['id'],
                'name' => $tenantUser['name'],
                'email' => $tenantUser['email'],
                'role' => $tenantUser['role'],
                'is_active' => (bool) $tenantUser['is_active'],
                'all_groups' => empty($userGroupIds[(int) $tenantUser['id']]),
                'group_ids' => $userGroupIds[(int) $tenantUser['id']] ?? [],
            ];
            break;
        }
    }
}

require __DIR__ . '/../../layouts/admin-header.php';
require __DIR__ . '/../../layouts/admin-sidebar.php';
?>

<style>
    .user-identity>div:last-child{min-width:0}
    .users-hero{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;padding:26px 28px;margin-bottom:22px;border:1px solid #e5e5e5;border-radius:20px;background:linear-gradient(135deg,#f8f8f8,#fff);box-shadow:0 16px 38px rgba(33,33,33,.05)}.users-title{font-size:30px;font-weight:800;letter-spacing:-.035em;color:#212121;margin:0 0 5px}.users-subtitle{color:#666;font-size:15px;margin:0}.users-add{display:inline-flex;align-items:center;gap:8px;min-height:46px;padding:0 18px;border:0;border-radius:11px;background:#212121;color:#fff;font-weight:700;white-space:nowrap}.users-add:hover{background:#333;color:#fff}.users-list{display:grid;gap:12px}.user-card{display:grid;grid-template-columns:minmax(250px,1.2fr) minmax(220px,1fr) 150px 100px 100px 50px;align-items:center;gap:18px;padding:18px 20px;border:1px solid #e5e5e5;border-radius:16px;background:#fff;box-shadow:0 10px 28px rgba(33,33,33,.045);transition:.16s ease}.user-card:hover{border-color:#cfcfcf;box-shadow:0 14px 34px rgba(33,33,33,.075);transform:translateY(-1px)}.user-identity{display:flex;align-items:center;gap:13px;min-width:0}.user-avatar{width:44px;height:44px;flex:0 0 auto;display:grid;place-items:center;border-radius:14px;background:#f0f0f0;color:#212121;font-size:15px;font-weight:850}.user-name{font-weight:800;color:#212121;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.user-email{font-size:13px;color:#666;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.user-groups{display:flex;flex-wrap:wrap;gap:6px}.user-group-tag{display:inline-flex;padding:5px 8px;border-radius:999px;background:#f3f3f3;color:#444;font-size:11px;font-weight:700}.user-role{font-size:13px;font-weight:750;color:#444}.user-status{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:800}.user-status::before{content:"";width:8px;height:8px;border-radius:50%;background:#9ca3af}.user-status.active{color:#166534}.user-status.active::before{background:#22c55e}.user-edit{justify-self:end;width:40px;height:40px;display:grid;place-items:center;border:1px solid #ddd;border-radius:11px;background:#fff;color:#333}.user-edit:hover{background:#f3f3f3}.users-empty{padding:54px 24px;text-align:center;border:1px dashed #d7d7d7;border-radius:18px;background:#fafafa;color:#666}.user-modal .modal-content{border:0;border-radius:20px;box-shadow:0 30px 80px rgba(0,0,0,.22)}.user-modal .modal-header{padding:22px 24px 18px;border-bottom:1px solid #eee}.user-modal .modal-body{padding:22px 24px}.user-modal .modal-footer{padding:16px 24px;border-top:1px solid #eee}.user-modal-title{font-size:22px;font-weight:850;letter-spacing:-.02em}.password-control{position:relative}.password-control .form-control{padding-right:48px}.password-toggle{position:absolute;right:6px;top:50%;transform:translateY(-50%);width:38px;height:38px;border:0;border-radius:9px;background:transparent;color:#666;display:grid;place-items:center}.password-toggle:hover{background:#f1f1f1;color:#212121}.group-access-box{border:1px solid #dedede;border-radius:14px;padding:14px;background:#fafafa}.group-all-option{display:flex;align-items:flex-start;gap:10px;padding:4px 2px 13px;border-bottom:1px solid #e8e8e8;margin-bottom:12px}.group-options{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.group-option{display:flex;align-items:center;gap:9px;padding:10px 11px;border:1px solid #e2e2e2;border-radius:10px;background:#fff}.group-option:has(input:checked){border-color:#777;background:#f3f3f3}.user-modal-note{font-size:12px;color:#666;margin-top:7px}@media(max-width:1100px){.user-card{grid-template-columns:minmax(240px,1.2fr) minmax(200px,1fr) 140px 100px 50px}.user-created{display:none}}@media(max-width:767px){.users-hero{display:block;padding:22px}.users-add{margin-top:18px;width:100%;justify-content:center}.user-card{grid-template-columns:1fr auto}.user-groups,.user-role,.user-created{grid-column:1 / -1}.user-status{grid-column:1}.user-edit{grid-column:2;grid-row:1}.group-options{grid-template-columns:1fr}}
</style>

<section class="users-hero">
    <div><h1 class="users-title">Usuários</h1><p class="users-subtitle">Gerencie acessos, perfis e os grupos disponíveis para cada pessoa.</p></div>
    <button type="button" class="users-add" data-user-create><span aria-hidden="true">+</span> Novo usuário</button>
</section>

<?php if ($successMessage): ?><div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= htmlspecialchars($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="users-list">
    <?php foreach ($users as $tenantUser): ?>
        <?php
        $listedGroupIds = $userGroupIds[(int) $tenantUser['id']] ?? [];
        $listedGroupNames = $userGroupNames[(int) $tenantUser['id']] ?? [];
        $initials = implode('', array_map(static fn (string $part): string => function_exists('mb_substr') ? mb_substr($part, 0, 1) : substr($part, 0, 1), array_slice(array_values(array_filter(explode(' ', trim((string) $tenantUser['name'])))), 0, 2)));
        $editPayload = [
            'id' => (int) $tenantUser['id'], 'name' => $tenantUser['name'], 'email' => $tenantUser['email'],
            'role' => $tenantUser['role'], 'is_active' => (bool) $tenantUser['is_active'],
            'all_groups' => !$listedGroupIds, 'group_ids' => $listedGroupIds,
        ];
        ?>
        <article class="user-card">
            <div class="user-identity"><div class="user-avatar"><?= htmlspecialchars(strtoupper($initials ?: 'U')) ?></div><div class="min-w-0"><div class="user-name"><?= htmlspecialchars($tenantUser['name']) ?></div><div class="user-email"><?= htmlspecialchars($tenantUser['email']) ?></div></div></div>
            <div class="user-groups">
                <?php if (!$listedGroupNames): ?><span class="user-group-tag">Todos os grupos</span><?php endif; ?>
                <?php foreach ($listedGroupNames as $groupName): ?><span class="user-group-tag"><?= htmlspecialchars($groupName) ?></span><?php endforeach; ?>
            </div>
            <div class="user-role"><?= htmlspecialchars($roleLabels[$tenantUser['role']] ?? ucfirst($tenantUser['role'])) ?></div>
            <div class="user-status <?= $tenantUser['is_active'] ? 'active' : '' ?>"><?= $tenantUser['is_active'] ? 'Ativo' : 'Inativo' ?></div>
            <div class="user-created small text-muted"><?= htmlspecialchars(date('d/m/Y', strtotime($tenantUser['created_at']))) ?></div>
            <button type="button" class="user-edit" data-user-edit="<?= htmlspecialchars(json_encode($editPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" title="Editar usuário" aria-label="Editar usuário">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            </button>
        </article>
    <?php endforeach; ?>
    <?php if (!$users): ?><div class="users-empty"><strong>Nenhum usuário cadastrado.</strong><div class="small mt-1">Crie o primeiro acesso para sua equipe.</div></div><?php endif; ?>
</div>

<div class="modal fade user-modal" id="userModal" tabindex="-1" aria-labelledby="userModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <form method="post" class="modal-content" id="userForm">
            <input type="hidden" name="action" value="save_user"><input type="hidden" name="user_id" id="userId">
            <div class="modal-header"><div><h2 class="modal-title user-modal-title" id="userModalTitle">Novo usuário</h2><p class="text-muted small mb-0" id="userModalSubtitle">Crie um acesso e defina os grupos disponíveis.</p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label" for="userName">Nome completo</label><input class="form-control" id="userName" name="name" maxlength="255" required></div>
                    <div class="col-md-6"><label class="form-label" for="userEmail">E-mail</label><input class="form-control" type="email" id="userEmail" name="email" maxlength="255" required></div>
                    <div class="col-md-6"><label class="form-label" for="userRole">Perfil de acesso</label><select class="form-select" id="userRole" name="role" required><?php foreach ($allowedRoles as $allowedRole): ?><option value="<?= $allowedRole ?>"><?= htmlspecialchars($roleLabels[$allowedRole]) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label" for="userPassword" id="userPasswordLabel">Senha inicial</label><div class="password-control"><input class="form-control" type="password" id="userPassword" name="password" minlength="8" autocomplete="new-password"><button class="password-toggle" type="button" data-password-toggle aria-label="Mostrar senha" title="Mostrar senha"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg></button></div><div class="user-modal-note" id="userPasswordNote">Use pelo menos 8 caracteres.</div></div>
                    <div class="col-12"><label class="form-label">Grupos permitidos</label><div class="group-access-box">
                        <?php if (!$scopeGroupIds): ?><label class="group-all-option"><input class="form-check-input mt-1" type="checkbox" name="all_groups" id="userAllGroups" value="1"><span><strong>Todos os grupos</strong><small class="d-block text-muted">O usuário também terá acesso a novos grupos criados no futuro.</small></span></label><?php endif; ?>
                        <div class="group-options" id="userGroupOptions">
                            <?php foreach ($groups as $group): ?><label class="group-option"><input class="form-check-input" type="checkbox" name="group_ids[]" value="<?= (int) $group['id'] ?>"><span><?= htmlspecialchars($group['name']) ?></span></label><?php endforeach; ?>
                            <?php if (!$groups): ?><div class="small text-muted">Nenhum grupo ativo disponível.</div><?php endif; ?>
                        </div>
                    </div></div>
                    <div class="col-12"><label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" id="userActive" value="1"><span class="form-check-label">Usuário ativo</span></label></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary px-4" id="userSubmit">Criar usuário</button></div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded',()=>{
    const modalElement=document.getElementById('userModal');
    const modal=bootstrap.Modal.getOrCreateInstance(modalElement);
    const form=document.getElementById('userForm');
    const fields={id:document.getElementById('userId'),name:document.getElementById('userName'),email:document.getElementById('userEmail'),role:document.getElementById('userRole'),password:document.getElementById('userPassword'),active:document.getElementById('userActive'),allGroups:document.getElementById('userAllGroups')};
    const groupChecks=Array.from(document.querySelectorAll('#userGroupOptions input[type=checkbox]'));
    const title=document.getElementById('userModalTitle');const subtitle=document.getElementById('userModalSubtitle');const submit=document.getElementById('userSubmit');const passwordLabel=document.getElementById('userPasswordLabel');const passwordNote=document.getElementById('userPasswordNote');
    const syncGroups=()=>{const disabled=!!fields.allGroups?.checked;groupChecks.forEach(check=>{check.disabled=disabled;check.closest('.group-option')?.classList.toggle('opacity-50',disabled)});};
    const fill=payload=>{const editing=!!payload?.id;form.reset();fields.id.value=payload?.id||'';fields.name.value=payload?.name||'';fields.email.value=payload?.email||'';fields.role.value=payload?.role||'viewer';fields.active.checked=payload?.is_active??true;fields.password.value='';fields.password.required=!editing;if(fields.allGroups)fields.allGroups.checked=payload?.all_groups??!editing;const selected=(payload?.group_ids||[]).map(Number);groupChecks.forEach(check=>check.checked=selected.includes(Number(check.value))||(!fields.allGroups&&!editing));title.textContent=editing?'Editar usuário':'Novo usuário';subtitle.textContent=editing?'Atualize o acesso, os grupos ou a senha desta pessoa.':'Crie um acesso e defina os grupos disponíveis.';submit.textContent=editing?'Salvar alterações':'Criar usuário';passwordLabel.textContent=editing?'Nova senha':'Senha inicial';passwordNote.textContent=editing?'Deixe em branco para manter a senha atual.':'Use pelo menos 8 caracteres.';syncGroups();};
    document.querySelector('[data-user-create]')?.addEventListener('click',()=>{fill(null);modal.show()});
    document.querySelectorAll('[data-user-edit]').forEach(button=>button.addEventListener('click',()=>{try{fill(JSON.parse(button.dataset.userEdit));modal.show()}catch(error){}}));
    fields.allGroups?.addEventListener('change',syncGroups);
    document.querySelector('[data-password-toggle]')?.addEventListener('click',event=>{const button=event.currentTarget;const visible=fields.password.type==='text';fields.password.type=visible?'password':'text';button.setAttribute('aria-label',visible?'Mostrar senha':'Ocultar senha');button.setAttribute('title',visible?'Mostrar senha':'Ocultar senha');});
    modalElement.addEventListener('hidden.bs.modal',()=>{fields.password.type='password'});
    <?php if ($openUserModal): ?>fill(<?= json_encode($modalValues ?: null, JSON_UNESCAPED_UNICODE) ?>);modal.show();<?php endif; ?>
});
</script>

<?php require __DIR__ . '/../../layouts/admin-footer.php'; ?>
