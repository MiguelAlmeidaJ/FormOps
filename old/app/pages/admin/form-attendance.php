<?php

requireTenantContext();

$tenantId = currentTenantIdForData();
$formId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$formId) {
    redirectTo('forms');
}

$stmt = $pdo->prepare('SELECT * FROM forms WHERE id = ? AND tenant_id = ? LIMIT 1');
$stmt->execute([$formId, $tenantId]);
$form = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$form) {
    redirectTo('forms');
}

if (shouldScopeTenantUserToGroup() && (int) ($form['form_group_id'] ?? 0) !== (int) currentUserFormGroupId()) {
    redirectTo('forms');
}

if (!isFormCompleted($form)) {
    $_SESSION['flash_error'] = 'A lista de presença só fica disponível quando o formulário estiver concluído.';
    redirectTo('forms');
}

function attendanceLower(string $text): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
}

$stmt = $pdo->prepare(
    'SELECT id, label
     FROM form_fields
     WHERE tenant_id = ? AND form_id = ? AND is_layout = 0 AND is_visible = 1
     ORDER BY field_order ASC, id ASC'
);
$stmt->execute([$tenantId, $formId]);
$fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
$fieldsById = [];
foreach ($fields as $field) {
    $fieldsById[(int) $field['id']] = $field;
}

$hasFieldFilter = array_key_exists('fields', $_GET);
$requestedFieldIds = array_values(array_filter(array_map('intval', (array) ($_GET['fields'] ?? [])), fn ($id) => isset($fieldsById[$id])));
$defaultFieldIds = [];
foreach ([
    ['nome'],
    ['email', 'e-mail', 'telefone', 'celular', 'whatsapp'],
    ['igreja', 'comunidade', 'empresa', 'organizacao', 'organização'],
] as $keywords) {
    foreach ($fields as $field) {
        $label = attendanceLower((string) $field['label']);
        foreach ($keywords as $keyword) {
            if (str_contains($label, attendanceLower($keyword))) {
                $defaultFieldIds[] = (int) $field['id'];
                break 2;
            }
        }
    }
}
$defaultFieldIds = array_values(array_unique($defaultFieldIds));
if (!$defaultFieldIds) {
    $defaultFieldIds = array_slice(array_map(fn ($field) => (int) $field['id'], $fields), 0, 4);
}
$selectedFieldIds = $hasFieldFilter ? $requestedFieldIds : $defaultFieldIds;
$selectedFields = array_values(array_filter($fields, fn ($field) => in_array((int) $field['id'], $selectedFieldIds, true)));
$presenceMode = ($_GET['presence_mode'] ?? 'signature') === 'check' ? 'check' : 'signature';

