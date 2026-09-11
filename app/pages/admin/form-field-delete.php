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
$isBulkDelete = ($_POST['bulk_delete'] ?? '') === '1';

if (!$formId || (!$isBulkDelete && !$fieldId)) {
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

if ($isBulkDelete) {
    $fieldIds = [];
    if (is_array($_POST['field_ids'])) {
        foreach ($_POST['field_ids'] as $value) {
            $validatedId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($validatedId !== false) {
                $fieldIds[$validatedId] = $validatedId;
            }
        }
    }
    $fieldIds = array_slice(array_values($fieldIds), 0, 500);

    if (!$fieldIds) {
        redirectTo('form-edit', ['id' => $formId, 'tab' => 'fields', 'error' => 'no_fields_selected']);
    }

    $placeholders = implode(',', array_fill(0, count($fieldIds), '?'));

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT id, type, default_value, field_order
            FROM form_fields
            WHERE form_id = ?
              AND tenant_id = ?
              AND id IN ($placeholders)
            ORDER BY field_order DESC, id DESC
        ");
        $stmt->execute(array_merge([$formId, $tenantId], $fieldIds));
        $selectedFields = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $selectedField) {
            $selectedFields[(int) $selectedField['id']] = $selectedField;
        }

        $deleteStmt = $pdo->prepare("
            DELETE ff
            FROM form_fields AS ff
            LEFT JOIN form_response_answers AS fra
              ON fra.field_id = ff.id
             AND fra.tenant_id = ff.tenant_id
            LEFT JOIN form_fields AS dependent
              ON dependent.tenant_id = ff.tenant_id
             AND dependent.form_id = ff.form_id
             AND dependent.conditional_enabled = 1
             AND dependent.conditional_field_id = ff.id
            WHERE ff.id = ?
              AND ff.form_id = ?
              AND ff.tenant_id = ?
              AND fra.id IS NULL
              AND dependent.id IS NULL
        ");
        foreach (array_keys($selectedFields) as $selectedFieldId) {
            $deleteStmt->execute([$selectedFieldId, $formId, $tenantId]);
        }

        $stmt = $pdo->prepare("
            SELECT id
            FROM form_fields
            WHERE form_id = ?
              AND tenant_id = ?
              AND id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$formId, $tenantId], $fieldIds));
        $remainingIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $deletedIds = array_diff(array_keys($selectedFields), $remainingIds);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    foreach ($deletedIds as $deletedId) {
        $deletedField = $selectedFields[$deletedId];
        if ($deletedField['type'] !== 'banner' || !str_starts_with((string) $deletedField['default_value'], 'assets/uploads/')) {
            continue;
        }
        $uploadsRoot = realpath(__DIR__ . '/../../../public/assets/uploads');
        $bannerFile = realpath(__DIR__ . '/../../../public/' . ltrim($deletedField['default_value'], '/'));
        if ($uploadsRoot && $bannerFile && str_starts_with($bannerFile, $uploadsRoot . DIRECTORY_SEPARATOR)) {
            @unlink($bannerFile);
        }
    }

    $deletedCount = count($deletedIds);
    $blockedCount = count($selectedFields) - $deletedCount;
    if ($deletedCount === 0) {
        redirectTo('form-edit', [
            'id' => $formId,
            'tab' => 'fields',
            'error' => 'selected_fields_blocked',
            'blocked' => $blockedCount,
        ]);
    }

    redirectTo('form-edit', [
        'id' => $formId,
        'tab' => 'fields',
        'success' => 'fields_deleted',
        'deleted' => $deletedCount,
        'blocked' => $blockedCount,
    ]);
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

// Os LEFT JOINs repetem as proteções no momento exato da exclusão sem
// consultar form_fields em uma subquery do próprio DELETE (erro MySQL 1093).
$stmt = $pdo->prepare("
    DELETE ff
    FROM form_fields AS ff
    LEFT JOIN form_response_answers AS fra
      ON fra.field_id = ff.id
     AND fra.tenant_id = ff.tenant_id
    LEFT JOIN form_fields AS dependent
      ON dependent.tenant_id = ff.tenant_id
     AND dependent.form_id = ff.form_id
     AND dependent.conditional_enabled = 1
     AND dependent.conditional_field_id = ff.id
    WHERE ff.id = ?
      AND ff.form_id = ?
      AND ff.tenant_id = ?
      AND fra.id IS NULL
      AND dependent.id IS NULL
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
