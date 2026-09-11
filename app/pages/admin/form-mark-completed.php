<?php

requireTenantContext();

$currentUser = user();
$canCompleteForms = isSuperAdmin() || (isTenantUser() && ($currentUser['role'] ?? null) === 'admin');

if (!$canCompleteForms) {
    $_SESSION['flash_error'] = 'Você não tem permissão para concluir formulários.';
    redirectTo('forms');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('forms');
}

$tenantId = currentTenantIdForData();
$formId = filter_input(INPUT_POST, 'form_id', FILTER_VALIDATE_INT);

if (!$tenantId || !$formId) {
    $_SESSION['flash_error'] = 'Não foi possível concluir este formulário.';
    redirectTo('forms');
}

try {
    if (shouldScopeTenantUserToGroup()) {
        $stmt = $pdo->prepare('UPDATE forms SET closes_at = NOW(), updated_at = NOW() WHERE id = ? AND tenant_id = ? AND ' . currentUserFormGroupScopeSql('form_group_id'));
        $stmt->execute(array_merge([$formId, $tenantId], currentUserFormGroupIds()));
    } else {
        $stmt = $pdo->prepare('UPDATE forms SET closes_at = NOW(), updated_at = NOW() WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$formId, $tenantId]);
    }

    if ($stmt->rowCount() < 1) {
        $_SESSION['flash_error'] = 'Não foi possível concluir este formulário.';
    } else {
        $_SESSION['flash_success'] = 'Formulário marcado como concluído.';
    }
} catch (Throwable $exception) {
    $_SESSION['flash_error'] = 'Não foi possível concluir este formulário.';
}

redirectTo('forms');
