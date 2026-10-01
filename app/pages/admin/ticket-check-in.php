<?php

requireTenantContext();

$tenantId = (int) currentTenantIdForData();
$currentUser = user();
$pageTitle = 'Controle de entrada';
$csrfToken = $_SESSION['ticket_checkin_access_csrf'] ??= bin2hex(random_bytes(32));
$message = null;
$messageType = 'info';
$generatedToken = null;

$eventsSql =
    'SELECT id, title, ticket_title, ticket_event_at, ticket_location
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
$eventsById = [];
foreach ($ticketEvents as $event) {
    $eventsById[(int) $event['id']] = $event;
}

$eventInput = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['form_id'] ?? null)
    : ($_GET['form_id'] ?? null);
$selectedEventId = filter_var($eventInput, FILTER_VALIDATE_INT) ?: null;
if ($selectedEventId === null && $ticketEvents) {
    $selectedEventId = (int) $ticketEvents[0]['id'];
}
if ($selectedEventId !== null && !isset($eventsById[$selectedEventId])) {
    $selectedEventId = null;
    $message = 'O evento selecionado não está disponível para controle de entrada.';
    $messageType = 'danger';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rotate_access_token') {
    $postedCsrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $postedCsrf)) {
        $message = 'A sessão expirou. Atualize a página e tente novamente.';
        $messageType = 'danger';
    } elseif ($selectedEventId === null || !isset($eventsById[$selectedEventId])) {
        $message = 'Selecione um formulário com ingressos ativos.';
        $messageType = 'danger';
    } else {
        $generatedToken = rotateTicketCheckinAccessToken(
            $pdo,
            $tenantId,
            $selectedEventId,
            !empty($currentUser['id']) ? (int) $currentUser['id'] : null
        );
        $message = 'Novo acesso gerado. O token anterior deixou de funcionar.';
        $messageType = 'success';
    }
}

$accessInfo = $selectedEventId !== null
    ? ticketCheckinAccessTokenInfo($pdo, $tenantId, $selectedEventId)
    : null;

