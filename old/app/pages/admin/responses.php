<?php

requireTenantContext();

$tenantId = currentTenantIdForData();
$currentUser = user();
$pageTitle = 'Respostas';

function responseTableText(?string $text, int $limit = 60): string
{
    $text = trim((string) $text);
    if ($text === '') {
        return '-';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text) <= $limit ? $text : mb_substr($text, 0, $limit) . '...';
    }

    return strlen($text) <= $limit ? $text : substr($text, 0, $limit) . '...';
}

function responseLabelLower(string $text): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

function findAnswerByLabel(array $fields, array $answers, array $keywords): ?string
{
    foreach ($fields as $field) {
        $label = responseLabelLower((string) $field['label']);

        foreach ($keywords as $keyword) {
            if (str_contains($label, responseLabelLower((string) $keyword))) {
                $answer = trim((string) ($answers[(int) $field['id']] ?? ''));
                if ($answer !== '') {
                    return $answer;
                }
            }
        }
    }

    return null;
}

function findRelevantAnswer(array $fields, array $answers): ?string
{
    $ignoredKeywords = [
        'nome', 'email', 'e-mail', 'whatsapp', 'telefone', 'celular',
        'cidade', 'igreja', 'comunidade',
    ];

    foreach ($fields as $field) {
        $label = responseLabelLower((string) $field['label']);
        $ignored = false;

        foreach ($ignoredKeywords as $keyword) {
            if (str_contains($label, responseLabelLower($keyword))) {
                $ignored = true;
                break;
            }
        }

        $answer = trim((string) ($answers[(int) $field['id']] ?? ''));
        if (!$ignored && $answer !== '') {
            return $answer;
        }
    }

    return null;
}

$stmt = $pdo->prepare('SELECT id, name FROM form_groups WHERE tenant_id = ? ORDER BY name ASC');
$stmt->execute([$tenantId]);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
$groupsById = [];
foreach ($groups as $group) {
    $groupsById[(int) $group['id']] = $group;
}
$selectedGroupId = filter_input(INPUT_GET, 'group_id', FILTER_VALIDATE_INT) ?: null;
if (shouldScopeTenantUserToGroup()) {
    $selectedGroupId = currentUserFormGroupId();
}
if ($selectedGroupId && !isset($groupsById[$selectedGroupId])) {
    $selectedGroupId = null;
}

$formsSql = 'SELECT id, title, form_group_id FROM forms WHERE tenant_id = ?';
$formsParams = [$tenantId];
if ($selectedGroupId) {
    $formsSql .= ' AND form_group_id = ?';
    $formsParams[] = $selectedGroupId;
}
$formsSql .= ' ORDER BY created_at DESC, id DESC';

$stmt = $pdo->prepare($formsSql);
$stmt->execute($formsParams);
$forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

$hasRequestedForm = array_key_exists('form_id', $_GET);
$requestedFormId = filter_input(INPUT_GET, 'form_id', FILTER_VALIDATE_INT);
$formId = $hasRequestedForm
    ? ($requestedFormId ?: null)
    : (isset($forms[0]['id']) ? (int) $forms[0]['id'] : null);
$selectedForm = null;
$invalidForm = $hasRequestedForm && !$requestedFormId;
$fields = [];
$responses = [];
$answersByResponse = [];

if (!$invalidForm && $formId !== null) {
    $selectedFormSql = 'SELECT * FROM forms WHERE id = ? AND tenant_id = ?';
    $selectedFormParams = [$formId, $tenantId];
    if ($selectedGroupId) {
        $selectedFormSql .= ' AND form_group_id = ?';
        $selectedFormParams[] = $selectedGroupId;
    }
    $selectedFormSql .= ' LIMIT 1';
    $stmt = $pdo->prepare($selectedFormSql);
    $stmt->execute($selectedFormParams);
    $selectedForm = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$selectedForm) {
        $invalidForm = true;
        $formId = null;
        http_response_code(404);
    }
}

if ($selectedForm) {
    $stmt = $pdo->prepare(
        'SELECT id, label, type, is_layout
         FROM form_fields
         WHERE tenant_id = ? AND form_id = ? AND is_layout = 0 AND is_visible = 1
         ORDER BY field_order ASC, id ASC'
    );
    $stmt->execute([$tenantId, $formId]);
    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare(
        'SELECT * FROM form_responses
         WHERE tenant_id = ? AND form_id = ?
         ORDER BY submitted_at DESC, id DESC'
    );
    $stmt->execute([$tenantId, $formId]);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($responses) {
        $stmt = $pdo->prepare(
            'SELECT a.response_id, a.field_id, a.answer
             FROM form_response_answers a
             INNER JOIN form_responses r
                ON r.id = a.response_id AND r.tenant_id = a.tenant_id
             WHERE a.tenant_id = ? AND r.form_id = ?'
        );
        $stmt->execute([$tenantId, $formId]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $answer) {
            $answersByResponse[(int) $answer['response_id']][(int) $answer['field_id']] = $answer['answer'];
        }
    }
}

