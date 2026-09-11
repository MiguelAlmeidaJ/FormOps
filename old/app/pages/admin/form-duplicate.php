<?php
requireTenantContext();
if (!canManageTenantData()) {
    redirectTo('forms');
}

$tenantId = currentTenantIdForData();
$formId = filter_var($_POST['id'] ?? ($_GET['id'] ?? null), FILTER_VALIDATE_INT);
if (!$formId) {
    redirectTo('forms');
}

$stmt = $pdo->prepare('SELECT * FROM forms WHERE id = ? AND tenant_id = ? LIMIT 1');
$stmt->execute([$formId, $tenantId]);
$form = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$form) {
    redirectTo('forms');
}

function duplicateSlug(PDO $pdo, int $tenantId, string $base): string
{
    $base = trim($base) !== '' ? $base : 'formulario';
    $slug = $base . '-copia';
    $candidate = $slug;
    $counter = 2;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM forms WHERE tenant_id = ? AND slug = ?');
    while (true) {
        $stmt->execute([$tenantId, $candidate]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $candidate;
        }
        $candidate = $slug . '-' . $counter++;
    }
}

$newForm = $form;
unset($newForm['id'], $newForm['created_at'], $newForm['updated_at']);
$newForm['title'] = $form['title'] . ' (Cópia)';
$newForm['slug'] = duplicateSlug($pdo, $tenantId, $form['slug']);
$newForm['is_active'] = 0;
$newForm['created_at'] = date('Y-m-d H:i:s');
$newForm['updated_at'] = date('Y-m-d H:i:s');

$columns = array_keys($newForm);
$sql = 'INSERT INTO forms (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($newForm));
    $newFormId = (int) $pdo->lastInsertId();

    $fields = $pdo->prepare('SELECT * FROM form_fields WHERE tenant_id = ? AND form_id = ? ORDER BY field_order ASC, id ASC');
    $fields->execute([$tenantId, $formId]);
    $fieldRows = $fields->fetchAll(PDO::FETCH_ASSOC);
    $fieldIdMap = [];
    $pendingConditionUpdates = [];

    foreach ($fieldRows as $field) {
        $oldFieldId = (int) $field['id'];
        $oldConditionalFieldId = $field['conditional_field_id'] ?? null;
        unset($field['id']);
        $field['form_id'] = $newFormId;
        $field['conditional_field_id'] = null;
        $field['created_at'] = date('Y-m-d H:i:s');
        $fieldColumns = array_keys($field);
        $fieldSql = 'INSERT INTO form_fields (' . implode(', ', $fieldColumns) . ') VALUES (' . implode(', ', array_fill(0, count($fieldColumns), '?')) . ')';
        $stmt = $pdo->prepare($fieldSql);
        $stmt->execute(array_values($field));
        $newFieldId = (int) $pdo->lastInsertId();
        $fieldIdMap[$oldFieldId] = $newFieldId;
        if ($oldConditionalFieldId) {
            $pendingConditionUpdates[$newFieldId] = (int) $oldConditionalFieldId;
        }
    }

    foreach ($pendingConditionUpdates as $newFieldId => $oldConditionalFieldId) {
        if (!empty($fieldIdMap[$oldConditionalFieldId])) {
            $stmt = $pdo->prepare('UPDATE form_fields SET conditional_field_id = ? WHERE id = ? AND tenant_id = ? AND form_id = ?');
            $stmt->execute([$fieldIdMap[$oldConditionalFieldId], $newFieldId, $tenantId, $newFormId]);
        }
    }

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    redirectTo('form-edit', ['id' => $formId, 'error' => 'duplicate_failed']);
}

redirectTo('form-edit', ['id' => $newFormId, 'success' => 'duplicated']);
