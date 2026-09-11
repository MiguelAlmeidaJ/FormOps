<?php

function formOpsMailConfig(): array
{
    static $config;
    if ($config === null) {
        $config = require __DIR__ . '/../config/mail.php';
    }
    return $config;
}

function smtpReadResponse($socket): array
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    $metadata = stream_get_meta_data($socket);
    if ($response === '') {
        throw new RuntimeException(!empty($metadata['timed_out'])
            ? 'Tempo limite excedido ao aguardar resposta do servidor SMTP.'
            : 'O servidor SMTP encerrou a conexão sem responder.');
    }

    return [
        'code' => (int) substr($response, 0, 3),
        'message' => trim(preg_replace('/\s+/', ' ', $response)),
    ];
}

function smtpWrite($socket, string $content): void
{
    $length = strlen($content);
    $written = 0;
    while ($written < $length) {
        $bytes = fwrite($socket, substr($content, $written));
        if ($bytes === false || $bytes === 0) {
            throw new RuntimeException('Não foi possível escrever na conexão SMTP.');
        }
        $written += $bytes;
    }
}

function smtpCommand($socket, ?string $command, array $expectedCodes): array
{
    if ($command !== null) {
        smtpWrite($socket, $command . "\r\n");
    }

    $response = smtpReadResponse($socket);
    if (!in_array($response['code'], $expectedCodes, true)) {
        throw new RuntimeException('Servidor SMTP respondeu com erro: ' . $response['message']);
    }
    return $response;
}

function smtpHeaderValue(string $value): string
{
    return trim(str_replace(["\r", "\n"], ' ', $value));
}

function smtpEncodedHeader(string $value): string
{
    $value = smtpHeaderValue($value);
    return function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n")
        : $value;
}

function smtpMessageIdDomain(string $email): string
{
    $position = strrpos($email, '@');
    return $position === false ? 'localhost' : substr($email, $position + 1);
}

function sendFormOpsEmail(
    string $recipient,
    string $subject,
    string $html,
    ?string $replyTo = null,
    ?string $replyName = null,
    array $attachments = []
): array {
    $config = formOpsMailConfig();
    $required = ['host', 'username', 'password', 'from_email'];
    foreach ($required as $key) {
        if (trim((string) ($config[$key] ?? '')) === '') {
            return ['success' => false, 'error' => 'Configuração SMTP incompleta: ' . $key . '.'];
        }
    }

    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Destinatário de e-mail inválido.'];
    }

    $fromEmail = trim((string) $config['from_email']);
    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Endereço do remetente SMTP inválido.'];
    }

    $host = trim((string) $config['host']);
    $port = max(1, (int) ($config['port'] ?? 587));
    $timeout = max(3, (int) ($config['timeout'] ?? 15));
    $encryption = strtolower(trim((string) ($config['encryption'] ?? 'tls')));
    $transport = $encryption === 'ssl' ? 'ssl' : 'tcp';
    $socket = null;

    try {
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'allow_self_signed' => false,
            ],
        ]);
        $socket = @stream_socket_client(
            $transport . '://' . $host . ':' . $port,
            $errorNumber,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!$socket) {
            throw new RuntimeException('Não foi possível conectar ao servidor SMTP: ' . $errorMessage . ' (' . $errorNumber . ').');
        }
        stream_set_timeout($socket, $timeout);

        smtpCommand($socket, null, [220]);
        $clientName = preg_replace('/[^a-z0-9.-]/i', '', gethostname() ?: 'localhost') ?: 'localhost';
        smtpCommand($socket, 'EHLO ' . $clientName, [250]);

        if ($encryption === 'tls') {
            smtpCommand($socket, 'STARTTLS', [220]);
            $cryptoEnabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoEnabled !== true) {
                throw new RuntimeException('Não foi possível estabelecer a conexão TLS com o servidor SMTP.');
            }
            smtpCommand($socket, 'EHLO ' . $clientName, [250]);
        }

        smtpCommand($socket, 'AUTH LOGIN', [334]);
        smtpCommand($socket, base64_encode((string) $config['username']), [334]);
        smtpCommand($socket, base64_encode((string) $config['password']), [235]);
        smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        smtpCommand($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
        smtpCommand($socket, 'DATA', [354]);

        $fromName = smtpEncodedHeader((string) ($config['from_name'] ?? 'FormOps'));
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . smtpMessageIdDomain($fromEmail) . '>',
            'From: ' . $fromName . ' <' . $fromEmail . '>',
            'To: <' . $recipient . '>',
            'Subject: ' . smtpEncodedHeader($subject),
            'MIME-Version: 1.0',
        ];
        if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $safeReplyName = smtpEncodedHeader($replyName ?: $replyTo);
            $headers[] = 'Reply-To: ' . $safeReplyName . ' <' . $replyTo . '>';
        }

        if ($attachments) {
            $boundary = 'formops_' . bin2hex(random_bytes(16));
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
            $parts = [
                '--' . $boundary,
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
                '',
                rtrim(chunk_split(base64_encode($html), 76, "\r\n")),
            ];
            foreach ($attachments as $attachment) {
                $content = (string) ($attachment['content'] ?? '');
                if ($content === '') {
                    continue;
                }
                $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', basename((string) ($attachment['filename'] ?? 'anexo.bin')));
                $contentType = smtpHeaderValue((string) ($attachment['content_type'] ?? 'application/octet-stream'));
                $disposition = ($attachment['disposition'] ?? 'attachment') === 'inline' ? 'inline' : 'attachment';
                $attachmentHeaders = [
                    '--' . $boundary,
                    'Content-Type: ' . $contentType . '; name="' . $filename . '"',
                    'Content-Disposition: ' . $disposition . '; filename="' . $filename . '"',
                    'Content-Transfer-Encoding: base64',
                ];
                $contentId = preg_replace('/[^A-Za-z0-9._@-]/', '', (string) ($attachment['content_id'] ?? ''));
                if ($disposition === 'inline' && $contentId !== '') {
                    $attachmentHeaders[] = 'Content-ID: <' . $contentId . '>';
                    $attachmentHeaders[] = 'X-Attachment-Id: ' . $contentId;
                }
                $attachmentHeaders[] = '';
                $attachmentHeaders[] = rtrim(chunk_split(base64_encode($content), 76, "\r\n"));
                array_push($parts, ...$attachmentHeaders);
            }
            $parts[] = '--' . $boundary . '--';
            $encodedBody = implode("\r\n", $parts);
        } else {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            $encodedBody = rtrim(chunk_split(base64_encode($html), 76, "\r\n"));
        }
        $payload = implode("\r\n", $headers) . "\r\n\r\n" . $encodedBody;
        $payload = preg_replace('/(?m)^\./', '..', $payload);
        smtpCommand($socket, $payload . "\r\n.", [250]);
        smtpCommand($socket, 'QUIT', [221]);

        return ['success' => true, 'error' => null];
    } catch (Throwable $exception) {
        return ['success' => false, 'error' => $exception->getMessage()];
    } finally {
        if (is_resource($socket)) {
            fclose($socket);
        }
    }
}
