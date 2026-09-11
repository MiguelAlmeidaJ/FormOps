<?php

requireTenantContext();
require_once __DIR__ . '/../../helpers/exports.php';

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
if (!currentUserCanAccessFormGroup(!empty($form['form_group_id']) ? (int) $form['form_group_id'] : null)) {
    redirectTo('forms');
}

$stmt = $pdo->prepare(
    'SELECT id, label, type, options
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

$selectedFieldIds = array_values(array_filter(array_map('intval', (array) ($_GET['fields'] ?? [])), fn ($id) => isset($fieldsById[$id])));
$selectedType = $_GET['type'] ?? 'all';
$includeEmpty = ($_GET['empty'] ?? '1') === '1';
$allowedTypes = ['all', 'choice', 'textual'];
if (!in_array($selectedType, $allowedTypes, true)) {
    $selectedType = 'all';
}

$stmt = $pdo->prepare('SELECT * FROM form_responses WHERE tenant_id = ? AND form_id = ? ORDER BY submitted_at ASC, id ASC');
$stmt->execute([$tenantId, $formId]);
$responses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$answersByField = [];
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
        $answersByField[(int) $answer['field_id']][] = trim((string) $answer['answer']);
        $answersByResponse[(int) $answer['response_id']][(int) $answer['field_id']] = (string) $answer['answer'];
    }
}

$totalResponses = count($responses);
$completed = isFormCompleted($form);
$firstResponseAt = $responses[0]['submitted_at'] ?? null;
$lastResponseAt = $responses ? $responses[count($responses) - 1]['submitted_at'] : null;
$choiceTypes = ['select', 'radio', 'checkbox'];
$chartFields = [];

foreach ($fields as $field) {
    $fieldId = (int) $field['id'];
    $isChoice = in_array($field['type'], $choiceTypes, true);
    if ($selectedFieldIds && !in_array($fieldId, $selectedFieldIds, true)) {
        continue;
    }
    if ($selectedType === 'choice' && !$isChoice) {
        continue;
    }
    if ($selectedType === 'textual' && $isChoice) {
        continue;
    }

    $counts = [];
    $fieldAnswers = $answersByField[$fieldId] ?? [];

    if ($isChoice) {
        foreach ($fieldAnswers as $rawAnswer) {
            $parts = $field['type'] === 'checkbox' ? preg_split('/\s*,\s*/', $rawAnswer) : [$rawAnswer];
            foreach ($parts ?: [] as $part) {
                $value = trim((string) $part);
                if ($value !== '') {
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                }
            }
        }
        if ($includeEmpty) {
            $answeredRows = count(array_filter($fieldAnswers, fn ($answer) => trim((string) $answer) !== ''));
            $emptyRows = max(0, $totalResponses - $answeredRows);
            if ($emptyRows > 0) {
                $counts['Sem resposta'] = $emptyRows;
            }
        }
    } else {
        $filled = count(array_filter($fieldAnswers, fn ($answer) => trim((string) $answer) !== ''));
        $empty = max(0, $totalResponses - $filled);
        $counts = ['Preenchido' => $filled];
        if ($includeEmpty) {
            $counts['Vazio'] = $empty;
        }
    }

    arsort($counts);
    $total = array_sum($counts);
    $topLabel = array_key_first($counts);
    $topCount = $topLabel !== null ? (int) $counts[$topLabel] : 0;
    $chartFields[] = [
        'id' => $fieldId,
        'label' => $field['label'],
        'type' => $field['type'],
        'is_choice' => $isChoice,
        'counts' => $counts,
        'total' => $total,
        'top_label' => $topLabel,
        'top_count' => $topCount,
        'top_percent' => $total > 0 ? round(($topCount / $total) * 100) : 0,
    ];
}

