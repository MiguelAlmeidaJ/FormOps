<?php
requireTenantContext();

$tenantId = currentTenantIdForData();
$groupId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'group_id', FILTER_VALIDATE_INT);

if (!$groupId) {
    redirectTo('forms');
}

$stmt = $pdo->prepare('SELECT id FROM form_groups WHERE id = ? AND tenant_id = ? LIMIT 1');
$stmt->execute([$groupId, $tenantId]);

if (!$stmt->fetch()) {
    redirectTo('groups');
}

redirectTo('forms', ['group_id' => $groupId]);

