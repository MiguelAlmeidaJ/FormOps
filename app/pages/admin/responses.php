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

function responseActionIcon(string $name): string
{
    $paths = [
        'view' => '<path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6S2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="2.5"/>',
        'approve' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16.5 9"/>',
        'resend' => '<path d="M22 2 11 13"/><path d="m22 2-7 20-4-9-9-4Z"/><path d="M11 13 2 9"/>',
        'delete' => '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="m19 6-1 14H6L5 6"/><path d="M10 11v5M14 11v5"/>',
    ];
    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['view']) . '</svg>';
}

$groupsSql = 'SELECT id, name FROM form_groups WHERE tenant_id = ?';
$groupsParams = [$tenantId];
if (shouldScopeTenantUserToGroup()) {
    $groupsSql .= ' AND ' . currentUserFormGroupScopeSql('id');
    $groupsParams = array_merge($groupsParams, currentUserFormGroupIds());
}
$groupsSql .= ' ORDER BY name ASC';
$stmt = $pdo->prepare($groupsSql);
$stmt->execute($groupsParams);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
$groupsById = [];
foreach ($groups as $group) {
    $groupsById[(int) $group['id']] = $group;
}
$selectedGroupId = filter_input(INPUT_GET, 'group_id', FILTER_VALIDATE_INT) ?: null;
if ($selectedGroupId && !isset($groupsById[$selectedGroupId])) {
    $selectedGroupId = null;
}

