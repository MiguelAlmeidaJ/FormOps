<?php

$rawAccessToken = trim((string) ($_GET['token'] ?? $_POST['access_token'] ?? ''));
$access = findTicketCheckinAccessByToken($pdo, $rawAccessToken);

function checkinAccessJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function checkinAccessCounts(PDO $pdo, int $tenantId, int $formId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(status = 'used' OR checked_in_at IS NOT NULL) AS used_count,
            SUM(status = 'valid' AND checked_in_at IS NULL) AS remaining_count,
            SUM(status = 'cancelled') AS cancelled_count
         FROM form_tickets
         WHERE tenant_id = ? AND form_id = ?"
    );
    $stmt->execute([$tenantId, $formId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'total' => (int) ($row['total'] ?? 0),
        'used' => (int) ($row['used_count'] ?? 0),
        'remaining' => (int) ($row['remaining_count'] ?? 0),
        'cancelled' => (int) ($row['cancelled_count'] ?? 0),
    ];
}

function checkinAccessTicketPayload(?array $ticket): ?array
{
    if (!$ticket) return null;
    return [
        'id' => (int) ($ticket['id'] ?? 0),
        'code' => (string) ($ticket['code'] ?? ''),
        'token' => (string) ($ticket['token'] ?? ''),
        'participant_name' => (string) ($ticket['participant_name'] ?? ''),
        'participant_email' => (string) ($ticket['participant_email'] ?? ''),
        'status' => (string) ($ticket['status'] ?? 'valid'),
        'issued_at' => $ticket['issued_at'] ?? null,
        'checked_in_at' => $ticket['checked_in_at'] ?? null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$access) {
        checkinAccessJson(['ok' => false, 'message' => 'Acesso inválido ou revogado.'], 403);
    }

    $tenantId = (int) $access['tenant_id'];
    $formId = (int) $access['form_id'];
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'sync') {
        checkinAccessJson([
            'ok' => true,
            'tickets' => array_map('checkinAccessTicketPayload', ticketCheckinSnapshot($pdo, $tenantId, $formId)),
            'counts' => checkinAccessCounts($pdo, $tenantId, $formId),
            'synced_at' => date(DATE_ATOM),
        ]);
    }

    if ($action === 'check_in') {
        $identifier = trim((string) ($_POST['identifier'] ?? ''));
        $ticket = findTenantTicketByIdentifier($pdo, $tenantId, $identifier);

        if (!$ticket || (int) ($ticket['form_id'] ?? 0) !== $formId) {
            checkinAccessJson([
                'ok' => false,
                'message' => 'Ingresso não encontrado para este evento.',
                'counts' => checkinAccessCounts($pdo, $tenantId, $formId),
            ], 404);
        }

        if (($ticket['status'] ?? '') === 'cancelled') {
            checkinAccessJson([
                'ok' => false,
                'message' => 'Este ingresso está cancelado.',
                'ticket' => checkinAccessTicketPayload($ticket),
                'counts' => checkinAccessCounts($pdo, $tenantId, $formId),
            ], 409);
        }

        if (($ticket['status'] ?? '') === 'used' || !empty($ticket['checked_in_at'])) {
            checkinAccessJson([
                'ok' => false,
                'message' => 'A entrada deste participante já foi registrada.',
                'ticket' => checkinAccessTicketPayload($ticket),
                'counts' => checkinAccessCounts($pdo, $tenantId, $formId),
            ], 409);
        }

        $stmt = $pdo->prepare(
            "UPDATE form_tickets
             SET status = 'used', checked_in_at = NOW(), checked_in_by = NULL
             WHERE id = ? AND tenant_id = ? AND form_id = ? AND status = 'valid' AND checked_in_at IS NULL"
        );
        $stmt->execute([(int) $ticket['id'], $tenantId, $formId]);

        $ticket = findTenantTicketByIdentifier($pdo, $tenantId, $identifier);
        if ($stmt->rowCount() !== 1) {
            checkinAccessJson([
                'ok' => false,
                'message' => 'Este ingresso foi atualizado em outro aparelho. Os dados foram sincronizados.',
                'ticket' => checkinAccessTicketPayload($ticket),
                'counts' => checkinAccessCounts($pdo, $tenantId, $formId),
            ], 409);
        }

        checkinAccessJson([
            'ok' => true,
            'message' => 'Entrada registrada com sucesso.',
            'ticket' => checkinAccessTicketPayload($ticket),
            'counts' => checkinAccessCounts($pdo, $tenantId, $formId),
        ]);
    }

    checkinAccessJson(['ok' => false, 'message' => 'Ação inválida.'], 400);
}

