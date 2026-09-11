<?php
requireTenantContext();
if (!canManageTenantData()) { redirectTo('forms'); }

$tenantId = currentTenantIdForData();
$currentUser = user();

$pageTitle = 'Novo formulário';

$errors = [];

$stmt = $pdo->prepare('SELECT id, name FROM form_groups WHERE tenant_id = ? AND is_active = 1 ORDER BY name ASC');
$stmt->execute([$tenantId]);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
$groupsById = [];
foreach ($groups as $group) {
    $groupsById[(int) $group['id']] = $group;
}
$preselectedGroupId = filter_input(INPUT_GET, 'group_id', FILTER_VALIDATE_INT) ?: null;
if (shouldScopeTenantUserToGroup()) {
    $preselectedGroupId = currentUserFormGroupId();
}
if ($preselectedGroupId && !isset($groupsById[$preselectedGroupId])) {
    $preselectedGroupId = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $type = $_POST['type'] ?? 'outro';
    $formGroupId = filter_input(INPUT_POST, 'form_group_id', FILTER_VALIDATE_INT) ?: null;
    if (shouldScopeTenantUserToGroup()) {
        $formGroupId = currentUserFormGroupId();
    }
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $closesAtInput = trim($_POST['closes_at'] ?? '');
    $closesAt = parseDateTimeLocal($closesAtInput);
    $paymentEnabled = isset($_POST['payment_enabled']) ? 1 : 0;

    if ($title === '') {
        $errors[] = 'O título do formulário é obrigatório.';
    }

    if ($formGroupId && !isset($groupsById[$formGroupId])) {
        $errors[] = 'Grupo inválido para este formulário.';
    }

    if ($closesAtInput !== '' && $closesAt === null) {
        $errors[] = 'Informe uma data limite valida.';
    }

    function createSlug($text) {
        $text = mb_strtolower($text, 'UTF-8');
        $text = preg_replace('/[áàãâä]/u', 'a', $text);
        $text = preg_replace('/[éèêë]/u', 'e', $text);
        $text = preg_replace('/[íìîï]/u', 'i', $text);
        $text = preg_replace('/[óòõôö]/u', 'o', $text);
        $text = preg_replace('/[úùûü]/u', 'u', $text);
        $text = preg_replace('/[ç]/u', 'c', $text);
        $text = preg_replace('/[^a-z0-9]+/i', '-', $text);
        $text = trim($text, '-');

        return $text;
    }

    if (empty($errors)) {
        $slugBase = createSlug($title);
        $slug = $slugBase;
        $counter = 1;

        while (true) {
            $stmt = $pdo->prepare("SELECT id FROM forms WHERE tenant_id = ? AND slug = ?");
            $stmt->execute([$tenantId, $slug]);

            if (!$stmt->fetch()) {
                break;
            }

            $slug = $slugBase . '-' . $counter;
            $counter++;
        }

        $stmt = $pdo->prepare("
            INSERT INTO forms 
            (tenant_id, form_group_id, title, slug, description, type, is_active, closes_at, payment_enabled) 
            VALUES 
            (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $tenantId,
            $formGroupId,
            $title,
            $slug,
            $description,
            $type,
            $is_active,
            $closesAt,
            $paymentEnabled
        ]);

        $formId = $pdo->lastInsertId();

        redirectTo('form-edit', ['id' => $formId, 'tab' => $paymentEnabled ? 'payment' : 'general']);
    }
}

require __DIR__ . '/../../../layouts/admin-header.php';
require __DIR__ . '/../../../layouts/admin-sidebar.php';
?>

    <div class="mb-4">
        <a href="forms" class="text-decoration-none">
            ← Voltar para formulários
        </a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-4">

            <h1 class="h4 mb-1">Novo formulário</h1>
            <p class="text-muted mb-4">
                Crie um formulário para inscrições, pesquisas ou outros processos.
            </p>

            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?= htmlspecialchars($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST">

                <div class="mb-3">
                    <label for="title" class="form-label">Título do formulário</label>
                    <input 
                        type="text" 
                        name="title" 
                        id="title" 
                        class="form-control"
                        placeholder="Ex: Inscrição para o evento"
                        value="<?= htmlspecialchars($_POST['title'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="mb-3">
                    <label for="description" class="form-label">Descrição</label>
                    <textarea 
                        name="description" 
                        id="description" 
                        class="form-control"
                        rows="4"
                        placeholder="Explique brevemente o objetivo deste formulário."
                    ><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>

                <div class="mb-3">
                    <label for="type" class="form-label">Tipo de formulário</label>
                    <select name="type" id="type" class="form-select">
                        <option value="inscricao">Inscrição</option>
                        <option value="pesquisa">Pesquisa</option>
                        <option value="contato">Contato</option>
                        <option value="outro" selected>Outro</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label for="form_group_id" class="form-label">Grupo</label>
                    <select name="form_group_id" id="form_group_id" class="form-select">
                        <option value="">Sem grupo</option>
                        <?php $selectedCreateGroup = filter_input(INPUT_POST, 'form_group_id', FILTER_VALIDATE_INT) ?: $preselectedGroupId; ?>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?= (int) $group['id'] ?>" <?= (int) $selectedCreateGroup === (int) $group['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($group['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Use grupos para separar áreas, departamentos, ministérios ou pastas.</div>
                </div>

                <div class="form-check form-switch mb-4">
                    <input 
                        class="form-check-input" 
                        type="checkbox" 
                        name="is_active" 
                        id="is_active"
                        checked
                    >
                    <label class="form-check-label" for="is_active">
                        Formulário ativo
                    </label>
                </div>


                <div class="mb-4">
                    <label for="closes_at" class="form-label">Data e hora limite</label>
                    <input type="datetime-local" name="closes_at" id="closes_at" class="form-control" value="<?= htmlspecialchars($_POST['closes_at'] ?? '') ?>">
                    <div class="form-text">Quando o prazo passar, o formulário será considerado concluído e não receberá novas respostas.</div>
                </div>

                <div class="form-check form-switch mb-4">
                    <input 
                        class="form-check-input" 
                        type="checkbox" 
                        name="payment_enabled" 
                        id="payment_enabled"
                        <?= isset($_POST['payment_enabled']) ? 'checked' : '' ?>
                    >
                    <label class="form-check-label" for="payment_enabled">
                        Ativar pagamento neste formulário
                    </label>
                    <div class="form-text">Depois de criar, configure valor, Pix e instruções na aba Pagamento.</div>
                </div>                <div class="d-flex justify-content-between">
                    <a href="forms" class="btn btn-outline-secondary">
                        Cancelar
                    </a>

                    <button type="submit" class="btn btn-primary">
                        Criar formulário
                    </button>
                </div>

            </form>

        </div>
    </div>

<?php require __DIR__ . '/../../../layouts/admin-footer.php'; ?>
