<?php

requireTenantContext();
header('Content-Type: application/json; charset=utf-8');

if (!canManageTenantData() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Operação não permitida.']);
    exit;
}

$tenantId = currentTenantIdForData();
$payload = json_decode(file_get_contents('php://input'), true);
$formId = filter_var($payload['form_id'] ?? null, FILTER_VALIDATE_INT);
$fieldId = filter_var($payload['field_id'] ?? null, FILTER_VALIDATE_INT);
$width = (int) ($payload['width'] ?? 0);

if (!$formId || !$fieldId || !in_array($width, [12, 6, 4], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Largura inválida.']);
    exit;
}

if (shouldScopeTenantUserToGroup()) {
    $stmt = $pdo->prepare('SELECT id FROM forms WHERE id = ? AND tenant_id = ? AND ' . currentUserFormGroupScopeSql('form_group_id') . ' LIMIT 1');
    $stmt->execute(array_merge([$formId, $tenantId], currentUserFormGroupIds()));
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Operação não permitida.']);
        exit;
    }
}

$stmt = $pdo->prepare("
    UPDATE form_fields
    SET width = ?
    WHERE id = ?
      AND form_id = ?
      AND tenant_id = ?
      AND is_layout = 0
      AND type NOT IN ('title', 'paragraph', 'divider', 'step', 'banner')
");
$stmt->execute([$width, $fieldId, $formId, $tenantId]);

if ($stmt->rowCount() === 0) {
    $check = $pdo->prepare("SELECT id FROM form_fields WHERE id=? AND form_id=? AND tenant_id=? AND is_layout=0 AND type NOT IN ('title','paragraph','divider','step','banner')");
    $check->execute([$fieldId, $formId, $tenantId]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Campo não encontrado.']);
        exit;
    }
}

echo json_encode(['ok' => true, 'width' => $width]);
