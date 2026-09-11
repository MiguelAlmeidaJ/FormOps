<?php

requireTenantContext();

$currentUser = user();
$canDeleteForms = isSuperAdmin() || (isTenantUser() && ($currentUser['role'] ?? null) === 'admin');

if (!$canDeleteForms) {
    $_SESSION['flash_error'] = 'Você não tem permissão para executar esta ação.';
    redirectTo('forms');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectTo('forms');
}

$tenantId = currentTenantIdForData();
$formId = filter_input(INPUT_POST, 'form_id', FILTER_VALIDATE_INT);

if (!$tenantId || !$formId) {
    $_SESSION['flash_error'] = 'Não foi possível apagar este formulário.';
    redirectTo('forms');
}

function formDeleteTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function formDeleteRemoveDirectory(string $directory, string $allowedRoot): void
{
    $root = realpath($allowedRoot);
    $target = realpath($directory);

    if (!$root || !$target || !str_starts_with($target, $root . DIRECTORY_SEPARATOR)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($target);
}

try {
    $stmt = $pdo->prepare('SELECT id, title, form_group_id FROM forms WHERE id = ? AND tenant_id = ? LIMIT 1');
    $stmt->execute([$formId, $tenantId]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$form) {
        $_SESSION['flash_error'] = 'Formulário não encontrado.';
        redirectTo('forms');
    }

    if (!currentUserCanAccessFormGroup(!empty($form['form_group_id']) ? (int) $form['form_group_id'] : null)) {
        $_SESSION['flash_error'] = 'Você não tem permissão para apagar este formulário.';
        redirectTo('forms');
    }

    $stmt = $pdo->prepare("
        SELECT default_value
        FROM form_fields
        WHERE tenant_id = ?
          AND form_id = ?
          AND type = 'banner'
          AND default_value LIKE 'assets/uploads/%'
    ");
    $stmt->execute([$tenantId, $formId]);
    $bannerPaths = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $pdo->beginTransaction();

    foreach (['form_coupon_redemptions', 'form_group_discounts', 'form_discount_coupons', 'form_payment_lots'] as $pricingTable) {
        if (formDeleteTableExists($pdo, $pricingTable)) {
            $stmt = $pdo->prepare('DELETE FROM ' . $pricingTable . ' WHERE tenant_id = ? AND form_id = ?');
            $stmt->execute([$tenantId, $formId]);
        }
    }

    if (formDeleteTableExists($pdo, 'form_tickets')) {
        $stmt = $pdo->prepare('DELETE FROM form_tickets WHERE tenant_id = ? AND form_id = ?');
        $stmt->execute([$tenantId, $formId]);
    }

    if (formDeleteTableExists($pdo, 'form_response_answers')) {
        $stmt = $pdo->prepare("
            DELETE fra
            FROM form_response_answers AS fra
            INNER JOIN form_responses AS fr
                ON fr.id = fra.response_id
               AND fr.tenant_id = fra.tenant_id
            WHERE fr.tenant_id = ?
              AND fr.form_id = ?
        ");
        $stmt->execute([$tenantId, $formId]);

        $stmt = $pdo->prepare("
            DELETE fra
            FROM form_response_answers AS fra
            INNER JOIN form_fields AS ff
                ON ff.id = fra.field_id
               AND ff.tenant_id = fra.tenant_id
            WHERE ff.tenant_id = ?
              AND ff.form_id = ?
        ");
        $stmt->execute([$tenantId, $formId]);
    }

    if (formDeleteTableExists($pdo, 'form_responses')) {
        $stmt = $pdo->prepare('DELETE FROM form_responses WHERE tenant_id = ? AND form_id = ?');
        $stmt->execute([$tenantId, $formId]);
    }

    if (formDeleteTableExists($pdo, 'form_fields')) {
        $stmt = $pdo->prepare('DELETE FROM form_fields WHERE tenant_id = ? AND form_id = ?');
        $stmt->execute([$tenantId, $formId]);
    }

    $stmt = $pdo->prepare('DELETE FROM forms WHERE tenant_id = ? AND id = ?');
    $stmt->execute([$tenantId, $formId]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Form delete affected an unexpected number of rows.');
    }

    $pdo->commit();

    $uploadsRoot = __DIR__ . '/../../../public/assets/uploads';
    foreach ($bannerPaths as $bannerPath) {
        $bannerFile = realpath(__DIR__ . '/../../../public/' . ltrim((string) $bannerPath, '/'));
        $root = realpath($uploadsRoot);
        if ($root && $bannerFile && str_starts_with($bannerFile, $root . DIRECTORY_SEPARATOR)) {
            @unlink($bannerFile);
        }
    }

    formDeleteRemoveDirectory(
        __DIR__ . '/../../../public/storage/tenants/' . $tenantId . '/forms/' . $formId,
        __DIR__ . '/../../../public/storage/tenants/' . $tenantId . '/forms'
    );

    $_SESSION['flash_success'] = 'Formulario apagado com sucesso.';
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['flash_error'] = 'Não foi possível apagar este formulário.';
}

redirectTo('forms');
