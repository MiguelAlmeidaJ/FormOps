<?php

$token = strtolower(trim((string) ($_GET['token'] ?? '')));
$ticket = findTicketByToken($pdo, $token);

if (!$ticket) {
    http_response_code(404);
}

$primary = ticketColor($ticket['ticket_primary_color'] ?? null, '#212121');
$background = ticketAssetUrl($ticket['ticket_background_path'] ?? '');
$eventTitle = trim((string) ($ticket['ticket_title'] ?? '')) ?: trim((string) ($ticket['form_title'] ?? 'Ingresso'));
$qrUrl = $ticket ? ticketQrImageUrl($ticket, 600) : '';
$pdfUrl = $ticket ? appUrl('ingresso-pdf', ['token' => $token]) : '';
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($ticket ? $eventTitle . ' · Ingresso' : 'Ingresso não encontrado') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root{--ticket-primary:<?= htmlspecialchars($primary) ?>}
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;background:#F3F3F3;color:#212121;font-family:"Poppins",sans-serif;padding:32px 18px}
        .page{width:min(100%,820px);margin:0 auto}
        .actions{display:flex;justify-content:flex-end;margin-bottom:16px}
        .btn{display:inline-flex;align-items:center;justify-content:center;min-height:44px;border:1px solid #212121;border-radius:12px;padding:9px 18px;background:#212121;color:#fff;text-decoration:none;font-size:14px;font-weight:800;box-shadow:0 10px 24px rgba(33,33,33,.16)}
        .ticket{overflow:hidden;border:1px solid #CECECE;border-radius:24px;background:#fff;box-shadow:0 26px 70px rgba(33,33,33,.14)}
        .ticket-image{position:relative;width:100%;aspect-ratio:16/9;display:grid;place-items:center;overflow:hidden;background:linear-gradient(135deg,var(--ticket-primary),#555)}
        .ticket-image img{display:block;width:100%;height:100%;object-fit:contain}
        .ticket-image-fallback{padding:30px;color:#fff;font-size:clamp(28px,6vw,52px);font-weight:900;text-align:center;letter-spacing:-.04em}
        .ticket-content{padding:clamp(22px,4vw,38px)}
        .ticket-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:28px;padding:22px 0;border-bottom:1px solid #E5E5E5}
        .ticket-row:first-child{padding-top:0}
        .ticket-detail span{display:block;margin-bottom:7px;color:#777;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.1em}
        .ticket-detail strong{display:block;color:#212121;font-size:clamp(16px,2.5vw,21px);font-weight:850;line-height:1.35;overflow-wrap:anywhere}
        .ticket-qr{display:flex;flex-direction:column;align-items:center;padding-top:30px;text-align:center}
        .ticket-qr img{display:block;width:min(100%,280px);aspect-ratio:1;border:1px solid #CECECE;border-radius:18px;padding:10px;background:#fff}
        .ticket-qr-label{margin-top:12px;color:#555;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.08em}
        .not-found{background:#fff;border-radius:20px;padding:50px;text-align:center;box-shadow:0 24px 60px rgba(33,33,33,.12)}
        @media(max-width:575px){body{padding:18px 12px}.actions{justify-content:stretch}.btn{width:100%}.ticket{border-radius:18px}.ticket-content{padding:20px}.ticket-row{grid-template-columns:1fr;gap:18px;padding:18px 0}.ticket-qr{padding-top:22px}.ticket-qr img{width:min(100%,230px)}}
    </style>
</head>
<body>
<main class="page">
    <?php if (!$ticket): ?>
        <section class="not-found"><h1>Ingresso não encontrado</h1><p>Confira se o link está completo ou solicite um novo acesso à organização.</p></section>
    <?php else: ?>
        <div class="actions"><a class="btn" href="<?= htmlspecialchars($pdfUrl) ?>">Baixar ingresso em PDF</a></div>
        <article class="ticket">
            <div class="ticket-image">
                <?php if ($background): ?><img src="<?= htmlspecialchars($background) ?>" alt="<?= htmlspecialchars($eventTitle) ?>"><?php else: ?><div class="ticket-image-fallback"><?= htmlspecialchars($eventTitle) ?></div><?php endif; ?>
            </div>
            <div class="ticket-content">
                <section class="ticket-row">
                    <div class="ticket-detail"><span>Local do evento</span><strong><?= htmlspecialchars($ticket['ticket_location'] ?: 'Local a confirmar') ?></strong></div>
                    <div class="ticket-detail"><span>Dia do evento</span><strong><?= !empty($ticket['ticket_event_at']) ? htmlspecialchars(date('d/m/Y H:i', strtotime($ticket['ticket_event_at']))) : 'Data a confirmar' ?></strong></div>
                </section>
                <section class="ticket-row">
                    <div class="ticket-detail"><span>Nome</span><strong><?= htmlspecialchars($ticket['participant_name'] ?: 'Participante') ?></strong></div>
                    <div class="ticket-detail"><span>Ingresso</span><strong><?= htmlspecialchars($ticket['code']) ?></strong></div>
                </section>
                <section class="ticket-qr">
                    <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR Code para verificação do ingresso">
                    <div class="ticket-qr-label">Apresente este QR Code na entrada</div>
                </section>
            </div>
        </article>
    <?php endif; ?>
</main>
</body>
</html>
