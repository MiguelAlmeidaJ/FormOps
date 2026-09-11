<?php

requireTenantContext();

if (!canManageTenantData()) {
    redirectTo('forms');
}

$tenantId = currentTenantIdForData();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('forms');
}

$formId = filter_input(INPUT_POST, 'form_id', FILTER_VALIDATE_INT);
$fieldId = filter_input(INPUT_POST, 'field_id', FILTER_VALIDATE_INT);

if (!$formId || !$fieldId) {
    redirectTo('forms');
}

$returnTab = in_array($_POST['return_tab'] ?? '', ['fields', 'structure'], true) ? $_POST['return_tab'] : 'fields';

if (shouldScopeTenantUserToGroup()) {
    $stmt = $pdo->prepare('SELECT id FROM forms WHERE id = ? AND tenant_id = ? AND form_group_id = ? LIMIT 1');
    $stmt->execute([$formId, $tenantId, currentUserFormGroupId()]);
    if (!$stmt->fetch()) {
        redirectTo('forms');
    }
}

$stmt = $pdo->prepare("
    SELECT id, type, is_layout, default_value
    FROM form_fields
    WHERE id = ?
      AND form_id = ?
      AND tenant_id = ?
    LIMIT 1
");
$stmt->execute([$fieldId, $formId, $tenantId]);
$field = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$field) {
    redirectTo('form-edit', ['id' => $formId, 'tab' => $returnTab, 'error' => 'field_not_found']);
}

$returnTab = 'fields';

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM form_response_answers
    WHERE field_id = ?
      AND tenant_id = ?
");
$stmt->execute([$fieldId, $tenantId]);

if ((int) $stmt->fetchColumn() > 0) {
    redirectTo('form-edit', ['id' => $formId, 'tab' => $returnTab, 'error' => 'field_has_answers']);
}

$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM form_fields
     WHERE tenant_id = ? AND form_id = ?
       AND conditional_enabled = 1 AND conditional_field_id = ?'
);
$stmt->execute([$tenantId, $formId, $fieldId]);
if ((int) $stmt->fetchColumn() > 0) {
    redirectTo('form-edit', ['id' => $formId, 'tab' => $returnTab, 'error' => 'field_is_condition_reference']);
}

// O NOT EXISTS repete a proteção no momento exato da exclusão.
$stmt = $pdo->prepare("
    DELETE ff
    FROM form_fields AS ff
    WHERE ff.id = ?
      AND ff.form_id = ?
      AND ff.tenant_id = ?
      AND NOT EXISTS (
          SELECT 1
          FROM form_response_answers AS fra
          WHERE fra.field_id = ff.id
            AND fra.tenant_id = ff.tenant_id
      )
      AND NOT EXISTS (
          SELECT 1
          FROM form_fields AS dependent
          WHERE dependent.tenant_id = ff.tenant_id
            AND dependent.form_id = ff.form_id
            AND dependent.conditional_enabled = 1
            AND dependent.conditional_field_id = ff.id
      )
");
$stmt->execute([$fieldId, $formId, $tenantId]);

if ($stmt->rowCount() !== 1) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM form_response_answers
        WHERE field_id = ?
          AND tenant_id = ?
    ");
    $stmt->execute([$fieldId, $tenantId]);

    if ((int) $stmt->fetchColumn() > 0) {
        $error = 'field_has_answers';
    } else {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM form_fields
             WHERE tenant_id = ? AND form_id = ?
               AND conditional_enabled = 1 AND conditional_field_id = ?'
        );
        $stmt->execute([$tenantId, $formId, $fieldId]);
        $error = (int) $stmt->fetchColumn() > 0 ? 'field_is_condition_reference' : 'field_not_found';
    }
    redirectTo('form-edit', ['id' => $formId, 'tab' => $returnTab, 'error' => $error]);
}

if ($field['type'] === 'banner' && str_starts_with((string) $field['default_value'], 'assets/uploads/')) {
    $uploadsRoot = realpath(__DIR__ . '/../../../public/assets/uploads');
    $bannerFile = realpath(__DIR__ . '/../../../public/' . ltrim($field['default_value'], '/'));
    if ($uploadsRoot && $bannerFile && str_starts_with($bannerFile, $uploadsRoot . DIRECTORY_SEPARATOR)) {
        @unlink($bannerFile);
    }
}

redirectTo('form-edit', ['id' => $formId, 'tab' => $returnTab, 'success' => 'field_deleted']);