$counts = ['total' => 0, 'used' => 0, 'remaining' => 0, 'cancelled' => 0];
if ($selectedEventId !== null) {
    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(status = 'used' OR checked_in_at IS NOT NULL) AS used_count,
            SUM(status = 'valid' AND checked_in_at IS NULL) AS remaining_count,
            SUM(status = 'cancelled') AS cancelled_count
         FROM form_tickets
         WHERE tenant_id = ? AND form_id = ?"
    );
    $stmt->execute([$tenantId, $selectedEventId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $counts = [
        'total' => (int) ($row['total'] ?? 0),
        'used' => (int) ($row['used_count'] ?? 0),
        'remaining' => (int) ($row['remaining_count'] ?? 0),
        'cancelled' => (int) ($row['cancelled_count'] ?? 0),
    ];
}

$accessUrl = $generatedToken !== null
    ? absoluteAppUrl('entrada', ['token' => $generatedToken])
    : null;

require __DIR__ . '/../../layouts/admin-header.php';
require __DIR__ . '/../../layouts/admin-sidebar.php';
?>

<style>
    .gate-page{max-width:1050px;margin:0 auto}.gate-hero{margin-bottom:22px}.gate-title{font-size:32px;font-weight:850;letter-spacing:-.035em;color:#172033;margin:0}.gate-subtitle{color:#64748b;margin:7px 0 0;max-width:720px}.gate-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(300px,.85fr);gap:18px;align-items:start}.gate-card{background:#fff;border:1px solid #e2e8f0;border-radius:18px;box-shadow:0 14px 35px rgba(15,23,42,.055);overflow:hidden}.gate-body{padding:22px}.gate-card h2{font-size:17px;font-weight:850;margin:0 0 6px;color:#172033}.gate-card p{color:#64748b;font-size:13px}.gate-form{display:grid;gap:15px;margin-top:18px}.gate-form label{font-size:12px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:.045em}.gate-form .form-select{min-height:48px;border-radius:12px}.gate-generate{min-height:48px;border-radius:12px;font-weight:800}.gate-stats{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:18px}.gate-stat{padding:14px;border:1px solid #e8edf3;border-radius:13px;background:#f8fafc}.gate-stat span{display:block;font-size:10px;font-weight:800;color:#64748b;text-transform:uppercase;letter-spacing:.05em}.gate-stat strong{display:block;margin-top:3px;font-size:25px;color:#172033}.gate-token{margin-top:18px;padding:16px;border:1px solid #bbf7d0;background:#f0fdf4;border-radius:14px}.gate-token-label{font-size:11px;font-weight:850;color:#166534;text-transform:uppercase;letter-spacing:.05em}.gate-link{display:flex;gap:8px;margin-top:8px}.gate-link input{min-width:0}.gate-link .btn{white-space:nowrap}.gate-open{width:100%;margin-top:10px;min-height:46px;font-weight:800}.gate-status{display:grid;gap:10px;margin-top:16px}.gate-status-row{display:flex;justify-content:space-between;gap:16px;padding:11px 0;border-bottom:1px solid #eef2f7;font-size:13px}.gate-status-row:last-child{border-bottom:0}.gate-status-row span{color:#64748b}.gate-status-row strong{text-align:right;color:#172033}.gate-note{margin-top:14px;padding:13px;border-radius:12px;background:#fff7ed;color:#9a3412;font-size:12px;line-height:1.5}.gate-message{padding:12px 14px;border-radius:12px;margin-bottom:16px;font-size:13px;font-weight:700}.gate-message.success{background:#dcfce7;color:#166534}.gate-message.danger{background:#fee2e2;color:#991b1b}@media(max-width:850px){.gate-grid{grid-template-columns:1fr}.gate-title{font-size:28px}}@media(max-width:575px){.gate-body{padding:17px}.gate-stats{grid-template-columns:1fr 1fr}.gate-link{display:grid}.gate-link .btn{width:100%}}
</style>

<div class="gate-page">
    <header class="gate-hero">
        <h1 class="gate-title">Controle de entrada</h1>
        <p class="gate-subtitle">Selecione o formulário e gere um acesso exclusivo para a equipe da portaria. A área de leitura funciona sem login e prepara uma cópia local dos ingressos para continuar operando mesmo com internet instável.</p>
    </header>

    <?php if ($message): ?><div class="gate-message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <?php if (!$ticketEvents): ?>
        <div class="alert alert-info">Nenhum formulário com emissão de ingressos ativa foi encontrado.</div>
    <?php else: ?>
        <div class="gate-grid">
            <section class="gate-card">
                <div class="gate-body">
                    <h2>Acesso da portaria</h2>
                    <p>Ao regenerar, o acesso anterior é revogado imediatamente.</p>

                    <form method="get" action="<?= htmlspecialchars(appUrl('ticket-check-in')) ?>" class="gate-form">
                        <div>
                            <label for="gateEvent">Formulário / evento</label>
                            <select class="form-select" id="gateEvent" name="form_id" onchange="this.form.submit()">
                                <?php foreach ($ticketEvents as $event): ?>
                                    <option value="<?= (int) $event['id'] ?>" <?= $selectedEventId === (int) $event['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(trim((string) ($event['ticket_title'] ?? '')) ?: $event['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>

                    <div class="gate-stats">
                        <div class="gate-stat"><span>Ingressos</span><strong><?= $counts['total'] ?></strong></div>
                        <div class="gate-stat"><span>Faltam entrar</span><strong><?= $counts['remaining'] ?></strong></div>
                        <div class="gate-stat"><span>Já entraram</span><strong><?= $counts['used'] ?></strong></div>
                        <div class="gate-stat"><span>Cancelados</span><strong><?= $counts['cancelled'] ?></strong></div>
                    </div>

                    <form method="post" class="mt-3" data-confirm="<?= $accessInfo ? 'Regenerar o acesso? O link anterior deixará de funcionar.' : 'Gerar acesso para a portaria?' ?>" data-confirm-button="<?= $accessInfo ? 'Regenerar acesso' : 'Gerar acesso' ?>">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="rotate_access_token">
                        <input type="hidden" name="form_id" value="<?= (int) $selectedEventId ?>">
                        <button class="btn btn-primary gate-generate w-100" type="submit"><?= $accessInfo ? 'Regenerar token de acesso' : 'Gerar token de acesso' ?></button>
                    </form>

                    <?php if ($accessUrl): ?>
                        <div class="gate-token">
                            <div class="gate-token-label">Novo acesso gerado</div>
                            <div class="gate-link">
                                <input class="form-control" id="gateAccessUrl" value="<?= htmlspecialchars($accessUrl) ?>" readonly>
                                <button class="btn btn-outline-success" type="button" id="copyGateAccess">Copiar</button>
                            </div>
                            <a class="btn btn-success gate-open" href="<?= htmlspecialchars($accessUrl) ?>" target="_blank" rel="noopener">Abrir e preparar este aparelho</a>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <aside class="gate-card">
                <div class="gate-body">
                    <h2>Status do acesso</h2>
                    <?php if ($accessInfo): ?>
                        <div class="gate-status">
                            <div class="gate-status-row"><span>Token</span><strong><?= htmlspecialchars($accessInfo['token_prefix']) ?>…</strong></div>
                            <div class="gate-status-row"><span>Gerado</span><strong><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) ($accessInfo['regenerated_at'] ?: $accessInfo['created_at'])))) ?></strong></div>
                            <div class="gate-status-row"><span>Último uso</span><strong><?= !empty($accessInfo['last_used_at']) ? htmlspecialchars(date('d/m/Y H:i', strtotime($accessInfo['last_used_at']))) : 'Ainda não utilizado' ?></strong></div>
                        </div>
                    <?php else: ?>
                        <p class="mb-0 mt-3">Ainda não existe um acesso ativo para este evento.</p>
                    <?php endif; ?>

                    <div class="gate-note">
                        <strong>Modo offline:</strong> abra o novo link no aparelho que será usado na entrada enquanto houver internet. A página salva localmente a lista de ingressos e as dependências do leitor. Entradas feitas sem conexão ficam na fila e são sincronizadas quando a internet voltar. Use apenas aparelhos confiáveis, pois essa cópia contém nome, e-mail e código dos participantes.
                    </div>
                </div>
            </aside>
        </div>
    <?php endif; ?>
</div>

<script>
(() => {
    const button = document.getElementById('copyGateAccess');
    const input = document.getElementById('gateAccessUrl');
    button?.addEventListener('click', async () => {
        if (!input) return;
        try {
            await navigator.clipboard.writeText(input.value);
            button.textContent = 'Copiado';
            setTimeout(() => button.textContent = 'Copiar', 1600);
        } catch (_) {
            input.select();
            document.execCommand('copy');
        }
    });
})();
</script>

<?php require __DIR__ . '/../../layouts/admin-footer.php'; ?>