$totalResponses = count($responses);
$lastResponseAt = $responses[0]['submitted_at'] ?? null;

require __DIR__ . '/../../layouts/admin-header.php';
require __DIR__ . '/../../layouts/admin-sidebar.php';
?>

<style>
    .response-summary-card { border: 1px solid #edf0f4; }
    .response-summary-label { color: #6b7280; font-size: .78rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
    .responses-table thead th { background: #f8fafc; color: #475569; font-size: .75rem; font-weight: 700; letter-spacing: .035em; text-transform: uppercase; white-space: nowrap; }
    .responses-table { table-layout: fixed; width: 100%; }
    .responses-table td { color: #334155; font-size: .9rem; padding-top: 1rem; padding-bottom: 1rem; overflow-wrap: anywhere; }
    .responses-table .number-column { width: 7%; }
    .responses-table .participant-column { width: 22%; }
    .responses-table .contact-column { width: 22%; }
    .responses-table .info-column { width: 22%; }
    .responses-table .date-column { width: 15%; }
    .responses-table .actions-column { width: 12%; }
    .response-primary { color: #1f2937; font-weight: 600; line-height: 1.35; }
    .response-secondary { color: #6b7280; font-size: .8rem; line-height: 1.35; margin-top: .25rem; }
    .response-empty { padding: 64px 24px; }
    @media (max-width: 991px) {
        .responses-table .info-column { display: none; }
        .responses-table .number-column { width: 9%; }
        .responses-table .participant-column, .responses-table .contact-column { width: 27%; }
        .responses-table .date-column { width: 21%; }
        .responses-table .actions-column { width: 16%; }
    }
    @media (max-width: 767px) {
        .responses-table .contact-column { display: none; }
        .responses-table .number-column { width: 13%; }
        .responses-table .participant-column { width: 38%; }
        .responses-table .date-column { width: 27%; }
        .responses-table .actions-column { width: 22%; }
    }
</style>

<div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-end gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Respostas</h1>
        <p class="text-muted mb-0">Consulte as respostas recebidas por formulário.</p>
    </div>

    <?php if ($forms || $groups): ?>
        <form method="get" action="<?= htmlspecialchars(appUrl('responses')) ?>" class="d-flex flex-wrap align-items-center gap-2">
            <?php if ($groups): ?>
                <label for="group_id" class="small fw-semibold text-muted text-nowrap">Grupo</label>
                <select id="group_id" name="group_id" class="form-select" style="min-width: 220px" onchange="document.getElementById('form_id')?.removeAttribute('name'); this.form.submit()">
                    <option value="">Todos os grupos</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= (int) $group['id'] ?>" <?= $selectedGroupId === (int) $group['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($group['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <?php if ($forms): ?>
            <label for="form_id" class="small fw-semibold text-muted text-nowrap">Formulário</label>
            <select id="form_id" name="form_id" class="form-select" style="min-width: 280px" onchange="this.form.submit()">
                <?php foreach ($forms as $form): ?>
                    <option value="<?= (int) $form['id'] ?>" <?= (int) $formId === (int) $form['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($form['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>

<?php if ($invalidForm): ?>
    <div class="alert alert-warning">O formulário informado não existe ou não pertence a esta empresa.</div>
<?php elseif (!$forms): ?>
    <div class="card shadow-sm">
        <div class="card-body response-empty text-center">
            <h2 class="h5 mb-2">Nenhum formulário cadastrado</h2>
            <p class="text-muted mb-3">Crie um formulário para começar a receber respostas.</p>
            <?php if (canManageTenantData()): ?>
                <a href="<?= htmlspecialchars(appUrl('form-create', $selectedGroupId ? ['group_id' => $selectedGroupId] : [])) ?>" class="btn btn-primary">Criar formulário</a>
            <?php endif; ?>
        </div>
    </div>
<?php elseif ($selectedForm): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card response-summary-card shadow-sm h-100"><div class="card-body">
                <div class="response-summary-label mb-2">Total de respostas</div>
                <div class="h3 mb-0"><?= $totalResponses ?></div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card response-summary-card shadow-sm h-100"><div class="card-body">
                <div class="response-summary-label mb-2">Última resposta</div>
                <div class="fw-semibold"><?= $lastResponseAt ? htmlspecialchars(date('d/m/Y \à\s H:i', strtotime($lastResponseAt))) : 'Nenhuma ainda' ?></div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card response-summary-card shadow-sm h-100"><div class="card-body">
                <div class="response-summary-label mb-2">Formulário selecionado</div>
                <div class="fw-semibold text-truncate" title="<?= htmlspecialchars($selectedForm['title']) ?>"><?= htmlspecialchars($selectedForm['title']) ?></div>
            </div></div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white border-0 d-flex justify-content-between align-items-center px-4 py-3">
            <div>
                <h2 class="h6 mb-0">Respostas recebidas</h2>
                <span class="text-muted small"><?= $totalResponses ?> <?= $totalResponses === 1 ? 'registro' : 'registros' ?></span>
            </div>
            <span class="badge rounded-pill text-bg-light border"><?= $totalResponses ?></span>
        </div>

        <?php if ($responses): ?>
            <div class="card-body p-0">
                <div class="w-100 overflow-hidden">
                    <table class="table table-hover align-middle mb-0 responses-table">
                        <thead>
                            <tr>
                                <th class="ps-4 number-column">Nº</th>
                                <th class="participant-column">Participante</th>
                                <th class="contact-column">Contato</th>
                                <th class="info-column">Informações principais</th>
                                <th class="date-column">Enviado em</th>
                                <th class="text-end pe-4 actions-column">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($responses as $response): ?>
                                <?php
                                $responseAnswers = $answersByResponse[(int) $response['id']] ?? [];
                                $name = findAnswerByLabel($fields, $responseAnswers, ['nome']);
                                $church = findAnswerByLabel($fields, $responseAnswers, ['igreja', 'comunidade']);
                                $email = findAnswerByLabel($fields, $responseAnswers, ['email', 'e-mail']);
                                $phone = findAnswerByLabel($fields, $responseAnswers, ['whatsapp', 'telefone', 'celular']);
                                $city = findAnswerByLabel($fields, $responseAnswers, ['cidade']);
                                $relevant = findAnswerByLabel($fields, $responseAnswers, ['empreende', 'negócio', 'area', 'área'])
                                    ?? findRelevantAnswer($fields, $responseAnswers);
                                $submittedTimestamp = strtotime($response['submitted_at']);
                                ?>
                                <tr>
                                    <td class="ps-4 number-column fw-semibold">#<?= (int) ($response['response_number'] ?? $response['id']) ?></td>
                                    <td class="participant-column">
                                        <div class="response-primary"><?= htmlspecialchars(responseTableText($name, 45)) ?></div>
                                        <?php if ($church): ?><div class="response-secondary"><?= htmlspecialchars(responseTableText($church, 45)) ?></div><?php endif; ?>
                                    </td>
                                    <td class="contact-column">
                                        <div class="response-primary fw-normal"><?= htmlspecialchars(responseTableText($email, 45)) ?></div>
                                        <?php if ($phone): ?><div class="response-secondary"><?= htmlspecialchars(responseTableText($phone, 30)) ?></div><?php endif; ?>
                                    </td>
                                    <td class="info-column">
                                        <div class="response-primary fw-normal"><?= htmlspecialchars(responseTableText($city, 40)) ?></div>
                                        <?php if ($relevant): ?><div class="response-secondary"><?= htmlspecialchars(responseTableText($relevant, 55)) ?></div><?php endif; ?>
                                    </td>
                                    <td class="date-column">
                                        <div class="response-primary fw-normal"><?= htmlspecialchars(date('d/m/Y', $submittedTimestamp)) ?></div>
                                        <div class="response-secondary"><?= htmlspecialchars(date('H:i', $submittedTimestamp)) ?></div>
                                    </td>
                                    <td class="text-end pe-4 actions-column">
                                        <a href="<?= htmlspecialchars(appUrl('response-view', ['id' => (int) $response['id'], 'form_id' => (int) $formId])) ?>" class="btn btn-sm btn-outline-secondary text-nowrap">Ver detalhes</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="card-body response-empty text-center">
                <h3 class="h5 mb-2">Nenhuma resposta recebida ainda.</h3>
                <p class="text-muted mb-0">Quando alguém responder este formulário, as respostas aparecerão aqui.</p>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../layouts/admin-footer.php'; ?>
