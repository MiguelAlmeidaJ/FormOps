<?php

requireTenantContext();

$tenantId = (int) currentTenantIdForData();
$identifier = trim((string) ($_GET['token'] ?? $_GET['code'] ?? $_POST['identifier'] ?? ''));
$csrfToken = $_SESSION['ticket_checkin_csrf'] ??= bin2hex(random_bytes(32));
$ticket = null;
$message = null;
$messageType = 'info';

$eventsSql =
    'SELECT id, title, ticket_title, ticket_event_at
     FROM forms
     WHERE tenant_id = ? AND ticket_enabled = 1';
$eventsParams = [$tenantId];
if (shouldScopeTenantUserToGroup()) {
    $eventsSql .= ' AND ' . currentUserFormGroupScopeSql('form_group_id');
    $eventsParams = array_merge($eventsParams, currentUserFormGroupIds());
}
$eventsSql .= ' ORDER BY ticket_event_at DESC, created_at DESC, id DESC';
$stmt = $pdo->prepare($eventsSql);
$stmt->execute($eventsParams);
$ticketEvents = $stmt->fetchAll(PDO::FETCH_ASSOC);
$ticketEventsById = [];
foreach ($ticketEvents as $event) {
    $ticketEventsById[(int) $event['id']] = $event;
}

$eventInput = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['form_id'] ?? null)
    : ($_GET['form_id'] ?? null);
$hasEventInput = $eventInput !== null && $eventInput !== '';
$selectedEventId = filter_var($eventInput, FILTER_VALIDATE_INT) ?: null;
if ($selectedEventId !== null && !isset($ticketEventsById[$selectedEventId])) {
    $selectedEventId = null;
    $message = 'O evento selecionado não possui emissão de ingressos ativa.';
    $messageType = 'danger';
}

function ticketAllowedForCurrentGroup(?array $ticket): bool
{
    return $ticket
        && (!shouldScopeTenantUserToGroup()
            || currentUserCanAccessFormGroup(!empty($ticket['form_group_id']) ? (int) $ticket['form_group_id'] : null));
}

// Links vindos do QR Code não carregam o filtro: selecione o evento do próprio ingresso.
if (!$hasEventInput && $identifier !== '') {
    $identifiedTicket = findTenantTicketByIdentifier($pdo, $tenantId, $identifier);
    if (ticketAllowedForCurrentGroup($identifiedTicket) && isset($ticketEventsById[(int) ($identifiedTicket['form_id'] ?? 0)])) {
        $selectedEventId = (int) $identifiedTicket['form_id'];
    }
}
if (!$hasEventInput && $selectedEventId === null && $ticketEvents) {
    $selectedEventId = (int) $ticketEvents[0]['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_in') {
    $postedCsrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postedCsrf)) {
        $message = 'A sessão expirou. Atualize a página e tente novamente.';
        $messageType = 'danger';
    } else {
        $ticket = findTenantTicketByIdentifier($pdo, $tenantId, $identifier);
        if (!ticketAllowedForCurrentGroup($ticket) || $selectedEventId === null || (int) ($ticket['form_id'] ?? 0) !== $selectedEventId || !isset($ticketEventsById[$selectedEventId])) {
            $ticket = null;
            $message = 'Ingresso não encontrado para o evento selecionado.';
            $messageType = 'danger';
        } elseif (($ticket['status'] ?? '') === 'cancelled') {
            $message = 'Este ingresso está cancelado e não pode ser utilizado.';
            $messageType = 'danger';
        } elseif (($ticket['status'] ?? '') === 'used' || !empty($ticket['checked_in_at'])) {
            $message = 'A entrada deste participante já foi registrada.';
            $messageType = 'warning';
        } else {
            $stmt = $pdo->prepare(
                "UPDATE form_tickets
                 SET status = 'used', checked_in_at = NOW(), checked_in_by = ?
                 WHERE id = ? AND tenant_id = ? AND status = 'valid' AND checked_in_at IS NULL"
            );
            $stmt->execute([(int) (user()['id'] ?? 0), (int) $ticket['id'], $tenantId]);
            if ($stmt->rowCount() === 1) {
                redirectTo('ticket-check-in', ['form_id' => $selectedEventId, 'token' => $ticket['token'], 'result' => 'success']);
            }
            $message = 'O ingresso foi atualizado por outro usuário. Consulte novamente.';
            $messageType = 'warning';
        }
    }
}

