<?php

$tenantSlug = trim($_GET['tenant'] ?? '');
$formSlug = trim($_GET['slug'] ?? '');

function renderPublicFormUnavailable(string $title, string $message, ?array $tenant = null, int $status = 404): never
{
    http_response_code($status);
    $brandName = trim((string) ($tenant['name'] ?? 'FormOps'));
    $primary = trim((string) ($tenant['primary_color'] ?? '#2563eb'));
    $secondary = trim((string) ($tenant['secondary_color'] ?? '#14b8a6'));
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primary)) $primary = '#2563eb';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $secondary)) $secondary = '#14b8a6';
    ?>
    <!doctype html>
    <html lang="pt-br">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= htmlspecialchars($title) ?> - <?= htmlspecialchars($brandName) ?></title>
        <style>
            :root{--primary:<?= htmlspecialchars($primary) ?>;--secondary:<?= htmlspecialchars($secondary) ?>}
            *{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f6f8fb;color:#0f172a;display:grid;place-items:center;padding:24px}
            .state{width:min(100%,720px);background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 24px 70px rgba(15,23,42,.12);overflow:hidden}
            .state-top{height:8px;background:linear-gradient(90deg,var(--primary),var(--secondary))}
            .state-body{padding:42px;text-align:center}.mark{width:64px;height:64px;border-radius:18px;display:grid;place-items:center;margin:0 auto 18px;background:color-mix(in srgb,var(--primary) 12%,#fff);color:var(--primary);font-size:28px;font-weight:900}
            h1{font-size:28px;line-height:1.15;margin:0 0 10px;font-weight:850}p{color:#64748b;font-size:16px;line-height:1.6;margin:0 auto;max-width:520px}.brand{margin-top:26px;color:#94a3b8;font-size:13px;font-weight:700}
            .actions{display:flex;justify-content:center;gap:10px;flex-wrap:wrap;margin-top:28px}.btn{border:1px solid #dbe3ef;border-radius:12px;min-height:42px;padding:10px 16px;text-decoration:none;font-weight:800;color:#334155;background:#fff}.btn.primary{background:var(--primary);border-color:var(--primary);color:#fff}
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
    $paymentMethod = $paymentEnabled ? trim((string) ($_POST['payment_method'] ?? '')) : '';
    $paymentAmount = $paymentEnabled ? (float) ($form['payment_amount'] ?? 0) : 0;
    $paymentTotal = $paymentAmount * $peopleCount;

    if ($paymentEnabled && $paymentAmount <= 0) {
        $errors[] = 'Pagamento não configurado para este formulário.';
    }
    if ($paymentEnabled && !$paymentMethods) {
        $errors[] = 'Nenhuma forma de pagamento foi configurada para este formulário.';
    }
    if ($paymentEnabled && ($paymentMethod === '' || !in_array($paymentMethod, $paymentMethods, true))) {
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
            $stmt = $pdo->prepare('SELECT id FROM forms WHERE id = ? AND tenant_id = ? FOR UPDATE');
            $stmt->execute([$form['id'], $tenant['id']]);

            $stmt = $pdo->prepare('SELECT COALESCE(MAX(response_number), 0) + 1 FROM form_responses WHERE tenant_id = ? AND form_id = ?');
            $stmt->execute([$tenant['id'], $form['id']]);
            $responseNumber = (int) $stmt->fetchColumn();
            $submissionGroup = bin2hex(random_bytes(16));

            $paymentStatus = $paymentEnabled ? 'pending' : 'approved';
            $responseStmt = $pdo->prepare('INSERT INTO form_responses (tenant_id, form_id, response_number, submission_group, person_index, people_count, payment_amount, payment_total, payment_method, payment_status, submitted_by_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $answerStmt = $pdo->prepare('INSERT INTO form_response_answers (tenant_id, response_id, field_id, answer) VALUES (?, ?, ?, ?)');

            foreach ($personPayloads as $personIndex) {
                $personKey = $personIndex ?? 1;
                $responseStmt->execute([$tenant['id'], $form['id'], $responseNumber++, $submissionGroup, $personKey, $peopleCount, $paymentAmount, $paymentTotal, $paymentMethod, $paymentStatus, $_SERVER['REMOTE_ADDR'] ?? null]);
                $responseId = (int) $pdo->lastInsertId();

                foreach ($activeAnswerFieldsByPerson[$personKey] as $field) {
                    $answer = fieldValue($field, $personIndex);
                    if (is_array($answer)) {
                        $answer = implode(', ', $answer);
                    }
                    $answerStmt->execute([$tenant['id'], $responseId, $field['id'], $answer]);
                }
            }

            $pdo->commit();
            $success = true;
            if ($paymentEnabled) {
                $paymentLabels = ['pix' => 'Pix', 'transferencia' => 'Transferência', 'dinheiro' => 'Dinheiro'];
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
                    'proof_url' => 'https://wa.me/?text=' . rawurlencode('Olá, segue o comprovante do pagamento do formulário ' . ($form['title'] ?? '') . ' no valor de R$ ' . number_format($paymentTotal, 2, ',', '.')),
                    'instructions' => trim((string) ($form['payment_instructions'] ?? '')), 
                ];
            }

        } catch (Exception $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Erro ao salvar resposta. Tente novamente.';
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
                $inputType = $field['type'] === 'phone' ? 'tel' : $field['type'];
                if ($mask !== 'none' && !in_array($inputType, ['tel', 'text'], true))
                    $inputType = 'text';
                ?>
                <input type="<?= htmlspecialchars($inputType) ?>" name="<?= $fieldName ?>" id="<?= $fieldId ?>"
                    class="form-control <?= htmlspecialchars($inputClass) ?>"
                    placeholder="<?= htmlspecialchars($placeholder) ?>" value="<?= htmlspecialchars((string) $value) ?>"
                    <?= $maskAttribute ?>         <?= $htmlRequired ? 'required' : '' ?>         <?= $readonly ? 'readonly' : '' ?>>

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
$primaryColor = publicColor($useTenantBranding ? ($tenant['primary_color'] ?? null) : (($form['primary_color'] ?? null) ?: ($tenant['primary_color'] ?? null)), '#0d6efd');
$secondaryColor = publicColor($useTenantBranding ? ($tenant['secondary_color'] ?? null) : (($form['secondary_color'] ?? null) ?: ($tenant['secondary_color'] ?? null)), '#198754');
$backgroundColor = publicColor($useTenantBranding ? ($tenant['background_color'] ?? null) : (($form['background_color'] ?? null) ?: ($tenant['background_color'] ?? null)), '#f6f7fb');
$textColor = publicColor($useTenantBranding ? ($tenant['text_color'] ?? null) : (($form['text_color'] ?? null) ?: ($tenant['text_color'] ?? null)), '#111827');
$buttonColor = publicColor($useTenantBranding ? ($tenant['button_color'] ?? null) : (($form['button_color'] ?? null) ?: ($tenant['button_color'] ?? null)), '#0d6efd');
$focusRgb = colorRgb($buttonColor);
$publicCover = publicAssetPath(!$useTenantBranding && !empty($form['cover_image_path']) ? $form['cover_image_path'] : ($tenant['cover_image_path'] ?? ''));
$formStyle = in_array(!$useTenantBranding && !empty($form['style']) ? $form['style'] : ($tenant['form_style'] ?? ''), ['clean', 'premium', 'minimal', 'church', 'business'], true) ? (!$useTenantBranding && !empty($form['style']) ? $form['style'] : $tenant['form_style']) : 'clean';
$successMessage = $form['success_message'] ?: 'Sua resposta foi enviada com sucesso.';
$allowMultiplePeople = (int) ($form['allow_multiple_people'] ?? 0) === 1;
$initialPeopleCount = $allowMultiplePeople ? max(1, min(20, (int) ($_POST['people_count'] ?? 1))) : 1;
?>
<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($form['title']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="/assets/clients/formops/favicon-formops.jpg">
    <link rel="stylesheet" href="assets/brand.css">
    <style>
        body {
            background:
                <?= htmlspecialchars($backgroundColor) ?>
            ;
            color:
                <?= htmlspecialchars($textColor) ?>
            ;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
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
            box-shadow: 0 18px 50px rgba(15, 23, 42, .08);
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

        .public-cover::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(15, 23, 42, .05) 15%, rgba(15, 23, 42, .78) 100%);
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
            background: #f8fafc;
            color: #64748b;
        }

        .form-text {
            font-size: 13px;
            color: #6b7280;
        }

        .choice-group {
            border: 1px solid #e5e7eb;
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
            border-color: #e5e7eb;
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
            border: 1px solid #E5E7EB;
            border-radius: 16px;
            padding: 18px;
            background: #F8FAFC;
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
        .payment-amount-card { border: 1px solid #E5E7EB; background: #fff; border-radius: 16px; padding: 16px; margin-bottom: 16px; }
        .payment-amount-label { color: #6B7280; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
        .payment-amount-value { color: #111827; font-size: 32px; font-weight: 900; line-height: 1.1; margin-top: 4px; }
        .payment-steps { margin: 0 0 18px; padding-left: 18px; color: #4B5563; }
        .payment-qr-card { border: 1px solid #E5E7EB; border-radius: 16px; background: #fff; padding: 16px; text-align: center; margin-bottom: 16px; }
        .payment-qr-card img { display: block; margin: 0 auto; }
        .pix-copy-group { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 10px; align-items: stretch; margin-top: 12px; }
        .pix-copy-field { min-height: 46px; border: 1px solid #D1D5DB; border-radius: 12px; background: #F9FAFB; padding: 10px 12px; font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .payment-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
        .payment-copy-feedback { color: #047857; font-size: 13px; font-weight: 700; margin-top: 8px; display: none; }
        .payment-copy-feedback.show { display: block; }
        @media(max-width:576px) { .payment-status-head, .payment-actions { flex-direction: column; } .pix-copy-group { grid-template-columns: 1fr; } .payment-amount-value { font-size: 28px; } }
        .people-count-card {
            border: 1px solid #E5E7EB;
            border-radius: 16px;
            padding: 16px;
            background: #F8FAFC;
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
        }

        .submit-btn:hover {
            background: <?= htmlspecialchars($buttonColor) ?>;
            border-color: <?= htmlspecialchars($buttonColor) ?>;
            transform: translateY(-1px);
            box-shadow: 0 10px 24px rgba(<?= $focusRgb ?>, .2);
        }

        @media(max-width:768px) {
            .public-wrapper {
                padding: 24px 12px;
            }

            .public-header:not(:has(.public-cover)),
            .public-body {
                padding: 24px;
            }

            .public-cover-content {
                padding: 24px;
            }

            .public-cover {
                min-height: 220px;
            }

            .public-header h1 {
                font-size: 24px;
            }
        }
    </style>
</head>

<body>
    <div class="public-wrapper">
        <div class="card public-card style-<?= htmlspecialchars($formStyle) ?>">
            <div class="public-header">
                <?php if ($publicCover): ?>
                    <div class="public-cover" style="background-image: url('<?= htmlspecialchars($publicCover) ?>')">
                        <div class="public-cover-content">
                            <h1><?= htmlspecialchars($form['title']) ?></h1>
                            <?php if (!empty($form['description'])): ?>
                                <p><?= nl2br(htmlspecialchars($form['description'])) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <h1><?= htmlspecialchars($form['title']) ?></h1>
                    <?php if (!empty($form['description'])): ?>
                        <p><?= nl2br(htmlspecialchars($form['description'])) ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
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

                            <div class="payment-actions">
                                <a class="btn btn-success" href="<?= htmlspecialchars($paymentInfo['proof_url']) ?>" target="_blank" rel="noopener" data-clear-payment>Enviar comprovante pelo WhatsApp</a>
                                <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($publicUrl) ?>" data-clear-payment>Voltar para formulário</a>
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
                                    <input class="form-control" type="number" id="people_count" name="people_count" value="<?= $initialPeopleCount ?>" min="1" max="20" step="1" inputmode="numeric">
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
                                        </div>
                                    <?php endfor; ?>
                                </div>
                            <?php else: ?>
                                <div class="row g-3">
                                    <?php foreach ($visibleFields as $field) renderField($field); ?>
                                </div>
                            <?php endif; ?>
                            <?php if ((int)($form['payment_enabled'] ?? 0) === 1): ?>
                                <?php $previewPeople = $allowMultiplePeople ? $initialPeopleCount : 1; $previewTotal = (float)($form['payment_amount'] ?? 0) * $previewPeople; ?>
                                <div class="payment-box mt-4" id="paymentPreview" data-amount="<?= htmlspecialchars((string)($form['payment_amount'] ?? 0)) ?>">
                                    <div class="fw-semibold mb-1">Pagamento</div>
                                    <div>Total: <strong data-payment-total>R$ <?= number_format($previewTotal, 2, ',', '.') ?></strong></div>
                                    <div class="small text-muted mb-3">R$ <?= number_format((float)($form['payment_amount'] ?? 0), 2, ',', '.') ?> por pessoa. Aprovação manual após confirmação.</div>
                                    <?php $paymentMethodLabels = ['pix' => 'Pix', 'transferencia' => 'Transferência', 'dinheiro' => 'Dinheiro']; $configuredPaymentMethods = array_values(array_filter(explode(',', (string)($form['payment_methods'] ?? '')))); ?>
                                    <div class="fw-semibold small mb-2">Escolha a forma de pagamento</div>
                                    <?php foreach ($configuredPaymentMethods as $methodValue): ?>
                                        <label class="form-check mb-1"><input class="form-check-input" type="radio" name="payment_method" value="<?= htmlspecialchars($methodValue) ?>" <?= (($_POST['payment_method'] ?? '') === $methodValue) ? 'checked' : '' ?> required> <?= htmlspecialchars($paymentMethodLabels[$methodValue] ?? $methodValue) ?></label>
                                    <?php endforeach; ?>
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

                if (event.target.closest('[data-clear-payment]')) {
                    localStorage.removeItem(paymentPersistKey);
                }
            });
        })();
    </script>    <script>
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
            const personTabs = Array.from(document.querySelectorAll('[data-person-tab]'));
            const personPanels = Array.from(document.querySelectorAll('[data-person-panel]'));

            function activatePerson(index) {
                personTabs.forEach(tab => tab.querySelector('.nav-link')?.classList.toggle('active', Number(tab.dataset.personTab) === index));
                personPanels.forEach(panel => panel.classList.toggle('active', Number(panel.dataset.personPanel) === index && !panel.hidden));
                document.querySelector(`[data-person-tab="${index}"]`)?.scrollIntoView({ inline: 'nearest', block: 'nearest' });
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
                const total = amount * peopleCountValue();
                const target = preview.querySelector('[data-payment-total]');
                if (target) target.textContent = total.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
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
                activatePerson(current ? Number(current.dataset.personPanel) : 1);
            }

            personTabs.forEach(tab => tab.querySelector('.nav-link')?.addEventListener('click', () => activatePerson(Number(tab.dataset.personTab))));
            peopleCountSelect?.addEventListener('input', syncPeopleCount);
            peopleCountSelect?.addEventListener('change', syncPeopleCount);
            peopleCountSelect?.addEventListener('blur', normalizePeopleCount);
            syncPeopleCount();
        })();
    </script>
</body>

</html>