$stmt = $pdo->prepare('SELECT * FROM form_responses WHERE tenant_id = ? AND form_id = ? ORDER BY response_number ASC, submitted_at ASC, id ASC');
$stmt->execute([$tenantId, $formId]);
$responses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$answersByResponse = [];
if ($responses) {
    $stmt = $pdo->prepare(
        'SELECT a.response_id, a.field_id, a.answer
         FROM form_response_answers a
         INNER JOIN form_responses r ON r.id = a.response_id AND r.tenant_id = a.tenant_id
         WHERE a.tenant_id = ? AND r.form_id = ?'
    );
    $stmt->execute([$tenantId, $formId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $answer) {
        $answersByResponse[(int) $answer['response_id']][(int) $answer['field_id']] = $answer['answer'];
    }
}

$pageTitle = 'Lista de presença';

require __DIR__ . '/../../layouts/admin-header.php';
require __DIR__ . '/../../layouts/admin-sidebar.php';
?>

<style>
    .attendance-config { background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:18px; margin-bottom:18px; box-shadow:0 12px 28px rgba(15,23,42,.05); }
    .attendance-config-title { color:#0f172a; font-size:15px; font-weight:850; margin:0 0 12px; }
    .attendance-config-grid { display:grid; grid-template-columns:minmax(190px,220px) minmax(0,1fr) auto; gap:14px; align-items:end; }
    .attendance-config .form-label { color:#334155; font-size:13px; font-weight:800; margin-bottom:7px; }
    .attendance-config .form-select { min-height:44px; border-radius:10px; border-color:#dbe3ef; }
    .attendance-field-wrap { min-width:0; }
    .attendance-field-list { display:flex; flex-wrap:wrap; gap:8px; max-height:96px; overflow:auto; border:1px solid #dbe3ef; border-radius:10px; padding:10px; background:#f8fafc; scrollbar-width:thin; }
    .attendance-chip { display:inline-flex; align-items:center; gap:7px; max-width:100%; border:1px solid #e5e7eb; border-radius:999px; padding:7px 11px; color:#334155; font-size:12px; background:#fff; box-shadow:0 1px 2px rgba(15,23,42,.04); }
    .attendance-chip input { flex:0 0 auto; }
    .attendance-chip span { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .attendance-actions { display:flex; justify-content:flex-end; }
    .attendance-actions .btn { min-height:44px; border-radius:10px; font-weight:800; white-space:nowrap; }
    .attendance-sheet { background:#fff; border:1px solid #e5e7eb; border-radius:8px; overflow:hidden; }
    .attendance-table th { background:#f8fafc; color:#475569; font-size:12px; letter-spacing:.04em; text-transform:uppercase; white-space:nowrap; }
    .attendance-table td, .attendance-table th { padding:13px 14px; vertical-align:middle; }
    .signature-line { min-width:170px; height:28px; border-bottom:1px solid #94a3b8; }
    .presence-check { width:24px; height:24px; border:2px solid #94a3b8; border-radius:6px; display:inline-block; }
    @media(max-width:991px){.attendance-config-grid{grid-template-columns:1fr}.attendance-actions .btn{width:100%}.attendance-field-list{max-height:160px}}
    @media print {
        .admin-sidebar, .admin-topbar, .no-print { display:none!important; }
        .admin-main, .admin-content { margin:0!important; padding:0!important; background:#fff!important; }
        .attendance-sheet { border:0; border-radius:0; }
        .attendance-table th, .attendance-table td { font-size:11px; padding:9px 10px; }
    }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4 no-print">
    <div>
        <a href="<?= htmlspecialchars(appUrl('forms')) ?>" class="text-decoration-none">Voltar para formulários</a>
        <h1 class="h3 mt-3 mb-1">Lista de presença</h1>
        <p class="text-muted mb-0"><?= htmlspecialchars($form['title']) ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Imprimir lista</button>
        <button type="button" class="btn btn-primary" onclick="window.print()">Baixar PDF</button>
    </div>
</div>

<form method="get" action="<?= htmlspecialchars(appUrl('form-attendance')) ?>" class="attendance-config no-print">
    <input type="hidden" name="id" value="<?= (int) $formId ?>">
    <h2 class="attendance-config-title">Configurar lista</h2>
    <div class="attendance-config-grid">
        <div>
            <label for="presence_mode" class="form-label">Controle de presença</label>
            <select id="presence_mode" name="presence_mode" class="form-select">
                <option value="signature" <?= $presenceMode === 'signature' ? 'selected' : '' ?>>Assinatura</option>
                <option value="check" <?= $presenceMode === 'check' ? 'selected' : '' ?>>Check de presença</option>
            </select>
        </div>
        <div class="attendance-field-wrap">
            <label class="form-label">Campos da lista</label>
            <div class="attendance-field-list">
                <?php foreach ($fields as $field): ?>
                    <label class="attendance-chip">
                        <input type="checkbox" name="fields[]" value="<?= (int) $field['id'] ?>" <?= in_array((int) $field['id'], $selectedFieldIds, true) ? 'checked' : '' ?>>
                        <span><?= htmlspecialchars($field['label']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="attendance-actions"><button class="btn btn-primary" type="submit">Atualizar lista</button></div>
    </div>
</form>

<section class="attendance-sheet">
    <div class="p-4 border-bottom">
        <h2 class="h4 mb-1"><?= htmlspecialchars($form['title']) ?></h2>
        <div class="text-muted small">Total de participantes: <?= count($responses) ?><?= formClosesAtValue($form) ? ' - Concluído em ' . htmlspecialchars(date('d/m/Y H:i', strtotime(formClosesAtValue($form)))) : '' ?></div>
    </div>
    <div class="table-responsive">
        <table class="table attendance-table mb-0">
            <thead>
                <tr>
                    <th style="width:70px">N.</th>
                    <?php foreach ($selectedFields as $field): ?>
                        <th><?= htmlspecialchars($field['label']) ?></th>
                    <?php endforeach; ?>
                    <th style="width:<?= $presenceMode === 'signature' ? '240' : '130' ?>px"><?= $presenceMode === 'signature' ? 'Assinatura' : 'Presença' ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($responses as $response): ?>
                    <?php $answers = $answersByResponse[(int) $response['id']] ?? []; ?>
                    <tr>
                        <td>#<?= (int) ($response['response_number'] ?? $response['id']) ?></td>
                        <?php foreach ($selectedFields as $field): ?>
                            <?php $answer = trim((string) ($answers[(int) $field['id']] ?? '')); ?>
                            <td><?= htmlspecialchars($answer !== '' ? $answer : '-') ?></td>
                        <?php endforeach; ?>
                        <td><?= $presenceMode === 'signature' ? '<div class="signature-line"></div>' : '<span class="presence-check"></span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$responses): ?>
                    <tr><td colspan="<?= count($selectedFields) + 2 ?>" class="text-center text-muted py-4">Nenhuma resposta recebida.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/../../layouts/admin-footer.php'; ?>
