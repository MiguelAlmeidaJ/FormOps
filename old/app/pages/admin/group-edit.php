<?php
requireTenantContext();
if (!canManageTenantData()) {
    redirectTo('groups');
}
if (shouldScopeTenantUserToGroup()) {
    redirectTo('groups');
}

$tenantId = currentTenantIdForData();
$currentUser = user();
$pageTitle = 'Editar grupo';
$errors = [];

$groupId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$groupId) {
    redirectTo('groups');
}

if (!function_exists('formOpsGroupSlug')) {
    function formOpsGroupSlug(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[áàãâä]/u', 'a', $text);
        $text = preg_replace('/[éèêë]/u', 'e', $text);
        $text = preg_replace('/[íìîï]/u', 'i', $text);
        $text = preg_replace('/[óòõôö]/u', 'o', $text);
        $text = preg_replace('/[úùûü]/u', 'u', $text);
        $text = preg_replace('/[ç]/u', 'c', $text);
        $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
        return trim($text, '-') ?: 'grupo';
    }
}

$stmt = $pdo->prepare('SELECT * FROM form_groups WHERE id = ? AND tenant_id = ? LIMIT 1');
$stmt->execute([$groupId, $tenantId]);
$group = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$group) {
    http_response_code(404);
    die('Grupo não encontrado.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $slug = formOpsGroupSlug(trim($_POST['slug'] ?? ''));
    $color = trim($_POST['color'] ?? '#0d6efd');
    $icon = trim($_POST['icon'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        $errors[] = 'O nome do grupo é obrigatório.';
    }

    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        $errors[] = 'Use apenas letras minúsculas, números e hífens no slug.';
    }

    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $errors[] = 'Informe uma cor válida.';
    }

    if ($icon !== '' && mb_strlen($icon, 'UTF-8') > 80) {
        $errors[] = 'O ícone deve ter no máximo 80 caracteres.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id FROM form_groups WHERE tenant_id = ? AND slug = ? AND id != ? LIMIT 1');
        $stmt->execute([$tenantId, $slug, $groupId]);
        if ($stmt->fetch()) {
            $errors[] = 'Já existe outro grupo com este slug.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'UPDATE form_groups
             SET name = ?, description = ?, slug = ?, color = ?, icon = ?, is_active = ?, updated_at = NOW()
             WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute([
            $name,
            $description !== '' ? $description : null,
            $slug,
            $color,
            $icon !== '' ? $icon : null,
            $isActive,
            $groupId,
            $tenantId,
        ]);

        redirectTo('groups', ['success' => 'updated']);
    }
}

require __DIR__ . '/../../layouts/admin-header.php';
require __DIR__ . '/../../layouts/admin-sidebar.php';
?>

<div class="mb-4">
    <a href="<?= htmlspecialchars(appUrl('groups')) ?>" class="text-decoration-none">← Voltar para grupos</a>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-4">
        <h1 class="h4 mb-1">Editar grupo</h1>
        <p class="text-muted mb-4">Ajuste como este grupo aparece no painel do FormOps.</p>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <div><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <div class="row g-3">
                <div class="col-md-8">
                    <label for="name" class="form-label">Nome do grupo</label>
                    <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($_POST['name'] ?? $group['name']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="slug" class="form-label">Slug</label>
                    <input type="text" class="form-control" id="slug" name="slug" value="<?= htmlspecialchars($_POST['slug'] ?? $group['slug']) ?>" required>
                </div>
                <div class="col-12">
                    <label for="description" class="form-label">Descrição</label>
                    <textarea class="form-control" id="description" name="description" rows="3"><?= htmlspecialchars($_POST['description'] ?? ($group['description'] ?? '')) ?></textarea>
                </div>
                <div class="col-md-4">
                    <label for="color" class="form-label">Cor do grupo</label>
                    <input type="color" class="form-control form-control-color w-100" id="color" name="color" value="<?= htmlspecialchars($_POST['color'] ?? ($group['color'] ?: '#0d6efd')) ?>">
                </div>
                <div class="col-md-4">
                    <label for="icon" class="form-label">Ícone ou inicial</label>
                    <input type="text" class="form-control" id="icon" name="icon" value="<?= htmlspecialchars($_POST['icon'] ?? ($group['icon'] ?? '')) ?>">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" <?= (int) ($_POST ? isset($_POST['is_active']) : ($group['is_active'] ?? 1)) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Grupo ativo</label>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between mt-4">
                <a href="<?= htmlspecialchars(appUrl('groups')) ?>" class="btn btn-outline-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary">Salvar alterações</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../layouts/admin-footer.php'; ?>
