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
$ticket = null;
$groupTickets = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_payment' && $responseId && $formId) {
    if (!canManageTenantData()) {
        redirectTo('response-view', ['id' => $responseId, 'form_id' => $formId, 'error' => 'permission']);
    }
    $scopeSql = shouldScopeTenantUserToGroup() ? ' AND ' . currentUserFormGroupScopeSql('f.form_group_id') : '';
    $stmt = $pdo->prepare('SELECT r.*, f.ticket_enabled FROM form_responses r INNER JOIN forms f ON f.id = r.form_id AND f.tenant_id = r.tenant_id WHERE r.id = ? AND r.form_id = ? AND r.tenant_id = ?' . $scopeSql . ' LIMIT 1');
    $params = [$responseId, $formId, $tenantId];
    if (shouldScopeTenantUserToGroup()) $params = array_merge($params, currentUserFormGroupIds());
    $stmt->execute($params);
    $approvalResponse = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($approvalResponse) {
        $pdo->beginTransaction();
        try {
            $submissionGroup = trim((string) ($approvalResponse['submission_group'] ?? ''));
            if ($submissionGroup !== '') {
                $stmt = $pdo->prepare("UPDATE form_responses SET payment_status = 'approved', payment_approved_by = ?, payment_approved_at = NOW() WHERE tenant_id = ? AND form_id = ? AND submission_group = ?");
                $stmt->execute([(int) ($currentUser['id'] ?? 0), $tenantId, $formId, $submissionGroup]);
                $stmt = $pdo->prepare('SELECT id FROM form_responses WHERE tenant_id = ? AND form_id = ? AND submission_group = ? ORDER BY person_index ASC, id ASC');
                $stmt->execute([$tenantId, $formId, $submissionGroup]);
            } else {
                $stmt = $pdo->prepare("UPDATE form_responses SET payment_status = 'approved', payment_approved_by = ?, payment_approved_at = NOW() WHERE id = ? AND form_id = ? AND tenant_id = ?");
                $stmt->execute([(int) ($currentUser['id'] ?? 0), $responseId, $formId, $tenantId]);
                $stmt = $pdo->prepare('SELECT id FROM form_responses WHERE id = ? AND tenant_id = ?');
                $stmt->execute([$responseId, $tenantId]);
            }
            $approvedResponseIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $ticketsToEmail = [];
            if ((int) ($approvalResponse['ticket_enabled'] ?? 0) === 1) {
                foreach ($approvedResponseIds as $approvedResponseId) {
                    $issuedTicket = issueTicketForResponse($pdo, (int) $tenantId, $approvedResponseId);
                    if ($issuedTicket && empty($issuedTicket['emailed_at'])) $ticketsToEmail[] = (int) $issuedTicket['id'];
                }
            }
            $pdo->commit();
            foreach ($ticketsToEmail as $ticketId) sendTicketEmail($pdo, $ticketId);
            redirectTo('response-view', ['id' => $responseId, 'form_id' => $formId, 'success' => 'payment_approved']);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            redirectTo('response-view', ['id' => $responseId, 'form_id' => $formId, 'error' => 'approval_failed']);
        }
    }
}
if ($responseId && $formId) {
    $scopeSql = shouldScopeTenantUserToGroup() ? ' AND ' . currentUserFormGroupScopeSql('f.form_group_id') : '';
    $stmt = $pdo->prepare(
        'SELECT r.*, f.title AS form_title, f.ticket_enabled
         FROM form_responses r
         INNER JOIN forms f ON f.id = r.form_id AND f.tenant_id = r.tenant_id
         WHERE r.id = ? AND r.form_id = ? AND r.tenant_id = ?
         ' . $scopeSql . '
         LIMIT 1'
    );
    $params = [$responseId, $formId, $tenantId];
    if (shouldScopeTenantUserToGroup()) {
        $params = array_merge($params, currentUserFormGroupIds());
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

    $stmt = $pdo->prepare('SELECT * FROM form_tickets WHERE tenant_id = ? AND response_id = ? LIMIT 1');
    $stmt->execute([$tenantId, $responseId]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!empty($response['submission_group'])) {
        $stmt = $pdo->prepare('SELECT ft.* FROM form_tickets ft INNER JOIN form_responses r ON r.id = ft.response_id AND r.tenant_id = ft.tenant_id WHERE ft.tenant_id = ? AND ft.form_id = ? AND r.submission_group = ? ORDER BY r.person_index ASC, r.id ASC');
        $stmt->execute([$tenantId, $formId, $response['submission_group']]);
        $groupTickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($ticket) {
        $groupTickets = [$ticket];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $response && canManageTenantData() && in_array($_POST['action'] ?? '', ['issue_ticket', 'resend_ticket'], true)) {
    $action = $_POST['action'];
    $targetResponseIds = [(int) $responseId];
    if ($action === 'issue_ticket' && !empty($response['submission_group'])) {
        $stmt = $pdo->prepare("SELECT id FROM form_responses WHERE tenant_id = ? AND form_id = ? AND submission_group = ? AND payment_status = 'approved' ORDER BY person_index ASC, id ASC");
        $stmt->execute([$tenantId, $formId, $response['submission_group']]);
        $targetResponseIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    $issued = 0;
    $sent = 0;
    foreach ($targetResponseIds as $targetResponseId) {
        $issuedTicket = issueTicketForResponse($pdo, (int) $tenantId, $targetResponseId);
        if (!$issuedTicket) continue;
        $issued++;
        if (sendTicketEmail($pdo, (int) $issuedTicket['id'])) $sent++;
    }
    if ($issued === 0) redirectTo('response-view', ['id' => $responseId, 'form_id' => $formId, 'error' => 'ticket_not_available']);
    redirectTo('response-view', ['id' => $responseId, 'form_id' => $formId, 'success' => $sent === $issued ? 'ticket_sent' : 'ticket_issued']);
}

require __DIR__ . '/../../layouts/admin-header.php';
require __DIR__ . '/../../layouts/admin-sidebar.php';
?>

<style>
    .response-meta { border: 1px solid #CECECE; }
    .response-detail-table th { width: 34%; color: #555555; font-weight: 600; }
    .response-detail-table td { color: #212121; white-space: pre-wrap; overflow-wrap: anywhere; }
    .ticket-admin-card { border:1px solid #CECECE; background:linear-gradient(135deg,#F3F3F3,#fff); }
    .ticket-admin-list { display:grid; gap:10px; }
    .ticket-admin-item { display:flex; justify-content:space-between; align-items:center; gap:14px; border:1px solid #CECECE; border-radius:10px; padding:12px 14px; background:#fff; }
    @media(max-width:767px){.ticket-admin-item{align-items:flex-start;flex-direction:column}}
</style>

<?php if (!$response): ?>
    <div class="mb-4">
        <a href="<?= htmlspecialchars(appUrl('responses', $formId ? ['form_id' => $formId] : [])) ?>" class="btn btn-sm btn-outline-secondary">Voltar para respostas</a>
    </div>
    <div class="alert alert-warning">A resposta informada não existe ou não pertence a esta empresa e formulário.</div>
<?php else: ?>
    <?php if (($_GET['success'] ?? '') === 'payment_approved'): ?><div class="alert alert-success">Pagamento aprovado. Os ingressos foram emitidos e o envio por e-mail foi processado.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'ticket_sent'): ?><div class="alert alert-success">Ingresso emitido e e-mail enviado.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'ticket_issued'): ?><div class="alert alert-warning">Ingresso emitido, mas o servidor não confirmou o envio do e-mail. O link está disponível abaixo.</div><?php endif; ?>
    <?php if (isset($_GET['error'])): ?><div class="alert alert-danger"><?= ($_GET['error'] ?? '') === 'ticket_not_available' ? 'O ingresso só pode ser emitido para uma inscrição aprovada e com o modo ingresso ativo.' : 'Não foi possível concluir a operação.' ?></div><?php endif; ?>
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-start gap-3 mb-4">
        <div>
            <a href="<?= htmlspecialchars(appUrl('responses', ['form_id' => (int) $formId])) ?>" class="btn btn-sm btn-outline-secondary mb-3">Voltar para respostas</a>
            <h1 class="h3 mb-1">Resposta #<?= (int) ($response['response_number'] ?? $response['id']) ?></h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($response['form_title']) ?></p>
        </div>
    </div>

    <?php if ((int) ($response['ticket_enabled'] ?? 0) === 1 && ($response['payment_status'] ?? 'pending') === 'approved'): ?>
        <div class="card ticket-admin-card shadow-sm mb-4"><div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                <div><div class="small text-uppercase fw-bold text-primary mb-1">Ingressos</div><h2 class="h5 mb-1">Entrega e acesso</h2><p class="text-muted small mb-0">Links individuais para os participantes desta inscrição.</p></div>
                <?php if (!$ticket && canManageTenantData()): ?><form method="post"><input type="hidden" name="action" value="issue_ticket"><button class="btn btn-primary btn-sm" type="submit">Emitir ingresso</button></form><?php endif; ?>
            </div>
            <?php if ($groupTickets): ?>
                <div class="ticket-admin-list">
                    <?php foreach ($groupTickets as $groupTicket): ?>
                        <?php $ticketLink = ticketPublicUrl($groupTicket); $whatsAppLink = 'https://wa.me/?text=' . rawurlencode('Olá, segue o seu ingresso: ' . $ticketLink); ?>
                        <div class="ticket-admin-item">
                            <div><strong><?= htmlspecialchars($groupTicket['participant_name'] ?: $groupTicket['code']) ?></strong><div class="small text-muted"><?= htmlspecialchars($groupTicket['code']) ?> · <?= !empty($groupTicket['emailed_at']) ? 'E-mail enviado em ' . date('d/m/Y H:i', strtotime($groupTicket['emailed_at'])) : ($groupTicket['email_error'] ?: 'E-mail ainda não enviado') ?></div></div>
                            <div class="d-flex flex-wrap gap-2"><a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($ticketLink) ?>" target="_blank" rel="noopener">Abrir</a><a class="btn btn-sm btn-outline-success" href="<?= htmlspecialchars($whatsAppLink) ?>" target="_blank" rel="noopener">WhatsApp</a><?php if ((int) $groupTicket['response_id'] === (int) $responseId && canManageTenantData()): ?><form method="post"><input type="hidden" name="action" value="resend_ticket"><button class="btn btn-sm btn-outline-secondary" type="submit">Reenviar e-mail</button></form><?php endif; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?><p class="text-muted mb-0">O ingresso ainda não foi emitido.</p><?php endif; ?>
        </div></div>
    <?php endif; ?>

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
                        <?php if (!empty($response['pricing_lot_name'])): ?><div class="small text-muted mt-1">Lote: <?= htmlspecialchars($response['pricing_lot_name']) ?></div><?php endif; ?>
                        <?php if ((float) ($response['pricing_discount_total'] ?? 0) > 0): ?><div class="small text-success mt-1">Subtotal R$ <?= number_format((float) $response['pricing_subtotal'], 2, ',', '.') ?> · desconto de R$ <?= number_format((float) $response['pricing_discount_total'], 2, ',', '.') ?><?= !empty($response['pricing_coupon_code']) ? ' · cupom ' . htmlspecialchars($response['pricing_coupon_code']) : '' ?></div><?php endif; ?>
                        <?php if ((float) ($response['pricing_participant_coupon_discount'] ?? 0) > 0): ?><div class="small text-success mt-1">Este ingresso: cupom <?= htmlspecialchars((string) $response['pricing_participant_coupon_code']) ?> · desconto de R$ <?= number_format((float) $response['pricing_participant_coupon_discount'], 2, ',', '.') ?> · valor R$ <?= number_format((float) $response['pricing_participant_total'], 2, ',', '.') ?></div><?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <?php if (($response['payment_status'] ?? 'pending') !== 'approved' && canManageTenantData()): ?>
                            <form method="post"><input type="hidden" name="action" value="approve_payment"><button type="submit" class="btn btn-success btn-sm">Aprovar pagamento</button></form>
                        <?php elseif (($response['payment_status'] ?? 'pending') === 'approved'): ?>
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
