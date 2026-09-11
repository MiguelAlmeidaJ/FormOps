<?php
requireTenantContext();

if (!isSuperAdmin()) {
    $_SESSION['flash_error'] = 'Você não tem permissão para executar esta ação.';
    redirectTo('forms');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('forms');
}

$tenantId = currentTenantIdForData();
$formId = filter_input(INPUT_POST, 'form_id', FILTER_VALIDATE_INT);

if (!$tenantId || !$formId) {
    $_SESSION['flash_error'] = 'Não foi possível resetar as respostas deste formulário.';
    redirectTo('forms');
}

function resetResponsesTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

try {
    $stmt = $pdo->prepare('SELECT id FROM forms WHERE id = ? AND tenant_id = ? LIMIT 1');
    $stmt->execute([$formId, $tenantId]);

    if (!$stmt->fetchColumn()) {
        $_SESSION['flash_error'] = 'Não foi possível resetar as respostas deste formulário.';
        redirectTo('forms');
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id FROM form_responses WHERE form_id = ? AND tenant_id = ?');
    $stmt->execute([$formId, $tenantId]);
    $responseIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);

    if (resetResponsesTableExists($pdo, 'form_tickets')) {
        $stmt = $pdo->prepare('DELETE FROM form_tickets WHERE tenant_id = ? AND form_id = ?');
        $stmt->execute([$tenantId, $formId]);
    }

    if (resetResponsesTableExists($pdo, 'form_coupon_redemptions')) {
        $stmt = $pdo->prepare('DELETE FROM form_coupon_redemptions WHERE tenant_id = ? AND form_id = ?');
        $stmt->execute([$tenantId, $formId]);
    }
    if (resetResponsesTableExists($pdo, 'form_payment_lots')) {
        $stmt = $pdo->prepare('UPDATE form_payment_lots SET used_quantity = 0 WHERE tenant_id = ? AND form_id = ?');
        $stmt->execute([$tenantId, $formId]);
    }
    if (resetResponsesTableExists($pdo, 'form_discount_coupons')) {
        $stmt = $pdo->prepare('UPDATE form_discount_coupons SET used_count = 0 WHERE tenant_id = ? AND form_id = ?');
        $stmt->execute([$tenantId, $formId]);
    }

    if ($responseIds && resetResponsesTableExists($pdo, 'form_response_answers')) {
        $placeholders = implode(',', array_fill(0, count($responseIds), '?'));
        $params = array_merge([$tenantId], $responseIds);
        $stmt = $pdo->prepare("DELETE FROM form_response_answers WHERE tenant_id = ? AND response_id IN ($placeholders)");
        $stmt->execute($params);
    }

    $stmt = $pdo->prepare('DELETE FROM form_responses WHERE form_id = ? AND tenant_id = ?');
    $stmt->execute([$formId, $tenantId]);

    $pdo->commit();
    $_SESSION['flash_success'] = 'Respostas do formulário resetadas com sucesso.';
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['flash_error'] = 'Não foi possível resetar as respostas deste formulário.';
}

redirectTo('forms');
