<?php
requireTenantContext();

if (!canManageTenantData()) {
    $_SESSION['flash_error'] = 'Você não tem permissão para executar esta ação.';
    redirectTo('forms');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('forms');
}

$tenantId = currentTenantIdForData();
$formId = filter_input(INPUT_POST, 'form_id', FILTER_VALIDATE_INT);
$nextStatus = filter_input(INPUT_POST, 'is_active', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1]]);

if (!$tenantId || !$formId || $nextStatus === false || $nextStatus === null) {
    $_SESSION['flash_error'] = 'Não foi possível atualizar o status deste formulário.';
    redirectTo('forms');
}

try {
    if (shouldScopeTenantUserToGroup()) {
        $stmt = $pdo->prepare('UPDATE forms SET is_active = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ? AND ' . currentUserFormGroupScopeSql('form_group_id'));
        $stmt->execute(array_merge([$nextStatus, $formId, $tenantId], currentUserFormGroupIds()));
    } else {
        $stmt = $pdo->prepare('UPDATE forms SET is_active = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$nextStatus, $formId, $tenantId]);
    }

    if ($stmt->rowCount() < 1) {
        $_SESSION['flash_error'] = 'Não foi possível atualizar o status deste formulário.';
    } else {
        $_SESSION['flash_success'] = $nextStatus === 1 ? 'Formulário ativado com sucesso.' : 'Formulário inativado com sucesso.';
    }
} catch (Throwable $exception) {
    $_SESSION['flash_error'] = 'Não foi possível atualizar o status deste formulário.';
}

redirectTo('forms');
