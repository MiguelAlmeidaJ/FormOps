<?php

requireTenantContext();

$tenantId = currentTenantIdForData();
$currentUser = user();
$pageTitle = 'Detalhes da resposta';
$responseId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$formId = filter_input(INPUT_GET, 'form_id', FILTER_VALIDATE_INT);

$response = null;
$fields = [];
$answersByField = [];
$paymentStatusLabels = ['pending' => 'Pendente', 'approved' => 'Aprovado'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_payment' && $responseId && $formId) {
    if (shouldScopeTenantUserToGroup()) {
        $stmt = $pdo->prepare("UPDATE form_responses r INNER JOIN forms f ON f.id = r.form_id AND f.tenant_id = r.tenant_id SET r.payment_status = 'approved', r.payment_approved_by = ?, r.payment_approved_at = NOW() WHERE r.id = ? AND r.form_id = ? AND r.tenant_id = ? AND f.form_group_id = ?");
        $stmt->execute([(int) ($currentUser['id'] ?? 0), $responseId, $formId, $tenantId, currentUserFormGroupId()]);
    } else {
        $stmt = $pdo->prepare("UPDATE form_responses SET payment_status = 'approved', payment_approved_by = ?, payment_approved_at = NOW() WHERE id = ? AND form_id = ? AND tenant_id = ?");
        $stmt->execute([(int) ($currentUser['id'] ?? 0), $responseId, $formId, $tenantId]);
    }
    redirectTo('response-view', ['id' => $responseId, 'form_id' => $formId]);
}
if ($responseId && $formId) {
    $scopeSql = shouldScopeTenantUserToGroup() ? ' AND f.form_group_id = ?' : '';
    $stmt = $pdo->prepare(
        'SELECT r.*, f.title AS form_title
         FROM form_responses r
         INNER JOIN forms f ON f.id = r.form_id AND f.tenant_id = r.tenant_id
         WHERE r.id = ? AND r.form_id = ? AND r.tenant_id = ?
         ' . $scopeSql . '
         LIMIT 1'
    );
    $params = [$responseId, $formId, $tenantId];
    if (shouldScopeTenantUserToGroup()) {
        $params[] = currentUserFormGroupId();
    }
    $stmt->execute($params);
    $response = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$response) {
    http_response_code(404);
} else {
    $stmt = $pdo->prepare(
        'SELECT id, label, type, is_layout
         FROM form_fields
         WHERE tenant_id = ? AND form_id = ? AND is_layout = 0
         ORDER BY field_order ASC, id ASC'
    );
    $stmt->execute([$tenantId, $formId]);
    $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare(
        'SELECT a.field_id, a.answer
         FROM form_response_answers a
         INNER JOIN form_responses r
            ON r.id = a.response_id AND r.tenant_id = a.tenant_id
         WHERE a.tenant_id = ? AND a.response_id = ? AND r.form_id = ?'
    );
    $stmt->execute([$tenantId, $responseId, $formId]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $answer) {
        $answersByField[(int) $answer['field_id']] = $answer['answer'];
    }
}

require __DIR__ . '/../../layouts/admin-header.php';
require __DIR__ . '/../../layouts/admin-sidebar.php';
?>

<style>
    .response-meta { border: 1px solid #edf0f4; }
    .response-detail-table th { width: 34%; color: #475569; font-weight: 600; }
    .response-detail-table td { color: #1f2937; white-space: pre-wrap; overflow-wrap: anywhere; }
</style>

<?php if (!$response): ?>
    <div class="mb-4">
        <a href="<?= htmlspecialchars(appUrl('responses', $formId ? ['form_id' => $formId] : [])) ?>" class="btn btn-sm btn-outline-secondary">Voltar para respostas</a>
    </div>
    <div class="alert alert-warning">A resposta informada não existe ou não pertence a esta empresa e formulário.</div>
<?php else: ?>
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
        <div>
            <a href="<?= htmlspecialchars(appUrl('responses', ['form_id' => (int) $formId])) ?>" class="btn btn-sm btn-outline-secondary mb-3">Voltar para respostas</a>
            <h1 class="h3 mb-1">Resposta #<?= (int) ($response['response_number'] ?? $response['id']) ?></h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($response['form_title']) ?></p>
        </div>
    </div>

    <div class="card response-meta shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="small text-uppercase fw-bold text-muted mb-1">Enviado em</div>
                    <div class="fw-semibold"><?= htmlspecialchars(date('d/m/Y \à\s H:i', strtotime($response['submitted_at']))) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="small text-uppercase fw-bold text-muted mb-1">Endereço IP</div>
                    <div class="fw-semibold"><?= htmlspecialchars($response['submitted_by_ip'] ?: '-') ?></div>
                </div>
                <?php if ((float)($response['payment_total'] ?? 0) > 0): ?>
                    <div class="col-md-6">
                        <div class="small text-uppercase fw-bold text-muted mb-1">Pagamento</div>
                        <div class="fw-semibold">R$ <?= number_format((float)$response['payment_total'], 2, ',', '.') ?> &middot; <?= htmlspecialchars($paymentStatusLabels[$response['payment_status'] ?? 'pending'] ?? 'Pendente') ?></div>
                    </div>
                    <div class="col-md-6">
                        <?php if (($response['payment_status'] ?? 'pending') !== 'approved'): ?>
                            <form method="post"><input type="hidden" name="action" value="approve_payment"><button type="submit" class="btn btn-success btn-sm">Aprovar pagamento</button></form>
                        <?php else: ?>
                            <div class="small text-uppercase fw-bold text-muted mb-1">Aprovado em</div>
                            <div class="fw-semibold"><?= htmlspecialchars($response['payment_approved_at'] ? date('d/m/Y H:i', strtotime($response['payment_approved_at'])) : '-') ?></div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white border-0 px-4 py-3">
            <h2 class="h6 mb-0">Respostas preenchidas</h2>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0 response-detail-table">
                    <thead>
                        <tr>
                            <th class="ps-4 bg-light">Campo</th>
                            <th class="pe-4 bg-light">Resposta</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fields as $field): ?>
                            <?php $answer = trim((string) ($answersByField[(int) $field['id']] ?? '')); ?>
                            <tr>
                                <th class="ps-4"><?= htmlspecialchars($field['label']) ?></th>
                                <td class="pe-4"><?= $answer !== '' ? htmlspecialchars($answer) : '<span class="text-muted">Não respondido</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$fields): ?>
                            <tr><td colspan="2" class="text-center text-muted py-5">Este formulário não possui campos de resposta.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../layouts/admin-footer.php'; ?>