$exportFormat = (string) ($_GET['export'] ?? '');
if (in_array($exportFormat, ['csv', 'pdf'], true)) {
    $availableExportFieldIds = array_map('intval', array_column($chartFields, 'id'));
    $exportWasConfigured = ($_GET['export_configured'] ?? '') === '1';
    $requestedExportFieldIds = $exportWasConfigured && is_array($_GET['export_fields'] ?? null)
        ? array_map('intval', $_GET['export_fields'])
        : $availableExportFieldIds;
    $requestedExportFieldIds = array_values(array_unique($requestedExportFieldIds));
    $exportFields = [];
    foreach ($requestedExportFieldIds as $exportFieldId) {
        if (in_array($exportFieldId, $availableExportFieldIds, true) && isset($fieldsById[$exportFieldId])) {
            $exportFields[] = $fieldsById[$exportFieldId];
        }
    }

    if (!$exportFields) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Selecione ao menos uma coluna para exportar.';
        exit;
    }

    $sortFieldId = filter_input(INPUT_GET, 'sort_field', FILTER_VALIDATE_INT);
    $sortField = $sortFieldId && in_array((int) $sortFieldId, $availableExportFieldIds, true)
        ? ($fieldsById[(int) $sortFieldId] ?? null)
        : $exportFields[0];
    $sortDirection = ($_GET['sort_direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
    $exportResponses = formExportSortResponses($responses, $answersByResponse, $sortField, $sortDirection);

    $exportTitle = (string) $form['title'];
    if (function_exists('iconv')) {
        $exportTitle = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $exportTitle) ?: $exportTitle;
    }
    $safeTitle = preg_replace('/[^a-z0-9]+/i', '-', $exportTitle ?: 'formulario');
    $safeTitle = trim(strtolower((string) $safeTitle), '-') ?: 'formulario';
    $filename = $safeTitle . '-inscricoes-' . date('Y-m-d');

    if ($exportFormat === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        $output = fopen('php://output', 'wb');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, array_column($exportFields, 'label'), ';', '"', '');

        foreach ($exportResponses as $response) {
            $row = [];
            $responseAnswers = $answersByResponse[(int) $response['id']] ?? [];
            foreach ($exportFields as $exportField) {
                $value = trim((string) ($responseAnswers[(int) $exportField['id']] ?? ''));
                if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
                    $value = "'" . $value;
                }
                $row[] = $value;
            }
            fputcsv($output, $row, ';', '"', '');
        }

        fclose($output);
        exit;
    }

    $pdf = formExportPdfBinary((string) $form['title'], $exportFields, $exportResponses, $answersByResponse);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo $pdf;
    exit;
}

$responsesByDay = [];
foreach ($responses as $response) {
    $day = date('d/m', strtotime($response['submitted_at']));
    $responsesByDay[$day] = ($responsesByDay[$day] ?? 0) + 1;
}
$maxDailyResponses = $responsesByDay ? max($responsesByDay) : 0;
$chartPalette = ['#2563EB', '#7C3AED', '#DB2777', '#EA580C', '#059669', '#0891B2', '#D97706', '#4F46E5'];

$pageTitle = 'Dashboard do formulário';

require __DIR__ . '/../../layouts/admin-header.php';
require __DIR__ . '/../../layouts/admin-sidebar.php';
?>

