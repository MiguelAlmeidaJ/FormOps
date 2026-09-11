<?php

function formExportComparableValue(array $field, string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return ['empty' => true, 'numeric' => false, 'value' => ''];
    }

    if (($field['type'] ?? '') === 'date') {
        foreach (['!d/m/Y', '!Y-m-d'] as $format) {
            $date = DateTime::createFromFormat($format, $value);
            if ($date instanceof DateTime) {
                return ['empty' => false, 'numeric' => true, 'value' => $date->getTimestamp()];
            }
        }
    }

    if (($field['type'] ?? '') === 'number') {
        $normalized = str_replace(['.', ','], ['', '.'], preg_replace('/[^0-9,.-]/', '', $value));
        if (is_numeric($normalized)) {
            return ['empty' => false, 'numeric' => true, 'value' => (float) $normalized];
        }
    }

    $normalized = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    return ['empty' => false, 'numeric' => false, 'value' => $normalized];
}

function formExportSortResponses(array $responses, array $answersByResponse, array $sortField, string $direction): array
{
    $direction = $direction === 'desc' ? 'desc' : 'asc';
    usort($responses, static function (array $left, array $right) use ($answersByResponse, $sortField, $direction): int {
        $fieldId = (int) $sortField['id'];
        $leftValue = formExportComparableValue($sortField, (string) ($answersByResponse[(int) $left['id']][$fieldId] ?? ''));
        $rightValue = formExportComparableValue($sortField, (string) ($answersByResponse[(int) $right['id']][$fieldId] ?? ''));

        if ($leftValue['empty'] !== $rightValue['empty']) {
            return $leftValue['empty'] ? 1 : -1;
        }
        if ($leftValue['empty']) {
            return ((int) $left['id']) <=> ((int) $right['id']);
        }

        $comparison = $leftValue['numeric'] && $rightValue['numeric']
            ? ($leftValue['value'] <=> $rightValue['value'])
            : strnatcasecmp((string) $leftValue['value'], (string) $rightValue['value']);
        if ($comparison === 0) {
            $comparison = ((int) $left['id']) <=> ((int) $right['id']);
        }
        return $direction === 'desc' ? -$comparison : $comparison;
    });
    return $responses;
}

function formExportPdfText(string $text): string
{
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    if (function_exists('iconv')) {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($encoded !== false) {
            return '<' . strtoupper(bin2hex($encoded)) . '>';
        }
    }
    return '<' . strtoupper(bin2hex(preg_replace('/[^\x20-\x7E]/', '', $text) ?? '')) . '>';
}

function formExportPdfTruncate(string $text, int $limit): string
{
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    if ($limit < 2) {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') <= $limit ? $text : mb_substr($text, 0, $limit - 1, 'UTF-8') . '…';
    }
    return strlen($text) <= $limit ? $text : substr($text, 0, $limit - 1) . '.';
}