if (!$ticket && $identifier !== '') {
    $ticket = findTenantTicketByIdentifier($pdo, $tenantId, $identifier);
    if (!ticketAllowedForCurrentGroup($ticket) || $selectedEventId === null || (int) ($ticket['form_id'] ?? 0) !== $selectedEventId || !isset($ticketEventsById[$selectedEventId])) {
        $ticket = null;
    }
    if (!$ticket && !$message) {
        $message = 'Ingresso não encontrado para o evento selecionado.';
        $messageType = 'danger';
    }
}

if ($ticket && ($_GET['result'] ?? '') === 'success') {
    $message = 'Entrada registrada com sucesso.';
    $messageType = 'success';
}

$recentEntries = [];
if ($selectedEventId !== null) {
    $recentSql =
        'SELECT ft.code, ft.participant_name, ft.checked_in_at, f.title AS form_title
         FROM form_tickets ft
         INNER JOIN forms f ON f.id = ft.form_id AND f.tenant_id = ft.tenant_id
         WHERE ft.tenant_id = ? AND ft.form_id = ? AND f.ticket_enabled = 1 AND ft.checked_in_at IS NOT NULL';
    $recentParams = [$tenantId, $selectedEventId];
    if (shouldScopeTenantUserToGroup()) {
        $recentSql .= ' AND ' . currentUserFormGroupScopeSql('f.form_group_id');
        $recentParams = array_merge($recentParams, currentUserFormGroupIds());
    }
    $recentSql .= ' ORDER BY ft.checked_in_at DESC LIMIT 10';
    $stmt = $pdo->prepare($recentSql);
    $stmt->execute($recentParams);
    $recentEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Controle de entrada';
require __DIR__ . '/../../layouts/admin-header.php';
require __DIR__ . '/../../layouts/admin-sidebar.php';
?>

<style>
    .checkin-hero{align-items:flex-end}.checkin-event-filter{min-width:300px}.checkin-event-filter label{display:block;margin-bottom:6px;color:#555555;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.04em}.checkin-event-filter .form-select{min-height:46px;border-radius:12px}@media(max-width:900px){.checkin-event-filter{min-width:0;margin-top:18px}}
    .checkin-page{max-width:1040px;margin:0 auto}.checkin-hero{display:flex;justify-content:space-between;gap:20px;margin-bottom:24px}.checkin-title{font-size:34px;font-weight:850;letter-spacing:-.04em;color:#212121;margin:0}.checkin-subtitle{color:#555555;margin:6px 0 0}.checkin-grid{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(300px,.75fr);gap:20px;align-items:start}.checkin-card{background:#fff;border:1px solid #CECECE;border-radius:20px;box-shadow:0 18px 40px rgba(33,33,33,.07);overflow:hidden}.checkin-card-body{padding:24px}.checkin-card-title{font-size:17px;font-weight:850;color:#212121;margin:0 0 15px}.checkin-search{display:flex;gap:10px}.checkin-search .form-control{min-height:48px;border-radius:12px}.checkin-search .btn{border-radius:12px;font-weight:800;white-space:nowrap}.scanner{display:none;margin-top:16px;border-radius:16px;overflow:hidden;background:#212121;position:relative}.scanner.is-active{display:block}.scanner video{display:block;width:100%;max-height:420px;object-fit:cover}.scanner-guide{position:absolute;inset:18%;border:3px solid rgba(255,255,255,.88);border-radius:18px;box-shadow:0 0 0 999px rgba(0,0,0,.25)}.scanner-message{font-size:13px;color:#555555;margin:10px 0 0}.ticket-result{margin-top:20px;border:1px solid #CECECE;border-radius:18px;overflow:hidden}.ticket-result-head{display:flex;justify-content:space-between;align-items:flex-start;gap:15px;padding:20px;background:#F3F3F3}.ticket-person{font-size:23px;font-weight:850;color:#212121;margin:0}.ticket-event{font-size:13px;color:#555555;margin-top:4px}.ticket-status{display:inline-flex;border-radius:999px;padding:7px 11px;font-size:11px;font-weight:900;text-transform:uppercase}.ticket-status.valid{background:#dcfce7;color:#166534}.ticket-status.used{background:#fef3c7;color:#92400e}.ticket-status.cancelled{background:#fee2e2;color:#991b1b}.ticket-result-body{padding:20px}.ticket-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.ticket-meta span{display:block;color:#555555;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em}.ticket-meta strong{display:block;color:#212121;margin-top:4px}.checkin-submit{width:100%;min-height:50px;margin-top:20px;border:0;border-radius:13px;background:#166534;color:#fff;font-weight:850}.checkin-message{padding:13px 15px;border-radius:12px;margin-top:16px;font-size:14px;font-weight:700}.checkin-message.success{background:#dcfce7;color:#166534}.checkin-message.warning{background:#fef3c7;color:#92400e}.checkin-message.danger{background:#fee2e2;color:#991b1b}.recent-list{display:grid}.recent-item{padding:15px 20px;border-top:1px solid #F3F3F3}.recent-item:first-child{border-top:0}.recent-name{font-weight:800;color:#212121}.recent-meta{font-size:12px;color:#555555;margin-top:3px}.recent-empty{padding:22px;color:#555555;text-align:center}@media(max-width:900px){.checkin-grid{grid-template-columns:1fr}.checkin-hero{display:block}}@media(max-width:575px){.checkin-search{flex-direction:column}.checkin-card-body{padding:18px}.ticket-meta{grid-template-columns:1fr}}
</style>

<div class="checkin-page">
    <header class="checkin-hero">
        <div><h1 class="checkin-title">Controle de entrada</h1><p class="checkin-subtitle">Leia o QR Code ou informe o código para validar o ingresso.</p></div>
        <?php if ($ticketEvents): ?>
            <form method="get" action="<?= htmlspecialchars(appUrl('ticket-check-in')) ?>" class="checkin-event-filter">
                <label for="checkinEvent">Evento com ingresso</label>
                <select class="form-select" id="checkinEvent" name="form_id" onchange="this.form.submit()">
                    <?php foreach ($ticketEvents as $event): ?>
                        <option value="<?= (int) $event['id'] ?>" <?= $selectedEventId === (int) $event['id'] ? 'selected' : '' ?>><?= htmlspecialchars(trim((string) ($event['ticket_title'] ?? '')) ?: $event['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
    </header>

    <?php if (!$ticketEvents): ?><div class="alert alert-info">Nenhum evento com emissão de ingressos ativa foi encontrado.</div><?php endif; ?>

    <div class="checkin-grid">
        <section class="checkin-card">
            <div class="checkin-card-body">
                <h2 class="checkin-card-title">Verificar ingresso</h2>
                <form method="get" action="<?= htmlspecialchars(appUrl('ticket-check-in')) ?>" id="ticketSearchForm">
                    <input type="hidden" name="form_id" value="<?= (int) $selectedEventId ?>">
                    <div class="checkin-search">
                        <input class="form-control" id="ticketIdentifier" name="code" value="<?= htmlspecialchars($identifier) ?>" placeholder="ING-... ou token do ingresso" autocomplete="off" required <?= $selectedEventId === null ? 'disabled' : '' ?>>
                        <button class="btn btn-primary" type="submit" <?= $selectedEventId === null ? 'disabled' : '' ?>>Consultar</button>
                        <button class="btn btn-outline-secondary" type="button" id="startScanner" <?= $selectedEventId === null ? 'disabled' : '' ?>>Ler QR Code</button>
                    </div>
                </form>
                <div class="scanner" id="scanner"><video id="scannerVideo" playsinline muted></video><div class="scanner-guide"></div></div>
                <p class="scanner-message" id="scannerMessage"></p>

                <?php if ($message): ?><div class="checkin-message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>

                <?php if ($ticket): ?>
                    <?php $ticketStatus = (string) ($ticket['status'] ?? 'invalid'); ?>
                    <article class="ticket-result">
                        <div class="ticket-result-head">
                            <div><h3 class="ticket-person"><?= htmlspecialchars($ticket['participant_name'] ?: 'Participante') ?></h3><div class="ticket-event"><?= htmlspecialchars($ticket['ticket_title'] ?: $ticket['form_title']) ?></div></div>
                            <span class="ticket-status <?= htmlspecialchars($ticketStatus) ?>"><?= htmlspecialchars(ticketStatusLabel($ticketStatus)) ?></span>
                        </div>
                        <div class="ticket-result-body">
                            <div class="ticket-meta">
                                <div><span>Código</span><strong><?= htmlspecialchars($ticket['code']) ?></strong></div>
                                <div><span>Emitido em</span><strong><?= htmlspecialchars(date('d/m/Y H:i', strtotime($ticket['issued_at']))) ?></strong></div>
                                <?php if (!empty($ticket['ticket_event_at'])): ?><div><span>Evento</span><strong><?= htmlspecialchars(date('d/m/Y H:i', strtotime($ticket['ticket_event_at']))) ?></strong></div><?php endif; ?>
                                <?php if (!empty($ticket['checked_in_at'])): ?><div><span>Entrada registrada</span><strong><?= htmlspecialchars(date('d/m/Y H:i:s', strtotime($ticket['checked_in_at']))) ?></strong></div><?php endif; ?>
                            </div>
                            <?php if ($ticketStatus === 'valid' && empty($ticket['checked_in_at'])): ?>
                                <form method="post" data-confirm="Registrar a entrada deste participante?" data-confirm-button="Registrar entrada">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="check_in">
                                    <input type="hidden" name="form_id" value="<?= (int) $selectedEventId ?>">
                                    <input type="hidden" name="identifier" value="<?= htmlspecialchars($ticket['token']) ?>">
                                    <button class="checkin-submit" type="submit">Registrar entrada</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endif; ?>
            </div>
        </section>

        <aside class="checkin-card">
            <div class="checkin-card-body pb-2"><h2 class="checkin-card-title">Entradas recentes</h2></div>
            <div class="recent-list">
                <?php foreach ($recentEntries as $entry): ?>
                    <div class="recent-item"><div class="recent-name"><?= htmlspecialchars($entry['participant_name'] ?: $entry['code']) ?></div><div class="recent-meta"><?= htmlspecialchars($entry['form_title']) ?> · <?= htmlspecialchars(date('d/m/Y H:i:s', strtotime($entry['checked_in_at']))) ?></div></div>
                <?php endforeach; ?>
                <?php if (!$recentEntries): ?><div class="recent-empty">Nenhuma entrada registrada.</div><?php endif; ?>
            </div>
        </aside>
    </div>
</div>

<script>
(() => {
    const button = document.getElementById('startScanner');
    const scanner = document.getElementById('scanner');
    const video = document.getElementById('scannerVideo');
    const message = document.getElementById('scannerMessage');
    const input = document.getElementById('ticketIdentifier');
    const form = document.getElementById('ticketSearchForm');
    let stream = null;
    let scanning = false;

    const stop = () => {
        scanning = false;
        stream?.getTracks().forEach(track => track.stop());
        stream = null;
        scanner.classList.remove('is-active');
        button.textContent = 'Ler QR Code';
    };

    button?.addEventListener('click', async () => {
        if (scanning) { stop(); return; }
        if (!('BarcodeDetector' in window)) {
            message.textContent = 'A leitura pela câmera não é suportada neste navegador. Digite o código do ingresso.';
            return;
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}}});
            video.srcObject = stream;
            await video.play();
            scanning = true;
            scanner.classList.add('is-active');
            button.textContent = 'Fechar câmera';
            message.textContent = 'Posicione o QR Code dentro da área destacada.';
            const detector = new BarcodeDetector({formats:['qr_code']});
            const scan = async () => {
                if (!scanning) return;
                try {
                    const codes = await detector.detect(video);
                    if (codes.length) {
                        input.value = codes[0].rawValue;
                        stop();
                        form.submit();
                        return;
                    }
                } catch (error) {}
                requestAnimationFrame(scan);
            };
            scan();
        } catch (error) {
            stop();
            message.textContent = 'Não foi possível acessar a câmera. Verifique a permissão do navegador.';
        }
    });
    window.addEventListener('beforeunload', stop);
})();
</script>

<?php require __DIR__ . '/../../layouts/admin-footer.php'; ?>
