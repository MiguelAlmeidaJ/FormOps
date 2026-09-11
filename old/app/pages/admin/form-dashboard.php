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
if ($responses) {
    $stmt = $pdo->prepare(
        'SELECT a.field_id, a.answer
         FROM form_response_answers a
         INNER JOIN form_responses r ON r.id = a.response_id AND r.tenant_id = a.tenant_id
         WHERE a.tenant_id = ? AND r.form_id = ?'
    );
    $stmt->execute([$tenantId, $formId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $answer) {
        $answersByField[(int) $answer['field_id']][] = trim((string) $answer['answer']);
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

$responsesByDay = [];
foreach ($responses as $response) {
    $day = date('d/m', strtotime($response['submitted_at']));
    $responsesByDay[$day] = ($responsesByDay[$day] ?? 0) + 1;
}
$maxDailyResponses = $responsesByDay ? max($responsesByDay) : 0;

$pageTitle = 'Dashboard do formulário';

require __DIR__ . '/../../layouts/admin-header.php';
require __DIR__ . '/../../layouts/admin-sidebar.php';
?>

<style>
    .dash-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:22px; }
    .dash-card { border:1px solid #e5e7eb; border-radius:8px; background:#fff; box-shadow:0 12px 28px rgba(15,23,42,.05); }
    .dash-card .card-body { padding:20px; }
    .metric-label { color:#64748b; font-size:12px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; }
    .metric-value { color:#0f172a; font-size:28px; font-weight:850; line-height:1.1; margin-top:8px; }
    .filter-panel { display:grid; grid-template-columns:minmax(190px,220px) minmax(170px,190px) minmax(0,1fr) auto; gap:14px; align-items:end; }
    .filter-title { color:#0f172a; font-size:15px; font-weight:850; margin:0 0 12px; }
    .filter-panel .form-label { color:#334155; font-size:13px; font-weight:800; margin-bottom:7px; }
    .filter-panel .form-select { min-height:44px; border-radius:10px; border-color:#dbe3ef; }
    .field-wrap { min-width:0; }
    .field-checks { display:flex; flex-wrap:wrap; gap:8px; max-height:92px; overflow:auto; padding:10px; border:1px solid #dbe3ef; border-radius:10px; background:#f8fafc; scrollbar-width:thin; }
    .field-chip { display:inline-flex; align-items:center; gap:7px; max-width:100%; border:1px solid #e5e7eb; border-radius:999px; padding:7px 11px; font-size:12px; color:#334155; background:#fff; box-shadow:0 1px 2px rgba(15,23,42,.04); }
    .field-chip input { flex:0 0 auto; }
    .field-chip span { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .filter-actions { display:flex; justify-content:flex-end; }
    .filter-actions .btn { min-height:44px; border-radius:10px; font-weight:800; white-space:nowrap; }
    .chart-grid { display:grid; grid-template-columns:repeat(2,minmax(280px,1fr)); gap:16px; }
    .chart-grid .dash-card { min-width:0; }
    .chart-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:14px; }
    .chart-head h2 { color:#1f2937; font-weight:850; line-height:1.25; overflow-wrap:anywhere; }
    .chart-kind { color:#64748b; font-size:12px; font-weight:800; text-transform:uppercase; }
    .chart-summary { display:flex; align-items:center; gap:8px; min-width:0; margin-bottom:12px; padding:10px 12px; border-radius:10px; background:linear-gradient(135deg,#eff6ff,#f0fdfa); color:#334155; font-size:13px; }
    .chart-summary strong { color:#0f172a; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .donut { width:72px; height:72px; border-radius:50%; background:conic-gradient(#2563eb calc(var(--p) * 1%), #e2e8f0 0); display:grid; place-items:center; flex:0 0 auto; }
    .donut::after { content:attr(data-label); width:48px; height:48px; border-radius:50%; background:#fff; display:grid; place-items:center; color:#0f172a; font-size:12px; font-weight:850; }
    .chart-row { display:grid; grid-template-columns:minmax(120px, 180px) minmax(120px,1fr) 84px; gap:10px; align-items:center; margin-top:10px; }
    .chart-label { color:#334155; font-weight:700; min-width:0; overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
    .chart-track { height:12px; border-radius:999px; background:#e2e8f0; overflow:hidden; box-shadow:inset 0 1px 2px rgba(15,23,42,.08); }
    .chart-bar { height:100%; border-radius:999px; background:linear-gradient(90deg,#2563eb,#14b8a6); min-width:2px; box-shadow:0 4px 10px rgba(37,99,235,.18); }
    .chart-count { color:#64748b; font-size:13px; font-weight:800; text-align:right; white-space:nowrap; }
    .chart-grid .dash-card .card-body { min-height:154px; }
    .trend { display:flex; align-items:end; gap:8px; height:180px; padding:16px 2px 4px; overflow-x:auto; overflow-y:hidden; scrollbar-width:thin; }
    .trend-col { flex:0 0 34px; min-width:34px; display:flex; flex-direction:column; justify-content:flex-end; align-items:center; gap:7px; color:#64748b; font-size:11px; }
    .trend-bar { width:100%; max-width:36px; min-height:4px; border-radius:8px 8px 0 0; background:linear-gradient(180deg,#14b8a6,#2563eb); }
    @media(max-width:1199px){.filter-panel{grid-template-columns:1fr 1fr}.field-wrap{grid-column:1/-1}.filter-actions{grid-column:1/-1}.chart-grid{grid-template-columns:1fr}}
    @media(max-width:767px){.dash-grid,.filter-panel{grid-template-columns:1fr}.chart-head{align-items:center}.donut{width:60px;height:60px}.donut::after{width:40px;height:40px;font-size:11px}.chart-row{grid-template-columns:1fr;gap:6px;padding:10px 0;border-top:1px solid #eef2f7}.chart-count{text-align:left}.field-checks{max-height:160px}.filter-actions .btn{width:100%}}
</style>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <a href="<?= htmlspecialchars(appUrl('forms')) ?>" class="text-decoration-none">Voltar para formulários</a>
        <h1 class="h3 mt-3 mb-1">Dashboard do formulário</h1>
        <p class="text-muted mb-0"><?= htmlspecialchars($form['title']) ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(appUrl('responses', ['form_id' => (int) $formId])) ?>">Ver respostas</a>
        <?php if ($completed): ?><a class="btn btn-primary" href="<?= htmlspecialchars(appUrl('form-attendance', ['id' => (int) $formId])) ?>">Gerar lista de presença</a><?php endif; ?>
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
                <?php foreach ($chartFields as $chart): ?>
                    <section class="dash-card">
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
                                <?php foreach ($chart['counts'] as $label => $count): ?>
                                    <?php $percent = $chart['total'] > 0 ? round(($count / $chart['total']) * 100) : 0; ?>
                                    <div class="chart-row">
                                        <div class="chart-label"><?= htmlspecialchars($label) ?></div>
                                        <div class="chart-track"><div class="chart-bar" style="width: <?= (int) $percent ?>%"></div></div>
                                        <div class="chart-count"><?= (int) $count ?> - <?= (int) $percent ?>%</div>
                                    </div>
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
                        <?php foreach ($responsesByDay as $day => $count): ?>
                            <?php $height = $maxDailyResponses > 0 ? max(4, round(($count / $maxDailyResponses) * 140)) : 4; ?>
                            <div class="trend-col"><div class="trend-bar" style="height: <?= (int) $height ?>px" title="<?= (int) $count ?> respostas"></div><strong><?= (int) $count ?></strong><span><?= htmlspecialchars($day) ?></span></div>
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

<?php require __DIR__ . '/../../layouts/admin-footer.php'; ?>
