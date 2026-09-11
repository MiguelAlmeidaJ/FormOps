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
$pageTitle = 'Novo grupo';
$errors = [];

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $color = trim($_POST['color'] ?? '#212121');
    $icon = trim($_POST['icon'] ?? '');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        $errors[] = 'O nome do grupo é obrigatório.';
    }

    $slug = $slug !== '' ? formOpsGroupSlug($slug) : formOpsGroupSlug($name);
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
        $stmt = $pdo->prepare('SELECT id FROM form_groups WHERE tenant_id = ? AND slug = ? LIMIT 1');
        $stmt->execute([$tenantId, $slug]);
        if ($stmt->fetch()) {
            $errors[] = 'Já existe um grupo com este slug.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'INSERT INTO form_groups (tenant_id, name, description, slug, color, icon, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $tenantId,
            $name,
            $description !== '' ? $description : null,
            $slug,
            $color,
            $icon !== '' ? $icon : null,
            $isActive,
        ]);

        redirectTo('groups', ['success' => 'created']);
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
        <h1 class="h4 mb-1">Novo grupo</h1>
        <p class="text-muted mb-4">Crie uma área para organizar formulários por assunto, ministério, departamento ou operação.</p>

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
                    <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" placeholder="Ex: Ministério de Mulheres" required>
                </div>
                <div class="col-md-4">
                    <label for="slug" class="form-label">Slug</label>
                    <input type="text" class="form-control" id="slug" name="slug" value="<?= htmlspecialchars($_POST['slug'] ?? '') ?>" placeholder="ministerio-de-mulheres">
                </div>
                <div class="col-12">
                    <label for="description" class="form-label">Descrição</label>
                    <textarea class="form-control" id="description" name="description" rows="3" placeholder="Explique quando usar este grupo."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>
                <div class="col-md-4">
                    <label for="color" class="form-label">Cor do grupo</label>
                    <input type="color" class="form-control form-control-color w-100" id="color" name="color" value="<?= htmlspecialchars($_POST['color'] ?? '#212121') ?>">
                </div>
                <div class="col-md-4">
                    <label for="icon" class="form-label">Ícone ou inicial</label>
                    <input type="text" class="form-control" id="icon" name="icon" value="<?= htmlspecialchars($_POST['icon'] ?? '') ?>" placeholder="Ex: M, Secretaria, 🙌">
                    <div class="form-text">Opcional. Use uma inicial, palavra curta ou emoji.</div>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
                        <label class="form-check-label" for="is_active">Grupo ativo</label>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between mt-4">
                <a href="<?= htmlspecialchars(appUrl('groups')) ?>" class="btn btn-outline-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary">Criar grupo</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../layouts/admin-footer.php'; ?>
