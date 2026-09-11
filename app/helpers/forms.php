<?php

require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/tickets.php';

function ensureFormLifecycleColumns(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute(['forms']);
    if ((int) $stmt->fetchColumn() === 0) {
        return;
    }

    $columns = [
        'closes_at' => "ALTER TABLE forms ADD COLUMN closes_at DATETIME NULL AFTER is_active",
        'additional_information' => "ALTER TABLE forms ADD COLUMN additional_information TEXT NULL AFTER public_subtitle",
        'hide_banner_text' => "ALTER TABLE forms ADD COLUMN hide_banner_text TINYINT(1) NOT NULL DEFAULT 0 AFTER public_subtitle",
        'payment_whatsapp' => "ALTER TABLE forms ADD COLUMN payment_whatsapp VARCHAR(30) NULL AFTER pix_key",
    ];

    foreach ($columns as $column => $sql) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute(['forms', $column]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }
}

function ensureUserGroupColumn(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute(['users']);
    if ((int) $stmt->fetchColumn() === 0) {
        return;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute(['users', 'form_group_id']);
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE users ADD COLUMN form_group_id INT NULL AFTER tenant_id');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_form_groups (
        tenant_id INT NOT NULL,
        user_id INT NOT NULL,
        form_group_id INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, form_group_id),
        KEY idx_user_form_groups_tenant_group (tenant_id, form_group_id),
        KEY idx_user_form_groups_tenant_user (tenant_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec(
        'INSERT IGNORE INTO user_form_groups (tenant_id, user_id, form_group_id)
         SELECT tenant_id, id, form_group_id
         FROM users
         WHERE tenant_id IS NOT NULL AND form_group_id IS NOT NULL'
    );
}

function ensureTicketInfrastructure(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute(['forms']);
    if ((int) $stmt->fetchColumn() === 0) {
        return;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute(['forms', 'ticket_email_message']);
    $hasLastColumn = (int) $stmt->fetchColumn() > 0;
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute(['form_tickets']);
    if ($hasLastColumn && (int) $stmt->fetchColumn() > 0) {
        return;
    }

    $columns = [
        'ticket_enabled' => "TINYINT(1) NOT NULL DEFAULT 0",
        'ticket_title' => "VARCHAR(160) NULL",
        'ticket_subtitle' => "VARCHAR(255) NULL",
        'ticket_event_at' => "DATETIME NULL",
        'ticket_location' => "VARCHAR(255) NULL",
        'ticket_instructions' => "TEXT NULL",
        'ticket_background_path' => "VARCHAR(500) NULL",
        'ticket_primary_color' => "VARCHAR(20) NULL DEFAULT '#212121'",
        'ticket_text_color' => "VARCHAR(20) NULL DEFAULT '#212121'",
        'ticket_name_field_id' => "INT NULL",
        'ticket_email_field_id' => "INT NULL",
        'ticket_email_subject' => "VARCHAR(190) NULL",
        'ticket_email_message' => "TEXT NULL",
    ];

    $columnExists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    foreach ($columns as $column => $definition) {
        $columnExists->execute(['forms', $column]);
        if ((int) $columnExists->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE forms ADD COLUMN ' . $column . ' ' . $definition);
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS form_tickets (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT NOT NULL,
        form_id INT NOT NULL,
        response_id INT NOT NULL,
        code VARCHAR(32) NOT NULL,
        token CHAR(64) NOT NULL,
        participant_name VARCHAR(255) NULL,
        participant_email VARCHAR(255) NULL,
        status ENUM('valid','used','cancelled') NOT NULL DEFAULT 'valid',
        issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        email_attempted_at DATETIME NULL,
        emailed_at DATETIME NULL,
        email_error TEXT NULL,
        checked_in_at DATETIME NULL,
        checked_in_by INT NULL,
        UNIQUE KEY uq_form_tickets_response (tenant_id, response_id),
        UNIQUE KEY uq_form_tickets_code (code),
        UNIQUE KEY uq_form_tickets_token (token),
        KEY idx_form_tickets_form_status (tenant_id, form_id, status),
        KEY idx_form_tickets_participant_email (participant_email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ticketColor(?string $value, string $fallback): string
{
    $value = trim((string) $value);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtoupper($value) : $fallback;
}

function ticketAssetUrl(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^(https?:)?//#i', $path) || str_starts_with($path, '/')) {
        return $path;
    }
    return str_starts_with($path, 'storage/') ? appUrl('public/' . $path) : appUrl($path);
}

function ticketPublicUrl(array $ticket): string
{
    return absoluteAppUrl('ingresso', ['token' => (string) $ticket['token']]);
}

function ticketAnswer(PDO $pdo, int $tenantId, int $responseId, ?int $fieldId): string
{
    if (!$fieldId) {
        return '';
    }
    $stmt = $pdo->prepare('SELECT answer FROM form_response_answers WHERE tenant_id = ? AND response_id = ? AND field_id = ? LIMIT 1');
    $stmt->execute([$tenantId, $responseId, $fieldId]);
    return trim((string) ($stmt->fetchColumn() ?: ''));
}

function ticketParticipantData(PDO $pdo, array $form, array $response): array
{
    $tenantId = (int) $response['tenant_id'];
    $responseId = (int) $response['id'];
    $name = ticketAnswer($pdo, $tenantId, $responseId, (int) ($form['ticket_name_field_id'] ?? 0));
    $email = ticketAnswer($pdo, $tenantId, $responseId, (int) ($form['ticket_email_field_id'] ?? 0));

    if ($name === '' || $email === '') {
        $stmt = $pdo->prepare(
            'SELECT ff.label, ff.type, fra.answer
             FROM form_response_answers fra
             INNER JOIN form_fields ff ON ff.id = fra.field_id AND ff.tenant_id = fra.tenant_id
             WHERE fra.tenant_id = ? AND fra.response_id = ?
             ORDER BY ff.field_order ASC, ff.id ASC'
        );
        $stmt->execute([$tenantId, $responseId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $answer) {
            $label = function_exists('mb_strtolower')
                ? mb_strtolower((string) $answer['label'], 'UTF-8')
                : strtolower((string) $answer['label']);
            $value = trim((string) $answer['answer']);
            if ($name === '' && $value !== '' && str_contains($label, 'nome')) {
                $name = $value;
            }
            if ($email === '' && $value !== '' && ($answer['type'] === 'email' || str_contains($label, 'email') || str_contains($label, 'e-mail'))) {
                $email = $value;
            }
        }
    }

    if ($name === '') {
        $name = 'Participante #' . (int) ($response['response_number'] ?? $responseId);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $email = '';
    }
    return ['name' => $name, 'email' => $email];
}

function ticketRandomCode(): string
{
    return 'ING-' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 12));
}

function issueTicketForResponse(PDO $pdo, int $tenantId, int $responseId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT r.*, f.ticket_enabled, f.ticket_name_field_id, f.ticket_email_field_id
         FROM form_responses r
         INNER JOIN forms f ON f.id = r.form_id AND f.tenant_id = r.tenant_id
         WHERE r.id = ? AND r.tenant_id = ? LIMIT 1'
    );
    $stmt->execute([$responseId, $tenantId]);
    $response = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$response || (int) ($response['ticket_enabled'] ?? 0) !== 1 || ($response['payment_status'] ?? 'pending') !== 'approved') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM form_tickets WHERE tenant_id = ? AND response_id = ? LIMIT 1');
    $stmt->execute([$tenantId, $responseId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        return $existing;
    }

    $participant = ticketParticipantData($pdo, $response, $response);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            $code = ticketRandomCode();
            $token = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare(
                'INSERT INTO form_tickets (tenant_id, form_id, response_id, code, token, participant_name, participant_email)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$tenantId, (int) $response['form_id'], $responseId, $code, $token, $participant['name'], $participant['email'] ?: null]);
            $ticketId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare('SELECT * FROM form_tickets WHERE id = ? LIMIT 1');
            $stmt->execute([$ticketId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062 || $attempt === 4) {
                throw $exception;
            }
        }
    }
    return null;
}

function buildTicketEmailPackage(array $ticket): array
{
    $eventTitle = trim((string) ($ticket['ticket_title'] ?: $ticket['form_title']));
    $subject = str_replace(["\r", "\n"], ' ', trim((string) ($ticket['ticket_email_subject'] ?: 'Seu ingresso para ' . $eventTitle)));
    $message = trim((string) ($ticket['ticket_email_message'] ?: 'Sua inscrição foi aprovada. Seu ingresso está pronto.'));
    $url = ticketPublicUrl($ticket);
    $primary = ticketColor($ticket['ticket_primary_color'] ?? null, '#212121');
    $safeName = htmlspecialchars((string) ($ticket['participant_name'] ?: 'Participante'), ENT_QUOTES, 'UTF-8');
    $safeEvent = htmlspecialchars($eventTitle, ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars((string) $ticket['code'], ENT_QUOTES, 'UTF-8');
    $safeTenant = htmlspecialchars((string) $ticket['tenant_name'], ENT_QUOTES, 'UTF-8');
    $safeLocation = htmlspecialchars((string) ($ticket['ticket_location'] ?: 'Local a confirmar'), ENT_QUOTES, 'UTF-8');
    $safeDate = !empty($ticket['ticket_event_at'])
        ? htmlspecialchars(date('d/m/Y H:i', strtotime($ticket['ticket_event_at'])), ENT_QUOTES, 'UTF-8')
        : 'Data a confirmar';

    $attachments = [];
    $bannerHtml = '<tr><td style="padding:52px 38px;background:' . $primary . ';color:#fff;text-align:center"><div style="font-size:34px;line-height:1.15;font-weight:800">' . $safeEvent . '</div></td></tr>';
    $bannerData = ticketBackgroundData($ticket['ticket_background_path'] ?? null);
    $bannerInfo = $bannerData ? @getimagesizefromstring($bannerData) : false;
    if ($bannerData && $bannerInfo && str_starts_with((string) ($bannerInfo['mime'] ?? ''), 'image/')) {
        if (($bannerInfo['mime'] ?? '') === 'image/webp' && extension_loaded('gd')) {
            $bannerImage = @imagecreatefromstring($bannerData);
            if ($bannerImage) {
                ob_start();
                imagejpeg($bannerImage, null, 90);
                $bannerData = (string) ob_get_clean();
                imagedestroy($bannerImage);
                $bannerInfo['mime'] = 'image/jpeg';
            }
        }
        $bannerCid = 'formops-event-' . (int) $ticket['id'];
        $extension = match ($bannerInfo['mime']) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
        $attachments[] = [
            'filename' => 'evento.' . $extension,
            'content_type' => $bannerInfo['mime'],
            'content' => $bannerData,
            'disposition' => 'inline',
            'content_id' => $bannerCid,
        ];
        $bannerHtml = '<tr><td style="padding:0;background:#212121"><img src="cid:' . $bannerCid . '" width="680" alt="' . $safeEvent . '" style="display:block;width:100%;max-width:680px;height:auto;border:0"></td></tr>';
    }

    $html = '<!doctype html><html lang="pt-br"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;padding:0;background:#F3F3F3;font-family:Arial,Helvetica,sans-serif;color:#212121">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0">Seu ingresso para ' . $safeEvent . ' está pronto.</div>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#F3F3F3"><tr><td align="center" style="padding:28px 14px">'
        . '<table role="presentation" width="680" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:680px;background:#fff;border:1px solid #CECECE;border-radius:18px;overflow:hidden">'
        . $bannerHtml
        . '<tr><td style="padding:34px 38px 14px"><div style="margin-bottom:10px;color:#777;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em">' . $safeTenant . '</div>'
        . '<div style="margin:0 0 8px;color:#555;font-size:14px">Olá, ' . $safeName . '.</div><h1 style="margin:0;color:#212121;font-size:30px;line-height:1.2;font-weight:800">' . $safeEvent . '</h1>'
        . '<p style="margin:16px 0 0;color:#555;font-size:15px;line-height:1.65">' . $safeMessage . '</p></td></tr>'
        . '<tr><td style="padding:18px 38px"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;border-top:1px solid #E5E5E5;border-bottom:1px solid #E5E5E5">'
        . '<tr><td width="50%" valign="top" style="padding:20px 14px 20px 0"><div style="color:#777;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em">Local do evento</div><div style="margin-top:7px;color:#212121;font-size:16px;font-weight:700;line-height:1.4">' . $safeLocation . '</div></td>'
        . '<td width="50%" valign="top" style="padding:20px 0 20px 14px"><div style="color:#777;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em">Dia do evento</div><div style="margin-top:7px;color:#212121;font-size:16px;font-weight:700;line-height:1.4">' . $safeDate . '</div></td></tr>'
        . '<tr><td width="50%" valign="top" style="padding:20px 14px 20px 0;border-top:1px solid #E5E5E5"><div style="color:#777;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em">Nome</div><div style="margin-top:7px;color:#212121;font-size:16px;font-weight:700;line-height:1.4">' . $safeName . '</div></td>'
        . '<td width="50%" valign="top" style="padding:20px 0 20px 14px;border-top:1px solid #E5E5E5"><div style="color:#777;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em">Ingresso</div><div style="margin-top:7px;color:#212121;font-size:16px;font-weight:700;line-height:1.4">' . $safeCode . '</div></td></tr>'
        . '</table></td></tr>'
        . '<tr><td align="center" style="padding:10px 38px 38px"><a href="' . $safeUrl . '" style="display:inline-block;padding:15px 25px;border-radius:12px;background:' . $primary . ';color:#fff;text-decoration:none;font-size:15px;font-weight:800">Acessar meu ingresso</a>'
        . '<p style="margin:18px 0 0;color:#777;font-size:12px;line-height:1.6">O ingresso em PDF também está anexado a este e-mail.<br><a href="' . $safeUrl . '" style="color:' . $primary . ';text-decoration:underline">Abrir ingresso no navegador</a></p></td></tr></table>'
        . '<div style="padding:18px;color:#999;font-size:11px;text-align:center">Enviado por ' . $safeTenant . ' através do FormOps.</div>'
        . '</td></tr></table></body></html>';

    $attachments[] = [
        'filename' => ticketPdfFilename($ticket),
        'content_type' => 'application/pdf',
        'content' => ticketPdfBinary($ticket),
    ];
    return ['subject' => $subject, 'html' => $html, 'attachments' => $attachments];
}

function sendTicketEmail(PDO $pdo, int $ticketId): bool
{
    $stmt = $pdo->prepare(ticketDetailsSelect() . ' WHERE ft.id = ? LIMIT 1');
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) {
        return false;
    }

    $email = trim((string) $ticket['participant_email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare("UPDATE form_tickets SET email_attempted_at = NOW(), email_error = 'E-mail do participante não informado.' WHERE id = ?");
        $stmt->execute([$ticketId]);
        return false;
    }

    $tenantEmail = trim((string) $ticket['tenant_email']);
    $replyTo = filter_var($tenantEmail, FILTER_VALIDATE_EMAIL) ? $tenantEmail : null;
    try {
        $package = buildTicketEmailPackage($ticket);
    } catch (Throwable $exception) {
        $error = mb_substr('Não foi possível montar o e-mail do ingresso: ' . $exception->getMessage(), 0, 1000);
        $stmt = $pdo->prepare('UPDATE form_tickets SET email_attempted_at = NOW(), emailed_at = NULL, email_error = ? WHERE id = ?');
        $stmt->execute([$error, $ticketId]);
        return false;
    }
    $result = sendFormOpsEmail(
        $email,
        $package['subject'],
        $package['html'],
        $replyTo,
        (string) $ticket['tenant_name'],
        $package['attachments']
    );
    $sent = (bool) ($result['success'] ?? false);
    $error = $sent ? null : mb_substr((string) ($result['error'] ?? 'O servidor SMTP não confirmou o envio do e-mail.'), 0, 1000);
    $stmt = $pdo->prepare('UPDATE form_tickets SET email_attempted_at = NOW(), emailed_at = ?, email_error = ? WHERE id = ?');
    $stmt->execute([$sent ? date('Y-m-d H:i:s') : null, $error, $ticketId]);
    return $sent;
}

function formClosesAtValue(array $form): ?string
{
    $value = trim((string) ($form['closes_at'] ?? ''));
    return $value !== '' && $value !== '0000-00-00 00:00:00' ? $value : null;
}

function isFormCompleted(array $form): bool
{
    $closesAt = formClosesAtValue($form);
    return $closesAt !== null && strtotime($closesAt) !== false && strtotime($closesAt) <= time();
}

function isFormOpenForResponses(array $form): bool
{
    return (int) ($form['is_active'] ?? 0) === 1 && !isFormCompleted($form);
}

function formStatusLabel(array $form): string
{
    if (isFormCompleted($form)) {
        return 'Concluído';
    }

    return (int) ($form['is_active'] ?? 0) === 1 ? 'Ativo' : 'Inativo';
}

function formStatusClass(array $form): string
{
    if (isFormCompleted($form)) {
        return 'completed';
    }

    return (int) ($form['is_active'] ?? 0) === 1 ? 'active' : 'inactive';
}

function formatDateTimeLocal(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '' || strtotime($value) === false) {
        return '';
    }

    return date('Y-m-d\TH:i', strtotime($value));
}

function parseDateTimeLocal(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d\TH:i', $value);
    $errors = DateTime::getLastErrors();
    if (!$date || ($errors !== false && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0))) {
        return null;
    }

    return $date->format('Y-m-d H:i:s');
}
