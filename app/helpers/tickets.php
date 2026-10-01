<?php

function ticketDetailsSelect(): string
{
    return 'SELECT ft.*, f.title AS form_title, f.form_group_id, f.ticket_title, f.ticket_subtitle,
                   f.ticket_event_at, f.ticket_location, f.ticket_instructions, f.ticket_background_path,
                   f.ticket_primary_color, f.ticket_text_color, f.ticket_email_subject, f.ticket_email_message,
                   t.name AS tenant_name, t.email AS tenant_email, t.logo_path AS tenant_logo
            FROM form_tickets ft
            INNER JOIN forms f ON f.id = ft.form_id AND f.tenant_id = ft.tenant_id
            INNER JOIN tenants t ON t.id = ft.tenant_id';
}

function ticketCheckinAccessTokenInfo(PDO $pdo, int $tenantId, int $formId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT * FROM form_checkin_access_tokens
         WHERE tenant_id = ? AND form_id = ? AND is_active = 1
         LIMIT 1'
    );
    $stmt->execute([$tenantId, $formId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function rotateTicketCheckinAccessToken(PDO $pdo, int $tenantId, int $formId, ?int $userId = null): string
{
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $tokenPrefix = substr($rawToken, 0, 10);

    $stmt = $pdo->prepare(
        'INSERT INTO form_checkin_access_tokens
            (tenant_id, form_id, token_hash, token_prefix, is_active, created_by, created_at, regenerated_at)
         VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            token_hash = VALUES(token_hash),
            token_prefix = VALUES(token_prefix),
            is_active = 1,
            created_by = VALUES(created_by),
            regenerated_at = NOW(),
            last_used_at = NULL'
    );
    $stmt->execute([$tenantId, $formId, $tokenHash, $tokenPrefix, $userId]);
    return $rawToken;
}

function findTicketCheckinAccessByToken(PDO $pdo, string $rawToken): ?array
{
    $rawToken = strtolower(trim($rawToken));
    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        return null;
    }

    $tokenHash = hash('sha256', $rawToken);
    $stmt = $pdo->prepare(
        'SELECT cat.*, f.title AS form_title, f.ticket_title, f.ticket_event_at, f.ticket_location,
                t.name AS tenant_name
         FROM form_checkin_access_tokens cat
         INNER JOIN forms f ON f.id = cat.form_id AND f.tenant_id = cat.tenant_id
         INNER JOIN tenants t ON t.id = cat.tenant_id
         WHERE cat.token_hash = ? AND cat.is_active = 1 AND f.ticket_enabled = 1
         LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    $access = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($access) {
        $pdo->prepare('UPDATE form_checkin_access_tokens SET last_used_at = NOW() WHERE id = ?')
            ->execute([(int) $access['id']]);
    }
    return $access;
}

function ticketCheckinSnapshot(PDO $pdo, int $tenantId, int $formId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, code, token, participant_name, participant_email, status, issued_at, checked_in_at
         FROM form_tickets
         WHERE tenant_id = ? AND form_id = ?
         ORDER BY id ASC'
    );
    $stmt->execute([$tenantId, $formId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function findTicketByToken(PDO $pdo, string $token): ?array
{
    $token = strtolower(trim($token));
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = $pdo->prepare(ticketDetailsSelect() . ' WHERE ft.token = ? LIMIT 1');
    $stmt->execute([$token]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function findTenantTicketByIdentifier(PDO $pdo, int $tenantId, string $identifier): ?array
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }

    if (preg_match('/token=([a-f0-9]{64})/i', $identifier, $matches)) {
        $identifier = $matches[1];
    }
    $isToken = preg_match('/^[a-f0-9]{64}$/i', $identifier) === 1;
    $where = $isToken ? 'LOWER(ft.token) = LOWER(?)' : 'UPPER(ft.code) = UPPER(?)';
    $stmt = $pdo->prepare(ticketDetailsSelect() . ' WHERE ft.tenant_id = ? AND ' . $where . ' LIMIT 1');
    $stmt->execute([$tenantId, $identifier]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function ticketCheckInUrl(array $ticket): string
{
    return absoluteAppUrl('ticket-check-in', [
        'form_id' => (int) ($ticket['form_id'] ?? 0),
        'token' => (string) $ticket['token'],
    ]);
}

function ticketQrImageUrl(array $ticket, int $size = 600): string
{
    $size = max(160, min(1000, $size));
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . urlencode(ticketCheckInUrl($ticket));
}

function ticketStatusLabel(string $status): string
{
    return [
        'valid' => 'Válido',
        'used' => 'Utilizado',
        'cancelled' => 'Cancelado',
    ][$status] ?? 'Inválido';
}

function ticketBackgroundData(?string $path): ?string
{
    $path = trim((string) $path);
    if ($path === '') {
        return null;
    }

    if (preg_match('#^https?://#i', $path)) {
        $context = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'FormOps/1.0']]);
        $data = @file_get_contents($path, false, $context);
        return $data === false ? null : $data;
    }

    $path = ltrim(str_replace('\\', '/', $path), '/');
    if (str_starts_with($path, 'public/')) {
        $path = substr($path, 7);
    }
    $publicRoot = realpath(dirname(__DIR__, 2) . '/public');
    $fullPath = realpath(dirname(__DIR__, 2) . '/public/' . $path);
    if (!$publicRoot || !$fullPath || !str_starts_with(str_replace('\\', '/', $fullPath), str_replace('\\', '/', $publicRoot) . '/') || !is_file($fullPath)) {
        return null;
    }
    $data = @file_get_contents($fullPath);
    return $data === false ? null : $data;
}

function ticketFontPath(bool $bold = false): ?string
{
    $candidates = $bold
        ? ['C:/Windows/Fonts/arialbd.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf']
        : ['C:/Windows/Fonts/arial.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    return null;
}

function ticketWrappedLines(string $text, string $font, int $size, int $maxWidth): array
{
    $words = preg_split('/\s+/', trim($text)) ?: [];
    $lines = [];
    $line = '';
    foreach ($words as $word) {
        $candidate = $line === '' ? $word : $line . ' ' . $word;
        $box = imagettfbbox($size, 0, $font, $candidate);
        $width = abs(($box[2] ?? 0) - ($box[0] ?? 0));
        if ($line !== '' && $width > $maxWidth) {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $candidate;
        }
    }
    if ($line !== '') {
        $lines[] = $line;
    }
    return $lines ?: [''];
}

function ticketDrawText($image, string $text, int $size, int $x, int $y, int $color, bool $bold = false, ?int $maxWidth = null, int $lineHeight = 0): int
{
    $font = ticketFontPath($bold);
    if (!$font) {
        imagestring($image, 5, $x, max(0, $y - 18), $text, $color);
        return $y + 24;
    }

    $lines = $maxWidth ? ticketWrappedLines($text, $font, $size, $maxWidth) : [$text];
    $lineHeight = $lineHeight ?: (int) round($size * 1.25);
    foreach ($lines as $line) {
        imagettftext($image, $size, 0, $x, $y, $color, $font, $line);
        $y += $lineHeight;
    }
    return $y;
}

function ticketRenderJpeg(array $ticket): string
{
    if (!extension_loaded('gd')) {
        throw new RuntimeException('A extensão GD é necessária para gerar o PDF do ingresso.');
    }

    $width = 1000;
    $height = 1400;
    $imageHeight = 563;
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, true);
    $white = imagecolorallocate($image, 255, 255, 255);
    $dark = imagecolorallocate($image, 33, 33, 33);
    $muted = imagecolorallocate($image, 105, 105, 105);
    $border = imagecolorallocate($image, 225, 225, 225);
    imagefill($image, 0, 0, $white);

    $backgroundData = ticketBackgroundData($ticket['ticket_background_path'] ?? null);
    $background = $backgroundData ? @imagecreatefromstring($backgroundData) : false;
    if ($background) {
        $sourceWidth = imagesx($background);
        $sourceHeight = imagesy($background);
        $scale = min($width / $sourceWidth, $imageHeight / $sourceHeight);
        $targetWidth = (int) round($sourceWidth * $scale);
        $targetHeight = (int) round($sourceHeight * $scale);
        $targetX = (int) (($width - $targetWidth) / 2);
        $targetY = (int) (($imageHeight - $targetHeight) / 2);
        imagefilledrectangle($image, 0, 0, $width, $imageHeight, $dark);
        imagecopyresampled($image, $background, $targetX, $targetY, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
        imagedestroy($background);
    } else {
        imagefilledrectangle($image, 0, 0, $width, $imageHeight, $dark);
        $eventTitle = trim((string) ($ticket['ticket_title'] ?? '')) ?: trim((string) ($ticket['form_title'] ?? 'Ingresso'));
        ticketDrawText($image, $eventTitle, 48, 80, 285, $white, true, 840, 60);
    }

    ticketDrawText($image, 'LOCAL DO EVENTO', 16, 75, 645, $muted, true);
    ticketDrawText($image, (string) ($ticket['ticket_location'] ?: 'Local a confirmar'), 27, 75, 695, $dark, true, 390, 34);
    ticketDrawText($image, 'DIA DO EVENTO', 16, 555, 645, $muted, true);
    $eventDate = !empty($ticket['ticket_event_at']) ? date('d/m/Y H:i', strtotime($ticket['ticket_event_at'])) : 'Data a confirmar';
    ticketDrawText($image, $eventDate, 27, 555, 695, $dark, true, 370, 34);
    imageline($image, 75, 765, 925, 765, $border);

    ticketDrawText($image, 'NOME', 16, 75, 835, $muted, true);
    ticketDrawText($image, (string) ($ticket['participant_name'] ?: 'Participante'), 27, 75, 885, $dark, true, 390, 34);
    ticketDrawText($image, 'INGRESSO', 16, 555, 835, $muted, true);
    ticketDrawText($image, (string) $ticket['code'], 27, 555, 885, $dark, true, 370, 34);
    imageline($image, 75, 955, 925, 955, $border);

    $qrContext = stream_context_create(['http' => ['timeout' => 12, 'user_agent' => 'FormOps/1.0']]);
    $qrData = @file_get_contents(ticketQrImageUrl($ticket, 600), false, $qrContext);
    $qrImage = $qrData ? @imagecreatefromstring($qrData) : false;
    if ($qrImage) {
        $qrSize = 340;
        imagefilledrectangle($image, 329, 989, 671, 1331, $white);
        imagerectangle($image, 329, 989, 671, 1331, imagecolorallocate($image, 206, 206, 206));
        imagecopyresampled($image, $qrImage, 331, 991, 0, 0, $qrSize - 4, $qrSize - 4, imagesx($qrImage), imagesy($qrImage));
        imagedestroy($qrImage);
    } else {
        imagerectangle($image, 330, 990, 670, 1330, imagecolorallocate($image, 206, 206, 206));
        ticketDrawText($image, 'QR indisponível', 20, 408, 1165, $muted, true);
    }
    ticketDrawText($image, 'APRESENTE ESTE QR CODE NA ENTRADA', 15, 335, 1370, $muted, true);

    ob_start();
    imagejpeg($image, null, 92);
    $jpeg = (string) ob_get_clean();
    imagedestroy($image);
    return $jpeg;
}

function ticketPdfBinary(array $ticket): string
{
    $jpeg = ticketRenderJpeg($ticket);
    $imageSize = getimagesizefromstring($jpeg);
    $imageWidth = (int) ($imageSize[0] ?? 1000);
    $imageHeight = (int) ($imageSize[1] ?? 1400);
    $objects = [];
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
    $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 600 840] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>';
    $objects[] = '<< /Type /XObject /Subtype /Image /Width ' . $imageWidth . ' /Height ' . $imageHeight . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($jpeg) . " >>\nstream\n" . $jpeg . "\nendstream";
    $content = "q\n600 0 0 840 0 0 cm\n/Im0 Do\nQ";
    $objects[] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $number = $index + 1;
        $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($index = 1; $index <= count($objects); $index++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);
    }
    $pdf .= 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $pdf;
}

function ticketPdfFilename(array $ticket): string
{
    return 'ingresso-' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string) ($ticket['code'] ?? 'formops')) . '.pdf';
}