$formsSql = 'SELECT id, title, form_group_id FROM forms WHERE tenant_id = ?';
$formsParams = [$tenantId];
if ($selectedGroupId) {
    $formsSql .= ' AND form_group_id = ?';
    $formsParams[] = $selectedGroupId;
} elseif (shouldScopeTenantUserToGroup()) {
    $formsSql .= ' AND ' . currentUserFormGroupScopeSql('form_group_id');
    $formsParams = array_merge($formsParams, currentUserFormGroupIds());
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
$ticketsByResponse = [];

if (!$invalidForm && $formId !== null) {
    $selectedFormSql = 'SELECT * FROM forms WHERE id = ? AND tenant_id = ?';
    $selectedFormParams = [$formId, $tenantId];
    if ($selectedGroupId) {
        $selectedFormSql .= ' AND form_group_id = ?';
        $selectedFormParams[] = $selectedGroupId;
    } elseif (shouldScopeTenantUserToGroup()) {
        $selectedFormSql .= ' AND ' . currentUserFormGroupScopeSql('form_group_id');
        $selectedFormParams = array_merge($selectedFormParams, currentUserFormGroupIds());
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
        'SELECT *
         FROM form_fields
         WHERE tenant_id = ? AND form_id = ? AND is_layout = 0 AND is_visible = 1
         ORDER BY field_order ASC, id ASC'
    );
    $stmt->execute([$tenantId, $formId]);
    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $responseCsrfToken = $_SESSION['response_actions_csrf'] ??= bin2hex(random_bytes(32));
    require __DIR__ . '/response-actions.php';

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

        $stmt = $pdo->prepare('SELECT * FROM form_tickets WHERE tenant_id = ? AND form_id = ?');
        $stmt->execute([$tenantId, $formId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ticket) {
            $ticketsByResponse[(int) $ticket['response_id']] = $ticket;
        }
    }
}

$totalResponses = count($responses);
$lastResponseAt = $responses[0]['submitted_at'] ?? null;
$weekStart = strtotime('monday this week 00:00');
$responsesThisWeek = count(array_filter($responses, static fn (array $response): bool => strtotime((string) $response['submitted_at']) >= $weekStart));

require __DIR__ . '/../../layouts/admin-header.php';
require __DIR__ . '/../../layouts/admin-sidebar.php';
?>

<style>
    .responses-filter-control:only-child{grid-column:1/-1}
    .responses-hero{padding:26px 28px 22px;margin-bottom:22px;border:1px solid #e5e5e5;border-radius:20px;background:#f8f8f8;box-shadow:0 16px 38px rgba(33,33,33,.045)}.responses-hero-top{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:22px}.responses-hero-title{font-size:30px;line-height:1.1;font-weight:800;letter-spacing:-.035em;color:#212121;margin:0 0 6px}.responses-hero-subtitle{font-size:15px;color:#666;margin:0}.responses-add{min-height:46px;padding:0 18px;border-radius:10px;font-weight:700;white-space:nowrap}.responses-filter-box{padding:17px 18px;border:1px solid #e5e5e5;border-radius:14px;background:#fff}.responses-filter-title{display:flex;align-items:center;gap:8px;margin-bottom:12px;color:#555;font-size:12px;font-weight:800;letter-spacing:.07em;text-transform:uppercase}.responses-filter-grid{display:grid;grid-template-columns:minmax(180px,35fr) minmax(280px,65fr);gap:12px}.responses-filter-control label{display:block;margin-bottom:6px;color:#555;font-size:12px;font-weight:700}.responses-filter-control .form-select{min-height:46px;border-radius:10px;border-color:#ddd}.responses-metrics{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:14px}.responses-metric{display:flex;align-items:center;gap:13px;min-height:82px;padding:14px 16px;border:1px solid #e7e7e7;border-radius:14px;background:#fff}.responses-metric-icon{width:38px;height:38px;flex:0 0 auto;display:grid;place-items:center;border-radius:12px;background:#f1f1f1;color:#333}.responses-metric-label{color:#777;font-size:10px;font-weight:800;letter-spacing:.07em;text-transform:uppercase}.responses-metric-value{color:#212121;font-size:23px;line-height:1.15;font-weight:850;margin-top:2px}.responses-metric-value.date{font-size:16px}.responses-metric-note{color:#777;font-size:11px;margin-top:2px}
    .responses-table thead th { background: #F3F3F3; color: #555555; font-size: .75rem; font-weight: 700; letter-spacing: .035em; text-transform: uppercase; white-space: nowrap; }
    .responses-table { table-layout: fixed; width: 100%; }
    .responses-table td { color: #212121; font-size: .9rem; padding-top: 1rem; padding-bottom: 1rem; overflow-wrap: anywhere; }
    .responses-table .select-column { width: 5%; text-align:center; }
    .responses-table .number-column { width: 7%; }
    .responses-table .participant-column { width: 20%; }
    .responses-table .contact-column { width: 20%; }
    .responses-table .info-column { width: 21%; }
    .responses-table .date-column { width: 15%; }
    .responses-table .actions-column { width: 12%; }
    .response-primary { color: #212121; font-weight: 600; line-height: 1.35; }
    .response-secondary { color: #555555; font-size: .8rem; line-height: 1.35; margin-top: .25rem; }
    .response-empty { padding: 64px 24px; }
    .response-row-actions { display:flex; justify-content:flex-end; align-items:center; gap:7px; }
    .response-row-actions form { margin:0; }
    .response-icon-action { width:36px; height:36px; display:inline-grid; place-items:center; border:1px solid #CECECE; border-radius:9px; background:#fff; color:#555555; padding:0; transition:.15s ease; }
    .response-icon-action:hover { color:#212121; border-color:#CECECE; background:#F3F3F3; }
    .response-icon-action.approve { color:#047857; }
    .response-icon-action.approve:hover { color:#065f46; border-color:#86efac; background:#f0fdf4; }
    .response-icon-action.resend { color:#555555; }
    .response-icon-action.resend:hover { color:#212121; border-color:#c4b5fd; background:#f5f3ff; }
    .response-icon-action.delete { color:#DC2626; }
    .response-icon-action.delete:hover { color:#991B1B; border-color:#FCA5A5; background:#FEF2F2; }
    .response-bulk-delete { min-height:36px; font-weight:700; white-space:nowrap; }
    .response-select { width:17px; height:17px; cursor:pointer; }
    .response-payment-state { display:inline-flex; border-radius:999px; padding:3px 7px; font-size:10px; font-weight:800; margin-top:6px; }
    .response-payment-state.pending { background:#fef3c7; color:#92400e; }
    .response-payment-state.approved { background:#dcfce7; color:#166534; }
    .manual-choice-group { display:grid; gap:7px; border:1px solid #CECECE; border-radius:10px; padding:12px; }
    #addParticipantModal .modal-content { max-width:100%; }
    #addParticipantModal .modal-footer { background:#fff; }
    @media (max-width: 991px) {
        .responses-table .info-column { display: none; }
        .responses-table .select-column { width: 6%; }
        .responses-table .number-column { width: 9%; }
        .responses-table .participant-column, .responses-table .contact-column { width: 25%; }
        .responses-table .date-column { width: 20%; }
        .responses-table .actions-column { width: 15%; }
    }
    @media (max-width: 767px) {
        .responses-hero{padding:21px 18px}.responses-hero-top{display:block}.responses-add{width:100%;margin-top:17px}.responses-filter-grid,.responses-metrics{grid-template-columns:1fr}.responses-metric{min-height:72px}
        .responses-table .contact-column { display: none; }
        .responses-table .select-column { width: 9%; }
        .responses-table .number-column { width: 13%; }
        .responses-table .participant-column { width: 33%; }
        .responses-table .date-column { width: 25%; }
        .responses-table .actions-column { width: 20%; }
        #addParticipantModal .modal-body { padding:18px 16px; }
        #addParticipantModal .modal-header { padding:16px; }
        #addParticipantModal .modal-footer { position:sticky; bottom:0; z-index:2; display:grid; grid-template-columns:1fr 1fr; gap:10px; padding:12px 16px calc(12px + env(safe-area-inset-bottom)); border-top:1px solid #E5E7EB; box-shadow:0 -8px 22px rgba(15,23,42,.08); }
        #addParticipantModal .modal-footer > .btn { width:100%; min-height:46px; margin:0; }
        #addParticipantModal .form-control, #addParticipantModal .form-select { min-height:48px; font-size:16px; }
        #addParticipantModal .manual-choice-group .form-check { min-height:44px; display:flex; align-items:center; gap:10px; margin:0; }
        #addParticipantModal .manual-choice-group .form-check-input { width:20px; height:20px; margin-top:0; }
        #addParticipantModal .manual-choice-group .form-check-label { width:100%; padding:10px 0; }
    }
</style>

<section class="responses-hero">
    <div class="responses-hero-top">
        <div><h1 class="responses-hero-title">Respostas</h1><p class="responses-hero-subtitle">Consulte e gerencie as respostas dos seus formulários.</p></div>
        <?php if ($selectedForm && canManageTenantData()): ?><button type="button" class="btn btn-primary responses-add" data-bs-toggle="modal" data-bs-target="#addParticipantModal">+ Adicionar participante</button><?php endif; ?>
    </div>

    <?php if ($forms || $groups): ?>
        <form method="get" action="<?= htmlspecialchars(appUrl('responses')) ?>" class="responses-filter-box">
            <div class="responses-filter-title"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 5h16M7 12h10M10 19h4"/></svg> Filtrar respostas</div>
            <div class="responses-filter-grid">
                <?php if ($groups): ?><div class="responses-filter-control"><label for="group_id">Grupo</label><select id="group_id" name="group_id" class="form-select" onchange="document.getElementById('form_id')?.removeAttribute('name'); this.form.submit()"><option value="">Todos os grupos</option><?php foreach ($groups as $group): ?><option value="<?= (int) $group['id'] ?>" <?= $selectedGroupId === (int) $group['id'] ? 'selected' : '' ?>><?= htmlspecialchars($group['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                <?php if ($forms): ?><div class="responses-filter-control"><label for="form_id">Formulário</label><select id="form_id" name="form_id" class="form-select" onchange="this.form.submit()"><?php foreach ($forms as $form): ?><option value="<?= (int) $form['id'] ?>" <?= (int) $formId === (int) $form['id'] ? 'selected' : '' ?>><?= htmlspecialchars($form['title']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($selectedForm): ?><div class="responses-metrics">
        <div class="responses-metric"><div class="responses-metric-icon">∑</div><div><div class="responses-metric-label">Total de respostas</div><div class="responses-metric-value"><?= $totalResponses ?></div><div class="responses-metric-note">participantes cadastrados</div></div></div>
        <div class="responses-metric"><div class="responses-metric-icon">↗</div><div><div class="responses-metric-label">Esta semana</div><div class="responses-metric-value"><?= $responsesThisWeek ?></div><div class="responses-metric-note">novas respostas</div></div></div>
        <div class="responses-metric"><div class="responses-metric-icon">◷</div><div><div class="responses-metric-label">Última resposta</div><div class="responses-metric-value date"><?= $lastResponseAt ? htmlspecialchars(date('d/m/Y', strtotime($lastResponseAt))) : 'Nenhuma ainda' ?></div><?php if ($lastResponseAt): ?><div class="responses-metric-note">às <?= htmlspecialchars(date('H:i', strtotime($lastResponseAt))) ?></div><?php endif; ?></div></div>
    </div><?php endif; ?>
</section>

<?php
$responseSuccessMessages = [
    'participant_added' => 'Participante adicionado com sucesso.',
    'participant_approved' => 'Participante aprovado com sucesso. O ingresso foi processado quando aplicável.',
    'ticket_resent' => 'Ingresso reenviado por e-mail.',
    'ticket_resend_failed' => 'O ingresso está disponível, mas o servidor não confirmou o reenvio do e-mail.',
    'response_deleted' => 'Inscrição excluída com sucesso.',
    'responses_deleted' => max(0, (int) ($_GET['deleted'] ?? 0)) . ' inscrições excluídas com sucesso.',
];
$responseErrorMessages = [
    'permission' => 'Você não tem permissão para executar esta ação.',
    'response_not_found' => 'Participante não encontrado.',
    'approval_failed' => 'Não foi possível aprovar o participante.',
    'ticket_not_found' => 'O ingresso deste participante ainda não foi emitido.',
    'selection_required' => 'Selecione ao menos uma inscrição para excluir.',
    'invalid_request' => 'A solicitação expirou. Atualize a página e tente novamente.',
    'delete_failed' => 'Não foi possível excluir as inscrições selecionadas.',
];
?>
<?php if (isset($responseSuccessMessages[$_GET['success'] ?? ''])): ?><div class="alert alert-success"><?= htmlspecialchars($responseSuccessMessages[$_GET['success']]) ?></div><?php endif; ?>
<?php if (isset($responseErrorMessages[$_GET['error'] ?? ''])): ?><div class="alert alert-danger"><?= htmlspecialchars($responseErrorMessages[$_GET['error']]) ?></div><?php endif; ?>
<?php if (!empty($responseOperationErrors)): ?><div class="alert alert-danger">Não foi possível adicionar o participante. Revise os campos no formulário.</div><?php endif; ?>

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
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white border-0 d-flex flex-wrap justify-content-between align-items-center gap-3 px-4 py-3">
            <div>
                <h2 class="h6 mb-0">Respostas recebidas</h2>
                <span class="text-muted small"><?= $totalResponses ?> <?= $totalResponses === 1 ? 'registro' : 'registros' ?></span>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <?php if ($responses && canManageTenantData()): ?>
                    <form id="bulk-delete-responses-form" method="post" action="<?= htmlspecialchars(appUrl('responses', responseActionRedirectParams($formId, $selectedGroupId))) ?>" data-confirm="Excluir as inscrições selecionadas? Esta ação não poderá ser desfeita." data-confirm-button="Excluir selecionadas">
                        <input type="hidden" name="response_action" value="delete_responses">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($responseCsrfToken) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger response-bulk-delete" id="bulk-delete-responses-button" disabled>Excluir selecionadas (<span id="bulk-delete-responses-count">0</span>)</button>
                    </form>
                <?php endif; ?>
                <span class="badge rounded-pill text-bg-light border"><?= $totalResponses ?></span>
            </div>
        </div>

        <?php if ($responses): ?>
            <div class="card-body p-0">
                <div class="w-100 overflow-hidden">
                    <table class="table table-hover align-middle mb-0 responses-table">
                        <thead>
                            <tr>
                                <?php if (canManageTenantData()): ?><th class="ps-4 select-column"><input class="form-check-input response-select" type="checkbox" id="select-all-responses" aria-label="Selecionar todas as inscrições"></th><?php endif; ?>
                                <th class="<?= canManageTenantData() ? '' : 'ps-4 ' ?>number-column">Nº</th>
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
                                $responseTicket = $ticketsByResponse[(int) $response['id']] ?? null;
                                ?>
                                <tr>
                                    <?php if (canManageTenantData()): ?><td class="ps-4 select-column"><input class="form-check-input response-select response-bulk-select" type="checkbox" name="response_ids[]" value="<?= (int) $response['id'] ?>" form="bulk-delete-responses-form" aria-label="Selecionar inscrição nº <?= (int) ($response['response_number'] ?? $response['id']) ?>"></td><?php endif; ?>
                                    <td class="<?= canManageTenantData() ? '' : 'ps-4 ' ?>number-column fw-semibold">#<?= (int) ($response['response_number'] ?? $response['id']) ?></td>
                                    <td class="participant-column">
                                        <div class="response-primary"><?= htmlspecialchars(responseTableText($name, 45)) ?></div>
                                        <?php if ($church): ?><div class="response-secondary"><?= htmlspecialchars(responseTableText($church, 45)) ?></div><?php endif; ?>
                                        <?php if ((int) ($selectedForm['payment_enabled'] ?? 0) === 1): ?><span class="response-payment-state <?= ($response['payment_status'] ?? 'pending') === 'approved' ? 'approved' : 'pending' ?>"><?= ($response['payment_status'] ?? 'pending') === 'approved' ? 'Aprovado' : 'Pagamento pendente' ?></span><?php endif; ?>
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
                                        <div class="response-row-actions">
                                            <a href="<?= htmlspecialchars(appUrl('response-view', ['id' => (int) $response['id'], 'form_id' => (int) $formId])) ?>" class="response-icon-action" title="Ver detalhes" aria-label="Ver detalhes"><?= responseActionIcon('view') ?></a>
                                            <?php if (canManageTenantData() && (int) ($selectedForm['payment_enabled'] ?? 0) === 1 && ($response['payment_status'] ?? 'pending') !== 'approved'): ?>
                                                <form method="post" action="<?= htmlspecialchars(appUrl('responses', responseActionRedirectParams($formId, $selectedGroupId))) ?>" data-confirm="Aprovar este participante?" data-confirm-button="Aprovar"><input type="hidden" name="response_action" value="approve_participant"><input type="hidden" name="response_id" value="<?= (int) $response['id'] ?>"><button class="response-icon-action approve" type="submit" title="Aprovar participante" aria-label="Aprovar participante"><?= responseActionIcon('approve') ?></button></form>
                                            <?php endif; ?>
                                            <?php if (canManageTenantData() && (int) ($selectedForm['ticket_enabled'] ?? 0) === 1 && $responseTicket): ?>
                                                <form method="post" action="<?= htmlspecialchars(appUrl('responses', responseActionRedirectParams($formId, $selectedGroupId))) ?>"><input type="hidden" name="response_action" value="resend_ticket"><input type="hidden" name="response_id" value="<?= (int) $response['id'] ?>"><button class="response-icon-action resend" type="submit" title="Reenviar ingresso" aria-label="Reenviar ingresso"><?= responseActionIcon('resend') ?></button></form>
                                            <?php endif; ?>
                                            <?php if (canManageTenantData()): ?>
                                                <form method="post" action="<?= htmlspecialchars(appUrl('responses', responseActionRedirectParams($formId, $selectedGroupId))) ?>" data-confirm="Excluir esta inscrição? Esta ação não poderá ser desfeita." data-confirm-button="Excluir"><input type="hidden" name="response_action" value="delete_response"><input type="hidden" name="response_id" value="<?= (int) $response['id'] ?>"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($responseCsrfToken) ?>"><button class="response-icon-action delete" type="submit" title="Excluir inscrição" aria-label="Excluir inscrição"><?= responseActionIcon('delete') ?></button></form>
                                            <?php endif; ?>
                                        </div>
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

<?php if ($selectedForm && canManageTenantData()): ?><?php require __DIR__ . '/response-participant-modal.php'; ?><?php endif; ?>

<?php if ($responses && canManageTenantData()): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const selectAll = document.getElementById('select-all-responses');
    const checkboxes = Array.from(document.querySelectorAll('.response-bulk-select'));
    const button = document.getElementById('bulk-delete-responses-button');
    const count = document.getElementById('bulk-delete-responses-count');
    if (!selectAll || !button || !count) return;

    const updateSelection = function () {
        const selected = checkboxes.filter(function (checkbox) { return checkbox.checked; }).length;
        count.textContent = String(selected);
        button.disabled = selected === 0;
        selectAll.checked = selected > 0 && selected === checkboxes.length;
        selectAll.indeterminate = selected > 0 && selected < checkboxes.length;
    };

    selectAll.addEventListener('change', function () {
        checkboxes.forEach(function (checkbox) { checkbox.checked = selectAll.checked; });
        updateSelection();
    });
    checkboxes.forEach(function (checkbox) { checkbox.addEventListener('change', updateSelection); });
    updateSelection();
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../layouts/admin-footer.php'; ?>