function formExportPdfBinary(string $title, array $fields, array $responses, array $answersByResponse): string
{
    $pageWidth = 842.0;
    $pageHeight = 595.0;
    $margin = 28.0;
    $tableWidth = $pageWidth - ($margin * 2);
    $columnCount = max(1, count($fields));
    $columnWidth = $tableWidth / $columnCount;
    $headerFontSize = max(5.0, min(8.0, $columnWidth / 9));
    $bodyFontSize = max(5.0, min(8.0, $columnWidth / 10));
    $rowHeight = 22.0;
    $rowsPerPage = 20;
    $responseChunks = $responses ? array_chunk($responses, $rowsPerPage) : [[]];
    $pageContents = [];

    foreach ($responseChunks as $pageIndex => $pageResponses) {
        $commands = [];
        $commands[] = '0.12 0.16 0.24 rg';
        $commands[] = 'BT /F2 14 Tf ' . $margin . ' 562 Td ' . formExportPdfText(formExportPdfTruncate($title, 90)) . ' Tj ET';
        $commands[] = '0.38 0.42 0.49 rg';
        $commands[] = 'BT /F1 8 Tf ' . $margin . ' 546 Td ' . formExportPdfText('Exportação de inscrições') . ' Tj ET';
        $commands[] = 'BT /F1 8 Tf 760 546 Td ' . formExportPdfText('Página ' . ($pageIndex + 1) . ' de ' . count($responseChunks)) . ' Tj ET';

        $headerBottom = 512.0;
        $commands[] = '0.91 0.94 0.98 rg ' . $margin . ' ' . $headerBottom . ' ' . $tableWidth . ' 24 re f';
        $commands[] = '0.76 0.81 0.88 RG 0.5 w ' . $margin . ' ' . $headerBottom . ' ' . $tableWidth . ' 24 re S';
        $maxHeaderChars = max(2, (int) floor(($columnWidth - 8) / ($headerFontSize * .52)));
        foreach ($fields as $columnIndex => $field) {
            $x = $margin + ($columnIndex * $columnWidth);
            if ($columnIndex > 0) {
                $commands[] = '0.82 0.85 0.9 RG 0.35 w ' . round($x, 2) . ' ' . $headerBottom . ' m ' . round($x, 2) . ' ' . ($headerBottom + 24) . ' l S';
            }
            $commands[] = '0.12 0.16 0.24 rg BT /F2 ' . $headerFontSize . ' Tf ' . round($x + 4, 2) . ' 521 Td ' . formExportPdfText(formExportPdfTruncate((string) $field['label'], $maxHeaderChars)) . ' Tj ET';
        }

        $maxBodyChars = max(2, (int) floor(($columnWidth - 8) / ($bodyFontSize * .5)));
        foreach ($pageResponses as $rowIndex => $response) {
            $rowTop = $headerBottom - ($rowIndex * $rowHeight);
            $rowBottom = $rowTop - $rowHeight;
            if ($rowIndex % 2 === 1) {
                $commands[] = '0.975 0.98 0.99 rg ' . $margin . ' ' . $rowBottom . ' ' . $tableWidth . ' ' . $rowHeight . ' re f';
            }
            $commands[] = '0.86 0.88 0.91 RG 0.35 w ' . $margin . ' ' . $rowBottom . ' ' . $tableWidth . ' ' . $rowHeight . ' re S';
            $responseAnswers = $answersByResponse[(int) $response['id']] ?? [];
            foreach ($fields as $columnIndex => $field) {
                $x = $margin + ($columnIndex * $columnWidth);
                if ($columnIndex > 0) {
                    $commands[] = '0.89 0.9 0.92 RG 0.3 w ' . round($x, 2) . ' ' . $rowBottom . ' m ' . round($x, 2) . ' ' . $rowTop . ' l S';
                }
                $value = (string) ($responseAnswers[(int) $field['id']] ?? '');
                $commands[] = '0.17 0.2 0.25 rg BT /F1 ' . $bodyFontSize . ' Tf ' . round($x + 4, 2) . ' ' . round($rowBottom + 8, 2) . ' Td ' . formExportPdfText(formExportPdfTruncate($value, $maxBodyChars)) . ' Tj ET';
            }
        }

        if (!$pageResponses) {
            $commands[] = '0.38 0.42 0.49 rg BT /F1 10 Tf ' . $margin . ' 480 Td ' . formExportPdfText('Nenhuma inscrição encontrada.') . ' Tj ET';
        }
        $pageContents[] = implode("\n", $commands);
    }

    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
    ];
    $pageIds = [];
    foreach ($pageContents as $content) {
        $pageId = count($objects) + 1;
        $contentId = $pageId + 1;
        $pageIds[] = $pageId;
        $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $pageWidth . ' ' . $pageHeight . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $contentId . ' 0 R >>';
        $objects[$contentId] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', array_map(static fn (int $id): string => $id . ' 0 R', $pageIds)) . '] /Count ' . count($pageIds) . ' >>';
    ksort($objects);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objects as $number => $object) {
        $offsets[$number] = strlen($pdf);
        $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    for ($number = 1; $number <= count($objects); $number++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
    }
    $pdf .= 'trailer << /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    return $pdf;
}