<style>
    .dash-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:22px; }
    .dash-card { border:1px solid #CECECE; border-radius:8px; background:#fff; box-shadow:0 12px 28px rgba(15,23,42,.05); }
    .dash-card .card-body { padding:20px; }
    .metric-label { color:#555555; font-size:12px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; }
    .metric-value { color:#212121; font-size:28px; font-weight:850; line-height:1.1; margin-top:8px; }
    .filter-panel { display:grid; grid-template-columns:minmax(190px,220px) minmax(170px,190px) minmax(0,1fr) auto; gap:14px; align-items:end; }
    .filter-title { color:#212121; font-size:15px; font-weight:850; margin:0 0 12px; }
    .filter-panel .form-label { color:#212121; font-size:13px; font-weight:800; margin-bottom:7px; }
    .filter-panel .form-select { min-height:44px; border-radius:10px; border-color:#CECECE; }
    .field-wrap { min-width:0; }
    .field-checks { display:flex; flex-wrap:wrap; gap:8px; max-height:92px; overflow:auto; padding:10px; border:1px solid #CECECE; border-radius:10px; background:#F3F3F3; scrollbar-width:thin; }
    .field-chip { display:inline-flex; align-items:center; gap:7px; max-width:100%; border:1px solid #CECECE; border-radius:999px; padding:7px 11px; font-size:12px; color:#212121; background:#fff; box-shadow:0 1px 2px rgba(15,23,42,.04); }
    .field-chip input { flex:0 0 auto; }
    .field-chip span { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .filter-actions { display:flex; justify-content:flex-end; }
    .filter-actions .btn { min-height:44px; border-radius:10px; font-weight:800; white-space:nowrap; }
    .chart-grid { display:grid; grid-template-columns:repeat(2,minmax(280px,1fr)); gap:16px; }
    .chart-grid .dash-card { min-width:0; overflow:hidden; border-top:4px solid var(--chart-color,#2563EB); }
    .chart-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:14px; }
    .chart-head h2 { color:#212121; font-weight:850; line-height:1.25; overflow-wrap:anywhere; }
    .chart-kind { color:#555555; font-size:12px; font-weight:800; text-transform:uppercase; }
    .chart-summary { display:flex; align-items:center; gap:8px; min-width:0; margin-bottom:12px; padding:10px 12px; border-radius:10px; background:linear-gradient(135deg,#EFF6FF,#F5F3FF); color:#212121; font-size:13px; }
    .chart-summary strong { color:var(--chart-color,#2563EB); min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .donut { width:72px; height:72px; border-radius:50%; background:conic-gradient(var(--chart-color,#2563EB) calc(var(--p) * 1%), #E5E7EB 0); display:grid; place-items:center; flex:0 0 auto; box-shadow:0 6px 16px rgba(15,23,42,.12); }
    .donut::after { content:attr(data-label); width:48px; height:48px; border-radius:50%; background:#fff; display:grid; place-items:center; color:#212121; font-size:12px; font-weight:850; }
    .chart-row { display:grid; grid-template-columns:minmax(120px, 180px) minmax(120px,1fr) 84px; gap:10px; align-items:center; margin-top:10px; }
    .chart-label { color:#212121; font-weight:700; min-width:0; overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
    .chart-track { height:12px; border-radius:999px; background:#E5E7EB; overflow:hidden; box-shadow:inset 0 1px 2px rgba(15,23,42,.08); }
    .chart-bar { height:100%; border-radius:999px; background:var(--bar-color,var(--chart-color,#2563EB)); min-width:2px; box-shadow:0 4px 10px rgba(37,99,235,.18); }
    .chart-count { color:#555555; font-size:13px; font-weight:800; text-align:right; white-space:nowrap; }
    .chart-grid .dash-card .card-body { min-height:154px; }
    .trend { display:flex; align-items:end; gap:8px; height:180px; padding:16px 2px 4px; overflow-x:auto; overflow-y:hidden; scrollbar-width:thin; }
    .trend-col { flex:0 0 34px; min-width:34px; display:flex; flex-direction:column; justify-content:flex-end; align-items:center; gap:7px; color:#555555; font-size:11px; }
    .trend-bar { width:100%; max-width:36px; min-height:4px; border-radius:8px 8px 0 0; background:var(--trend-color,#2563EB); box-shadow:0 5px 12px rgba(15,23,42,.12); }
    .export-column-list { display:grid; gap:8px; max-height:310px; overflow-y:auto; padding:2px; }
    .export-column-item { display:grid; grid-template-columns:auto minmax(0,1fr) auto; gap:10px; align-items:center; padding:11px 12px; border:1px solid #DEE2E6; border-radius:10px; background:#fff; }
    .export-column-item .form-check-input { margin:0; }
    .export-column-label { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-weight:650; }
    .export-column-actions { display:flex; gap:5px; }
    .export-column-actions .btn { width:34px; height:34px; display:grid; place-items:center; padding:0; }
    .export-format-options { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .export-format-option { display:flex; align-items:center; gap:10px; min-height:58px; padding:12px; border:1px solid #DEE2E6; border-radius:10px; cursor:pointer; }
    .export-format-option:has(input:checked) { border-color:#198754; background:#F0FDF4; }
    @media(max-width:1199px){.filter-panel{grid-template-columns:1fr 1fr}.field-wrap{grid-column:1/-1}.filter-actions{grid-column:1/-1}.chart-grid{grid-template-columns:1fr}}
    @media(max-width:767px){.dash-grid,.filter-panel{grid-template-columns:1fr}.chart-head{align-items:center}.donut{width:60px;height:60px}.donut::after{width:40px;height:40px;font-size:11px}.chart-row{grid-template-columns:1fr;gap:6px;padding:10px 0;border-top:1px solid #F3F3F3}.chart-count{text-align:left}.field-checks{max-height:160px}.filter-actions .btn{width:100%}.export-format-options{grid-template-columns:1fr}.export-column-list{max-height:42vh}.export-modal-footer{display:grid;grid-template-columns:1fr 1fr}.export-modal-footer .btn{width:100%;margin:0}}
</style>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <a href="<?= htmlspecialchars(appUrl('forms')) ?>" class="text-decoration-none">Voltar para formulários</a>
        <h1 class="h3 mt-3 mb-1">Dashboard do formulário</h1>
        <p class="text-muted mb-0"><?= htmlspecialchars($form['title']) ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#exportDashboardModal" <?= !$chartFields ? 'disabled' : '' ?>>Exportar dados</button>
        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(appUrl('responses', ['form_id' => (int) $formId])) ?>">Ver respostas</a>
        <?php if ($completed): ?><a class="btn btn-primary" href="<?= htmlspecialchars(appUrl('form-attendance', ['id' => (int) $formId])) ?>">Gerar lista de presença</a><?php endif; ?>
    </div>
</div>

<div class="modal fade" id="exportDashboardModal" tabindex="-1" aria-labelledby="exportDashboardModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
        <form class="modal-content" method="get" action="<?= htmlspecialchars(appUrl('form-dashboard')) ?>" id="dashboardExportForm">
            <input type="hidden" name="id" value="<?= (int) $formId ?>">
            <input type="hidden" name="type" value="<?= htmlspecialchars($selectedType) ?>">
            <input type="hidden" name="empty" value="<?= $includeEmpty ? '1' : '0' ?>">
            <input type="hidden" name="export_configured" value="1">
            <?php foreach ($selectedFieldIds as $selectedFieldId): ?><input type="hidden" name="fields[]" value="<?= (int) $selectedFieldId ?>"><?php endforeach; ?>
            <div class="modal-header">
                <div><h2 class="modal-title h5 mb-1" id="exportDashboardModalLabel">Exportar dados</h2><p class="text-muted small mb-0">Escolha as colunas, a ordenação e o formato do arquivo.</p></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <section class="mb-4">
                    <div class="d-flex justify-content-between align-items-center gap-3 mb-2"><h3 class="h6 mb-0">Colunas do arquivo</h3><span class="text-muted small">Use as setas para alterar a ordem</span></div>
                    <div class="export-column-list" id="exportColumnList">
                        <?php foreach ($chartFields as $chart): ?>
                            <div class="export-column-item">
                                <input class="form-check-input export-column-check" type="checkbox" name="export_fields[]" value="<?= (int) $chart['id'] ?>" checked aria-label="Incluir <?= htmlspecialchars($chart['label']) ?>">
                                <span class="export-column-label" title="<?= htmlspecialchars($chart['label']) ?>"><?= htmlspecialchars($chart['label']) ?></span>
                                <span class="export-column-actions"><button class="btn btn-sm btn-outline-secondary" type="button" data-move-column="up" title="Mover para cima" aria-label="Mover <?= htmlspecialchars($chart['label']) ?> para cima">↑</button><button class="btn btn-sm btn-outline-secondary" type="button" data-move-column="down" title="Mover para baixo" aria-label="Mover <?= htmlspecialchars($chart['label']) ?> para baixo">↓</button></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="invalid-feedback d-none" id="exportColumnError">Selecione ao menos uma coluna.</div>
                </section>

                <section class="row g-3 mb-4">
                    <div class="col-md-7"><label class="form-label" for="exportSortField">Ordenar registros por</label><select class="form-select" id="exportSortField" name="sort_field"><?php foreach ($chartFields as $chart): ?><option value="<?= (int) $chart['id'] ?>"><?= htmlspecialchars($chart['label']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-5"><label class="form-label" for="exportSortDirection">Direção</label><select class="form-select" id="exportSortDirection" name="sort_direction"><option value="asc">Crescente (A–Z)</option><option value="desc">Decrescente (Z–A)</option></select></div>
                </section>

                <section><h3 class="h6 mb-2">Formato</h3><div class="export-format-options"><label class="export-format-option"><input class="form-check-input" type="radio" name="export" value="csv" checked><span><strong class="d-block">CSV</strong><small class="text-muted">Ideal para Excel e planilhas</small></span></label><label class="export-format-option"><input class="form-check-input" type="radio" name="export" value="pdf"><span><strong class="d-block">PDF</strong><small class="text-muted">Tabela pronta para compartilhar</small></span></label></div></section>
            </div>
            <div class="modal-footer export-modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success">Exportar arquivo</button></div>
        </form>
    </div>
</div>

<section class="dash-grid">
    <div class="dash-card"><div class="card-body"><div class="metric-label">Status</div><div class="metric-value"><?= htmlspecialchars(formStatusLabel($form)) ?></div></div></div>
    <div class="dash-card"><div class="card-body"><div class="metric-label">Respostas</div><div class="metric-value"><?= (int) $totalResponses ?></div></div></div>
    <div class="dash-card"><div class="card-body"><div class="metric-label">Campos filtrados</div><div class="metric-value"><?= count($chartFields) ?></div></div></div>
    <div class="dash-card"><div class="card-body"><div class="metric-label">Prazo</div><div class="fw-semibold mt-2"><?= formClosesAtValue($form) ? htmlspecialchars(date('d/m/Y H:i', strtotime(formClosesAtValue($form)))) : 'Sem limite' ?></div></div></div>
</section>

<div class="dash-card mb-4">
    <div class="card-body">
        <h2 class="filter-title">Filtros do painel</h2>
        <form method="get" action="<?= htmlspecialchars(appUrl('form-dashboard')) ?>" class="filter-panel">
            <input type="hidden" name="id" value="<?= (int) $formId ?>">
            <div>
                <label class="form-label" for="type">Tipo de informação</label>
                <select class="form-select" id="type" name="type">
                    <option value="all" <?= $selectedType === 'all' ? 'selected' : '' ?>>Todos os campos</option>
                    <option value="choice" <?= $selectedType === 'choice' ? 'selected' : '' ?>>Escolhas</option>
                    <option value="textual" <?= $selectedType === 'textual' ? 'selected' : '' ?>>Texto/preenchimento</option>
                </select>
            </div>
            <div>
                <label class="form-label" for="empty">Vazios</label>
                <select class="form-select" id="empty" name="empty">
                    <option value="1" <?= $includeEmpty ? 'selected' : '' ?>>Incluir vazios</option>
                    <option value="0" <?= !$includeEmpty ? 'selected' : '' ?>>Ocultar vazios</option>
                </select>
            </div>
            <div class="field-wrap">
                <label class="form-label">Campos exibidos</label>
                <div class="field-checks">
                    <?php foreach ($fields as $field): ?>
                        <label class="field-chip"><input type="checkbox" name="fields[]" value="<?= (int) $field['id'] ?>" <?= (!$selectedFieldIds || in_array((int) $field['id'], $selectedFieldIds, true)) ? 'checked' : '' ?>><span><?= htmlspecialchars($field['label']) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="filter-actions"><button class="btn btn-primary" type="submit">Aplicar filtros</button></div>
        </form>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        <?php if ($chartFields): ?>
            <div class="chart-grid">
                <?php foreach ($chartFields as $chartIndex => $chart): ?>
                    <?php $chartColor = $chartPalette[$chartIndex % count($chartPalette)]; ?>
                    <section class="dash-card" style="--chart-color: <?= htmlspecialchars($chartColor) ?>">
                        <div class="card-body">
                            <div class="chart-head">
                                <div>
                                    <h2 class="h6 mb-1"><?= htmlspecialchars($chart['label']) ?></h2>
                                    <div class="chart-kind"><?= htmlspecialchars($chart['is_choice'] ? 'Distribuição' : 'Preenchimento') ?></div>
                                </div>
                                <div class="donut" style="--p: <?= (int) $chart['top_percent'] ?>" data-label="<?= (int) $chart['top_percent'] ?>%"></div>
                            </div>
                            <?php if ($chart['top_label'] !== ''): ?>
                                <div class="chart-summary">
                                    <span><?= htmlspecialchars($chart['is_choice'] ? 'Mais escolhido' : 'Resumo') ?>:</span>
                                    <strong><?= htmlspecialchars($chart['top_label']) ?></strong>
                                </div>
                            <?php endif; ?>
                            <?php if ($chart['counts']): ?>
                                <?php $barPosition = 0; foreach ($chart['counts'] as $label => $count): ?>
                                    <?php $percent = $chart['total'] > 0 ? round(($count / $chart['total']) * 100) : 0; ?>
                                    <div class="chart-row">
                                        <div class="chart-label"><?= htmlspecialchars((string) $label) ?></div>
                                        <div class="chart-track"><div class="chart-bar" style="width: <?= (int) $percent ?>%; --bar-color: <?= htmlspecialchars($chartPalette[($barPosition + $chartIndex) % count($chartPalette)]) ?>"></div></div>
                                        <div class="chart-count"><?= (int) $count ?> - <?= (int) $percent ?>%</div>
                                    </div>
                                    <?php $barPosition++; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-muted small">Sem dados para este campo.</div>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-light border mb-0">Nenhum campo corresponde aos filtros selecionados.</div>
        <?php endif; ?>
    </div>
    <div class="col-xl-4">
        <div class="dash-card mb-4">
            <div class="card-body">
                <h2 class="h5 mb-3">Respostas por dia</h2>
                <?php if ($responsesByDay): ?>
                    <div class="trend">
                        <?php $trendPosition = 0; foreach ($responsesByDay as $day => $count): ?>
                            <?php $height = $maxDailyResponses > 0 ? max(4, round(($count / $maxDailyResponses) * 140)) : 4; ?>
                            <div class="trend-col"><div class="trend-bar" style="height: <?= (int) $height ?>px; --trend-color: <?= htmlspecialchars($chartPalette[$trendPosition % count($chartPalette)]) ?>" title="<?= (int) $count ?> respostas"></div><strong><?= (int) $count ?></strong><span><?= htmlspecialchars($day) ?></span></div>
                            <?php $trendPosition++; ?>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-muted">Nenhuma resposta recebida.</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="dash-card">
            <div class="card-body">
                <h2 class="h5 mb-3">Linha do tempo</h2>
                <div class="small text-muted">Primeira resposta</div>
                <div class="fw-semibold mb-3"><?= $firstResponseAt ? htmlspecialchars(date('d/m/Y H:i', strtotime($firstResponseAt))) : 'Nenhuma' ?></div>
                <div class="small text-muted">Última resposta</div>
                <div class="fw-semibold"><?= $lastResponseAt ? htmlspecialchars(date('d/m/Y H:i', strtotime($lastResponseAt))) : 'Nenhuma' ?></div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('dashboardExportForm');
    const list = document.getElementById('exportColumnList');
    const error = document.getElementById('exportColumnError');
    if (!form || !list) return;

    function updateMoveButtons() {
        const items = Array.from(list.querySelectorAll('.export-column-item'));
        items.forEach(function (item, index) {
            const up = item.querySelector('[data-move-column="up"]');
            const down = item.querySelector('[data-move-column="down"]');
            if (up) up.disabled = index === 0;
            if (down) down.disabled = index === items.length - 1;
        });
    }

    list.addEventListener('click', function (event) {
        const button = event.target.closest('[data-move-column]');
        if (!button) return;
        const item = button.closest('.export-column-item');
        if (!item) return;
        if (button.dataset.moveColumn === 'up' && item.previousElementSibling) list.insertBefore(item, item.previousElementSibling);
        if (button.dataset.moveColumn === 'down' && item.nextElementSibling) list.insertBefore(item.nextElementSibling, item);
        updateMoveButtons();
        button.focus();
    });

    form.addEventListener('submit', function (event) {
        const selected = list.querySelectorAll('.export-column-check:checked');
        const valid = selected.length > 0;
        error?.classList.toggle('d-none', valid);
        if (!valid) {
            event.preventDefault();
            list.querySelector('.export-column-check')?.focus();
        }
    });
    list.addEventListener('change', function () { if (list.querySelector('.export-column-check:checked')) error?.classList.add('d-none'); });
    updateMoveButtons();
});
</script>

<?php require __DIR__ . '/../../layouts/admin-footer.php'; ?>
