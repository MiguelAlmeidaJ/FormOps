<?php

$token = strtolower(trim((string) ($_GET['token'] ?? '')));
$ticket = findTicketByToken($pdo, $token);
if (!$ticket) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Ingresso não encontrado.';
    exit;
}

try {
    $pdf = ticketPdfBinary($ticket);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . ticketPdfFilename($ticket) . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf;
} catch (Throwable $exception) {
    error_log('FormOps ticket PDF error: ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Não foi possível gerar o PDF do ingresso.';
}
exit;
