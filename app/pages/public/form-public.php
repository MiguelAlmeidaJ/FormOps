<?php

$tenantSlug = trim($_GET['tenant'] ?? '');
$formSlug = trim($_GET['slug'] ?? '');

function renderPublicFormUnavailable(string $title, string $message, ?array $tenant = null, int $status = 404): never
{
    http_response_code($status);
    $brandName = trim((string) ($tenant['name'] ?? 'FormOps'));
    $primary = trim((string) ($tenant['primary_color'] ?? '#212121'));
    $secondary = trim((string) ($tenant['secondary_color'] ?? '#555555'));
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primary)) $primary = '#212121';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $secondary)) $secondary = '#555555';
    ?>
    <!doctype html>
    <html lang="pt-br">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= htmlspecialchars($title) ?> - <?= htmlspecialchars($brandName) ?></title>
        <style>
            :root{--primary:<?= htmlspecialchars($primary) ?>;--secondary:<?= htmlspecialchars($secondary) ?>}
            *{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:"Poppins",sans-serif;background:#F3F3F3;color:#212121;display:grid;place-items:center;padding:24px}
            .state{width:min(100%,720px);background:#fff;border:1px solid #CECECE;border-radius:18px;box-shadow:0 24px 70px rgba(33,33,33,.12);overflow:hidden}
            .state-top{height:8px;background:linear-gradient(90deg,var(--primary),var(--secondary))}
            .state-body{padding:42px;text-align:center}.mark{width:64px;height:64px;border-radius:18px;display:grid;place-items:center;margin:0 auto 18px;background:color-mix(in srgb,var(--primary) 12%,#fff);color:var(--primary);font-size:28px;font-weight:900}
            h1{font-size:28px;line-height:1.15;margin:0 0 10px;font-weight:850}p{color:#555555;font-size:16px;line-height:1.6;margin:0 auto;max-width:520px}.brand{margin-top:26px;color:#555555;font-size:13px;font-weight:700}
            .actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap;margin-top:28px}.btn{border:1px solid #CECECE;border-radius:12px;min-height:42px;padding:10px 16px;text-decoration:none;font-weight:800;color:#334155;background:#fff}.btn.primary{background:var(--primary);border-color:var(--primary);color:#fff}
            @media(max-width:560px){.state-body{padding:30px 20px}h1{font-size:24px}}
        </style>
    </head>
    <body>
        <main class="state"><div class="state-top"></div><section class="state-body">
            <div class="mark">!</div><h1><?= htmlspecialchars($title) ?></h1><p><?= htmlspecialchars($message) ?></p>
            <div class="actions"><a class="btn primary" href="<?= htmlspecialchars(appUrl('login')) ?>">Acessar painel</a><a class="btn" href="javascript:history.back()">Voltar</a></div>
            <div class="brand"><?= htmlspecialchars($brandName) ?></div>
        </section></main>
    </body>
    </html>
    <?php
    exit;
}

if ($tenantSlug === '' || $formSlug === '') {
    renderPublicFormUnavailable('Link incompleto', 'O link acessado não possui as informações necessárias para abrir o formulário.');
    http_response_code(404);
    die('Formulário não encontrado.');
}

$stmt = $pdo->prepare('SELECT * FROM tenants WHERE slug = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$tenantSlug]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    renderPublicFormUnavailable('Organização não encontrada', 'Não encontramos uma organização ativa para este link.');
    http_response_code(404);
    die('Organização não encontrada.');
}

$stmt = $pdo->prepare('SELECT * FROM forms WHERE tenant_id = ? AND slug = ? LIMIT 1');
$stmt->execute([$tenant['id'], $formSlug]);
$form = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$form) {
    renderPublicFormUnavailable('Formulário não encontrado', 'Esse formulário pode ter sido removido ou o link pode estar incorreto.', $tenant);
    http_response_code(404);
    die('Formulário não encontrado ou inativo.');
}

if ((int) ($form['is_active'] ?? 0) !== 1) {
    renderPublicFormUnavailable('Formulário indisponível', 'Este formulário está inativo no momento. Entre em contato com a organização responsável para mais informações.', $tenant, 403);
}

$formCompleted = isFormCompleted($form);

$stmt = $pdo->prepare('SELECT * FROM form_fields WHERE tenant_id = ? AND form_id = ? ORDER BY field_order ASC');
$stmt->execute([$tenant['id'], $form['id']]);
$fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

function pixAscii(string $value, int $maxLength): string
{
    $value = trim($value);
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($converted !== false) {
        $value = $converted;
    }
    $value = strtoupper(preg_replace('/[^A-Z0-9 .\-]/i', '', $value) ?? '');
    $value = preg_replace('/\s+/', ' ', $value) ?? '';
    return substr($value !== '' ? $value : 'FORMOPS', 0, $maxLength);
}

function pixEmv(string $id, string $value): string
{
    return $id . str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT) . $value;
}

function pixCrc16(string $payload): string
{
    $crc = 0xFFFF;
    $length = strlen($payload);
    for ($offset = 0; $offset < $length; $offset++) {
        $crc ^= ord($payload[$offset]) << 8;
        for ($bit = 0; $bit < 8; $bit++) {
            $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
            $crc &= 0xFFFF;
        }
    }
    return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
}

function pixBuildPayload(string $pixKey, float $amount, string $receiverName, string $receiverCity, string $txid): string
{
    $merchantAccount = pixEmv('00', 'br.gov.bcb.pix') . pixEmv('01', trim($pixKey));
    $payload = pixEmv('00', '01')
        . pixEmv('26', $merchantAccount)
        . pixEmv('52', '0000')
        . pixEmv('53', '986')
        . pixEmv('54', number_format($amount, 2, '.', ''))
        . pixEmv('58', 'BR')
        . pixEmv('59', pixAscii($receiverName, 25))
        . pixEmv('60', pixAscii($receiverCity, 15))
        . pixEmv('62', pixEmv('05', pixAscii($txid, 25)));
    $payloadForCrc = $payload . '6304';
    return $payloadForCrc . pixCrc16($payloadForCrc);
}
function isLayoutField(array $field): bool
{
    return (int) ($field['is_layout'] ?? 0) === 1
        || in_array($field['type'], ['title', 'paragraph', 'divider', 'step', 'banner'], true);
}

$visibleFields = array_values(array_filter($fields, fn($field) => (int) ($field['is_visible'] ?? 1) === 1));
$answerFields = array_values(array_filter($visibleFields, fn($field) => !isLayoutField($field)));

function fieldOptions(array $field): array
{
    $options = preg_split('/\r\n|\r|\n/', (string) ($field['options'] ?? ''));
    return array_values(array_filter(array_map('trim', $options), fn($option) => $option !== ''));
}

function fieldValue(array $field, ?int $personIndex = null)
{
    $fieldKey = 'field_' . $field['id'];
    $defaultValue = $field['default_value'] ?? '';
    $personData = [];
    if ($personIndex !== null) {
        $postedPeople = $_POST['people'] ?? [];
        $personData = is_array($postedPeople[$personIndex] ?? null) ? $postedPeople[$personIndex] : [];
    }

    if ((int) ($field['is_readonly'] ?? 0) === 1) {
        $value = $defaultValue;
    } elseif ($personIndex !== null && $_SERVER['REQUEST_METHOD'] === 'POST' && array_key_exists($fieldKey, $personData)) {
        $value = $personData[$fieldKey];
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && array_key_exists($fieldKey, $_POST)) {
        $value = $_POST[$fieldKey];
    } else {
        $value = $defaultValue;
    }

    $options = fieldOptions($field);
    if ($field['type'] === 'checkbox') {
        if (!is_array($value)) {
            $value = $value === '' || $value === null ? [] : preg_split('/\s*,\s*/', (string) $value);
        }
        return array_values(array_intersect(array_map('strval', $value), $options));
    }

    $value = is_array($value) ? '' : trim((string) $value);
    if (in_array($field['type'], ['select', 'radio'], true) && $value !== '' && !in_array($value, $options, true)) {
        return '';
    }

    return $value;
}

function conditionalComparableValues($value): array
{
    $values = is_array($value) ? $value : [$value];
    return array_map(
        fn ($item) => function_exists('mb_strtolower')
            ? mb_strtolower(trim((string) $item), 'UTF-8')
            : strtolower(trim((string) $item)),
        $values
    );
}

function fieldConditionIsMet(array $field, array $fieldsById, array $activeFields, ?int $personIndex = null): bool
{
    if ((int) ($field['conditional_enabled'] ?? 0) !== 1) {
        return true;
    }

    $referenceId = (int) ($field['conditional_field_id'] ?? 0);
    if (!isset($fieldsById[$referenceId]) || empty($activeFields[$referenceId])) {
        return false;
    }

    $operator = $field['conditional_operator'] ?? 'equals';
    $actualValues = conditionalComparableValues(fieldValue($fieldsById[$referenceId], $personIndex));
    $expectedValues = conditionalComparableValues($field['conditional_value'] ?? '');
    $expected = $expectedValues[0] ?? '';
    $filled = count(array_filter($actualValues, fn ($value) => $value !== '')) > 0;
    $equals = in_array($expected, $actualValues, true);
    $contains = false;
    foreach ($actualValues as $actual) {
        if ($expected !== '' && str_contains($actual, $expected)) {
            $contains = true;
            break;
        }
    }

    return match ($operator) {
        'equals' => $equals,
        'not_equals' => !$equals,
        'contains' => $contains,
        'not_contains' => !$contains,
        'filled' => $filled,
        'empty' => !$filled,
        default => false,
    };
}

$errors = [];
$success = false;
$paymentInfo = null;
$issuedTickets = [];
$pricingQuote = null;
$couponScopes = ['registration' => false, 'participant' => false];
if ((int) ($form['payment_enabled'] ?? 0) === 1) {
    $couponScopes = pricingCouponScopes($pdo, (int) $tenant['id'], (int) $form['id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $formCompleted) {
    $errors[] = 'Este formulário já foi concluído e não aceita novas respostas.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$formCompleted) {
    $allowMultiplePeople = (int) ($form['allow_multiple_people'] ?? 0) === 1;
    $peopleCount = $allowMultiplePeople ? (int) ($_POST['people_count'] ?? 1) : 1;
    $peopleCount = max(1, min(20, $peopleCount));
    $personPayloads = $allowMultiplePeople ? range(1, $peopleCount) : [null];
    $paymentEnabled = (int) ($form['payment_enabled'] ?? 0) === 1;
    $paymentMethods = array_values(array_filter(explode(',', (string) ($form['payment_methods'] ?? ''))));
    $couponCode = $paymentEnabled && $couponScopes['registration'] ? pricingNormalizeCode($_POST['coupon_code'] ?? '') : '';
    $participantCouponCodes = [];
    if ($paymentEnabled && $couponScopes['participant']) {
        foreach (range(1, $peopleCount) as $participantIndex) {
            $participantCouponCodes[$participantIndex] = pricingNormalizeCode($_POST['participant_coupon'][$participantIndex] ?? '');
        }
    }
    try {
        $pricingQuote = $paymentEnabled ? formPricingQuote($pdo, $form, $peopleCount, $couponCode, false, $participantCouponCodes) : null;
    } catch (DomainException $exception) {
        $errors[] = $exception->getMessage();
    }
    $paymentAmount = $pricingQuote ? (float) $pricingQuote['unit_price'] : ($paymentEnabled ? (float) ($form['payment_amount'] ?? 0) : 0);
    $paymentTotal = $pricingQuote ? (float) $pricingQuote['total'] : ($paymentAmount * $peopleCount);
    $paymentMethod = $paymentEnabled && $paymentTotal > 0 ? trim((string) ($_POST['payment_method'] ?? '')) : '';

    if ($paymentEnabled && !$pricingQuote && $paymentAmount <= 0) {
        $errors[] = 'Pagamento não configurado para este formulário.';
    }
    if ($paymentEnabled && $paymentTotal > 0 && !$paymentMethods) {
        $errors[] = 'Nenhuma forma de pagamento foi configurada para este formulário.';
    }
    if ($paymentEnabled && $paymentTotal > 0 && ($paymentMethod === '' || !in_array($paymentMethod, $paymentMethods, true))) {
        $errors[] = 'Selecione uma forma de pagamento.';
    }

    $fieldsById = [];
    foreach ($answerFields as $field) {
        $fieldsById[(int) $field['id']] = $field;
    }

    $activeFieldsByPerson = [];
    $activeAnswerFieldsByPerson = [];
    foreach ($personPayloads as $personIndex) {
        $activeFields = [];
        $activeAnswerFields = [];
        foreach ($answerFields as $field) {
            $isActive = fieldConditionIsMet($field, $fieldsById, $activeFields, $personIndex);
            $activeFields[(int) $field['id']] = $isActive;
            if ($isActive) {
                $activeAnswerFields[] = $field;
            }
        }
        $personKey = $personIndex ?? 1;
        $activeFieldsByPerson[$personKey] = $activeFields;
        $activeAnswerFieldsByPerson[$personKey] = $activeAnswerFields;

        foreach ($activeAnswerFields as $field) {
            $value = fieldValue($field, $personIndex);
            if ((int) $field['is_required'] === 1) {
                $empty = is_array($value) ? count($value) === 0 : $value === '';
                if ($empty) {
                    $prefix = $allowMultiplePeople ? 'Pessoa ' . $personKey . ': ' : '';
                    $errors[] = $prefix . 'O campo "' . $field['label'] . '" é obrigatório.';
                }
            }
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM forms WHERE id = ? AND tenant_id = ? FOR UPDATE');
            $stmt->execute([$form['id'], $tenant['id']]);
            $lockedForm = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lockedForm) throw new DomainException('Este formulário não está mais disponível.');
            if ($paymentEnabled) {
                $pricingQuote = formPricingQuote($pdo, $lockedForm, $peopleCount, $couponCode, true, $participantCouponCodes);
                $paymentAmount = (float) $pricingQuote['unit_price'];
                $paymentTotal = (float) $pricingQuote['total'];
            }

            $stmt = $pdo->prepare('SELECT COALESCE(MAX(response_number), 0) + 1 FROM form_responses WHERE tenant_id = ? AND form_id = ?');
            $stmt->execute([$tenant['id'], $form['id']]);
            $responseNumber = (int) $stmt->fetchColumn();
            $firstResponseNumber = $responseNumber;
            $submissionGroup = bin2hex(random_bytes(16));

            $paymentStatus = $paymentEnabled && $paymentTotal > 0 ? 'pending' : 'approved';
            $responseStmt = $pdo->prepare('INSERT INTO form_responses (tenant_id, form_id, response_number, submission_group, person_index, people_count, payment_amount, payment_total, payment_method, payment_status, pricing_lot_id, pricing_lot_name, pricing_subtotal, pricing_group_discount, pricing_coupon_discount, pricing_discount_total, pricing_coupon_code, pricing_group_rule, pricing_participant_subtotal, pricing_participant_group_discount, pricing_participant_coupon_discount, pricing_participant_total, pricing_participant_coupon_code, submitted_by_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $answerStmt = $pdo->prepare('INSERT INTO form_response_answers (tenant_id, response_id, field_id, answer) VALUES (?, ?, ?, ?)');
            $createdResponseIds = [];

            foreach ($personPayloads as $personIndex) {
                $personKey = $personIndex ?? 1;
                $participantPricing = $pricingQuote['participants'][$personKey] ?? [];
                $responseStmt->execute([$tenant['id'], $form['id'], $responseNumber++, $submissionGroup, $personKey, $peopleCount, $paymentAmount, $paymentTotal, $paymentMethod ?: null, $paymentStatus, $pricingQuote['lot_id'] ?? null, $pricingQuote['lot_name'] ?? null, $pricingQuote['subtotal'] ?? 0, $pricingQuote['group_discount'] ?? 0, $pricingQuote['coupon_discount'] ?? 0, $pricingQuote['discount_total'] ?? 0, $pricingQuote['coupon_code'] ?? null, $pricingQuote['group_rule_label'] ?? null, $participantPricing['subtotal'] ?? $paymentAmount, $participantPricing['group_discount'] ?? 0, $participantPricing['coupon_discount'] ?? 0, $participantPricing['total'] ?? $paymentAmount, $participantPricing['coupon_code'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null]);
                $responseId = (int) $pdo->lastInsertId();
                $createdResponseIds[] = $responseId;

                foreach ($activeAnswerFieldsByPerson[$personKey] as $field) {
                    $answer = fieldValue($field, $personIndex);
                    if (is_array($answer)) {
                        $answer = implode(', ', $answer);
                    }
                    $answerStmt->execute([$tenant['id'], $responseId, $field['id'], $answer]);
                }
            }

            if ($paymentEnabled && $pricingQuote) {
                reserveFormPricing($pdo, $pricingQuote, (int) $tenant['id'], (int) $form['id'], $submissionGroup);
            }

            if ($paymentStatus === 'approved' && (int) ($form['ticket_enabled'] ?? 0) === 1) {
                foreach ($createdResponseIds as $createdResponseId) {
                    $issuedTicket = issueTicketForResponse($pdo, (int) $tenant['id'], $createdResponseId);
                    if ($issuedTicket) $issuedTickets[] = $issuedTicket;
                }
            }

            $pdo->commit();
            foreach ($issuedTickets as $issuedTicket) {
                sendTicketEmail($pdo, (int) $issuedTicket['id']);
            }
            $success = true;
            if ($paymentEnabled && $paymentTotal > 0) {
                $paymentLabels = ['pix' => 'Pix', 'transferencia' => 'Transferência', 'dinheiro' => 'Dinheiro'];
                $paymentWhatsApp = preg_replace('/\D+/', '', (string) ($form['payment_whatsapp'] ?? ''));
                if (in_array(strlen($paymentWhatsApp), [10, 11], true)) $paymentWhatsApp = '55' . $paymentWhatsApp;
                $proofMessage = 'Olá, segue o comprovante da inscrição #' . $firstResponseNumber . ' no formulário ' . ($form['title'] ?? '') . ' no valor de R$ ' . number_format($paymentTotal, 2, ',', '.');
                $pixPayload = '';
                if ($paymentMethod === 'pix' && trim((string) ($form['pix_key'] ?? '')) !== '') {
                    $pixPayload = pixBuildPayload((string) $form['pix_key'], $paymentTotal, (string) ($tenant['name'] ?? 'FORMOPS'), 'SAO PAULO', 'FORM' . (int) $form['id']);
                }
                $paymentInfo = [
                    'amount' => $paymentAmount,
                    'total' => $paymentTotal,
                    'method' => $paymentMethod,
                    'method_label' => $paymentLabels[$paymentMethod] ?? $paymentMethod,
                    'pix_key' => trim((string) ($form['pix_key'] ?? '')),
                    'pix_code' => $pixPayload,
                    'pix_qr' => $pixPayload !== '' ? 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=' . urlencode($pixPayload) : '',
                    'proof_url' => 'https://wa.me/' . $paymentWhatsApp . '?text=' . rawurlencode($proofMessage),
                    'proof_message' => $proofMessage,
                    'instructions' => trim((string) ($form['payment_instructions'] ?? '')), 
                    'pricing' => $pricingQuote,
                ];
            }

        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('FormOps submission error: ' . $exception->getMessage());
            $errors[] = $exception instanceof DomainException ? $exception->getMessage() : 'Erro ao salvar resposta. Tente novamente.';
        }
    }
}

function renderField(array $field, ?int $personIndex = null): void
{
    if ((int) ($field['is_visible'] ?? 1) !== 1) {
        return;
    }

    if (isLayoutField($field)) {
        $type = $field['type'];
        if ($type === 'title') {
            echo '<div class="col-12"><h2 class="form-section-title">' . htmlspecialchars($field['label']) . '</h2></div>';
        } elseif ($type === 'paragraph') {
            $text = $field['help_text'] ?: $field['default_value'];
            echo '<div class="col-12"><p class="form-section-text">' . nl2br(htmlspecialchars((string) $text)) . '</p></div>';
        } elseif ($type === 'divider') {
            echo '<div class="col-12"><hr class="form-divider"></div>';
        } elseif ($type === 'step') {
            echo '<div class="col-12"><div class="form-step-label">' . htmlspecialchars($field['label']) . '</div></div>';
        } elseif ($type === 'banner') {
            $imageUrl = trim((string) ($field['default_value'] ?? ''));
            if ($imageUrl !== '') {
                $imageSrc = preg_match('#^(https?:)?//#i', $imageUrl) || str_starts_with($imageUrl, '/') ? $imageUrl : appUrl($imageUrl);
                echo '<div class="col-12"><figure class="form-banner"><img src="' . htmlspecialchars($imageSrc) . '" alt="' . htmlspecialchars($field['label'] ?: 'Banner do formulário') . '">';
                if (!empty($field['help_text']))
                    echo '<figcaption>' . htmlspecialchars($field['help_text']) . '</figcaption>';
                echo '</figure></div>';
            }
        }
        return;
    }

    $fieldBaseId = 'field_' . (int) $field['id'];
    $fieldId = $personIndex !== null ? $fieldBaseId . '_person_' . $personIndex : $fieldBaseId;
    $fieldName = $personIndex !== null ? 'people[' . $personIndex . '][' . $fieldBaseId . ']' : $fieldBaseId;
    $candidateWidth = (int) ($field['width'] ?? 12);
    $width = in_array($candidateWidth, [12, 6, 4], true) ? $candidateWidth : 12;
    $mask = in_array($field['mask'] ?? 'none', ['none', 'phone', 'cpf', 'cnpj', 'cep', 'money'], true) ? $field['mask'] : 'none';
    $value = fieldValue($field, $personIndex);
    $required = (int) $field['is_required'] === 1;
    $htmlRequired = $required && $personIndex === null;
    $readonly = (int) ($field['is_readonly'] ?? 0) === 1;
    $placeholder = (string) ($field['placeholder'] ?? '');
    $helpText = (string) ($field['help_text'] ?? '');
    $cssClass = trim((string) ($field['css_class'] ?? ''));
    $inputClass = trim('public-input ' . $cssClass);
    $options = fieldOptions($field);
    $maskAttribute = $mask !== 'none' ? ' data-mask="' . htmlspecialchars($mask) . '"' : '';
    ?>
    <div class="col-md-<?= $width ?> form-field-wrapper"
        data-field-id="<?= htmlspecialchars($fieldId) ?>"
        data-conditional-enabled="<?= (int) ($field['conditional_enabled'] ?? 0) ?>"
        data-conditional-field-id="<?= $personIndex !== null && !empty($field['conditional_field_id']) ? 'field_' . (int) $field['conditional_field_id'] . '_person_' . $personIndex : (int) ($field['conditional_field_id'] ?? 0) ?>"
        data-conditional-operator="<?= htmlspecialchars((string) ($field['conditional_operator'] ?? 'equals')) ?>"
        data-conditional-value="<?= htmlspecialchars((string) ($field['conditional_value'] ?? '')) ?>"
        <?= (int) ($field['conditional_enabled'] ?? 0) === 1 ? 'hidden' : '' ?>>
        <div class="form-field mb-2">
            <label class="form-label" for="<?= $fieldId ?>">
                <?= htmlspecialchars($field['label']) ?>
                <?php if ($required): ?><span class="text-danger">*</span><?php endif; ?>
            </label>

            <?php if (in_array($field['type'], ['text', 'email', 'phone', 'number', 'date'], true)): ?>
                <?php
                $isDateInput = $field['type'] === 'date';
                $inputType = $field['type'] === 'phone' ? 'tel' : ($isDateInput ? 'text' : $field['type']);
                if ($mask !== 'none' && !in_array($inputType, ['tel', 'text'], true))
                    $inputType = 'text';
                $effectivePlaceholder = $isDateInput && $placeholder === '' ? 'DD/MM/AAAA' : $placeholder;
                ?>
                <input type="<?= htmlspecialchars($inputType) ?>" name="<?= $fieldName ?>" id="<?= $fieldId ?>"
                    class="form-control <?= htmlspecialchars($inputClass) ?>"
                    placeholder="<?= htmlspecialchars($effectivePlaceholder) ?>" value="<?= htmlspecialchars((string) $value) ?>"
                    <?= $maskAttribute ?><?= $isDateInput ? ' data-date-input inputmode="numeric" maxlength="10" autocomplete="bday"' : '' ?><?= $field['type'] === 'number' ? ' inputmode="decimal"' : '' ?>         <?= $htmlRequired ? 'required' : '' ?>         <?= $readonly ? 'readonly' : '' ?>>

            <?php elseif ($field['type'] === 'textarea'): ?>
                <textarea name="<?= $fieldName ?>" id="<?= $fieldId ?>" class="form-control <?= htmlspecialchars($inputClass) ?>"
                    rows="4" placeholder="<?= htmlspecialchars($placeholder) ?>" <?= $maskAttribute ?>         <?= $htmlRequired ? 'required' : '' ?>         <?= $readonly ? 'readonly' : '' ?>><?= htmlspecialchars((string) $value) ?></textarea>

            <?php elseif ($field['type'] === 'select'): ?>
                <?php if ($readonly): ?><input type="hidden" name="<?= $fieldName ?>"
                        value="<?= htmlspecialchars((string) $value) ?>"><?php endif; ?>
                <select name="<?= $fieldName ?>" id="<?= $fieldId ?>" class="form-select <?= htmlspecialchars($inputClass) ?>"
                    <?= $htmlRequired ? 'required' : '' ?>         <?= $readonly ? 'disabled' : '' ?>>
                    <option value="">Selecione uma opção</option>
                    <?php foreach ($options as $option): ?>
                        <option value="<?= htmlspecialchars($option) ?>" <?= $value === $option ? 'selected' : '' ?>>
                            <?= htmlspecialchars($option) ?></option><?php endforeach; ?>
                </select>

            <?php elseif (in_array($field['type'], ['radio', 'checkbox'], true)): ?>
                <div class="choice-group">
                    <?php if ($readonly): ?>
                        <?php foreach ((array) $value as $hiddenValue): ?><input type="hidden"
                                name="<?= $fieldName ?><?= $field['type'] === 'checkbox' ? '[]' : '' ?>"
                                value="<?= htmlspecialchars((string) $hiddenValue) ?>"><?php endforeach; ?>
                    <?php endif; ?>
                    <?php foreach ($options as $index => $option): ?>
                        <?php
                        $optionId = $fieldId . '_' . $index;
                        $checked = $field['type'] === 'checkbox' ? in_array($option, (array) $value, true) : $value === $option;
                        // Checkbox obrigatório é validado no servidor como grupo; no HTML,
                        // required em um único checkbox obrigaria especificamente aquela opção.
                        $optionRequired = $htmlRequired && $field['type'] === 'radio';
                        ?>
                        <div class="form-check">
                            <input class="form-check-input <?= htmlspecialchars($cssClass) ?>"
                                type="<?= htmlspecialchars($field['type']) ?>"
                                name="<?= $fieldName ?><?= $field['type'] === 'checkbox' ? '[]' : '' ?>" id="<?= $optionId ?>"
                                value="<?= htmlspecialchars($option) ?>" <?= $checked ? 'checked' : '' ?>             <?= $optionRequired ? 'required' : '' ?>             <?= $readonly ? 'disabled' : '' ?>>
                            <label class="form-check-label" for="<?= $optionId ?>"><?= htmlspecialchars($option) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($helpText !== ''): ?>
                <div class="form-text mt-2"><?= htmlspecialchars($helpText) ?></div><?php endif; ?>
        </div>
    </div>
    <?php
}

function publicColor($value, string $fallback): string
{
    return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? $value : $fallback;
}

function publicAssetPath(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^(https?:)?//#i', $path) || str_starts_with($path, '/')) {
        return $path;
    }
    if (str_starts_with($path, 'storage/')) {
        return appUrl('public/' . $path);
    }
    return appUrl($path);
}
function colorRgb(string $hex): string
{
    $hex = ltrim($hex, '#');
    return hexdec(substr($hex, 0, 2)) . ', ' . hexdec(substr($hex, 2, 2)) . ', ' . hexdec(substr($hex, 4, 2));
}

$useTenantBranding = (int) ($form['use_tenant_branding'] ?? 1) === 1;
$primaryColor = publicColor($useTenantBranding ? ($tenant['primary_color'] ?? null) : (($form['primary_color'] ?? null) ?: ($tenant['primary_color'] ?? null)), '#212121');
$secondaryColor = publicColor($useTenantBranding ? ($tenant['secondary_color'] ?? null) : (($form['secondary_color'] ?? null) ?: ($tenant['secondary_color'] ?? null)), '#555555');
$backgroundColor = publicColor($useTenantBranding ? ($tenant['background_color'] ?? null) : (($form['background_color'] ?? null) ?: ($tenant['background_color'] ?? null)), '#F3F3F3');
$textColor = publicColor($useTenantBranding ? ($tenant['text_color'] ?? null) : (($form['text_color'] ?? null) ?: ($tenant['text_color'] ?? null)), '#212121');
$buttonColor = publicColor($useTenantBranding ? ($tenant['button_color'] ?? null) : (($form['button_color'] ?? null) ?: ($tenant['button_color'] ?? null)), '#212121');
$focusRgb = colorRgb($buttonColor);
$publicCover = publicAssetPath(!$useTenantBranding && !empty($form['cover_image_path']) ? $form['cover_image_path'] : ($tenant['cover_image_path'] ?? ''));
$hideBannerText = (int) ($form['hide_banner_text'] ?? 0) === 1;
$formStyle = in_array(!$useTenantBranding && !empty($form['style']) ? $form['style'] : ($tenant['form_style'] ?? ''), ['clean', 'premium', 'minimal', 'church', 'business'], true) ? (!$useTenantBranding && !empty($form['style']) ? $form['style'] : $tenant['form_style']) : 'clean';
$successMessage = $form['success_message'] ?: 'Sua resposta foi enviada com sucesso.';
$allowMultiplePeople = (int) ($form['allow_multiple_people'] ?? 0) === 1;
$initialPeopleCount = $allowMultiplePeople ? max(1, min(20, (int) ($_POST['people_count'] ?? 1))) : 1;
$pricingPreview = $pricingQuote;
$pricingAvailabilityError = null;
$groupPricingRules = [];
if ((int) ($form['payment_enabled'] ?? 0) === 1) {
    $groupPricingRules = pricingGroupRules($pdo, (int) $tenant['id'], (int) $form['id']);
    if (!$pricingPreview) {
        try {
            $previewParticipantCoupons = [];
            if ($couponScopes['participant']) {
                foreach (range(1, $initialPeopleCount) as $participantIndex) {
                    $previewParticipantCoupons[$participantIndex] = pricingNormalizeCode($_POST['participant_coupon'][$participantIndex] ?? '');
                }
            }
            $pricingPreview = formPricingQuote($pdo, $form, $initialPeopleCount, $couponScopes['registration'] ? pricingNormalizeCode($_POST['coupon_code'] ?? '') : '', false, $previewParticipantCoupons);
        } catch (DomainException $exception) {
            $pricingAvailabilityError = $exception->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($form['title']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="/assets/clients/formops/favicon-formops.png">
    <link rel="stylesheet" href="assets/brand.css">
    <style>
        body {
            background:
                <?= htmlspecialchars($backgroundColor) ?>
            ;
            color:
                <?= htmlspecialchars($textColor) ?>
            ;
            font-family: "Poppins", sans-serif;
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
            overscroll-behavior-y: none;
        }

        .public-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 48px 16px;
        }

        .public-card {
            width: 100%;
            max-width: 760px;
            border: 1px solid rgba(17, 24, 39, .06);
            border-radius: 22px;
            overflow: hidden;
            box-shadow: 0 18px 50px rgba(33, 33, 33, .08);
            min-width: 0;
        }

        .style-minimal {
            box-shadow: none !important;
            border-radius: 10px;
        }

        .style-premium {
            box-shadow: 0 24px 70px rgba(0, 0, 0, .16) !important;
        }

        .style-business {
            border-radius: 10px;
        }

        .style-church .public-header {
            background: linear-gradient(135deg, <?= htmlspecialchars($primaryColor) ?>, <?= htmlspecialchars($secondaryColor) ?>);
        }

        .public-cover {
            min-height: 260px;
            background: linear-gradient(135deg, <?= htmlspecialchars($primaryColor) ?>, <?= htmlspecialchars($secondaryColor) ?>);
            background-size: cover;
            background-position: center;
            position: relative;
            display: flex;
            align-items: flex-end;
        }

        .public-cover-mobile {
            display: none;
        }

        .public-cover::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(33, 33, 33, .05) 15%, rgba(33, 33, 33, .78) 100%);
        }

        .public-cover.is-text-hidden::before {
            display: none;
        }

        .public-cover-content {
            position: relative;
            z-index: 1;
            color: #fff;
            padding: 34px;
            width: 100%;
        }

        .public-header {
            background: <?= $publicCover ? '#fff' : htmlspecialchars($primaryColor) ?>;
            color: <?= $publicCover ? htmlspecialchars($textColor) : '#fff' ?>;
            padding: <?= $publicCover ? '0' : '34px' ?>;
        }

        .public-header h1 {
            font-size: 28px;
            margin-bottom: 8px;
        }

        .public-header p {
            opacity: .9;
            margin-bottom: 0;
        }

        .public-additional-information {
            padding: 26px 34px;
            background: #fff;
            color: <?= htmlspecialchars($textColor) ?>;
            border-bottom: 1px solid #CECECE;
        }

        .public-additional-information h2 {
            margin: 0 0 8px;
            font-size: 18px;
            font-weight: 750;
        }

        .public-additional-information p {
            margin: 0;
            color: #555555;
            font-size: 15px;
            line-height: 1.65;
        }

        .public-logo {
            max-height: 70px;
            max-width: 220px;
            object-fit: contain;
            margin-bottom: 20px;
        }

        .public-body {
            padding: 34px;
            background: #fff;
            color: <?= htmlspecialchars($textColor) ?>;
        }

        .form-label {
            font-weight: 600;
            font-size: 14px;
            color: <?= htmlspecialchars($textColor) ?>;
            margin-bottom: 8px;
        }

        .public-input,
        .form-control,
        .form-select {
            min-height: 48px;
            border-radius: 12px;
            border: 1px solid #d9dee8;
            padding: 12px 14px;
            font-size: 15px;
            transition: all .2s ease;
            max-width: 100%;
        }

        textarea.public-input {
            min-height: 118px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: <?= htmlspecialchars($buttonColor) ?>;
            box-shadow: 0 0 0 .2rem rgba(<?= $focusRgb ?>, .15);
        }

        .form-control[readonly],
        .form-select:disabled {
            background: #F3F3F3;
            color: #555555;
        }

        .form-text {
            font-size: 13px;
            color: #6b7280;
        }

        .choice-group {
            border: 1px solid #CECECE;
            border-radius: 12px;
            padding: 12px 14px;
            background: #fff;
        }

        .choice-group .form-check {
            padding-top: 4px;
            padding-bottom: 4px;
        }

        .choice-group .form-check:not(:last-child) {
            margin-bottom: 6px;
        }

        .form-check-input {
            width: 1.05em;
            height: 1.05em;
        }

        .form-check-input:checked {
            background-color: <?= htmlspecialchars($buttonColor) ?>;
            border-color: <?= htmlspecialchars($buttonColor) ?>;
        }

        .form-step-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(<?= $focusRgb ?>, .12);
            color: <?= htmlspecialchars($buttonColor) ?>;
            border-radius: 999px;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 700;
            margin-top: 8px;
            margin-bottom: 4px;
        }

        .form-section-title {
            font-size: 20px;
            font-weight: 700;
            color: <?= htmlspecialchars($textColor) ?>;
            margin-top: 12px;
            margin-bottom: 6px;
        }

        .form-section-text {
            color: #6b7280;
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 6px;
        }

        .form-divider {
            border-color: #CECECE;
            margin: 12px 0;
            opacity: 1;
        }

        .form-banner {
            margin: 8px 0 12px;
        }

        .form-banner img {
            display: block;
            width: 100%;
            max-height: 360px;
            object-fit: cover;
            border-radius: 14px;
        }

        .form-banner figcaption {
            color: #6b7280;
            font-size: 13px;
            margin-top: 8px;
        }

        .payment-box {
            border: 1px solid #CECECE;
            border-radius: 16px;
            padding: 18px;
            background: #F3F3F3;
        }

        .payment-status-card {
            border: 1px solid #FDE68A;
            border-radius: 18px;
            background: linear-gradient(180deg, #FFFBEB 0%, #FFFFFF 100%);
            padding: 20px;
            box-shadow: 0 18px 36px rgba(146, 64, 14, .08);
        }

        .payment-status-head { display: flex; justify-content: space-between; gap: 14px; align-items: flex-start; margin-bottom: 18px; }
        .payment-status-title { font-size: 22px; font-weight: 850; color: #92400E; margin: 0; }
        .payment-status-subtitle { color: #92400E; opacity: .82; margin: 3px 0 0; }
        .payment-badge { display: inline-flex; align-items: center; border-radius: 999px; background: #F59E0B; color: #fff; font-size: 12px; font-weight: 800; padding: 6px 10px; text-transform: uppercase; letter-spacing: .04em; }
        .payment-amount-card { border: 1px solid #CECECE; background: #fff; border-radius: 16px; padding: 16px; margin-bottom: 16px; }
        .payment-amount-label { color: #6B7280; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
        .payment-amount-value { color: #111827; font-size: 32px; font-weight: 900; line-height: 1.1; margin-top: 4px; }
        .payment-steps { margin: 0 0 18px; padding-left: 18px; color: #4B5563; }
        .payment-qr-card { border: 1px solid #CECECE; border-radius: 16px; background: #fff; padding: 16px; text-align: center; margin-bottom: 16px; }
        .payment-qr-card img { display: block; margin: 0 auto; }
        .pix-copy-group { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 10px; align-items: stretch; margin-top: 12px; }
        .pix-copy-field { min-height: 46px; border: 1px solid #D1D5DB; border-radius: 12px; background: #F9FAFB; padding: 10px 12px; font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .payment-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
        .payment-proof-box { margin-top: 16px; padding: 15px; border: 1px solid #D1FAE5; border-radius: 14px; background: #F0FDF4; }
        .payment-proof-file { display: block; width: 100%; margin-top: 9px; padding: 9px; border: 1px dashed #86EFAC; border-radius: 10px; background: #fff; font-size: 13px; }
        .payment-proof-feedback { display: none; margin-top: 9px; color: #166534; font-size: 12px; font-weight: 700; }
        .payment-proof-feedback.show { display: block; }
        .payment-copy-feedback { color: #047857; font-size: 13px; font-weight: 700; margin-top: 8px; display: none; }
        .payment-copy-feedback.show { display: block; }
        .pricing-summary { display:grid; gap:8px; margin:14px 0; padding:13px 0; border-top:1px solid #CECECE; border-bottom:1px solid #CECECE; }
        .pricing-row { display:flex; justify-content:space-between; gap:12px; color:#555555; font-size:14px; }
        .pricing-row.discount { color:#047857; }
        .pricing-row.total { color:#111827; font-size:17px; font-weight:850; }
        .pricing-lot-badge { display:inline-flex; border-radius:999px; padding:5px 9px; background:#F3F3F3; color:#212121; font-size:11px; font-weight:850; margin-top:6px; }
        .coupon-control { display:grid; grid-template-columns:minmax(0,1fr); gap:6px; margin:14px 0; }
        .coupon-control input { text-transform:uppercase; }
        .issued-tickets { border:1px solid #bbf7d0; border-radius:18px; background:#f0fdf4; padding:20px; margin-top:16px; }
        .issued-tickets h2 { color:#166534; font-size:20px; font-weight:850; margin:0 0 5px; }
        .issued-ticket-list { display:grid; gap:10px; margin-top:15px; }
        .issued-ticket-link { display:flex; justify-content:space-between; align-items:center; gap:12px; border:1px solid #dcfce7; border-radius:12px; padding:12px 14px; background:#fff; text-decoration:none; color:#166534; font-weight:800; }
        @media(max-width:576px) { .payment-status-head, .payment-actions { flex-direction: column; } .pix-copy-group { grid-template-columns: 1fr; } .payment-amount-value { font-size: 28px; } }
        .people-count-card {
            border: 1px solid #CECECE;
            border-radius: 16px;
            padding: 16px;
            background: #F3F3F3;
        }

        .people-tabs {
            gap: 8px;
            overflow-x: auto;
            flex-wrap: nowrap;
            padding: 0 2px 8px;
            scroll-behavior: smooth;
            scrollbar-color: #94A3B8 transparent;
            scrollbar-width: thin;
            -webkit-overflow-scrolling: touch;
        }

        .people-tabs::-webkit-scrollbar {
            height: 6px;
        }

        .people-tabs::-webkit-scrollbar-track {
            background: transparent;
        }

        .people-tabs::-webkit-scrollbar-thumb {
            background: #94A3B8;
            border-radius: 999px;
        }

        .people-tabs .nav-link {
            border-radius: 999px;
            color: #334155;
            background: #F1F5F9;
            white-space: nowrap;
            font-weight: 700;
            scroll-margin-inline: 12px;
            min-height: 44px;
            touch-action: manipulation;
        }

        .people-tabs .nav-link.active {
            background: <?= htmlspecialchars($buttonColor) ?>;
            color: #fff;
        }

        .person-panel { display: none; }
        .person-panel.active { display: block; }

        .submit-btn {
            min-height: 50px;
            border-radius: 12px;
            font-weight: 600;
            margin-top: 18px;
            background: <?= htmlspecialchars($buttonColor) ?>;
            border-color: <?= htmlspecialchars($buttonColor) ?>;
            transition: transform .2s ease, box-shadow .2s ease;
            touch-action: manipulation;
        }

        .submit-btn:hover {
            background: <?= htmlspecialchars($buttonColor) ?>;
            border-color: <?= htmlspecialchars($buttonColor) ?>;
            transform: translateY(-1px);
            box-shadow: 0 10px 24px rgba(<?= $focusRgb ?>, .2);
        }

        @media(max-width:768px) {
            .public-wrapper {
                align-items: flex-start;
                padding: 16px 10px;
            }

            .public-header:not(:has(.public-cover)),
            .public-additional-information,
            .public-body {
                padding: 22px 18px;
            }

            .public-cover-content {
                padding: 24px;
            }

            .public-cover {
                min-height: 0;
                background-image: none !important;
                background-color: #212121;
                display: block;
            }

            .public-cover-mobile {
                display: block;
                width: 100%;
                height: auto;
            }

            .public-cover-content {
                position: absolute;
                inset: auto 0 0;
            }

            .public-header h1 {
                font-size: 24px;
            }

            .public-card { border-radius: 16px; }
            .form-control, .form-select { font-size: 16px; }
            .choice-group { padding: 8px 12px; }
            .choice-group .form-check { min-height: 44px; display:flex; align-items:center; gap:10px; margin:0; padding-left:1.75rem; }
            .choice-group .form-check-input { width:20px; height:20px; margin-top:0; }
            .choice-group .form-check-label { width:100%; padding:10px 0; }
            .people-count-card { padding:14px; }
            .people-tabs { margin-inline:-2px; scroll-snap-type:x proximity; }
            .people-tabs .nav-item { scroll-snap-align:start; }
            .submit-btn { position:sticky; bottom:8px; z-index:5; box-shadow:0 8px 24px rgba(15,23,42,.2); }
        }

        @media(max-width:380px) {
            .public-wrapper { padding:0; }
            .public-card { border-radius:0; border-inline:0; }
            .public-header:not(:has(.public-cover)), .public-additional-information, .public-body { padding:20px 15px; }
        }
    </style>
</head>

<body>
    <div class="public-wrapper">
        <div class="card public-card style-<?= htmlspecialchars($formStyle) ?>">
            <div class="public-header">
                <?php if ($publicCover): ?>
                    <div class="public-cover <?= $hideBannerText ? 'is-text-hidden' : '' ?>" style="background-image: url('<?= htmlspecialchars($publicCover) ?>')">
                        <img class="public-cover-mobile" src="<?= htmlspecialchars($publicCover) ?>" alt="">
                        <?php if (!$hideBannerText): ?><div class="public-cover-content">
                            <h1><?= htmlspecialchars($form['title']) ?></h1>
                            <?php if (!empty($form['description'])): ?>
                                <p><?= nl2br(htmlspecialchars($form['description'])) ?></p>
                            <?php endif; ?>
                        </div><?php endif; ?>
                    </div>
                <?php else: ?>
                    <h1><?= htmlspecialchars($form['title']) ?></h1>
                    <?php if (!empty($form['description'])): ?>
                        <p><?= nl2br(htmlspecialchars($form['description'])) ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if (!empty($form['additional_information'])): ?>
                <section class="public-additional-information" aria-labelledby="additional-information-title">
                    <h2 id="additional-information-title">Informações complementares</h2>
                    <p><?= nl2br(htmlspecialchars($form['additional_information'])) ?></p>
                </section>
            <?php endif; ?>
            <div class="public-body">
                <?php if ($success): ?>
                    <div class="alert alert-success"><?= nl2br(htmlspecialchars($successMessage)) ?></div>
                    <?php if ($paymentInfo): ?>
                        <section class="payment-status-card mt-3" id="paymentStatusCard" data-payment-persist="1">
                            <div class="payment-status-head">
                                <div>
                                    <h2 class="payment-status-title">Pagamento pendente</h2>
                                    <p class="payment-status-subtitle">Aguardando envio do comprovante.</p>
                                </div>
                                <span class="payment-badge">Pendente</span>
                            </div>

                            <div class="payment-amount-card">
                                <div class="payment-amount-label">Valor total</div>
                                <div class="payment-amount-value">R$ <?= number_format($paymentInfo['total'], 2, ',', '.') ?></div>
                                <div class="text-muted small mt-2">Valor por pessoa: R$ <?= number_format($paymentInfo['amount'], 2, ',', '.') ?> · Forma escolhida: <?= htmlspecialchars($paymentInfo['method_label']) ?></div>
                                <?php if (!empty($paymentInfo['pricing']['lot_name'])): ?><div class="pricing-lot-badge"><?= htmlspecialchars($paymentInfo['pricing']['lot_name']) ?></div><?php endif; ?>
                                <?php if (!empty($paymentInfo['pricing']['discount_total'])): ?><div class="small text-success mt-2">Descontos aplicados: − R$ <?= number_format((float) $paymentInfo['pricing']['discount_total'], 2, ',', '.') ?></div><?php endif; ?>
                            </div>

                            <ol class="payment-steps">
                                <li>Escaneie o QR Code ou copie o código Pix.</li>
                                <li>Faça o pagamento no app do seu banco.</li>
                                <li>Envie o comprovante para liberar sua inscrição.</li>
                            </ol>

                            <?php if ($paymentInfo['method'] === 'pix' && $paymentInfo['pix_key']): ?>
                                <div class="payment-qr-card">
                                    <div class="small text-muted mb-2">Chave Pix</div>
                                    <div class="fw-semibold mb-3"><?= htmlspecialchars($paymentInfo['pix_key']) ?></div>
                                    <?php if ($paymentInfo['pix_qr']): ?>
                                        <img src="<?= htmlspecialchars($paymentInfo['pix_qr']) ?>" alt="QR Code Pix" class="img-fluid mb-3" style="max-width:220px" onerror="this.style.display='none';this.nextElementSibling?.classList.remove('d-none');">
                                        <div class="alert alert-warning small d-none mb-3">Não foi possível carregar o QR Code. Use o Pix copia e cola abaixo.</div>
                                        <div><a class="small" href="<?= htmlspecialchars($paymentInfo['pix_qr']) ?>" target="_blank" rel="noopener">Abrir QR Code</a></div>
                                    <?php endif; ?>
                                    <?php if (!empty($paymentInfo['pix_code'])): ?>
                                        <label class="form-label small text-muted" for="pixCopyPaste">Pix copia e cola</label>
                                        <div class="pix-copy-group">
                                            <input id="pixCopyPaste" class="pix-copy-field" value="<?= htmlspecialchars($paymentInfo['pix_code']) ?>" readonly>
                                            <button type="button" class="btn btn-primary" data-copy-pix="#pixCopyPaste">Copiar código Pix</button>
                                        </div>
                                        <div class="payment-copy-feedback" data-copy-feedback>Código copiado!</div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($paymentInfo['instructions']): ?><div class="small text-muted mb-3"><?= nl2br(htmlspecialchars($paymentInfo['instructions'])) ?></div><?php endif; ?>

                            <!--<div class="payment-proof-box">
                                <label class="fw-semibold small" for="paymentProofFile">Comprovante de pagamento</label>
                                <input class="payment-proof-file" type="file" id="paymentProofFile" accept="image/*,.pdf,application/pdf" data-payment-proof-file>
                                <div class="small text-muted mt-2">No celular, imagens e PDFs podem ser enviados pelo compartilhamento nativo. Se o navegador não suportar anexos, o WhatsApp abrirá com a mensagem pronta para você anexar o arquivo manualmente.</div>
                                <div class="payment-proof-feedback" data-proof-feedback></div>
                            </div>-->
                            <div class="payment-actions">
                                <button class="btn btn-success" type="button" data-share-proof data-proof-url="<?= htmlspecialchars($paymentInfo['proof_url']) ?>" data-proof-message="<?= htmlspecialchars($paymentInfo['proof_message']) ?>">Enviar comprovante pelo WhatsApp</button>
                                <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($publicUrl) ?>" data-clear-payment>Voltar para formulário</a>
                            </div>
                        </section>
                    <?php endif; ?>
                    <?php if ($issuedTickets): ?>
                        <section class="issued-tickets">
                            <h2><?= count($issuedTickets) === 1 ? 'Ingresso emitido' : 'Ingressos emitidos' ?></h2>
                            <div class="text-muted small">O envio por e-mail foi solicitado. Você também pode acessar agora:</div>
                            <div class="issued-ticket-list">
                                <?php foreach ($issuedTickets as $issuedTicket): ?>
                                    <a class="issued-ticket-link" href="<?= htmlspecialchars(ticketPublicUrl($issuedTicket)) ?>" target="_blank" rel="noopener"><span><?= htmlspecialchars($issuedTicket['participant_name'] ?: $issuedTicket['code']) ?></span><span>Abrir ingresso →</span></a>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>

                <?php else: ?>
                    <div id="persistedPaymentContainer"></div>
                    <?php if ($errors): ?>
                        <div class="alert alert-danger"><?php foreach ($errors as $error): ?>
                                <div><?= htmlspecialchars($error) ?></div><?php endforeach; ?>
                        </div><?php endif; ?>
                    <?php if ($formCompleted): ?>
                        <div class="alert alert-warning mb-0">Este formulário foi concluído e não aceita novas respostas.</div>
                    <?php elseif ($visibleFields): ?>
                        <form method="post" id="publicForm">
                            <?php if ($allowMultiplePeople): ?>
                                <div class="people-count-card mb-4">
                                    <label class="form-label" for="people_count">Quantas pessoas serão inscritas?</label>
                                    <select class="form-select" id="people_count" name="people_count">
                                        <?php for ($peopleOption = 1; $peopleOption <= 20; $peopleOption++): ?><option value="<?= $peopleOption ?>" <?= $peopleOption === $initialPeopleCount ? 'selected' : '' ?>><?= $peopleOption ?> <?= $peopleOption === 1 ? 'pessoa' : 'pessoas' ?></option><?php endfor; ?>
                                    </select>
                                </div>
                                <ul class="nav nav-pills people-tabs mb-3" id="peopleTabs" role="tablist">
                                    <?php for ($personIndex = 1; $personIndex <= 20; $personIndex++): ?>
                                        <li class="nav-item person-tab-item" data-person-tab="<?= $personIndex ?>" <?= $personIndex > $initialPeopleCount ? 'hidden' : '' ?>><button class="nav-link <?= $personIndex === 1 ? 'active' : '' ?>" type="button" data-person-target="person-panel-<?= $personIndex ?>">Pessoa <?= $personIndex ?></button></li>
                                    <?php endfor; ?>
                                </ul>
                                <div id="peoplePanels">
                                    <?php for ($personIndex = 1; $personIndex <= 20; $personIndex++): ?>
                                        <div class="person-panel <?= $personIndex === 1 ? 'active' : '' ?>" <?= $personIndex > $initialPeopleCount ? 'hidden' : '' ?> id="person-panel-<?= $personIndex ?>" data-person-panel="<?= $personIndex ?>">
                                            <div class="row g-3">
                                                <?php foreach ($visibleFields as $field) renderField($field, $personIndex); ?>
                                            </div>
                                            <?php if ((int) ($form['payment_enabled'] ?? 0) === 1 && $couponScopes['participant']): ?>
                                                <label class="coupon-control mt-3"><span class="fw-semibold small">Cupom individual da pessoa <?= $personIndex ?></span><input class="form-control" type="text" name="participant_coupon[<?= $personIndex ?>]" maxlength="80" value="<?= htmlspecialchars($_POST['participant_coupon'][$personIndex] ?? '') ?>" placeholder="Digite o cupom deste participante"><small class="text-muted">O desconto será aplicado somente ao ingresso desta pessoa.</small></label>
                                            <?php endif; ?>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                            <?php else: ?>
                                <div class="row g-3">
                                    <?php foreach ($visibleFields as $field) renderField($field); ?>
                                </div>
                                <?php if ((int) ($form['payment_enabled'] ?? 0) === 1 && $couponScopes['participant']): ?>
                                    <label class="coupon-control mt-3"><span class="fw-semibold small">Cupom individual do participante</span><input class="form-control" type="text" name="participant_coupon[1]" maxlength="80" value="<?= htmlspecialchars($_POST['participant_coupon'][1] ?? '') ?>" placeholder="Digite o cupom deste participante"><small class="text-muted">O desconto será aplicado somente a este ingresso.</small></label>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ((int)($form['payment_enabled'] ?? 0) === 1): ?>
                                <?php $previewPeople = $allowMultiplePeople ? $initialPeopleCount : 1; $previewAmount = (float) ($pricingPreview['unit_price'] ?? $form['payment_amount'] ?? 0); $previewSubtotal = (float) ($pricingPreview['subtotal'] ?? ($previewAmount * $previewPeople)); $previewTotal = (float) ($pricingPreview['total'] ?? $previewSubtotal); ?>
                                <div class="payment-box mt-4" id="paymentPreview" data-amount="<?= htmlspecialchars((string) $previewAmount) ?>" data-group-rules="<?= htmlspecialchars(json_encode($groupPricingRules, JSON_UNESCAPED_UNICODE)) ?>" data-coupon-type="<?= htmlspecialchars((string) ($pricingPreview['coupon_type'] ?? '')) ?>" data-coupon-value="<?= htmlspecialchars((string) ($pricingPreview['coupon_value'] ?? '')) ?>" data-participant-coupons="<?= htmlspecialchars(json_encode($pricingPreview['participants'] ?? [], JSON_UNESCAPED_UNICODE)) ?>">
                                    <div class="fw-semibold mb-1">Pagamento</div>
                                    <?php if (!empty($pricingPreview['lot_name'])): ?><div class="pricing-lot-badge">Lote atual: <?= htmlspecialchars($pricingPreview['lot_name']) ?></div><?php endif; ?>
                                    <?php if ($pricingAvailabilityError): ?><div class="alert alert-warning small mt-3 mb-0"><?= htmlspecialchars($pricingAvailabilityError) ?></div><?php endif; ?>
                                    <div class="pricing-summary">
                                        <div class="pricing-row"><span>R$ <?= number_format($previewAmount, 2, ',', '.') ?> × <span data-pricing-people><?= $previewPeople ?></span> pessoa(s)</span><strong data-pricing-subtotal>R$ <?= number_format($previewSubtotal, 2, ',', '.') ?></strong></div>
                                        <div class="pricing-row discount" data-group-discount-row <?= empty($pricingPreview['group_discount']) ? 'hidden' : '' ?>><span>Desconto para grupo</span><strong data-group-discount>− R$ <?= number_format((float) ($pricingPreview['group_discount'] ?? 0), 2, ',', '.') ?></strong></div>
                                        <div class="pricing-row discount" data-participant-coupon-discount-row <?= empty($pricingPreview['participant_coupon_discount']) ? 'hidden' : '' ?>><span>Cupons individuais</span><strong data-participant-coupon-discount>− R$ <?= number_format((float) ($pricingPreview['participant_coupon_discount'] ?? 0), 2, ',', '.') ?></strong></div>
                                        <div class="pricing-row discount" data-coupon-discount-row <?= empty($pricingPreview['registration_coupon_discount']) ? 'hidden' : '' ?>><span>Cupom da inscrição <?= htmlspecialchars((string) ($pricingPreview['coupon_code'] ?? '')) ?></span><strong data-coupon-discount>− R$ <?= number_format((float) ($pricingPreview['registration_coupon_discount'] ?? 0), 2, ',', '.') ?></strong></div>
                                        <div class="pricing-row total"><span>Total</span><strong data-payment-total>R$ <?= number_format($previewTotal, 2, ',', '.') ?></strong></div>
                                    </div>
                                    <?php if ($couponScopes['registration']): ?><label class="coupon-control"><span class="fw-semibold small">Cupom da inscrição</span><input class="form-control" type="text" name="coupon_code" maxlength="80" value="<?= htmlspecialchars($_POST['coupon_code'] ?? '') ?>" placeholder="Digite o código, se possuir"><small class="text-muted">Este cupom será aplicado ao total do grupo.</small></label><?php endif; ?>
                                    <?php $paymentMethodLabels = ['pix' => 'Pix', 'transferencia' => 'Transferência', 'dinheiro' => 'Dinheiro']; $configuredPaymentMethods = array_values(array_filter(explode(',', (string)($form['payment_methods'] ?? '')))); ?>
                                    <div class="fw-semibold small mb-2">Escolha a forma de pagamento</div>
                                    <?php foreach ($configuredPaymentMethods as $methodValue): ?>
                                        <label class="form-check mb-1"><input class="form-check-input" type="radio" name="payment_method" value="<?= htmlspecialchars($methodValue) ?>" <?= (($_POST['payment_method'] ?? '') === $methodValue) ? 'checked' : '' ?>> <?= htmlspecialchars($paymentMethodLabels[$methodValue] ?? $methodValue) ?></label>
                                    <?php endforeach; ?>
                                    <div class="small text-muted mt-2">Se os descontos zerarem o total, a inscrição será aprovada automaticamente.</div>
                                </div>
                            <?php endif; ?>
                            <button type="submit" class="btn btn-primary submit-btn w-100">Enviar resposta</button>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-warning mb-0">Este formulário ainda não possui campos visíveis.</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <script>
        (function () {
            const paymentPersistKey = <?= json_encode('formops-payment-' . ($tenant['slug'] ?? '') . '-' . ($form['slug'] ?? '')) ?>;
            const currentCard = document.getElementById('paymentStatusCard');
            const persistedContainer = document.getElementById('persistedPaymentContainer');

            function toggleFormWhenPaymentExists(hasPayment) {
                const form = document.getElementById('publicForm');
                if (form) form.hidden = hasPayment;
            }

            if (currentCard) {
                localStorage.setItem(paymentPersistKey, currentCard.outerHTML);
                toggleFormWhenPaymentExists(true);
            } else if (persistedContainer) {
                const savedPayment = localStorage.getItem(paymentPersistKey);
                if (savedPayment) {
                    persistedContainer.innerHTML = savedPayment;
                    toggleFormWhenPaymentExists(true);
                } else {
                    toggleFormWhenPaymentExists(false);
                }
            }

            document.addEventListener('click', async function (event) {
                const copyButton = event.target.closest('[data-copy-pix]');
                if (copyButton) {
                    const target = document.querySelector(copyButton.dataset.copyPix);
                    if (target) {
                        target.select?.();
                        await navigator.clipboard?.writeText(target.value || target.textContent || '');
                        const feedback = copyButton.closest('.payment-status-card')?.querySelector('[data-copy-feedback]');
                        feedback?.classList.add('show');
                        setTimeout(() => feedback?.classList.remove('show'), 2400);
                    }
                }

                const proofButton = event.target.closest('[data-share-proof]');
                if (proofButton) {
                    const card = proofButton.closest('.payment-status-card');
                    const file = card?.querySelector('[data-payment-proof-file]')?.files?.[0];
                    const feedback = card?.querySelector('[data-proof-feedback]');
                    const shareData = file ? {title:'Comprovante de pagamento',text:proofButton.dataset.proofMessage||'',files:[file]} : null;
                    if (shareData && navigator.share && (!navigator.canShare || navigator.canShare(shareData))) {
                        try {
                            await navigator.share(shareData);
                            if (feedback) { feedback.textContent='Arquivo compartilhado. Selecione o WhatsApp e envie para o contato indicado.'; feedback.classList.add('show'); }
                            return;
                        } catch (error) {
                            if (error?.name === 'AbortError') return;
                        }
                    }
                    if (file && feedback) {
                        feedback.textContent='O WhatsApp será aberto com a mensagem pronta. Anexe o arquivo selecionado manualmente na conversa.';
                        feedback.classList.add('show');
                    }
                    window.open(proofButton.dataset.proofUrl, '_blank', 'noopener');
                }

                if (event.target.closest('[data-clear-payment]')) {
                    localStorage.removeItem(paymentPersistKey);
                }
            });
        })();
    </script>    <script>
        document.querySelectorAll('[data-date-input]').forEach(function (input) {
            function applyDateMask() {
                let value = input.value.trim();
                const canonical = value.match(/^(\d{4})-(\d{2})-(\d{2})$/);
                if (canonical) value = canonical[3] + canonical[2] + canonical[1];
                const digits = value.replace(/\D/g, '').slice(0, 8);
                input.value = digits.replace(/^(\d{2})(\d)/, '$1/$2').replace(/^(\d{2}\/\d{2})(\d)/, '$1/$2');
                input.setCustomValidity('');
            }

            function validateDate() {
                if (input.value === '') { input.setCustomValidity(''); return; }
                const match = input.value.match(/^(\d{2})\/(\d{2})\/(\d{4})$/);
                if (!match) { input.setCustomValidity('Informe a data no formato DD/MM/AAAA.'); return; }
                const day = Number(match[1]);
                const month = Number(match[2]);
                const year = Number(match[3]);
                const date = new Date(year, month - 1, day);
                const valid = year >= 1900 && year <= 2100 && date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day;
                input.setCustomValidity(valid ? '' : 'Informe uma data válida no formato DD/MM/AAAA.');
            }

            input.addEventListener('input', applyDateMask);
            input.addEventListener('blur', validateDate);
            applyDateMask();
        });

        document.querySelectorAll('input[type="number"]').forEach(function (input) {
            input.addEventListener('wheel', function (event) { event.preventDefault(); input.blur(); }, { passive: false });
        });

        document.querySelectorAll('[data-mask]').forEach(function (input) {
            function applyMask() {
                const mask = input.dataset.mask;
                let value = input.value.replace(/\D/g, '');
                if (mask === 'phone') { value = value.slice(0, 11).replace(/^(\d{2})(\d)/g, '($1) $2').replace(/(\d{5})(\d)/, '$1-$2'); }
                if (mask === 'cpf') { value = value.slice(0, 11).replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2'); }
                if (mask === 'cnpj') { value = value.slice(0, 14).replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d)/, '$1-$2'); }
                if (mask === 'cep') { value = value.slice(0, 8).replace(/(\d{5})(\d)/, '$1-$2'); }
                if (mask === 'money') { value = (Number(value) / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }); }
                input.value = value;
            }
            input.addEventListener('input', applyMask);
            if (input.value !== '') applyMask();
        });

        (function () {
            const wrappers = Array.from(document.querySelectorAll('.form-field-wrapper[data-field-id]'));
            const byId = new Map(wrappers.map(wrapper => [wrapper.dataset.fieldId, wrapper]));

            function normalize(value) {
                return String(value ?? '').trim().toLocaleLowerCase('pt-BR');
            }

            function fieldValues(wrapper) {
                const controls = Array.from(wrapper.querySelectorAll('input, select, textarea'))
                    .filter(control => !control.disabled);
                const checks = controls.filter(control => control.type === 'radio' || control.type === 'checkbox');
                if (checks.length) return checks.filter(control => control.checked).map(control => normalize(control.value));
                const primary = controls.find(control => control.type !== 'hidden') || controls[0];
                return primary ? [normalize(primary.value)] : [];
            }

            function clearAndDisable(wrapper) {
                wrapper.querySelectorAll('input, select, textarea').forEach(control => {
                    if (control.type === 'radio' || control.type === 'checkbox') control.checked = false;
                    else if (control.type !== 'hidden') control.value = '';
                    control.disabled = true;
                    if (control.required) {
                        control.dataset.conditionalRequired = '1';
                        control.required = false;
                    }
                });
            }

            function enable(wrapper) {
                wrapper.querySelectorAll('input, select, textarea').forEach(control => {
                    control.disabled = control.dataset.conditionalOriginalDisabled === '1';
                    if (control.dataset.conditionalRequired === '1') control.required = true;
                });
            }

            function conditionMatches(wrapper) {
                if (wrapper.dataset.conditionalEnabled !== '1') return true;
                const reference = byId.get(wrapper.dataset.conditionalFieldId);
                if (!reference || reference.hidden) return false;
                const values = fieldValues(reference);
                const expected = normalize(wrapper.dataset.conditionalValue);
                const filled = values.some(value => value !== '');
                const equals = values.includes(expected);
                const contains = expected !== '' && values.some(value => value.includes(expected));
                switch (wrapper.dataset.conditionalOperator) {
                    case 'equals': return equals;
                    case 'not_equals': return !equals;
                    case 'contains': return contains;
                    case 'not_contains': return !contains;
                    case 'filled': return filled;
                    case 'empty': return !filled;
                    default: return false;
                }
            }

            function updateConditions() {
                wrappers.forEach(wrapper => {
                    const panel = wrapper.closest('[data-person-panel]');
                    if (panel && panel.hidden) {
                        clearAndDisable(wrapper);
                        return;
                    }
                    const show = conditionMatches(wrapper);
                    if (show) {
                        wrapper.hidden = false;
                        enable(wrapper);
                    } else {
                        wrapper.hidden = true;
                        clearAndDisable(wrapper);
                    }
                });
            }

            document.addEventListener('input', updateConditions);
            document.addEventListener('change', updateConditions);
            wrappers.forEach(wrapper => wrapper.querySelectorAll('input, select, textarea').forEach(control => {
                control.dataset.conditionalOriginalDisabled = control.disabled ? '1' : '0';
            }));
            const peopleCountSelect = document.getElementById('people_count');
            const peopleTabsContainer = document.getElementById('peopleTabs');
            const personTabs = Array.from(document.querySelectorAll('[data-person-tab]'));
            const personPanels = Array.from(document.querySelectorAll('[data-person-panel]'));
            const activePersonKey = <?= json_encode('formops-active-person-' . ($tenant['slug'] ?? '') . '-' . ($form['slug'] ?? '')) ?>;

            function activatePerson(index) {
                personTabs.forEach(tab => tab.querySelector('.nav-link')?.classList.toggle('active', Number(tab.dataset.personTab) === index));
                personPanels.forEach(panel => panel.classList.toggle('active', Number(panel.dataset.personPanel) === index && !panel.hidden));
                const activeTab = document.querySelector(`[data-person-tab="${index}"]`);
                if (peopleTabsContainer && activeTab) {
                    const targetLeft = activeTab.offsetLeft - Math.max(0, (peopleTabsContainer.clientWidth - activeTab.clientWidth) / 2);
                    peopleTabsContainer.scrollTo({ left: targetLeft, behavior: 'smooth' });
                }
                try { sessionStorage.setItem(activePersonKey, String(index)); } catch (_) {}
                updateConditions();
                updatePaymentTotal();
            }

            function peopleCountValue() {
                const value = Number(peopleCountSelect?.value || 1);
                if (!Number.isFinite(value)) return 1;
                return Math.max(1, Math.min(20, Math.trunc(value)));
            }

            function normalizePeopleCount() {
                if (peopleCountSelect) peopleCountSelect.value = peopleCountValue();
            }

            function updatePaymentTotal() {
                const preview = document.getElementById('paymentPreview');
                if (!preview) return;
                const amount = Number(preview.dataset.amount || 0);
                const people = peopleCountValue();
                const unitCents = Math.round(amount * 100);
                const subtotalCents = unitCents * people;
                const subtotal = subtotalCents / 100;
                let rules = [];
                try { rules = JSON.parse(preview.dataset.groupRules || '[]'); } catch (_) {}
                const rule = rules.filter(item => Number(item.min_people) <= people).sort((a,b) => Number(b.min_people) - Number(a.min_people))[0];
                let groupDiscount = 0;
                if (rule) groupDiscount = rule.discount_type === 'percentage' ? subtotal * Math.min(100, Number(rule.discount_value || 0)) / 100 : Number(rule.discount_value || 0);
                groupDiscount = Math.min(subtotal, Math.max(0, groupDiscount));
                const groupDiscountCents = Math.round(groupDiscount * 100);
                const groupShareBase = Math.floor(groupDiscountCents / people);
                const groupShareRemainder = groupDiscountCents % people;
                let participantCouponData = {};
                try { participantCouponData = JSON.parse(preview.dataset.participantCoupons || '{}'); } catch (_) {}
                let participantCouponDiscountCents = 0;
                for (let personIndex = 1; personIndex <= people; personIndex++) {
                    const participantCoupon = participantCouponData[String(personIndex)] || participantCouponData[personIndex] || {};
                    const participantBaseCents = Math.max(0, unitCents - groupShareBase - (personIndex <= groupShareRemainder ? 1 : 0));
                    const participantCouponValue = Number(participantCoupon.coupon_value || 0);
                    let discountCents = participantCoupon.coupon_type === 'percentage'
                        ? Math.round(participantBaseCents * Math.min(100, participantCouponValue) / 100)
                        : (participantCoupon.coupon_type === 'fixed' ? Math.round(participantCouponValue * 100) : 0);
                    participantCouponDiscountCents += Math.min(participantBaseCents, Math.max(0, discountCents));
                }
                const afterParticipantCoupons = Math.max(0, subtotalCents - groupDiscountCents - participantCouponDiscountCents) / 100;
                const couponType = preview.dataset.couponType || '';
                const couponValue = Number(preview.dataset.couponValue || 0);
                let couponDiscount = couponType === 'percentage' ? afterParticipantCoupons * Math.min(100, couponValue) / 100 : (couponType === 'fixed' ? couponValue : 0);
                couponDiscount = Math.min(afterParticipantCoupons, Math.max(0, couponDiscount));
                const participantCouponDiscount = participantCouponDiscountCents / 100;
                const total = Math.max(0, afterParticipantCoupons - couponDiscount);
                const target = preview.querySelector('[data-payment-total]');
                if (target) target.textContent = total.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
                const peopleTarget = preview.querySelector('[data-pricing-people]');
                if (peopleTarget) peopleTarget.textContent = String(people);
                const subtotalTarget = preview.querySelector('[data-pricing-subtotal]');
                if (subtotalTarget) subtotalTarget.textContent = subtotal.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
                const groupRow = preview.querySelector('[data-group-discount-row]');
                const groupTarget = preview.querySelector('[data-group-discount]');
                if (groupRow) groupRow.hidden = groupDiscount <= 0;
                if (groupTarget) groupTarget.textContent = '− ' + groupDiscount.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
                const participantCouponRow = preview.querySelector('[data-participant-coupon-discount-row]');
                const participantCouponTarget = preview.querySelector('[data-participant-coupon-discount]');
                if (participantCouponRow) participantCouponRow.hidden = participantCouponDiscount <= 0;
                if (participantCouponTarget) participantCouponTarget.textContent = '− ' + participantCouponDiscount.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
                const couponRow = preview.querySelector('[data-coupon-discount-row]');
                const couponTarget = preview.querySelector('[data-coupon-discount]');
                if (couponRow) couponRow.hidden = couponDiscount <= 0;
                if (couponTarget) couponTarget.textContent = '− ' + couponDiscount.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
            }
            function syncPeopleCount() {
                if (!peopleCountSelect) {
                    updateConditions();
                    return;
                }
                const count = peopleCountValue();
                personTabs.forEach(tab => { tab.hidden = Number(tab.dataset.personTab) > count; });
                personPanels.forEach(panel => {
                    panel.hidden = Number(panel.dataset.personPanel) > count;
                    if (panel.hidden) panel.classList.remove('active');
                });
                const current = personPanels.find(panel => panel.classList.contains('active') && !panel.hidden);
                let requestedPerson = current ? Number(current.dataset.personPanel) : 1;
                try { requestedPerson = Number(sessionStorage.getItem(activePersonKey) || requestedPerson); } catch (_) {}
                activatePerson(Math.max(1, Math.min(count, Number.isFinite(requestedPerson) ? requestedPerson : 1)));
            }

            personTabs.forEach(tab => tab.querySelector('.nav-link')?.addEventListener('click', () => activatePerson(Number(tab.dataset.personTab))));
            peopleCountSelect?.addEventListener('input', syncPeopleCount);
            peopleCountSelect?.addEventListener('change', syncPeopleCount);
            peopleCountSelect?.addEventListener('blur', normalizePeopleCount);
            syncPeopleCount();
        })();

        (function () {
            const form = document.getElementById('publicForm');
            if (!form) return;
            const submitButton = form.querySelector('button[type="submit"]');
            form.addEventListener('submit', function (event) {
                form.querySelectorAll('[data-date-input]').forEach(function (input) { input.dispatchEvent(new Event('blur')); });
                if (!form.checkValidity()) {
                    event.preventDefault();
                    form.reportValidity();
                    return;
                }
                if (!submitButton) return;
                submitButton.disabled = true;
                submitButton.textContent = 'Enviando...';
                submitButton.setAttribute('aria-busy', 'true');
            });
            window.addEventListener('pageshow', function () {
                if (!submitButton) return;
                submitButton.disabled = false;
                submitButton.textContent = 'Enviar resposta';
                submitButton.removeAttribute('aria-busy');
            });
        })();
    </script>
</body>

</html>


