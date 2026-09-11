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
$orderedIds = array_values(array_unique(array_map('intval', $payload['ordered_ids'] ?? [])));

if (!$formId || !$orderedIds || in_array(0, $orderedIds, true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Ordem inválida.']);
    exit;
}

if (shouldScopeTenantUserToGroup()) {
    $stmt = $pdo->prepare('SELECT id FROM forms WHERE id = ? AND tenant_id = ? AND form_group_id = ? LIMIT 1');
    $stmt->execute([$formId, $tenantId, currentUserFormGroupId()]);
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Operação não permitida.']);
        exit;
    }
}

$stmt = $pdo->prepare(
    'SELECT id, conditional_enabled, conditional_field_id
     FROM form_fields WHERE tenant_id = ? AND form_id = ? ORDER BY field_order, id'
);
$stmt->execute([$tenantId, $formId]);
$fieldRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$validIds = array_map(fn ($field) => (int) $field['id'], $fieldRows);

$submitted = $orderedIds;
$expected = $validIds;
sort($submitted);
sort($expected);
if ($submitted !== $expected) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'A lista não corresponde aos campos do formulário.']);
    exit;
}

$positions = array_flip($orderedIds);
foreach ($fieldRows as $field) {
    if ((int) ($field['conditional_enabled'] ?? 0) !== 1) {
        continue;
    }

    $fieldId = (int) $field['id'];
    $referenceId = (int) ($field['conditional_field_id'] ?? 0);
    if (!isset($positions[$referenceId]) || $positions[$referenceId] >= $positions[$fieldId]) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => 'Um campo condicional deve permanecer depois do seu campo de referência.']);
        exit;
    }
}

try {
    $pdo->beginTransaction();
    $update = $pdo->prepare('UPDATE form_fields SET field_order = ? WHERE id = ? AND form_id = ? AND tenant_id = ?');
    foreach ($orderedIds as $index => $fieldId) {
        $update->execute([$index + 1, $fieldId, $formId, $tenantId]);
    }
    $pdo->commit();
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Não foi possível salvar a ordem.']);
}