if (!$access) {
    http_response_code(403);
    ?>
    <!doctype html>
    <html lang="pt-br">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <meta name="referrer" content="no-referrer">
        <title>Acesso indisponível · FormOps</title>
        <style>
            *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:22px;background:#f3f6fb;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#172033}.box{width:min(100%,560px);padding:30px;border:1px solid #dfe5ed;border-radius:20px;background:#fff;box-shadow:0 18px 50px rgba(15,23,42,.08);text-align:center}.mark{width:56px;height:56px;border-radius:16px;background:#fee2e2;color:#991b1b;display:grid;place-items:center;margin:0 auto 16px;font-size:24px;font-weight:900}h1{font-size:24px;margin:0 0 8px}p{color:#64748b;line-height:1.55;margin:0}
        </style>
    </head>
    <body><main class="box"><div class="mark">!</div><h1>Acesso inválido ou revogado</h1><p>Solicite um novo link de controle de entrada ao responsável pelo evento.</p></main></body>
    </html>
    <?php
    exit;
}

$tenantId = (int) $access['tenant_id'];
$formId = (int) $access['form_id'];
$tickets = ticketCheckinSnapshot($pdo, $tenantId, $formId);
$counts = checkinAccessCounts($pdo, $tenantId, $formId);
$eventTitle = trim((string) ($access['ticket_title'] ?? '')) ?: (string) ($access['form_title'] ?? 'Evento');
$bootstrap = [
    'access_token' => $rawAccessToken,
    'form_id' => $formId,
    'event_title' => $eventTitle,
    'event_at' => $access['ticket_event_at'] ?? null,
    'location' => $access['ticket_location'] ?? null,
    'tenant_name' => $access['tenant_name'] ?? 'FormOps',
    'tickets' => array_map('checkinAccessTicketPayload', $tickets),
    'counts' => $counts,
    'generated_at' => date(DATE_ATOM),
    'sync_url' => appUrl('entrada'),
    'asset_base' => APP_BASE_PATH,
];
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="theme-color" content="#012672">
    <meta name="referrer" content="no-referrer">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title><?= htmlspecialchars($eventTitle) ?> · Entrada</title>
    <link rel="icon" href="<?= htmlspecialchars(appUrl('assets/clients/formops/favicon-formops.png')) ?>">
    <style>
        :root{--blue:#012672;--blue2:#067ae3;--ink:#172033;--muted:#64748b;--line:#dfe5ed;--bg:#f3f6fb;--ok:#047857;--warn:#a16207;--bad:#b91c1c}*{box-sizing:border-box}html,body{margin:0;min-height:100%;background:var(--bg);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--ink)}body{padding:env(safe-area-inset-top) 0 env(safe-area-inset-bottom)}button,input{font:inherit}.top{position:sticky;top:0;z-index:20;background:rgba(255,255,255,.96);backdrop-filter:blur(16px);border-bottom:1px solid var(--line)}.top-inner{max-width:980px;margin:auto;padding:13px 16px;display:flex;align-items:center;justify-content:space-between;gap:14px}.event-name{font-size:16px;font-weight:850;line-height:1.2}.event-meta{font-size:11px;color:var(--muted);margin-top:3px}.net{display:inline-flex;align-items:center;gap:6px;padding:7px 9px;border-radius:999px;font-size:11px;font-weight:800;background:#dcfce7;color:#166534}.net.offline{background:#fef3c7;color:#92400e}.dot{width:7px;height:7px;border-radius:50%;background:currentColor}.page{max-width:980px;margin:auto;padding:16px}.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px;margin-bottom:14px}.stat{background:#fff;border:1px solid var(--line);border-radius:14px;padding:12px}.stat span{font-size:9px;font-weight:850;letter-spacing:.05em;text-transform:uppercase;color:var(--muted);display:block}.stat strong{font-size:25px;line-height:1.15;display:block;margin-top:4px}.stat.remaining strong{color:var(--blue2)}.stat.pending strong{color:var(--warn)}.card{background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:0 12px 30px rgba(15,23,42,.05);overflow:hidden}.body{padding:16px}.scan-title{font-size:17px;font-weight:850;margin:0 0 4px}.scan-copy{font-size:12px;color:var(--muted);margin:0 0 14px}.actions{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px}.code-input{min-width:0;height:48px;border:1px solid #cbd5e1;border-radius:12px;padding:0 13px;font-size:16px;outline:0}.code-input:focus{border-color:var(--blue2);box-shadow:0 0 0 3px rgba(6,122,227,.12)}.btn{min-height:46px;border:0;border-radius:12px;padding:0 15px;font-weight:850;cursor:pointer}.btn.primary{background:var(--blue2);color:#fff}.btn.secondary{background:#eef5ff;color:#015cc0}.btn.success{background:#047857;color:#fff;width:100%;margin-top:13px}.camera-button{width:100%;margin-top:9px}.scanner{display:none;position:relative;margin-top:12px;border-radius:16px;overflow:hidden;background:#07111f;aspect-ratio:4/3}.scanner.active{display:block}.scanner video{width:100%;height:100%;object-fit:cover;display:block}.guide{position:absolute;inset:17%;border:3px solid rgba(255,255,255,.94);border-radius:18px;box-shadow:0 0 0 999px rgba(0,0,0,.26);pointer-events:none}.scan-message{min-height:19px;margin:9px 2px 0;font-size:12px;color:var(--muted)}.result{display:none;margin-top:14px;border:1px solid var(--line);border-radius:16px;overflow:hidden}.result.show{display:block}.result-head{padding:15px;background:#f8fafc;display:flex;justify-content:space-between;gap:12px}.person{font-size:20px;font-weight:900;line-height:1.2;overflow-wrap:anywhere}.email{font-size:12px;color:var(--muted);margin-top:4px;overflow-wrap:anywhere}.badge{align-self:flex-start;border-radius:999px;padding:6px 9px;font-size:9px;font-weight:900;text-transform:uppercase;white-space:nowrap}.badge.valid{background:#dcfce7;color:#166534}.badge.used,.badge.used_pending{background:#fef3c7;color:#92400e}.badge.cancelled{background:#fee2e2;color:#991b1b}.result-body{padding:14px}.meta{display:grid;grid-template-columns:1fr 1fr;gap:12px}.meta span{font-size:9px;font-weight:850;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);display:block}.meta strong{font-size:13px;display:block;margin-top:3px;overflow-wrap:anywhere}.feedback{display:none;margin-top:12px;padding:12px;border-radius:12px;font-size:13px;font-weight:750}.feedback.show{display:block}.feedback.ok{background:#dcfce7;color:#166534}.feedback.warn{background:#fef3c7;color:#92400e}.feedback.bad{background:#fee2e2;color:#991b1b}.offline-info{margin-top:14px;padding:12px 13px;border-radius:13px;background:#eef5ff;color:#334155;font-size:11px;line-height:1.45}.sync-line{display:flex;justify-content:space-between;gap:10px;margin-top:8px;color:var(--muted)}.sync-line button{border:0;background:none;padding:0;color:#015cc0;font-weight:800;font-size:11px}.canvas{display:none}@media(max-width:600px){.page{padding:11px}.top-inner{padding:11px 12px}.stats{gap:6px}.stat{padding:10px}.stat strong{font-size:22px}.actions{grid-template-columns:1fr}.actions .btn{width:100%}.body{padding:14px}.meta{grid-template-columns:1fr}.person{font-size:19px}} 
    </style>
</head>
<body>
<header class="top">
    <div class="top-inner">
        <div><div class="event-name"><?= htmlspecialchars($eventTitle) ?></div><div class="event-meta"><?= htmlspecialchars((string) ($access['tenant_name'] ?? '')) ?><?= !empty($access['ticket_location']) ? ' · ' . htmlspecialchars((string) $access['ticket_location']) : '' ?></div></div>
        <div class="net" id="networkState"><span class="dot"></span><span>Online</span></div>
    </div>
</header>

<main class="page">
    <section class="stats">
        <div class="stat remaining"><span>Faltam entrar</span><strong id="remainingCount"><?= $counts['remaining'] ?></strong></div>
        <div class="stat"><span>Já entraram</span><strong id="usedCount"><?= $counts['used'] ?></strong></div>
        <div class="stat pending"><span>Fila offline</span><strong id="pendingCount">0</strong></div>
    </section>

    <section class="card">
        <div class="body">
            <h1 class="scan-title">Ler ingresso</h1>
            <p class="scan-copy">Use a câmera ou digite o código. A identificação do participante aparece imediatamente.</p>

            <form id="lookupForm" class="actions">
                <input class="code-input" id="identifierInput" autocomplete="off" autocapitalize="characters" placeholder="ING-... ou token" aria-label="Código do ingresso">
                <button class="btn primary" type="submit">Consultar</button>
            </form>
            <button class="btn secondary camera-button" type="button" id="cameraButton">Abrir câmera</button>

            <div class="scanner" id="scanner">
                <video id="scannerVideo" playsinline muted></video>
                <div class="guide"></div>
            </div>
            <canvas class="canvas" id="scannerCanvas"></canvas>
            <div class="scan-message" id="scannerMessage"></div>

            <article class="result" id="ticketResult">
                <div class="result-head">
                    <div><div class="person" id="resultName"></div><div class="email" id="resultEmail"></div></div>
                    <span class="badge" id="resultStatus"></span>
                </div>
                <div class="result-body">
                    <div class="meta">
                        <div><span>Código</span><strong id="resultCode"></strong></div>
                        <div><span>Entrada</span><strong id="resultCheckedAt">Ainda não registrada</strong></div>
                    </div>
                    <button class="btn success" type="button" id="checkinButton">Registrar entrada</button>
                </div>
            </article>

            <div class="feedback" id="feedback"></div>

            <div class="offline-info">
                Este aparelho mantém uma cópia local dos ingressos. Sem internet, as entradas ficam salvas aqui e são enviadas ao FormOps assim que a conexão voltar.
                <div class="sync-line"><span id="lastSync">Preparando cópia local…</span><button type="button" id="syncNow">Sincronizar agora</button></div>
            </div>
        </div>
    </section>
</main>

<script src="<?= htmlspecialchars(appUrl('assets/vendor/jsQR.js')) ?>"></script>
<script>
window.__FORMOPS_CHECKIN__ = <?= json_encode($bootstrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script>
(() => {
    const boot = window.__FORMOPS_CHECKIN__;
    const storageKey = 'formops-checkin:' + boot.form_id + ':' + boot.access_token.slice(0, 12);
    const queueKey = storageKey + ':queue';
    const snapshotKey = storageKey + ':snapshot';
    const syncUrl = boot.sync_url;
    const input = document.getElementById('identifierInput');
    const form = document.getElementById('lookupForm');
    const result = document.getElementById('ticketResult');
    const resultName = document.getElementById('resultName');
    const resultEmail = document.getElementById('resultEmail');
    const resultStatus = document.getElementById('resultStatus');
    const resultCode = document.getElementById('resultCode');
    const resultCheckedAt = document.getElementById('resultCheckedAt');
    const checkinButton = document.getElementById('checkinButton');
    const feedback = document.getElementById('feedback');
    const remainingCount = document.getElementById('remainingCount');
    const usedCount = document.getElementById('usedCount');
    const pendingCount = document.getElementById('pendingCount');
    const lastSync = document.getElementById('lastSync');
    const syncNow = document.getElementById('syncNow');
    const networkState = document.getElementById('networkState');
    const cameraButton = document.getElementById('cameraButton');
    const scanner = document.getElementById('scanner');
    const video = document.getElementById('scannerVideo');
    const canvas = document.getElementById('scannerCanvas');
    const scannerMessage = document.getElementById('scannerMessage');
    const context = canvas.getContext('2d', {willReadFrequently:true});

    let snapshot = null;
    let index = new Map();
    let selectedTicket = null;
    let stream = null;
    let scanning = false;
    let scanTimer = null;
    let lastDecodeAt = 0;

    function readJson(key, fallback) {
        try { return JSON.parse(localStorage.getItem(key) || '') || fallback; } catch (_) { return fallback; }
    }
    function writeJson(key, value) {
        try { localStorage.setItem(key, JSON.stringify(value)); return true; } catch (_) { return false; }
    }
    function initialSnapshot() {
        const local = readJson(snapshotKey, null);
        if (local && Number(local.form_id) === Number(boot.form_id) && Array.isArray(local.tickets)) return local;
        return {form_id:boot.form_id,event_title:boot.event_title,tickets:boot.tickets,counts:boot.counts,synced_at:boot.generated_at};
    }
    function normalizeIdentifier(value) {
        value = String(value || '').trim();
        if (!value) return '';
        try {
            const url = new URL(value);
            const token = url.searchParams.get('token');
            if (token) return token.trim().toLowerCase();
        } catch (_) {}
        const match = value.match(/token=([a-f0-9]{64})/i);
        if (match) return match[1].toLowerCase();
        return /^[a-f0-9]{64}$/i.test(value) ? value.toLowerCase() : value.toUpperCase();
    }
    function rebuildIndex() {
        index = new Map();
        (snapshot.tickets || []).forEach(ticket => {
            if (ticket.code) index.set(String(ticket.code).toUpperCase(), ticket);
            if (ticket.token) index.set(String(ticket.token).toLowerCase(), ticket);
        });
    }
    function queue() { return readJson(queueKey, []); }
    function saveQueue(items) { writeJson(queueKey, items); updateStats(); }
    function saveSnapshot() {
        writeJson(snapshotKey, snapshot);
        rebuildIndex();
        updateStats();
    }
    function statusLabel(status) {
        return status === 'used' ? 'Já utilizado' : status === 'used_pending' ? 'Offline pendente' : status === 'cancelled' ? 'Cancelado' : 'Válido';
    }
    function updateStats(counts = null) {
        if (counts) snapshot.counts = counts;
        let remaining = 0, used = 0;
        (snapshot.tickets || []).forEach(ticket => {
            if (ticket.status === 'valid' && !ticket.checked_in_at) remaining++;
            if (ticket.status === 'used' || ticket.status === 'used_pending' || ticket.checked_in_at) used++;
        });
        remainingCount.textContent = String(remaining);
        usedCount.textContent = String(used);
        pendingCount.textContent = String(queue().length);
    }
    function showFeedback(message, type='ok') {
        feedback.textContent = message;
        feedback.className = 'feedback show ' + type;
    }
    function hideFeedback() { feedback.className = 'feedback'; feedback.textContent = ''; }
    function renderTicket(ticket) {
        selectedTicket = ticket || null;
        if (!ticket) {
            result.classList.remove('show');
            return;
        }
        resultName.textContent = ticket.participant_name || 'Participante';
        resultEmail.textContent = ticket.participant_email || 'E-mail não informado';
        resultCode.textContent = ticket.code || '-';
        resultCheckedAt.textContent = ticket.checked_in_at ? new Date(ticket.checked_in_at.replace(' ', 'T')).toLocaleString('pt-BR') : 'Ainda não registrada';
        resultStatus.textContent = statusLabel(ticket.status);
        resultStatus.className = 'badge ' + (ticket.status || 'valid');
        checkinButton.hidden = ticket.status !== 'valid' || !!ticket.checked_in_at;
        result.classList.add('show');
    }
    function lookup(value) {
        hideFeedback();
        const key = normalizeIdentifier(value);
        const ticket = index.get(key);
        if (!ticket) {
            renderTicket(null);
            showFeedback('Ingresso não encontrado na cópia deste evento.', 'bad');
            return null;
        }
        renderTicket(ticket);
        return ticket;
    }
    function setNetworkState() {
        const online = navigator.onLine;
        networkState.classList.toggle('offline', !online);
        networkState.querySelector('span:last-child').textContent = online ? 'Online' : 'Offline';
    }
    function replaceTicket(updated) {
        if (!updated) return;
        const pos = snapshot.tickets.findIndex(ticket => Number(ticket.id) === Number(updated.id));
        if (pos >= 0) snapshot.tickets[pos] = {...snapshot.tickets[pos], ...updated};
        else snapshot.tickets.push(updated);
        saveSnapshot();
        if (selectedTicket && Number(selectedTicket.id) === Number(updated.id)) renderTicket(snapshot.tickets.find(t => Number(t.id) === Number(updated.id)));
    }
    async function api(action, extra = {}) {
        const body = new FormData();
        body.set('access_token', boot.access_token);
        body.set('action', action);
        Object.entries(extra).forEach(([key,value]) => body.set(key, value));
        const response = await fetch(syncUrl, {method:'POST', body, headers:{'X-Requested-With':'XMLHttpRequest'}});
        let payload = {};
        try { payload = await response.json(); } catch (_) {}
        if (!response.ok || !payload.ok) {
            const error = new Error(payload.message || 'Não foi possível concluir a operação.');
            error.payload = payload;
            throw error;
        }
        return payload;
    }
    async function registerEntry(ticket) {
        if (!ticket) return;
        if (ticket.status !== 'valid' || ticket.checked_in_at) {
            showFeedback('Este ingresso já não está disponível para entrada.', 'warn');
            return;
        }
        checkinButton.disabled = true;
        hideFeedback();

        if (navigator.onLine) {
            try {
                const payload = await api('check_in', {identifier:ticket.token || ticket.code});
                replaceTicket(payload.ticket);
                showFeedback(payload.message || 'Entrada registrada com sucesso.', 'ok');
                if (navigator.vibrate) navigator.vibrate(80);
                input.value = '';
                return;
            } catch (error) {
                if (error.payload?.ticket) replaceTicket(error.payload.ticket);
                if (error.payload && error.payload.message && navigator.onLine) {
                    showFeedback(error.payload.message, 'warn');
                    return;
                }
            } finally {
                checkinButton.disabled = false;
            }
        }

        const now = new Date().toISOString();
        const pending = {...ticket,status:'used_pending',checked_in_at:now};
        replaceTicket(pending);
        const items = queue();
        if (!items.some(item => Number(item.ticket_id) === Number(ticket.id))) {
            items.push({ticket_id:ticket.id,identifier:ticket.token || ticket.code,created_at:now});
            saveQueue(items);
        }
        showFeedback('Entrada registrada neste aparelho. Será sincronizada quando a internet voltar.', 'warn');
        if (navigator.vibrate) navigator.vibrate([60,40,60]);
        input.value = '';
        checkinButton.disabled = false;
    }
    async function flushQueue() {
        if (!navigator.onLine) return;
        let items = queue();
        if (!items.length) return;
        const remaining = [];
        for (const item of items) {
            try {
                const payload = await api('check_in', {identifier:item.identifier});
                if (payload.ticket) replaceTicket(payload.ticket);
            } catch (error) {
                if (error.payload?.ticket) {
                    replaceTicket(error.payload.ticket);
                } else if (!navigator.onLine) {
                    remaining.push(item);
                }
            }
        }
        saveQueue(remaining);
    }
    async function syncSnapshot() {
        if (!navigator.onLine) {
            lastSync.textContent = 'Sem internet · usando cópia local';
            return;
        }
        syncNow.disabled = true;
        try {
            await flushQueue();
            const payload = await api('sync');
            snapshot = {form_id:boot.form_id,event_title:boot.event_title,tickets:payload.tickets,counts:payload.counts,synced_at:payload.synced_at};
            saveSnapshot();
            lastSync.textContent = 'Cópia atualizada às ' + new Date(payload.synced_at).toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});
            if (selectedTicket) {
                const current = snapshot.tickets.find(t => Number(t.id) === Number(selectedTicket.id));
                renderTicket(current || null);
            }
        } catch (_) {
            lastSync.textContent = 'Falha ao sincronizar · cópia local mantida';
        } finally {
            syncNow.disabled = false;
        }
    }

    function stopCamera() {
        scanning = false;
        if (scanTimer) cancelAnimationFrame(scanTimer);
        scanTimer = null;
        stream?.getTracks().forEach(track => track.stop());
        stream = null;
        video.srcObject = null;
        scanner.classList.remove('active');
        cameraButton.textContent = 'Abrir câmera';
    }
    async function startCamera() {
        if (scanning) { stopCamera(); return; }
        if (!navigator.mediaDevices?.getUserMedia) {
            scannerMessage.textContent = 'Este navegador não liberou acesso à câmera. Digite o código do ingresso.';
            return;
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video:{facingMode:{ideal:'environment'},width:{ideal:1280},height:{ideal:720}},
                audio:false
            });
            video.srcObject = stream;
            await video.play();
            scanning = true;
            scanner.classList.add('active');
            cameraButton.textContent = 'Fechar câmera';
            scannerMessage.textContent = 'Aponte para o QR Code do ingresso.';
            scanFrame();
        } catch (_) {
            stopCamera();
            scannerMessage.textContent = 'Não foi possível abrir a câmera. No Safari, confirme a permissão de câmera para este site.';
        }
    }
    function scanFrame(timestamp = 0) {
        if (!scanning) return;

        // Decodificar QR é a parte mais pesada. Limitamos a ~7 leituras/s para
        // manter Safari/iPhone responsivo e reduzir aquecimento da câmera.
        if (timestamp - lastDecodeAt < 140) {
            scanTimer = requestAnimationFrame(scanFrame);
            return;
        }
        lastDecodeAt = timestamp;

        if (video.readyState >= 2 && video.videoWidth && video.videoHeight && typeof window.jsQR === 'function') {
            const maxWidth = 520;
            const scale = Math.min(1, maxWidth / video.videoWidth);
            canvas.width = Math.max(1, Math.round(video.videoWidth * scale));
            canvas.height = Math.max(1, Math.round(video.videoHeight * scale));
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            const image = context.getImageData(0,0,canvas.width,canvas.height);
            const code = window.jsQR(image.data, image.width, image.height, {inversionAttempts:'dontInvert'});
            if (code?.data) {
                input.value = code.data;
                stopCamera();
                const ticket = lookup(code.data);
                if (ticket) {
                    scannerMessage.textContent = 'QR Code identificado.';
                    if (navigator.vibrate) navigator.vibrate(45);
                }
                return;
            }
        }
        scanTimer = requestAnimationFrame(scanFrame);
    }

    snapshot = initialSnapshot();
    saveSnapshot();
    setNetworkState();
    updateStats();
    lastSync.textContent = navigator.onLine ? 'Cópia local pronta' : 'Offline · cópia local pronta';

    form.addEventListener('submit', event => {
        event.preventDefault();
        lookup(input.value);
    });
    checkinButton.addEventListener('click', () => registerEntry(selectedTicket));
    cameraButton.addEventListener('click', startCamera);
    syncNow.addEventListener('click', syncSnapshot);
    window.addEventListener('online', () => { setNetworkState(); syncSnapshot(); });
    window.addEventListener('offline', () => { setNetworkState(); lastSync.textContent = 'Offline · usando cópia local'; });
    window.addEventListener('pagehide', stopCamera);

    async function prepareOfflineShell() {
        if (!('caches' in window) || !navigator.onLine) return;
        try {
            const cache = await caches.open('formops-checkin-v2');
            await Promise.all([
                cache.add(window.location.href),
                cache.add(<?= json_encode(appUrl('assets/vendor/jsQR.js')) ?>),
                cache.add(<?= json_encode(appUrl('assets/clients/formops/favicon-formops.png')) ?>)
            ]);
        } catch (_) {}
    }

    if ('serviceWorker' in navigator && location.protocol === 'https:') {
        navigator.serviceWorker.register(<?= json_encode(appUrl('checkin-sw.js')) ?>, {scope:<?= json_encode(APP_BASE_PATH . '/') ?>})
            .then(async () => {
                await prepareOfflineShell();
                await syncSnapshot();
            })
            .catch(() => syncSnapshot());
    } else {
        prepareOfflineShell().finally(syncSnapshot);
    }
})();
</script>
</body>
</html>
