<?php
requireTenantContext();
if (!canManageTenantData()) { redirectTo('forms'); }

$tenantId = currentTenantIdForData();
$currentUser = user();
$tenantSlug = isSuperAdmin() ? ($_SESSION['maintenance_tenant_slug'] ?? '') : ($currentUser['tenant_slug'] ?? '');

$pageTitle = 'Editar formulário';

$formId = $_GET['id'] ?? null;
$allowedTabs = ['general', 'publication', 'payment', 'ticket', 'fields', 'structure', 'appearance'];
$activeTab = $_POST['tab'] ?? $_GET['tab'] ?? 'general';
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'general';
}
if ($activeTab === 'structure') {
    $activeTab = 'fields';
}

if (!$formId) {
    die('Formulário não encontrado.');
}

// Busca o formulário
$stmt = $pdo->prepare("SELECT * FROM forms WHERE id = ? AND tenant_id = ?");
$stmt->execute([$formId, $tenantId]);
$form = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$form) {
    die('Formulário não encontrado.');
}

if (!currentUserCanAccessFormGroup(!empty($form['form_group_id']) ? (int) $form['form_group_id'] : null)) {
    redirectTo('forms');
}

if ($activeTab === 'payment' && (int) ($form['payment_enabled'] ?? 0) !== 1) {
    $activeTab = 'general';
}
$publicPath = 'f/' . rawurlencode($tenantSlug) . '/' . rawurlencode($form['slug']);
$publicUrl = absoluteAppUrl($publicPath);
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&data=' . urlencode($publicUrl);

$stmt = $pdo->prepare('SELECT * FROM tenants WHERE id = ?');
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);


function formAssetUrl(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') return '';
    if (preg_match('#^(https?:)?//#i', $path) || str_starts_with($path, '/')) return $path;
    if (str_starts_with($path, 'storage/')) return appUrl('public/' . $path);
    return appUrl($path);
}

function optimizeFormImage(string $tmpName, string $extension, string $absolutePath, int $maxWidth, int $quality): bool
{
    if (!extension_loaded('gd') || !function_exists('imagewebp')) return false;
    $source = match (strtolower($extension)) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($tmpName),
        'png' => @imagecreatefrompng($tmpName),
        default => false,
    };
    if (!$source) return false;
    $width = imagesx($source); $height = imagesy($source);
    if ($width <= 0 || $height <= 0) { imagedestroy($source); return false; }
    $targetWidth = min($width, $maxWidth);
    $targetHeight = (int) round($height * ($targetWidth / $width));
    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    imagealphablending($target, false); imagesavealpha($target, true);
    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
    imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
    $ok = imagewebp($target, $absolutePath, $quality);
    imagedestroy($source); imagedestroy($target);
    return $ok;
}

function uploadFormBrandFile(string $field, int $tenantId, int $formId, array $allowedExtensions, int $maxBytes, array &$errors): ?string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $file = $_FILES[$field];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) <= 0) { $errors[] = 'Não foi possível enviar o arquivo.'; return null; }
    if (($file['size'] ?? 0) > $maxBytes) { $errors[] = $field === 'logo_file' ? 'A logo deve ter no máximo 2MB.' : 'A imagem de capa deve ter no máximo 4MB.'; return null; }
    $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) { $errors[] = $field === 'logo_file' ? 'A logo deve ser JPG, PNG ou SVG.' : 'A capa deve ser JPG ou PNG.'; return null; }
    $tmpName = (string)($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_file($tmpName)) { $errors[] = 'Upload inválido.'; return null; }
    $directory = 'storage/tenants/' . $tenantId . '/forms/' . $formId . '/branding';
    $absoluteDirectory = __DIR__ . '/../../../../public/' . $directory;
    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true)) { $errors[] = 'Não foi possível criar a pasta de upload.'; return null; }
    $prefix = $field === 'logo_file' ? 'logo' : 'cover';
    $baseName = $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    if ($extension === 'svg') {
        $svg = file_get_contents($tmpName);
        if ($svg === false || stripos($svg, '<svg') === false || preg_match('/<script|on\w+\s*=|javascript:/i', $svg)) { $errors[] = 'O SVG enviado não é válido.'; return null; }
        $filename = $baseName . '.svg';
        if (!move_uploaded_file($tmpName, $absoluteDirectory . '/' . $filename) && !rename($tmpName, $absoluteDirectory . '/' . $filename)) { $errors[] = 'Não foi possível salvar a logo.'; return null; }
        return $directory . '/' . $filename;
    }
    if (@getimagesize($tmpName) === false) { $errors[] = 'O arquivo enviado não parece ser uma imagem válida.'; return null; }
    $filename = $baseName . '.webp';
    $absolutePath = $absoluteDirectory . '/' . $filename;
    if (!optimizeFormImage($tmpName, $extension, $absolutePath, $field === 'logo_file' ? 900 : 1800, $field === 'logo_file' ? 88 : 82)) {
        $fallback = $baseName . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
        if (!move_uploaded_file($tmpName, $absoluteDirectory . '/' . $fallback) && !rename($tmpName, $absoluteDirectory . '/' . $fallback)) { $errors[] = 'Não foi possível salvar o arquivo.'; return null; }
        return $directory . '/' . $fallback;
    }
    return $directory . '/' . $filename;
}

$groupsSql = 'SELECT id, name FROM form_groups WHERE tenant_id = ? AND is_active = 1';
$groupsParams = [$tenantId];
if (shouldScopeTenantUserToGroup()) {
    $groupsSql .= ' AND ' . currentUserFormGroupScopeSql('id');
    $groupsParams = array_merge($groupsParams, currentUserFormGroupIds());
}
$groupsSql .= ' ORDER BY name ASC';
$stmt = $pdo->prepare($groupsSql);
$stmt->execute($groupsParams);
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
$groupsById = [];
foreach ($groups as $group) {
    $groupsById[(int) $group['id']] = $group;
}

$errors = [];
$success = null;

require __DIR__ . '/pricing-actions.php';

$stmt = $pdo->prepare(
    'SELECT id, label, field_order
     FROM form_fields
     WHERE tenant_id = ? AND form_id = ? AND is_layout = 0
     ORDER BY field_order ASC, id ASC'
);
$stmt->execute([$tenantId, $formId]);
$existingResponseFields = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_condition') {
    $fieldId = filter_var($_POST['field_id'] ?? null, FILTER_VALIDATE_INT);
    $conditionalEnabled = isset($_POST['conditional_enabled']) ? 1 : 0;
    $conditionalFieldId = filter_var($_POST['conditional_field_id'] ?? null, FILTER_VALIDATE_INT);
    $conditionalOperator = $_POST['conditional_operator'] ?? 'equals';
    $conditionalValue = trim($_POST['conditional_value'] ?? '');
    $allowedConditionalOperators = ['equals', 'not_equals', 'contains', 'not_contains', 'filled', 'empty'];

    $stmt = $pdo->prepare(
        'SELECT id, field_order, is_layout
         FROM form_fields WHERE id = ? AND form_id = ? AND tenant_id = ? LIMIT 1'
    );
    $stmt->execute([$fieldId, $formId, $tenantId]);
    $conditionalTarget = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conditionalTarget || (int) $conditionalTarget['is_layout'] === 1) {
        $errors[] = 'Campo inválido para exibição condicional.';
    }

    if ($conditionalEnabled && !in_array($conditionalOperator, $allowedConditionalOperators, true)) {
        $errors[] = 'Operador condicional inválido.';
    }

    if ($conditionalEnabled && $conditionalTarget) {
        $stmt = $pdo->prepare(
            'SELECT id FROM form_fields
             WHERE id = ? AND form_id = ? AND tenant_id = ? AND is_layout = 0 AND field_order < ?
             LIMIT 1'
        );
        $stmt->execute([$conditionalFieldId, $formId, $tenantId, $conditionalTarget['field_order']]);
        if (!$stmt->fetch()) {
            $errors[] = 'Selecione um campo de referência anterior ao campo dependente.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare(
            'UPDATE form_fields
             SET conditional_enabled = ?, conditional_field_id = ?, conditional_operator = ?, conditional_value = ?
             WHERE id = ? AND form_id = ? AND tenant_id = ? AND is_layout = 0'
        );
        $stmt->execute([
            $conditionalEnabled,
            $conditionalEnabled ? $conditionalFieldId : null,
            $conditionalEnabled ? $conditionalOperator : null,
            $conditionalEnabled && !in_array($conditionalOperator, ['filled', 'empty'], true) ? $conditionalValue : null,
            $fieldId,
            $formId,
            $tenantId,
        ]);
        redirectTo('form-edit', ['id' => $formId, 'tab' => 'fields', 'success' => 'condition_updated']);
    }
}

// Atualizar dados do formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_form') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $type = $_POST['type'] ?? 'outro';
    $formGroupId = filter_input(INPUT_POST, 'form_group_id', FILTER_VALIDATE_INT) ?: null;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $paymentEnabled = isset($_POST['payment_enabled']) ? 1 : 0;

    if ($title === '') {
        $errors[] = 'O título do formulário é obrigatório.';
    }

    if (!in_array($type, ['inscricao', 'pesquisa', 'contato', 'cadastro', 'evento', 'outro'], true)) {
        $errors[] = 'Tipo de formulário inválido.';
    }

    if ($formGroupId && !isset($groupsById[$formGroupId])) {
        $errors[] = 'Grupo inválido para este formulário.';
    }
    if (shouldScopeTenantUserToGroup() && !$formGroupId) {
        $errors[] = 'Selecione um dos seus grupos para este formulário.';
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            UPDATE forms 
            SET title = ?, description = ?, type = ?, form_group_id = ?, is_active = ?, payment_enabled = ?, updated_at = NOW()
            WHERE id = ? AND tenant_id = ?
        ");

        $stmt->execute([
            $title,
            $description,
            $type,
            $formGroupId,
            $is_active,
            $paymentEnabled,
            $formId,
            $tenantId
        ]);

        redirectTo('form-edit', ['id' => $formId, 'tab' => 'general', 'success' => 'form_updated']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_publication') {
    $slug = trim($_POST['slug'] ?? '');
    $allowMultiplePeople = isset($_POST['allow_multiple_people']) ? 1 : 0;
    $closesAtInput = trim($_POST['closes_at'] ?? '');
    $closesAt = parseDateTimeLocal($closesAtInput);
    $activeTab = 'publication';

    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
        $errors[] = 'Use apenas letras minúsculas, números e hífens no slug.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM forms WHERE tenant_id = ? AND slug = ? AND id != ? LIMIT 1');
        $stmt->execute([$tenantId, $slug, $formId]);
        if ($stmt->fetch()) {
            $errors[] = 'Este slug já está sendo usado por outro formulário.';
        }
    }

    if ($closesAtInput !== '' && $closesAt === null) {
        $errors[] = 'Informe uma data limite valida.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('UPDATE forms SET slug = ?, allow_multiple_people = ?, closes_at = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$slug, $allowMultiplePeople, $closesAt, $formId, $tenantId]);
        redirectTo('form-edit', ['id' => $formId, 'tab' => 'publication', 'success' => 'publication_updated']);
    }
}

function uploadTicketBackground(int $tenantId, int $formId, array &$errors): ?string
{
    $field = 'ticket_background_file';
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $file = $_FILES[$field];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) <= 0) { $errors[] = 'Não foi possível enviar o fundo do ingresso.'; return null; }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) { $errors[] = 'O fundo do ingresso deve ter no máximo 5MB.'; return null; }
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png'], true)) { $errors[] = 'O fundo do ingresso deve ser JPG ou PNG.'; return null; }
    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_file($tmpName) || @getimagesize($tmpName) === false) { $errors[] = 'O fundo enviado não é uma imagem válida.'; return null; }
    $directory = 'storage/tenants/' . $tenantId . '/forms/' . $formId . '/ticket';
    $absoluteDirectory = __DIR__ . '/../../../../public/' . $directory;
    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true)) { $errors[] = 'Não foi possível criar a pasta do modelo de ingresso.'; return null; }
    $baseName = 'background-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
    $filename = $baseName . '.webp';
    if (!optimizeFormImage($tmpName, $extension, $absoluteDirectory . '/' . $filename, 1800, 86)) {
        $filename = $baseName . '.' . ($extension === 'jpeg' ? 'jpg' : $extension);
        if (!move_uploaded_file($tmpName, $absoluteDirectory . '/' . $filename) && !rename($tmpName, $absoluteDirectory . '/' . $filename)) { $errors[] = 'Não foi possível salvar o fundo do ingresso.'; return null; }
    }
    return $directory . '/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_payment') {
    $activeTab = 'payment';
    $paymentEnabled = (int) ($form['payment_enabled'] ?? 0) === 1;
    $paymentAmountInput = str_replace(',', '.', trim($_POST['payment_amount'] ?? '0'));
    $paymentAmount = is_numeric($paymentAmountInput) ? (float) $paymentAmountInput : -1;
    $allowedMethods = ['pix', 'transferencia', 'dinheiro'];
    $paymentMethods = array_values(array_intersect($allowedMethods, (array) ($_POST['payment_methods'] ?? [])));
    $pixKey = trim($_POST['pix_key'] ?? '');
    $paymentWhatsApp = preg_replace('/\D+/', '', (string) ($_POST['payment_whatsapp'] ?? ''));
    if (in_array(strlen($paymentWhatsApp), [10, 11], true)) $paymentWhatsApp = '55' . $paymentWhatsApp;
    $paymentInstructions = trim($_POST['payment_instructions'] ?? '');

    if ($paymentEnabled && $paymentAmount < 0) $errors[] = 'Informe um valor-base válido.';
    if ($paymentEnabled && !$paymentMethods) $errors[] = 'Selecione pelo menos uma forma de pagamento.';
    if ($paymentEnabled && in_array('pix', $paymentMethods, true) && $pixKey === '') $errors[] = 'Informe a chave Pix para receber pagamentos por Pix.';
    if ($paymentEnabled && (strlen($paymentWhatsApp) < 10 || strlen($paymentWhatsApp) > 15)) $errors[] = 'Informe um WhatsApp válido com DDI para receber os comprovantes.';

    if (!$errors) {
        $stmt = $pdo->prepare('UPDATE forms SET payment_amount = ?, payment_methods = ?, pix_key = ?, payment_whatsapp = ?, payment_instructions = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$paymentAmount, implode(',', $paymentMethods), $pixKey, $paymentWhatsApp, $paymentInstructions, $formId, $tenantId]);
        redirectTo('form-edit', ['id' => $formId, 'tab' => 'payment', 'success' => 'payment_updated']);
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_ticket') {
    $activeTab = 'ticket';
    $ticketEnabled = isset($_POST['ticket_enabled']) ? 1 : 0;
    $ticketTitle = trim($_POST['ticket_title'] ?? '');
    $ticketSubtitle = trim($_POST['ticket_subtitle'] ?? '');
    $ticketEventAtInput = trim($_POST['ticket_event_at'] ?? '');
    $ticketEventAt = parseDateTimeLocal($ticketEventAtInput);
    $ticketLocation = trim($_POST['ticket_location'] ?? '');
    $ticketInstructions = trim($_POST['ticket_instructions'] ?? '');
    $ticketPrimaryColor = strtoupper(trim($_POST['ticket_primary_color'] ?? '#212121'));
    $ticketTextColor = strtoupper(trim($_POST['ticket_text_color'] ?? '#212121'));
    $ticketNameFieldId = filter_var($_POST['ticket_name_field_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $ticketEmailFieldId = filter_var($_POST['ticket_email_field_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
    $ticketEmailSubject = trim($_POST['ticket_email_subject'] ?? '');
    $ticketEmailMessage = trim($_POST['ticket_email_message'] ?? '');

    if (strlen($ticketTitle) > 160) $errors[] = 'O título do ingresso deve ter no máximo 160 caracteres.';
    if (strlen($ticketSubtitle) > 255) $errors[] = 'O subtítulo do ingresso deve ter no máximo 255 caracteres.';
    if (strlen($ticketLocation) > 255) $errors[] = 'O local deve ter no máximo 255 caracteres.';
    if (strlen($ticketEmailSubject) > 190) $errors[] = 'O assunto do e-mail deve ter no máximo 190 caracteres.';
    if ($ticketEventAtInput !== '' && $ticketEventAt === null) $errors[] = 'Informe uma data e hora válidas para o evento.';
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $ticketPrimaryColor) || !preg_match('/^#[0-9a-fA-F]{6}$/', $ticketTextColor)) $errors[] = 'Informe cores válidas para o ingresso.';

    $validFieldIds = [];
    $stmt = $pdo->prepare('SELECT id FROM form_fields WHERE tenant_id = ? AND form_id = ? AND is_layout = 0');
    $stmt->execute([$tenantId, $formId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $fieldId) $validFieldIds[(int) $fieldId] = true;
    if ($ticketNameFieldId && !isset($validFieldIds[$ticketNameFieldId])) $errors[] = 'O campo de nome selecionado é inválido.';
    if ($ticketEmailFieldId && !isset($validFieldIds[$ticketEmailFieldId])) $errors[] = 'O campo de e-mail selecionado é inválido.';
    if ($ticketEnabled && !$ticketNameFieldId) $errors[] = 'Selecione o campo que contém o nome do participante.';
    if ($ticketEnabled && !$ticketEmailFieldId) $errors[] = 'Selecione o campo que contém o e-mail do participante.';

    $backgroundPath = null;
    if (!$errors) $backgroundPath = uploadTicketBackground((int) $tenantId, (int) $formId, $errors);

    if (!$errors) {
        $removeBackground = isset($_POST['remove_ticket_background']);
        $storedBackground = $removeBackground ? null : ($backgroundPath ?: ($form['ticket_background_path'] ?? null));
        $stmt = $pdo->prepare('UPDATE forms SET ticket_enabled = ?, ticket_title = ?, ticket_subtitle = ?, ticket_event_at = ?, ticket_location = ?, ticket_instructions = ?, ticket_background_path = ?, ticket_primary_color = ?, ticket_text_color = ?, ticket_name_field_id = ?, ticket_email_field_id = ?, ticket_email_subject = ?, ticket_email_message = ?, updated_at = NOW() WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$ticketEnabled, $ticketTitle ?: null, $ticketSubtitle ?: null, $ticketEventAt, $ticketLocation ?: null, $ticketInstructions ?: null, $storedBackground, $ticketPrimaryColor, $ticketTextColor, $ticketNameFieldId, $ticketEmailFieldId, $ticketEmailSubject ?: null, $ticketEmailMessage ?: null, $formId, $tenantId]);
        redirectTo('form-edit', ['id' => $formId, 'tab' => 'ticket', 'success' => 'ticket_updated']);
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_appearance') {
    $activeTab = 'appearance';
    $hasCustomAppearanceUpload = false;
    foreach (['logo_file', 'cover_image_file'] as $uploadField) {
        if (!empty($_FILES[$uploadField])
            && ($_FILES[$uploadField]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && (int) ($_FILES[$uploadField]['size'] ?? 0) > 0) {
            $hasCustomAppearanceUpload = true;
            break;
        }
    }
    // Um arquivo próprio deve passar a ser exibido imediatamente. Manter a
    // herança ativa faria o upload ser salvo, mas a capa padrão continuaria visível.
    $useTenantBranding = isset($_POST['use_tenant_branding']) && !$hasCustomAppearanceUpload ? 1 : 0;
    $hideBannerText = isset($_POST['hide_banner_text']) ? 1 : 0;
    $appearance = [
        'public_title' => trim($_POST['public_title'] ?? ''),
        'public_subtitle' => trim($_POST['public_subtitle'] ?? ''),
        'additional_information' => trim($_POST['additional_information'] ?? ''),
        'style' => trim($_POST['style'] ?? 'clean'),
    ];
    foreach (['primary_color', 'secondary_color', 'background_color', 'text_color', 'button_color'] as $key) {
        $appearance[$key] = strtoupper(trim($_POST[$key] ?? ''));
        if (!$useTenantBranding && $appearance[$key] !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $appearance[$key])) {
            $errors[] = 'Informe cores válidas.';
        }
    }
    if (strlen($appearance['public_title']) > 120) $errors[] = 'O nome público deve ter no máximo 120 caracteres.';
    if (strlen($appearance['public_subtitle']) > 180) $errors[] = 'O subtítulo deve ter no máximo 180 caracteres.';
    if (mb_strlen($appearance['additional_information']) > 5000) $errors[] = 'As informações complementares devem ter no máximo 5.000 caracteres.';
    if (!in_array($appearance['style'], ['clean', 'premium', 'minimal', 'church', 'business'], true)) $errors[] = 'Estilo inválido.';

    $logoPath = uploadFormBrandFile('logo_file', $tenantId, (int)$formId, ['jpg', 'jpeg', 'png', 'svg'], 2 * 1024 * 1024, $errors);
    $coverPath = uploadFormBrandFile('cover_image_file', $tenantId, (int)$formId, ['jpg', 'jpeg', 'png'], 4 * 1024 * 1024, $errors);

    if (!$errors) {
        if ($logoPath !== null || $coverPath !== null) {
            $useTenantBranding = 0;
        }
        $stmt = $pdo->prepare('UPDATE forms
            SET use_tenant_branding = ?, logo_path = COALESCE(?, logo_path), cover_image_path = COALESCE(?, cover_image_path), public_title = ?, public_subtitle = ?, additional_information = ?, hide_banner_text = ?, primary_color = ?, secondary_color = ?, background_color = ?, text_color = ?, button_color = ?, style = ?, updated_at = NOW()
            WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$useTenantBranding, $logoPath, $coverPath, $appearance['public_title'] ?: null, $appearance['public_subtitle'] ?: null, $appearance['additional_information'] ?: null, $hideBannerText, $appearance['primary_color'] ?: null, $appearance['secondary_color'] ?: null, $appearance['background_color'] ?: null, $appearance['text_color'] ?: null, $appearance['button_color'] ?: null, $appearance['style'], $formId, $tenantId]);
        redirectTo('form-edit', ['id' => $formId, 'tab' => 'appearance', 'success' => 'appearance_updated']);
    }
}

// Adicionar campo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_field') {
    $activeTab = 'fields';
    $fieldId = filter_var($_POST['field_id'] ?? null, FILTER_VALIDATE_INT);
    $label = trim($_POST['label'] ?? '');
    $type = $_POST['field_type'] ?? 'text';
    $placeholder = trim($_POST['placeholder'] ?? '');
    $options = trim($_POST['options'] ?? '');
    $helpText = trim($_POST['help_text'] ?? '');
    $defaultValue = trim($_POST['default_value'] ?? '');
    $isRequired = isset($_POST['is_required']) ? 1 : 0;
    $isVisible = isset($_POST['is_visible']) ? 1 : 0;
    $isReadonly = isset($_POST['is_readonly']) ? 1 : 0;
    $width = (int) ($_POST['width'] ?? 12);
    $mask = $_POST['mask'] ?? 'none';
    $allowedTypes = ['text','email','phone','number','date','textarea','select','radio','checkbox','title','paragraph','divider','step','banner'];
    $layoutTypes = ['title', 'paragraph', 'divider', 'step', 'banner'];
    $isLayout = in_array($type, $layoutTypes, true) ? 1 : 0;

    if (!$fieldId) $errors[] = 'Campo inválido.';
    if ($label === '' && !in_array($type, ['divider', 'banner'], true)) $errors[] = 'O nome do campo é obrigatório.';
    if (!in_array($type, $allowedTypes, true)) $errors[] = 'Tipo de campo inválido.';
    if (!in_array($width, [12, 6, 4], true)) $errors[] = 'Largura de campo inválida.';
    if (!in_array($mask, ['none', 'phone', 'cpf', 'cnpj', 'cep', 'money'], true)) $errors[] = 'Máscara de campo inválida.';
    if (in_array($type, ['select', 'radio', 'checkbox'], true) && $options === '') $errors[] = 'Esse tipo de campo precisa de opções.';

    if ($isLayout) {
        $width = 12;
        $mask = 'none';
        $options = '';
        $isRequired = 0;
        $isReadonly = 0;
    }

    if (!$errors) {
        $stmt = $pdo->prepare('UPDATE form_fields
            SET label = ?, type = ?, is_layout = ?, width = ?, mask = ?, placeholder = ?, help_text = ?, default_value = ?, options = ?, is_required = ?, is_visible = ?, is_readonly = ?
            WHERE id = ? AND form_id = ? AND tenant_id = ?');
        $stmt->execute([$label, $type, $isLayout, $width, $mask, $placeholder ?: null, $helpText ?: null, $defaultValue ?: null, $options ?: null, $isRequired, $isVisible, $isReadonly, $fieldId, $formId, $tenantId]);
        redirectTo('form-edit', ['id' => $formId, 'tab' => 'fields', 'success' => 'field_updated']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_field') {
    $label = trim($_POST['label'] ?? '');
    $type = $_POST['field_type'] ?? 'text';
    $placeholder = trim($_POST['placeholder'] ?? '');
    $options = trim($_POST['options'] ?? '');
    $isRequired = isset($_POST['is_required']) ? 1 : 0;
    $width = (int) ($_POST['width'] ?? 12);
    $mask = $_POST['mask'] ?? 'none';
    $helpText = trim($_POST['help_text'] ?? '');
    $defaultValue = trim($_POST['default_value'] ?? '');
    $isVisible = isset($_POST['is_visible']) ? 1 : 0;
    $isReadonly = isset($_POST['is_readonly']) ? 1 : 0;
    $conditionalEnabled = isset($_POST['conditional_enabled']) ? 1 : 0;
    $conditionalFieldId = filter_var($_POST['conditional_field_id'] ?? null, FILTER_VALIDATE_INT);
    $conditionalOperator = $_POST['conditional_operator'] ?? 'equals';
    $conditionalValue = trim($_POST['conditional_value'] ?? '');
    $cssClass = trim($_POST['css_class'] ?? '');
    $layoutTypes = ['title', 'paragraph', 'divider', 'step', 'banner'];
    $isLayout = in_array($type, $layoutTypes, true) ? 1 : 0;

    if ($type === 'banner') {
        $defaultValue = trim($_POST['banner_url'] ?? '');
    }

    if ($label === '' && !in_array($type, ['divider', 'banner'], true)) {
        $errors[] = 'O nome do campo é obrigatório.';
    }

    $allowedTypes = [
        'text',
        'email',
        'phone',
        'number',
        'date',
        'textarea',
        'select',
        'radio',
        'checkbox',
        'title',
        'paragraph',
        'divider',
        'step',
        'banner'
    ];

    if (!in_array($type, $allowedTypes)) {
        $errors[] = 'Tipo de campo inválido.';
    }

    if (!in_array($width, [12, 6, 4], true)) {
        $errors[] = 'Largura de campo inválida.';
    }

    $allowedMasks = ['none', 'phone', 'cpf', 'cnpj', 'cep', 'money'];
    if (!in_array($mask, $allowedMasks, true)) {
        $errors[] = 'Máscara de campo inválida.';
    }

    $allowedConditionalOperators = ['equals', 'not_equals', 'contains', 'not_contains', 'filled', 'empty'];
    if ($conditionalEnabled && !in_array($conditionalOperator, $allowedConditionalOperators, true)) {
        $errors[] = 'Operador condicional inválido.';
    }

    if ($conditionalEnabled) {
        $stmt = $pdo->prepare(
            'SELECT id FROM form_fields
             WHERE id = ? AND form_id = ? AND tenant_id = ? AND is_layout = 0
             LIMIT 1'
        );
        $stmt->execute([$conditionalFieldId, $formId, $tenantId]);
        if (!$stmt->fetch()) {
            $errors[] = 'Selecione um campo de referência válido.';
        }
    }

    if (in_array($type, ['select', 'radio', 'checkbox']) && $options === '') {
        $errors[] = 'Esse tipo de campo precisa de opções.';
    }

    $bannerFile = $_FILES['banner_file'] ?? null;
    $hasBannerUpload = $bannerFile && ($bannerFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($type === 'banner' && !$hasBannerUpload && $defaultValue === '') {
        $errors[] = 'Informe a imagem do banner.';
    }

    if ($type === 'banner' && $hasBannerUpload) {
        if (($bannerFile['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || ($bannerFile['size'] ?? 0) > 5 * 1024 * 1024) {
            $errors[] = 'O banner deve ter no máximo 5 MB.';
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($bannerFile['tmp_name']);
            $bannerExtensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
            if (!isset($bannerExtensions[$mime])) {
                $errors[] = 'Envie um banner JPG, PNG, WEBP ou GIF.';
            }
        }
    }

    if ($isLayout) {
        $width = 12;
        $mask = 'none';
        $options = '';
        $isRequired = 0;
        $isReadonly = 0;
        $conditionalEnabled = 0;
        $conditionalFieldId = null;
        $conditionalOperator = null;
        $conditionalValue = '';
    }

    if (!$errors && $type === 'banner' && $hasBannerUpload) {
        $uploadDirectory = __DIR__ . '/../../../../public/assets/uploads/tenants/' . $tenantId . '/banners';
        if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0775, true)) {
            $errors[] = 'Não foi possível preparar a pasta do banner.';
        } else {
            $fileName = bin2hex(random_bytes(12)) . '.' . $bannerExtensions[$mime];
            if (!move_uploaded_file($bannerFile['tmp_name'], $uploadDirectory . '/' . $fileName)) {
                $errors[] = 'Não foi possível salvar o banner.';
            } else {
                $defaultValue = 'assets/uploads/tenants/' . $tenantId . '/banners/' . $fileName;
            }
        }
    }

    if (empty($errors)) {
        $fieldName = strtolower($label);
        $fieldName = preg_replace('/[áàãâä]/u', 'a', $fieldName);
        $fieldName = preg_replace('/[éèêë]/u', 'e', $fieldName);
        $fieldName = preg_replace('/[íìîï]/u', 'i', $fieldName);
        $fieldName = preg_replace('/[óòõôö]/u', 'o', $fieldName);
        $fieldName = preg_replace('/[úùûü]/u', 'u', $fieldName);
        $fieldName = preg_replace('/[ç]/u', 'c', $fieldName);
        $fieldName = preg_replace('/[^a-z0-9]+/i', '_', $fieldName);
        $fieldName = trim($fieldName, '_');

        $stmt = $pdo->prepare("SELECT COALESCE(MAX(field_order), 0) FROM form_fields WHERE form_id = ? AND tenant_id = ?");
        $stmt->execute([$formId, $tenantId]);
        $fieldOrder = $stmt->fetchColumn() + 1;

        $stmt = $pdo->prepare("
            INSERT INTO form_fields
            (tenant_id, form_id, label, name, type, is_layout, width, mask, placeholder, help_text,
             default_value, options, is_required, is_visible, is_readonly, conditional_enabled,
             conditional_field_id, conditional_operator, conditional_value, css_class, field_order)
            VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $tenantId,
            $formId,
            $label,
            $fieldName,
            $type,
            $isLayout,
            $width,
            $mask,
            $placeholder,
            $helpText !== '' ? $helpText : null,
            $defaultValue !== '' ? $defaultValue : null,
            $options,
            $isRequired,
            $isVisible,
            $isReadonly,
            $conditionalEnabled,
            $conditionalEnabled ? $conditionalFieldId : null,
            $conditionalEnabled ? $conditionalOperator : null,
            $conditionalEnabled && !in_array($conditionalOperator, ['filled', 'empty'], true) ? $conditionalValue : null,
            $cssClass !== '' ? $cssClass : null,
            $fieldOrder
        ]);

        redirectTo('form-edit', ['id' => $formId, 'tab' => 'fields', 'success' => 'field_added']);
    }
}

// Mensagens de sucesso
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'form_updated') {
        $success = 'Formulário atualizado com sucesso.';
    }

    if ($_GET['success'] === 'field_added') {
        $success = 'Campo adicionado com sucesso.';
    }


    if ($_GET['success'] === 'field_updated') {
        $success = 'Campo atualizado com sucesso.';
    }
    if ($_GET['success'] === 'field_deleted') {
        $success = 'Campo excluído com sucesso.';
    }
    if ($_GET['success'] === 'fields_deleted') {
        $deletedCount = max(0, (int) ($_GET['deleted'] ?? 0));
        $blockedCount = max(0, (int) ($_GET['blocked'] ?? 0));
        $success = $deletedCount === 1
            ? '1 campo selecionado foi excluído com sucesso.'
            : $deletedCount . ' campos selecionados foram excluídos com sucesso.';
        if ($blockedCount > 0) {
            $success .= ' ' . $blockedCount . ($blockedCount === 1
                ? ' campo não pôde ser excluído por possuir respostas ou ser usado em uma condição.'
                : ' campos não puderam ser excluídos por possuírem respostas ou serem usados em condições.');
        }
    }

    if ($_GET['success'] === 'condition_updated') {
        $success = 'Exibição condicional atualizada com sucesso.';
    }

    if ($_GET['success'] === 'publication_updated') {
        $success = 'Publicação atualizada com sucesso.';
    }

    if ($_GET['success'] === 'appearance_updated') {
        $success = 'Aparência atualizada com sucesso.';
    }


    if ($_GET['success'] === 'payment_updated') {
        $success = 'Pagamento atualizado com sucesso.';
    }
    if ($_GET['success'] === 'ticket_updated') {
        $success = 'Modelo de ingresso atualizado com sucesso.';
    }
    $pricingSuccessMessages = [
        'lot_saved' => 'Lote salvo com sucesso.',
        'lot_removed' => 'Lote removido ou desativado com sucesso.',
        'coupon_saved' => 'Cupom salvo com sucesso.',
        'coupon_removed' => 'Cupom removido ou desativado com sucesso.',
        'group_discount_saved' => 'Regra de desconto para grupo salva com sucesso.',
        'group_discount_removed' => 'Regra de desconto para grupo removida com sucesso.',
    ];
    if (isset($pricingSuccessMessages[$_GET['success']])) {
        $success = $pricingSuccessMessages[$_GET['success']];
    }
    if ($_GET['success'] === 'duplicated') {
        $success = 'Formulário duplicado com sucesso.';
    }
}


if (isset($_GET['error']) && $_GET['error'] === 'duplicate_failed') {
    $errors[] = 'Não foi possível duplicar o formulário.';
}
if (isset($_GET['error'])) {
    if ($_GET['error'] === 'field_has_answers') {
        $errors[] = 'Este campo não pode ser excluído porque já possui respostas vinculadas.';
    }

    if ($_GET['error'] === 'field_not_found') {
        $errors[] = 'Campo não encontrado.';
    }

    if ($_GET['error'] === 'field_is_condition_reference') {
        $errors[] = 'Este campo é referência de uma condição. Remova ou altere a condição antes de excluí-lo.';
    }

    if ($_GET['error'] === 'no_fields_selected') {
        $errors[] = 'Selecione pelo menos um campo para excluir.';
    }

    if ($_GET['error'] === 'selected_fields_blocked') {
        $errors[] = 'Os campos selecionados não puderam ser excluídos porque possuem respostas ou são usados em condições.';
    }
}

// Busca campos cadastrados
$stmt = $pdo->prepare("
    SELECT * FROM form_fields 
    WHERE form_id = ? AND tenant_id = ? 
    ORDER BY field_order ASC
");
$stmt->execute([$formId, $tenantId]);
$fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
$responseFields = array_values(array_filter($fields, fn ($field) => (int) ($field['is_layout'] ?? 0) !== 1 && !in_array($field['type'], ['title', 'paragraph', 'divider', 'step', 'banner'], true)));
$layoutFields = array_values(array_filter($fields, fn ($field) => (int) ($field['is_layout'] ?? 0) === 1 || in_array($field['type'], ['title', 'paragraph', 'divider', 'step', 'banner'], true)));

$stmt = $pdo->prepare("
    SELECT field_id, COUNT(*) AS total
    FROM form_response_answers
    WHERE tenant_id = ?
      AND field_id IN (
          SELECT id
          FROM form_fields
          WHERE tenant_id = ?
            AND form_id = ?
      )
    GROUP BY field_id
");
$stmt->execute([$tenantId, $tenantId, $formId]);

$fieldAnswersCount = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $fieldAnswersCount[$row['field_id']] = (int) $row['total'];
}

$widthLabels = [12 => 'Inteira', 6 => 'Metade', 4 => '1/3'];
$maskLabels = ['none' => '-', 'phone' => 'Telefone', 'cpf' => 'CPF', 'cnpj' => 'CNPJ', 'cep' => 'CEP', 'money' => 'Moeda'];
$fieldTypeLabels = ['text'=>'Texto','email'=>'E-mail','phone'=>'Telefone','number'=>'Número','date'=>'Data','textarea'=>'Texto longo','select'=>'Seleção','radio'=>'Escolha única','checkbox'=>'Múltipla escolha','step'=>'Etapa','title'=>'Título','paragraph'=>'Texto','divider'=>'Divisor','banner'=>'Banner'];

// Recarrega formulário atualizado
$stmt = $pdo->prepare("SELECT * FROM forms WHERE id = ? AND tenant_id = ?");
$stmt->execute([$formId, $tenantId]);
$form = $stmt->fetch(PDO::FETCH_ASSOC);

require __DIR__ . '/../../../layouts/admin-header.php';
require __DIR__ . '/../../../layouts/admin-sidebar.php';
?>
<style>
    .form-editor { max-width: 1180px; margin: 0 auto; }
    .form-editor .editor-kicker { color:#555555; font-size:12px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
    .form-editor .card { border:1px solid #CECECE; border-radius:12px; box-shadow:none!important; }
    .form-editor .form-editor-tabs { border-bottom:1px solid #dfe3e8; gap:28px; }
    .form-editor .form-editor-tabs .nav-link { color:#555555; border:0; border-bottom:2px solid transparent; border-radius:0; padding:12px 1px 11px; margin-bottom:-1px; font-size:14px; }
    .form-editor .form-editor-tabs .nav-link:hover { color:#212121; border-bottom-color:#CECECE; }
    .form-editor .form-editor-tabs .nav-link.active { color:#212121; background:transparent; border-bottom-color:#212121; }
    .form-editor .btn { border-radius:8px; }
    .form-editor .form-control,.form-editor .form-select,.form-editor .input-group-text { border-radius:8px; }
    .form-editor .table > :not(caption) > * > * { padding-top:14px; padding-bottom:14px; }
    .form-editor .section-heading { font-size:18px; font-weight:650; margin-bottom:20px; }
    .form-editor .builder-advanced { border-top:1px solid #CECECE; padding-top:14px; }
    .form-editor .builder-advanced summary { cursor:pointer; color:#475467; font-size:14px; font-weight:600; }
    .field-sort-list { display:grid; gap:8px; }
    .field-sort-item { display:flex; align-items:center; gap:12px; min-height:64px; padding:10px 12px; border:1px solid #CECECE; border-radius:10px; background:#fff; transition:border-color .15s,box-shadow .15s,opacity .15s; }
    .field-sort-item.dragging { opacity:.45; }
    .field-sort-item.drag-over { border-color:#CECECE; box-shadow:0 0 0 3px rgba(148,163,184,.14); }
    .drag-handle { border:0; background:transparent; color:#98a2b3; font-size:20px; letter-spacing:-4px; cursor:grab; padding:8px; }
    .drag-handle:active { cursor:grabbing; }
    .field-sort-main { min-width:0; flex:1; }
    .field-kind { color:#555555; font-size:12px; padding:2px 7px; border:1px solid #CECECE; border-radius:999px; }
    .field-sort-actions { display:flex; align-items:center; gap:6px; }
    .field-sort-actions form { margin:0; }
    .field-width-control { display:flex; align-items:center; gap:7px; color:#555555; font-size:12px; white-space:nowrap; }
    .field-width-control select { width:auto; min-width:112px; height:34px; padding:4px 30px 4px 9px; border-radius:7px; font-size:12px; }
    .field-banner-thumb { width:72px; height:44px; object-fit:cover; border-radius:7px; border:1px solid #CECECE; }
    @media(max-width:768px){.form-editor .form-editor-tabs{gap:20px}.form-editor .card-body{padding:20px!important}}
    @media(max-width:640px){.field-sort-item{align-items:flex-start;flex-wrap:wrap}.field-sort-main{min-width:calc(100% - 50px)}.field-sort-actions{width:100%;justify-content:flex-end;padding-left:42px}}
</style>
<div class="form-editor">

    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <a href="forms" class="text-decoration-none">← Voltar para formulários</a>
            <div class="editor-kicker mt-4 mb-1">Formulário</div>
            <div class="d-flex align-items-center gap-2">
                <h1 class="h3 mb-0"><?= htmlspecialchars($form['title']) ?></h1>
                <span class="badge <?= isFormCompleted($form) ? 'bg-primary' : ($form['is_active'] ? 'bg-success' : 'bg-secondary') ?>"><?= htmlspecialchars(formStatusLabel($form)) ?></span>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="<?= htmlspecialchars($publicUrl) ?>" target="_blank" class="btn btn-dark">Abrir formulário</a>
            <button type="button" class="btn btn-outline-secondary" data-copy-url="<?= htmlspecialchars($publicUrl) ?>" onclick="navigator.clipboard.writeText(this.dataset.copyUrl)">Copiar URL</button>
            <form method="post" action="<?= htmlspecialchars(appUrl('form-duplicate')) ?>" data-confirm="Duplicar este formulário?">
                <input type="hidden" name="id" value="<?= (int) $formId ?>">
                <button class="btn btn-outline-primary" type="submit">Duplicar formulário</button>
            </form>
        </div>
    </div>

    <?php
        $editorTabs = ['general' => 'Geral', 'publication' => 'Publicação', 'payment' => 'Pagamento', 'ticket' => 'Ingresso', 'fields' => 'Construtor', 'appearance' => 'Aparência'];
        if ((int)($form['payment_enabled'] ?? 0) !== 1) {
            unset($editorTabs['payment']);
        }
    ?>    <nav class="nav form-editor-tabs flex-nowrap overflow-auto mb-4">
        <?php foreach ($editorTabs as $tabKey => $tabLabel): ?>
            <a class="nav-link text-nowrap <?= $activeTab === $tabKey ? 'active fw-semibold' : '' ?>" href="<?= htmlspecialchars(appUrl('form-edit', ['id' => $formId, 'tab' => $tabKey])) ?>"><?= $tabLabel ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $error): ?>
                <div><?= htmlspecialchars($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <?php if (in_array($activeTab, ['general', 'publication'], true)): ?>
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body p-4">

                    <?php if ($activeTab === 'general'): ?>
                    <h2 class="section-heading">Configurações gerais</h2>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_form">
                        <input type="hidden" name="tab" value="general">

                        <div class="mb-3">
                            <label for="title" class="form-label">Título</label>
                            <input 
                                type="text" 
                                name="title" 
                                id="title" 
                                class="form-control"
                                value="<?= htmlspecialchars($form['title']) ?>"
                                required
                            >
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Descrição</label>
                            <textarea 
                                name="description" 
                                id="description" 
                                class="form-control"
                                rows="4"
                            ><?= htmlspecialchars($form['description'] ?? '') ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="type" class="form-label">Tipo</label>
                            <select name="type" id="type" class="form-select">
                                <?php foreach (['inscricao'=>'Inscrição','pesquisa'=>'Pesquisa','contato'=>'Contato','cadastro'=>'Cadastro','evento'=>'Evento','outro'=>'Outro'] as $formType => $formTypeLabel): ?>
                                    <option value="<?= $formType ?>" <?= $form['type'] === $formType ? 'selected' : '' ?>><?= $formTypeLabel ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="form_group_id" class="form-label">Grupo</label>
                            <select name="form_group_id" id="form_group_id" class="form-select">
                                <?php if (!shouldScopeTenantUserToGroup()): ?><option value="">Sem grupo</option><?php endif; ?>
                                <?php $selectedEditGroup = filter_input(INPUT_POST, 'form_group_id', FILTER_VALIDATE_INT) ?: (int) ($form['form_group_id'] ?? 0); ?>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?= (int) $group['id'] ?>" <?= (int) $selectedEditGroup === (int) $group['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($group['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Organize este formulário dentro de uma área/departamento.</div>
                        </div>

                        <div class="form-check form-switch mb-4">
                            <input 
                                class="form-check-input" 
                                type="checkbox" 
                                name="is_active" 
                                id="is_active"
                                <?= $form['is_active'] ? 'checked' : '' ?>
                            >
                            <label class="form-check-label" for="is_active">
                                Formulário ativo
                            </label>
                        </div>

                        <div class="form-check form-switch mb-4">
                            <input 
                                class="form-check-input" 
                                type="checkbox" 
                                name="payment_enabled" 
                                id="payment_enabled"
                                <?= ((int)($_POST['payment_enabled'] ?? $form['payment_enabled'] ?? 0) === 1) ? 'checked' : '' ?>
                            >
                            <label class="form-check-label" for="payment_enabled">
                                Ativar pagamento neste formulário
                            </label>
                            <div class="form-text">Quando ativo, a aba Pagamento aparece para configurar valor, Pix e instruções.</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            Salvar alterações
                        </button>
                    </form>
                    <?php else: ?>

                    <h2 class="section-heading">Publicação</h2>

                    <form method="post" class="mb-4">
                        <input type="hidden" name="action" value="update_publication">
                        <input type="hidden" name="tab" value="publication">
                        <div class="mb-3">
                            <label class="form-label" for="closes_at">Data e hora limite</label>
                            <input class="form-control" type="datetime-local" name="closes_at" id="closes_at" value="<?= htmlspecialchars($_POST['closes_at'] ?? formatDateTimeLocal($form['closes_at'] ?? null)) ?>">
                            <div class="form-text">Depois desse horário, o formulário fica concluído e bloqueia novas respostas.</div>
                        </div>
                        <label for="slug" class="form-label">Slug público</label>
                        <div class="input-group">
                            <span class="input-group-text">/f/<?= htmlspecialchars($tenantSlug) ?>/</span>
                            <input type="text" id="slug" name="slug" class="form-control" value="<?= htmlspecialchars($_POST['slug'] ?? $form['slug']) ?>" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" required>
                            <button class="btn btn-primary" type="submit">Salvar URL</button>
                        </div>
                        <div class="form-check form-switch mt-3">
                            <input class="form-check-input" type="checkbox" name="allow_multiple_people" id="allow_multiple_people" <?= ((int)($_POST['allow_multiple_people'] ?? $form['allow_multiple_people'] ?? 0) === 1) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="allow_multiple_people">Permitir inscrição de mais de uma pessoa no mesmo envio</label>
                            <div class="form-text">Quando ativo, o formulário pergunta quantas pessoas serão inscritas e exibe abas Pessoa 1, Pessoa 2...</div>
                        </div>
                        <button class="btn btn-primary mt-3" type="submit">Salvar publicação</button>
                    </form>

                    <div>
                        <label class="form-label">Link público</label>

                        <div class="input-group">
                            <input 
                                type="text" 
                                class="form-control"
                                value="<?= htmlspecialchars($publicUrl) ?>"
                                readonly
                            >

                            <a 
                                href="<?= htmlspecialchars($publicUrl) ?>"
                                target="_blank" 
                                class="btn btn-outline-success"
                            >
                                Abrir
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            </div>

            <?php if ($activeTab === 'publication'): ?>
            <div class="card shadow-sm mt-4">
                <div class="card-body p-4 text-center">
                    <h2 class="section-heading mb-2">QR Code</h2>
                    <p class="text-muted small">Arquivo pronto para materiais digitais e impressos.</p>
                    <img src="<?= htmlspecialchars($qrUrl) ?>" alt="QR Code do formulário" class="img-fluid mb-3" style="max-width:220px">
                    <div class="d-flex flex-wrap justify-content-center gap-2">
                        <a href="<?= htmlspecialchars($qrUrl) ?>" target="_blank" rel="noopener" class="btn btn-outline-primary">Abrir QR Code</a>
                        <button type="button" class="btn btn-outline-secondary" data-copy-url="<?= htmlspecialchars($publicUrl) ?>" onclick="navigator.clipboard.writeText(this.dataset.copyUrl)">Copiar link</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($activeTab === 'payment'): ?>
        <?php require __DIR__ . '/pricing-panel.php'; ?>
        <?php endif; ?>

        <?php if ($activeTab === 'ticket'): ?>
        <?php
            $ticketBackground = formAssetUrl($form['ticket_background_path'] ?? '');
            $ticketTitlePreview = trim((string) ($_POST['ticket_title'] ?? ($form['ticket_title'] ?? ''))) ?: $form['title'];
            $ticketSubtitlePreview = trim((string) ($_POST['ticket_subtitle'] ?? ($form['ticket_subtitle'] ?? ''))) ?: 'Seu acesso confirmado ao evento';
            $ticketPrimaryPreview = ticketColor($_POST['ticket_primary_color'] ?? ($form['ticket_primary_color'] ?? null), '#212121');
            $ticketTextPreview = ticketColor($_POST['ticket_text_color'] ?? ($form['ticket_text_color'] ?? null), '#212121');
            $ticketEventPreview = $_POST['ticket_event_at'] ?? formatDateTimeLocal($form['ticket_event_at'] ?? null);
            $ticketLocationPreview = trim((string) ($_POST['ticket_location'] ?? ($form['ticket_location'] ?? ''))) ?: 'Local do evento';
            $selectedNameField = (int) ($_POST['ticket_name_field_id'] ?? ($form['ticket_name_field_id'] ?? 0));
            $selectedEmailField = (int) ($_POST['ticket_email_field_id'] ?? ($form['ticket_email_field_id'] ?? 0));
            $ticketEnabledChecked = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_ticket'
                ? isset($_POST['ticket_enabled'])
                : (int) ($form['ticket_enabled'] ?? 0) === 1;
        ?>
        <style>
            .ticket-editor-layout{display:grid;grid-template-columns:minmax(0,1fr)420px;gap:24px;align-items:start}.ticket-config-stack{display:grid;gap:20px}.ticket-config-card{background:#fff;border:1px solid #CECECE;border-radius:16px;padding:24px}.ticket-config-card h2{font-size:18px;font-weight:800;margin:0 0 5px}.ticket-config-card>p{color:#555555;font-size:14px;margin:0 0 20px}.ticket-toggle{display:flex;align-items:flex-start;gap:12px;border:1px solid #CECECE;background:#F3F3F3;border-radius:14px;padding:16px}.ticket-toggle input{width:42px;height:22px;flex:0 0 auto}.ticket-upload{display:block;border:1px dashed #CECECE;border-radius:14px;padding:20px;text-align:center;background:#F3F3F3;cursor:pointer}.ticket-upload input{position:absolute;opacity:0;pointer-events:none}.ticket-upload strong{display:block;margin-bottom:5px}.ticket-preview-shell{position:sticky;top:24px;background:#fff;border:1px solid #CECECE;border-radius:16px;padding:20px}.ticket-preview-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:15px}.ticket-preview-head h2{font-size:16px;margin:0;font-weight:800}.ticket-preview{position:relative;overflow:hidden;min-height:520px;border-radius:22px;background:linear-gradient(145deg,color-mix(in srgb,var(--tp) 18%,#fff),#fff 62%);color:var(--tt);box-shadow:0 22px 44px rgba(15,23,42,.14)}.ticket-preview.has-image::after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(15,23,42,.42),rgba(15,23,42,.82));z-index:1}.ticket-preview-bg{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}.ticket-preview-content{position:relative;z-index:2;min-height:520px;padding:34px;display:flex;flex-direction:column;justify-content:space-between}.ticket-preview.has-image .ticket-preview-content{color:#fff}.ticket-preview-kicker{text-transform:uppercase;letter-spacing:.14em;font-size:10px;font-weight:900;opacity:.7}.ticket-preview-title{font-size:35px;line-height:1;letter-spacing:-.05em;font-weight:900;margin:10px 0}.ticket-preview-subtitle{font-size:14px;opacity:.78}.ticket-preview-details{display:grid;gap:16px}.ticket-preview-detail span{display:block;text-transform:uppercase;letter-spacing:.08em;font-size:9px;font-weight:900;opacity:.6}.ticket-preview-detail strong{font-size:15px}.ticket-preview-code{border-top:1px dashed currentColor;padding-top:18px;display:flex;justify-content:space-between;align-items:end;gap:12px}.ticket-preview-qr{width:84px;height:84px;background:repeating-linear-gradient(45deg,#111 0 4px,#fff 4px 8px);border:8px solid #fff}.ticket-color-row{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.ticket-color-control{display:flex;align-items:center;gap:10px;border:1px solid #CECECE;border-radius:10px;padding:8px}.ticket-color-control input[type=color]{width:38px;height:38px;border:0;padding:0;background:none}.ticket-color-control input[type=text]{border:0;outline:0;min-width:0;width:100%;font-weight:800;text-transform:uppercase}.ticket-save{min-height:48px;font-weight:800}.ticket-field-note{font-size:12px;color:#555555;margin-top:6px}@media(max-width:1100px){.ticket-editor-layout{grid-template-columns:1fr}.ticket-preview-shell{position:static}.ticket-preview{max-width:560px}.ticket-preview-content{min-height:460px}}@media(max-width:640px){.ticket-color-row{grid-template-columns:1fr}.ticket-config-card{padding:18px}}
        </style>
        <form method="post" enctype="multipart/form-data" id="ticketModelForm" class="col-12">
            <input type="hidden" name="action" value="update_ticket"><input type="hidden" name="tab" value="ticket">
            <div class="ticket-editor-layout">
                <div class="ticket-config-stack">
                    <section class="ticket-config-card">
                        <h2>Modo ingresso</h2><p>Emita um ingresso individual quando a inscrição estiver aprovada.</p>
                        <label class="ticket-toggle"><input type="checkbox" name="ticket_enabled" id="ticket_enabled" <?= $ticketEnabledChecked ? 'checked' : '' ?>><span><strong>Ativar emissão de ingressos</strong><br><small class="text-muted">Inscrições gratuitas recebem o ingresso ao concluir. Inscrições pagas recebem após a aprovação.</small></span></label>
                    </section>
                    <section class="ticket-config-card">
                        <h2>Conteúdo do ingresso</h2><p>Defina as informações apresentadas ao participante.</p>
                        <div class="row g-3">
                            <div class="col-md-7"><label class="form-label" for="ticket_title">Título do evento</label><input class="form-control js-ticket-text" data-ticket-target="title" id="ticket_title" name="ticket_title" maxlength="160" value="<?= htmlspecialchars($_POST['ticket_title'] ?? ($form['ticket_title'] ?? '')) ?>" placeholder="<?= htmlspecialchars($form['title']) ?>"></div>
                            <div class="col-md-5"><label class="form-label" for="ticket_event_at">Data e hora</label><input class="form-control js-ticket-text" data-ticket-target="date" type="datetime-local" id="ticket_event_at" name="ticket_event_at" value="<?= htmlspecialchars($ticketEventPreview) ?>"></div>
                            <div class="col-12"><label class="form-label" for="ticket_subtitle">Subtítulo</label><input class="form-control js-ticket-text" data-ticket-target="subtitle" id="ticket_subtitle" name="ticket_subtitle" maxlength="255" value="<?= htmlspecialchars($_POST['ticket_subtitle'] ?? ($form['ticket_subtitle'] ?? '')) ?>" placeholder="Seu acesso confirmado ao evento"></div>
                            <div class="col-12"><label class="form-label" for="ticket_location">Local</label><input class="form-control js-ticket-text" data-ticket-target="location" id="ticket_location" name="ticket_location" maxlength="255" value="<?= htmlspecialchars($_POST['ticket_location'] ?? ($form['ticket_location'] ?? '')) ?>" placeholder="Nome e endereço do local"></div>
                            <div class="col-12"><label class="form-label" for="ticket_instructions">Instruções ao participante</label><textarea class="form-control" id="ticket_instructions" name="ticket_instructions" rows="3" placeholder="Apresente este QR Code na entrada."><?= htmlspecialchars($_POST['ticket_instructions'] ?? ($form['ticket_instructions'] ?? '')) ?></textarea></div>
                        </div>
                    </section>
                    <section class="ticket-config-card">
                        <h2>Dados do participante</h2><p>Escolha de quais campos virão o nome impresso e o destinatário do e-mail.</p>
                        <?php if (!$responseFields): ?><div class="alert alert-warning">Adicione campos de resposta no Construtor antes de ativar os ingressos.</div><?php endif; ?>
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label" for="ticket_name_field_id">Campo com o nome</label><select class="form-select" id="ticket_name_field_id" name="ticket_name_field_id"><option value="">Selecione</option><?php foreach ($responseFields as $field): ?><option value="<?= (int) $field['id'] ?>" <?= $selectedNameField === (int) $field['id'] ? 'selected' : '' ?>><?= htmlspecialchars($field['label']) ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6"><label class="form-label" for="ticket_email_field_id">Campo com o e-mail</label><select class="form-select" id="ticket_email_field_id" name="ticket_email_field_id"><option value="">Selecione</option><?php foreach ($responseFields as $field): ?><option value="<?= (int) $field['id'] ?>" <?= $selectedEmailField === (int) $field['id'] ? 'selected' : '' ?>><?= htmlspecialchars($field['label']) ?><?= $field['type'] === 'email' ? ' · E-mail' : '' ?></option><?php endforeach; ?></select><div class="ticket-field-note">O servidor precisa estar configurado para envio de e-mails.</div></div>
                        </div>
                    </section>
                    <section class="ticket-config-card">
                        <h2>Mensagem de entrega</h2><p>Personalize o e-mail enviado após a emissão.</p>
                        <div class="row g-3"><div class="col-12"><label class="form-label" for="ticket_email_subject">Assunto</label><input class="form-control" id="ticket_email_subject" name="ticket_email_subject" maxlength="190" value="<?= htmlspecialchars($_POST['ticket_email_subject'] ?? ($form['ticket_email_subject'] ?? '')) ?>" placeholder="Seu ingresso para <?= htmlspecialchars($ticketTitlePreview) ?>"></div><div class="col-12"><label class="form-label" for="ticket_email_message">Mensagem</label><textarea class="form-control" id="ticket_email_message" name="ticket_email_message" rows="4" placeholder="Sua inscrição foi aprovada. Acesse seu ingresso no botão abaixo."><?= htmlspecialchars($_POST['ticket_email_message'] ?? ($form['ticket_email_message'] ?? '')) ?></textarea></div></div>
                    </section>
                    <section class="ticket-config-card">
                        <h2>Visual</h2><p>Use cores próprias ou envie uma arte como fundo do ingresso.</p>
                        <label class="ticket-upload" for="ticket_background_file"><strong>Enviar imagem de fundo</strong><span class="text-muted small">JPG ou PNG, recomendado 1600 × 900 px, máximo 5MB.</span><input type="file" id="ticket_background_file" name="ticket_background_file" accept=".jpg,.jpeg,.png,image/jpeg,image/png"></label>
                        <?php if ($ticketBackground): ?><label class="form-check mt-3"><input class="form-check-input" type="checkbox" name="remove_ticket_background" value="1"> Remover imagem atual</label><?php endif; ?>
                        <div class="ticket-color-row mt-3">
                            <?php foreach (['ticket_primary_color' => ['Cor principal', $ticketPrimaryPreview], 'ticket_text_color' => ['Cor do texto', $ticketTextPreview]] as $colorName => [$colorLabel, $colorValue]): ?>
                                <div><label class="form-label" for="<?= $colorName ?>"><?= $colorLabel ?></label><div class="ticket-color-control"><input type="color" id="<?= $colorName ?>" name="<?= $colorName ?>" value="<?= htmlspecialchars($colorValue) ?>" data-ticket-color="<?= $colorName ?>"><input type="text" value="<?= htmlspecialchars($colorValue) ?>" maxlength="7" data-ticket-color-text="<?= $colorName ?>"></div></div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <button class="btn btn-primary ticket-save" type="submit">Salvar modelo de ingresso</button>
                </div>
                <aside class="ticket-preview-shell">
                    <div class="ticket-preview-head"><h2>Prévia do ingresso</h2><span class="badge text-bg-light border">Ao vivo</span></div>
                    <div class="ticket-preview <?= $ticketBackground ? 'has-image' : '' ?>" id="ticketPreview" style="--tp:<?= htmlspecialchars($ticketPrimaryPreview) ?>;--tt:<?= htmlspecialchars($ticketTextPreview) ?>">
                        <?php if ($ticketBackground): ?><img class="ticket-preview-bg" id="ticketPreviewBackground" src="<?= htmlspecialchars($ticketBackground) ?>" alt=""><?php else: ?><img class="ticket-preview-bg d-none" id="ticketPreviewBackground" src="" alt=""><?php endif; ?>
                        <div class="ticket-preview-content"><div><div class="ticket-preview-kicker">Ingresso individual</div><div class="ticket-preview-title" data-ticket-preview="title"><?= htmlspecialchars($ticketTitlePreview) ?></div><div class="ticket-preview-subtitle" data-ticket-preview="subtitle"><?= htmlspecialchars($ticketSubtitlePreview) ?></div></div><div class="ticket-preview-details"><div class="ticket-preview-detail"><span>Participante</span><strong>Nome do participante</strong></div><div class="ticket-preview-detail"><span>Data e hora</span><strong data-ticket-preview="date"><?= $ticketEventPreview ? htmlspecialchars(date('d/m/Y H:i', strtotime($ticketEventPreview))) : 'Data do evento' ?></strong></div><div class="ticket-preview-detail"><span>Local</span><strong data-ticket-preview="location"><?= htmlspecialchars($ticketLocationPreview) ?></strong></div><div class="ticket-preview-code"><div><div class="ticket-preview-kicker">Código</div><strong>ING-A1B2C3D4E5F6</strong></div><div class="ticket-preview-qr"></div></div></div></div>
                    </div>
                </aside>
            </div>
        </form>
        <script>
        (()=>{const preview=document.getElementById('ticketPreview');const defaults={title:<?= json_encode($form['title'], JSON_UNESCAPED_UNICODE) ?>,subtitle:'Seu acesso confirmado ao evento',location:'Local do evento',date:'Data do evento'};document.querySelectorAll('.js-ticket-text').forEach(input=>input.addEventListener('input',()=>{const target=document.querySelector(`[data-ticket-preview="${input.dataset.ticketTarget}"]`);if(!target)return;let value=input.value.trim();if(input.dataset.ticketTarget==='date'&&value){const date=new Date(value);value=Number.isNaN(date.getTime())?value:date.toLocaleString('pt-BR',{dateStyle:'short',timeStyle:'short'})}target.textContent=value||defaults[input.dataset.ticketTarget]}));const vars={ticket_primary_color:'--tp',ticket_text_color:'--tt'};document.querySelectorAll('[data-ticket-color]').forEach(color=>{const textInput=document.querySelector(`[data-ticket-color-text="${color.dataset.ticketColor}"]`);const sync=value=>{if(/^#[0-9a-fA-F]{6}$/.test(value)){color.value=value;textInput.value=value.toUpperCase();preview.style.setProperty(vars[color.dataset.ticketColor],value)}};color.addEventListener('input',()=>sync(color.value));textInput.addEventListener('input',()=>sync(textInput.value))});document.getElementById('ticket_background_file')?.addEventListener('change',event=>{const file=event.target.files?.[0];if(!file)return;const image=document.getElementById('ticketPreviewBackground');image.src=URL.createObjectURL(file);image.classList.remove('d-none');preview.classList.add('has-image')})})();
        </script>
        <?php endif; ?>

        <?php if ($activeTab === 'fields'): ?>
        <div class="col-12">

            <div class="card shadow-sm mb-4">
                <div class="card-body p-4">

                    <h2 class="section-heading">Adicionar ao formulário</h2>

                    <form method="POST">
                        <input type="hidden" name="action" value="add_field">
                        <input type="hidden" name="tab" value="fields">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="label" class="form-label">Nome ou título</label>
                                <input type="text" name="label" id="label" class="form-control" placeholder="Ex: Nome completo ou Dados pessoais" value="<?= htmlspecialchars($_POST['label'] ?? '') ?>">
                            </div>

                            <div class="col-md-6">
                                <label for="field_type" class="form-label">Tipo do campo</label>
                                <select name="field_type" id="field_type" class="form-select">
                                    <optgroup label="Campos de resposta">
                                        <?php foreach (['text' => 'Texto curto', 'email' => 'E-mail', 'phone' => 'Telefone/WhatsApp', 'number' => 'Número', 'date' => 'Data', 'textarea' => 'Texto longo', 'select' => 'Lista de seleção', 'radio' => 'Escolha única', 'checkbox' => 'Múltipla escolha'] as $typeValue => $typeLabel): ?>
                                            <option value="<?= $typeValue ?>" <?= ($_POST['field_type'] ?? 'text') === $typeValue ? 'selected' : '' ?>><?= $typeLabel ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <optgroup label="Organização visual">
                                        <?php foreach (['step' => 'Etapa', 'title' => 'Título de seção', 'paragraph' => 'Texto explicativo', 'divider' => 'Linha divisória', 'banner' => 'Banner com imagem'] as $typeValue => $typeLabel): ?>
                                            <option value="<?= $typeValue ?>" <?= ($_POST['field_type'] ?? '') === $typeValue ? 'selected' : '' ?>><?= $typeLabel ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                </select>
                                <div class="form-text">Escolha um campo de resposta ou um bloco visual.</div>
                            </div>

                            <?php if ($activeTab === 'fields'): ?>
                            <div class="col-md-6 builder-response">
                                <label for="placeholder" class="form-label">Placeholder</label>
                                <input type="text" name="placeholder" id="placeholder" class="form-control" placeholder="Ex: Digite seu nome completo" value="<?= htmlspecialchars($_POST['placeholder'] ?? '') ?>">
                            </div>
                            <?php endif; ?>

                            <div class="col-md-6">
                                <label for="help_text" class="form-label">Texto de ajuda</label>
                                <input type="text" name="help_text" id="help_text" class="form-control" placeholder="Ex: Usaremos apenas para contato" value="<?= htmlspecialchars($_POST['help_text'] ?? '') ?>">
                            </div>

                            <div class="col-12 builder-banner d-none">
                                <label for="banner_url" class="form-label">Imagem do banner</label>
                                <input type="file" name="banner_file" id="banner_file" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
                                <div class="form-text mb-2">JPG, PNG, WEBP ou GIF, até 5 MB.</div>
                                <input type="text" name="banner_url" id="banner_url" class="form-control" placeholder="Ou cole uma URL/caminho de imagem" value="<?= htmlspecialchars($_POST['banner_url'] ?? '') ?>">
                            </div>

                            <?php if ($activeTab === 'fields'): ?>
                            <div class="col-md-6 builder-advanced-item">
                                <label for="width" class="form-label">Largura</label>
                                <select name="width" id="width" class="form-select">
                                    <option value="12" <?= ($_POST['width'] ?? '12') === '12' ? 'selected' : '' ?>>Linha inteira</option>
                                    <option value="6" <?= ($_POST['width'] ?? '') === '6' ? 'selected' : '' ?>>Metade da linha</option>
                                    <option value="4" <?= ($_POST['width'] ?? '') === '4' ? 'selected' : '' ?>>Um terço da linha</option>
                                </select>
                            </div>

                            <div class="col-md-6 builder-advanced-item">
                                <label for="mask" class="form-label">Máscara</label>
                                <select name="mask" id="mask" class="form-select">
                                    <?php foreach (['none' => 'Sem máscara', 'phone' => 'Telefone/WhatsApp', 'cpf' => 'CPF', 'cnpj' => 'CNPJ', 'cep' => 'CEP', 'money' => 'Moeda'] as $maskValue => $maskLabel): ?>
                                        <option value="<?= $maskValue ?>" <?= ($_POST['mask'] ?? 'none') === $maskValue ? 'selected' : '' ?>><?= $maskLabel ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <?php if ($activeTab === 'fields'): ?>
                            <div class="col-12 builder-advanced-item">
                                <label for="default_value" class="form-label">Valor padrão</label>
                                <input type="text" name="default_value" id="default_value" class="form-control" value="<?= htmlspecialchars($_POST['default_value'] ?? '') ?>">
                            </div>

                            <div class="col-12 builder-options">
                                <label for="options" class="form-label">Opções</label>
                                <textarea name="options" id="options" class="form-control" rows="4" placeholder="Use uma opção por linha. Ex:&#10;Sim&#10;Não&#10;Talvez"><?= htmlspecialchars($_POST['options'] ?? '') ?></textarea>
                                <div class="form-text">Use apenas para lista de seleção, escolha única ou múltipla escolha.</div>
                            </div>

                            <div class="col-12 builder-advanced-item">
                                <label for="css_class" class="form-label">Classe CSS extra <span class="text-muted fw-normal">(opcional)</span></label>
                                <input type="text" name="css_class" id="css_class" class="form-control" placeholder="Ex: campo-destaque" value="<?= htmlspecialchars($_POST['css_class'] ?? '') ?>">
                            </div>

                            <div class="col-12 builder-advanced-item conditional-builder">
                                <div class="border rounded-3 p-3">
                                    <div class="fw-semibold mb-2">Exibição condicional</div>
                                    <div class="form-check mb-3">
                                        <input class="form-check-input conditional-enabled-toggle" type="checkbox" name="conditional_enabled" id="conditional_enabled" <?= isset($_POST['conditional_enabled']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="conditional_enabled">Exibir este campo apenas se uma condição for atendida</label>
                                    </div>
                                    <div class="row g-3 conditional-settings">
                                        <div class="col-md-5">
                                            <label class="form-label" for="conditional_field_id">Campo de referência</label>
                                            <select class="form-select" name="conditional_field_id" id="conditional_field_id">
                                                <option value="">Selecione um campo anterior</option>
                                                <?php foreach ($existingResponseFields as $referenceField): ?>
                                                    <option value="<?= (int) $referenceField['id'] ?>" <?= (int) ($_POST['conditional_field_id'] ?? 0) === (int) $referenceField['id'] ? 'selected' : '' ?>><?= htmlspecialchars($referenceField['label']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label" for="conditional_operator">Condição</label>
                                            <select class="form-select conditional-operator" name="conditional_operator" id="conditional_operator">
                                                <?php foreach (['equals'=>'É igual a','not_equals'=>'É diferente de','contains'=>'Contém','not_contains'=>'Não contém','filled'=>'Está preenchido','empty'=>'Está vazio'] as $operatorValue => $operatorLabel): ?>
                                                    <option value="<?= $operatorValue ?>" <?= ($_POST['conditional_operator'] ?? 'equals') === $operatorValue ? 'selected' : '' ?>><?= $operatorLabel ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4 conditional-value-group">
                                            <label class="form-label" for="conditional_value">Valor esperado</label>
                                            <input class="form-control" name="conditional_value" id="conditional_value" value="<?= htmlspecialchars($_POST['conditional_value'] ?? '') ?>" placeholder="Ex: Sim">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12 d-flex flex-wrap gap-4 builder-flags">
                                <div class="form-check builder-response-flag"><input class="form-check-input" type="checkbox" name="is_required" id="is_required" <?= isset($_POST['is_required']) ? 'checked' : '' ?>><label class="form-check-label" for="is_required">Obrigatório</label></div>
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="is_visible" id="is_visible" <?= ($_POST['action'] ?? '') !== 'add_field' || isset($_POST['is_visible']) ? 'checked' : '' ?>><label class="form-check-label" for="is_visible">Visível no formulário</label></div>
                                <div class="form-check builder-advanced-item"><input class="form-check-input" type="checkbox" name="is_readonly" id="is_readonly" <?= isset($_POST['is_readonly']) ? 'checked' : '' ?>><label class="form-check-label" for="is_readonly">Somente leitura</label></div>
                            </div>
                            <?php else: ?>
                            <div class="col-12">
                                <label for="default_value" class="form-label">Texto complementar</label>
                                <input type="text" name="default_value" id="default_value" class="form-control" value="<?= htmlspecialchars($_POST['default_value'] ?? '') ?>" placeholder="Usado principalmente no texto explicativo">
                            </div>
                            <div class="col-12">
                                <div class="form-check"><input class="form-check-input" type="checkbox" name="is_visible" id="is_visible" checked><label class="form-check-label" for="is_visible">Visível no formulário</label></div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <details class="builder-advanced mt-3" id="builder-advanced">
                            <summary>Opções avançadas</summary>
                            <div class="row g-3 mt-1" id="builder-advanced-body"></div>
                        </details>

                        <button type="submit" class="btn btn-dark mt-4 px-4">
                            Adicionar
                        </button>
                    </form>

                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-body p-4">

                    <?php $tabFields = $fields; ?>
                    <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                        <div><h2 class="section-heading mb-1">Ordem do formulário</h2><div class="small text-muted">Arraste os itens ou use as setas.</div></div>
                        <span id="order-status" class="small text-muted"></span>
                    </div>

                    <?php if (count($tabFields) > 0): ?>

                        <form id="bulk-delete-fields-form" method="post" action="<?= htmlspecialchars(appUrl('form-field-delete')) ?>" class="d-flex flex-wrap align-items-center gap-3 mb-3" data-confirm="Excluir os campos selecionados? Esta ação não poderá ser desfeita." data-confirm-button="Excluir selecionados">
                            <input type="hidden" name="form_id" value="<?= (int) $formId ?>">
                            <input type="hidden" name="return_tab" value="fields">
                            <input type="hidden" name="bulk_delete" value="1">
                            <label class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" id="select-all-fields">
                                <span class="form-check-label">Selecionar todos disponíveis</span>
                            </label>
                            <button type="submit" class="btn btn-sm btn-outline-danger" id="bulk-delete-fields-button" disabled>
                                Excluir selecionados (<span id="bulk-delete-fields-count">0</span>)
                            </button>
                        </form>

                        <div id="sortable-fields" class="field-sort-list" data-form-id="<?= (int) $formId ?>" data-reorder-url="<?= htmlspecialchars(appUrl('form-fields-reorder')) ?>" data-width-url="<?= htmlspecialchars(appUrl('form-field-width')) ?>">
                            <?php $previousResponseFields = []; foreach ($tabFields as $field): ?>
                                <?php $hasAnswers = ($fieldAnswersCount[$field['id']] ?? 0) > 0; $isLayoutField = (int) ($field['is_layout'] ?? 0) === 1 || in_array($field['type'], ['title','paragraph','divider','step','banner'], true); ?>
                                <div class="field-sort-item" draggable="true" data-field-id="<?= (int) $field['id'] ?>">
                                    <input class="form-check-input field-bulk-select flex-shrink-0" type="checkbox" name="field_ids[]" value="<?= (int) $field['id'] ?>" form="bulk-delete-fields-form" draggable="false" aria-label="Selecionar <?= htmlspecialchars($field['label'] !== '' ? $field['label'] : 'item do formulário') ?>" <?= $hasAnswers ? 'disabled title="Este campo possui respostas"' : '' ?>>
                                    <button type="button" class="drag-handle" aria-label="Arrastar">⋮⋮</button>
                                    <?php if ($field['type'] === 'banner' && !empty($field['default_value'])): ?><?php $thumbSrc = preg_match('#^(https?:)?//#i', $field['default_value']) || str_starts_with($field['default_value'], '/') ? $field['default_value'] : appUrl($field['default_value']); ?><img class="field-banner-thumb" src="<?= htmlspecialchars($thumbSrc) ?>" alt=""><?php endif; ?>
                                    <div class="field-sort-main">
                                        <div class="d-flex flex-wrap align-items-center gap-2"><strong><?= htmlspecialchars($field['type'] === 'divider' && $field['label'] === '' ? 'Linha divisória' : $field['label']) ?></strong><span class="field-kind"><?= htmlspecialchars($fieldTypeLabels[$field['type']] ?? $field['type']) ?></span><?php if (!(int)($field['is_visible'] ?? 1)): ?><span class="badge bg-secondary">Oculto</span><?php endif; ?><?php if ((int)($field['conditional_enabled'] ?? 0) === 1): ?><span class="badge text-bg-info">Condicional</span><?php endif; ?></div>
                                        <?php if (!empty($field['help_text'])): ?><div class="small text-muted mt-1 text-truncate"><?= htmlspecialchars($field['help_text']) ?></div><?php endif; ?>
                                        <?php if (!$isLayoutField): ?>
                                            <?php $referenceLabels = array_column($previousResponseFields, 'label', 'id'); ?>
                                            <?php if ((int)($field['conditional_enabled'] ?? 0) === 1): ?><div class="small text-muted mt-1">Aparece se: <?= htmlspecialchars($referenceLabels[$field['conditional_field_id']] ?? 'campo anterior') ?> · <?= htmlspecialchars(['equals'=>'igual a','not_equals'=>'diferente de','contains'=>'contém','not_contains'=>'não contém','filled'=>'está preenchido','empty'=>'está vazio'][$field['conditional_operator']] ?? 'condição') ?><?= !in_array($field['conditional_operator'] ?? '', ['filled','empty'], true) ? ' · ' . htmlspecialchars((string)$field['conditional_value']) : '' ?></div><?php endif; ?>
                                            <details class="conditional-editor mt-2">
                                                <summary class="small text-primary">Configurar condição</summary>
                                                <form method="post" class="row g-2 mt-1" draggable="false">
                                                    <input type="hidden" name="action" value="update_condition"><input type="hidden" name="tab" value="fields"><input type="hidden" name="field_id" value="<?= (int)$field['id'] ?>">
                                                    <div class="col-12 form-check ms-2"><input class="form-check-input conditional-enabled-toggle" type="checkbox" name="conditional_enabled" id="condition_enabled_<?= (int)$field['id'] ?>" <?= (int)($field['conditional_enabled'] ?? 0) === 1 ? 'checked' : '' ?>><label class="form-check-label" for="condition_enabled_<?= (int)$field['id'] ?>">Exibir apenas sob condição</label></div>
                                                    <div class="col-md-5 conditional-settings"><select class="form-select form-select-sm" name="conditional_field_id"><option value="">Campo de referência</option><?php foreach ($previousResponseFields as $referenceField): ?><option value="<?= (int)$referenceField['id'] ?>" <?= (int)($field['conditional_field_id'] ?? 0) === (int)$referenceField['id'] ? 'selected' : '' ?>><?= htmlspecialchars($referenceField['label']) ?></option><?php endforeach; ?></select></div>
                                                    <div class="col-md-3 conditional-settings"><select class="form-select form-select-sm conditional-operator" name="conditional_operator"><?php foreach (['equals'=>'É igual a','not_equals'=>'É diferente de','contains'=>'Contém','not_contains'=>'Não contém','filled'=>'Está preenchido','empty'=>'Está vazio'] as $operatorValue=>$operatorLabel): ?><option value="<?= $operatorValue ?>" <?= ($field['conditional_operator'] ?? 'equals') === $operatorValue ? 'selected' : '' ?>><?= $operatorLabel ?></option><?php endforeach; ?></select></div>
                                                    <div class="col-md-4 conditional-settings conditional-value-group"><input class="form-control form-control-sm" name="conditional_value" value="<?= htmlspecialchars((string)($field['conditional_value'] ?? '')) ?>" placeholder="Valor esperado"></div>
                                                    <div class="col-12"><button class="btn btn-sm btn-outline-primary">Salvar condição</button></div>
                                                </form>
                                            </details>
                                        <?php endif; ?>
                                        <details class="field-edit-editor mt-2">
                                            <summary class="small text-primary">Editar campo</summary>
                                            <form method="post" class="row g-2 mt-1 field-edit-form" draggable="false">
                                                <input type="hidden" name="action" value="update_field">
                                                <input type="hidden" name="tab" value="fields">
                                                <input type="hidden" name="field_id" value="<?= (int)$field['id'] ?>">
                                                <div class="col-md-6"><label class="form-label small mb-1">Nome do campo</label><input class="form-control form-control-sm" name="label" value="<?= htmlspecialchars($field['label']) ?>" required></div>
                                                <div class="col-md-3"><label class="form-label small mb-1">Tipo</label><select class="form-select form-select-sm field-edit-type" name="field_type"><?php foreach ($fieldTypeLabels as $typeValue => $typeLabel): ?><option value="<?= htmlspecialchars($typeValue) ?>" <?= $field['type'] === $typeValue ? 'selected' : '' ?>><?= htmlspecialchars($typeLabel) ?></option><?php endforeach; ?></select></div>
                                                <div class="col-md-3 field-edit-response"><label class="form-label small mb-1">Largura</label><select class="form-select form-select-sm" name="width"><?php foreach ([12=>'Inteira',6=>'Metade',4=>'1/3'] as $widthValue=>$widthLabel): ?><option value="<?= $widthValue ?>" <?= (int)($field['width'] ?? 12) === $widthValue ? 'selected' : '' ?>><?= $widthLabel ?></option><?php endforeach; ?></select></div>
                                                <div class="col-md-4 field-edit-response"><label class="form-label small mb-1">Placeholder</label><input class="form-control form-control-sm" name="placeholder" value="<?= htmlspecialchars((string)($field['placeholder'] ?? '')) ?>"></div>
                                                <div class="col-md-4"><label class="form-label small mb-1">Texto de ajuda</label><input class="form-control form-control-sm" name="help_text" value="<?= htmlspecialchars((string)($field['help_text'] ?? '')) ?>"></div>
                                                <div class="col-md-4"><label class="form-label small mb-1">Valor padrão / imagem</label><input class="form-control form-control-sm" name="default_value" value="<?= htmlspecialchars((string)($field['default_value'] ?? '')) ?>"></div>
                                                <div class="col-12 field-edit-options"><label class="form-label small mb-1">Opções</label><textarea class="form-control form-control-sm" name="options" rows="3" placeholder="Uma opção por linha"><?= htmlspecialchars((string)($field['options'] ?? '')) ?></textarea></div>
                                                <div class="col-md-3 field-edit-response"><label class="form-label small mb-1">Máscara</label><select class="form-select form-select-sm" name="mask"><?php foreach ($maskLabels as $maskValue => $maskLabel): ?><option value="<?= htmlspecialchars($maskValue) ?>" <?= ($field['mask'] ?? 'none') === $maskValue ? 'selected' : '' ?>><?= htmlspecialchars($maskLabel) ?></option><?php endforeach; ?></select></div>
                                                <div class="col-12 d-flex flex-wrap gap-3 field-edit-response"><label class="form-check"><input class="form-check-input" type="checkbox" name="is_required" <?= (int)($field['is_required'] ?? 0) === 1 ? 'checked' : '' ?>> Obrigatório</label><label class="form-check"><input class="form-check-input" type="checkbox" name="is_visible" <?= (int)($field['is_visible'] ?? 1) === 1 ? 'checked' : '' ?>> Visível</label><label class="form-check"><input class="form-check-input" type="checkbox" name="is_readonly" <?= (int)($field['is_readonly'] ?? 0) === 1 ? 'checked' : '' ?>> Somente leitura</label></div>
                                                <div class="col-12"><button class="btn btn-sm btn-outline-primary">Salvar campo</button></div>
                                            </form>
                                        </details>
                                    </div>
                                    <?php if (!$isLayoutField): ?>
                                    <label class="field-width-control">Largura
                                        <select class="form-select field-width" data-field-id="<?= (int)$field['id'] ?>">
                                            <option value="12" <?= (int)($field['width'] ?? 12) === 12 ? 'selected' : '' ?>>Inteira</option>
                                            <option value="6" <?= (int)($field['width'] ?? 12) === 6 ? 'selected' : '' ?>>Metade</option>
                                            <option value="4" <?= (int)($field['width'] ?? 12) === 4 ? 'selected' : '' ?>>1/3</option>
                                        </select>
                                    </label>
                                    <?php else: ?>
                                    <span class="field-kind">Linha inteira</span>
                                    <?php endif; ?>
                                    <div class="field-sort-actions">
                                        <button type="button" class="btn btn-sm btn-light move-up" aria-label="Mover para cima">↑</button>
                                        <button type="button" class="btn btn-sm btn-light move-down" aria-label="Mover para baixo">↓</button>
                                        <?php if (!$hasAnswers): ?><form method="post" action="<?= htmlspecialchars(appUrl('form-field-delete')) ?>" data-confirm="Excluir este item?"><input type="hidden" name="form_id" value="<?= (int)$formId ?>"><input type="hidden" name="field_id" value="<?= (int)$field['id'] ?>"><input type="hidden" name="return_tab" value="fields"><button class="btn btn-sm btn-outline-danger">Excluir</button></form><?php else: ?><span class="badge bg-light text-dark">Com respostas</span><?php endif; ?>
                                    </div>
                                </div>
                                <?php if (!$isLayoutField): $previousResponseFields[] = $field; endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <div class="table-responsive d-none">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th>Ordem</th>
                                        <th>Campo</th>
                                        <th>Tipo</th>
                                        <th>Largura</th>
                                        <th>Máscara</th>
                                        <th>Obrigatório</th>
                                        <th>Visível</th>
                                        <th class="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tabFields as $field): ?>
                                        <?php $hasAnswers = ($fieldAnswersCount[$field['id']] ?? 0) > 0; ?>
                                        <?php $isLayoutField = (int) ($field['is_layout'] ?? 0) === 1 || in_array($field['type'], ['title', 'paragraph', 'divider', 'step', 'banner'], true); ?>
                                        <?php if ($isLayoutField): ?>
                                        <tr class="table-light">
                                            <td><?= (int) $field['field_order'] ?></td>
                                            <td colspan="6">
                                                <span class="badge bg-dark me-2"><?= htmlspecialchars(strtoupper($field['type'])) ?></span>
                                                <strong><?= htmlspecialchars($field['type'] === 'divider' && $field['label'] === '' ? 'Linha divisória' : $field['label']) ?></strong>
                                                <?php if ($field['type'] === 'paragraph' && !empty($field['help_text'] ?: $field['default_value'])): ?>
                                                    <div class="small text-muted mt-1"><?= htmlspecialchars($field['help_text'] ?: $field['default_value']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if (!$hasAnswers): ?>
                                                <form method="post" action="<?= htmlspecialchars(appUrl('form-field-delete')) ?>" class="d-inline" data-confirm="Tem certeza que deseja excluir este bloco? Esta ação não poderá ser desfeita.">
                                                    <input type="hidden" name="form_id" value="<?= (int) $form['id'] ?>">
                                                    <input type="hidden" name="field_id" value="<?= (int) $field['id'] ?>">
                                                    <input type="hidden" name="return_tab" value="structure">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Excluir</button>
                                                </form>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-dark">Bloqueado</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                        <tr>
                                            <td><?= $field['field_order'] ?></td>

                                            <td>
                                                <?= htmlspecialchars($field['label']) ?>

                                                <?php if (!empty($field['placeholder'])): ?>
                                                    <div class="small text-muted">
                                                        <?= htmlspecialchars($field['placeholder']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?= htmlspecialchars($field['type']) ?>
                                                </span>
                                            </td>

                                            <td><?= htmlspecialchars($widthLabels[(int) ($field['width'] ?? 12)] ?? 'Inteira') ?></td>

                                            <td><?= htmlspecialchars($maskLabels[$field['mask'] ?? 'none'] ?? '-') ?></td>

                                            <td>
                                                <?php if ($field['is_required']): ?>
                                                    <span class="badge bg-danger">Sim</span>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-dark">Não</span>
                                                <?php endif; ?>
                                            </td>

                                            <td>
                                                <?php if ((int) ($field['is_visible'] ?? 1) === 1): ?>
                                                    <span class="badge bg-success">Visível</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Oculto</span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="text-end">
                                                <?php if (!$hasAnswers): ?>
                                                    <form method="post" action="<?= htmlspecialchars(appUrl('form-field-delete')) ?>" class="d-inline" data-confirm="Tem certeza que deseja excluir este campo? Esta ação não poderá ser desfeita.">
                                                        <input type="hidden" name="form_id" value="<?= (int) $form['id'] ?>">
                                                        <input type="hidden" name="field_id" value="<?= (int) $field['id'] ?>">
                                                        <input type="hidden" name="return_tab" value="fields">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger">Excluir</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-dark">Bloqueado</span>
                                                    <div class="small text-muted">Já possui respostas</div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    <?php else: ?>

                        <div class="text-center py-4">
                            <p class="text-muted mb-0">
                                Nenhum <?= $activeTab === 'fields' ? 'campo' : 'bloco estrutural' ?> cadastrado ainda.
                            </p>
                        </div>

                    <?php endif; ?>

                </div>
            </div>

        </div>
        <?php endif; ?>

        <?php if ($activeTab === 'appearance'): ?>
        <?php
            $useTenantBranding = (int)($form['use_tenant_branding'] ?? 1) === 1;
            $styles = ['clean'=>'Clean','premium'=>'Premium','minimal'=>'Minimal','church'=>'Church','business'=>'Business'];
            $fallbackSubtitle = 'Personalize a apresentação pública deste formulário.';
            $appearanceValues = [];
            foreach (['primary_color'=>'#212121','secondary_color'=>'#555555','background_color'=>'#F3F3F3','text_color'=>'#212121','button_color'=>'#212121'] as $key=>$fallback) {
                $appearanceValues[$key] = $useTenantBranding ? ($tenant[$key] ?? $fallback) : (($form[$key] ?? '') ?: ($tenant[$key] ?? $fallback));
            }
            $appearanceTitle = $useTenantBranding ? (($tenant['public_title'] ?? '') ?: $tenant['name']) : (($form['public_title'] ?? '') ?: $form['title']);
            $appearanceSubtitle = $useTenantBranding ? (($tenant['public_subtitle'] ?? '') ?: $fallbackSubtitle) : (($form['public_subtitle'] ?? '') ?: (($tenant['public_subtitle'] ?? '') ?: $fallbackSubtitle));
            $appearanceCover = formAssetUrl(!$useTenantBranding && !empty($form['cover_image_path']) ? $form['cover_image_path'] : ($tenant['cover_image_path'] ?? ''));
            $hideBannerText = $_SERVER['REQUEST_METHOD'] === 'POST' ? isset($_POST['hide_banner_text']) : (int)($form['hide_banner_text'] ?? 0) === 1;
        ?>
        <style>
            .appearance-textarea{min-height:150px;resize:vertical}.appearance-preview-information{padding:16px 22px;border-bottom:1px solid #CECECE;background:#fff;color:var(--preview-text);white-space:pre-line;font-size:13px;line-height:1.55}.appearance-preview-information:empty{display:none}.appearance-preview-information::before{content:"Informações complementares";display:block;font-weight:850;font-size:14px;margin-bottom:5px}
            .appearance-layout{display:grid;grid-template-columns:minmax(0,1fr)390px;gap:24px;align-items:start}.appearance-stack{display:grid;gap:22px}.appearance-card{background:#fff;border:1px solid #CECECE;border-radius:18px;box-shadow:0 18px 40px rgba(15,23,42,.06);overflow:hidden}.appearance-card-body{padding:24px}.appearance-title{font-size:28px;font-weight:850;letter-spacing:-.04em;color:#212121;margin:0}.appearance-subtitle{color:#555555;margin:6px 0 0}.appearance-card-title{font-size:18px;font-weight:800;color:#212121;margin:0 0 4px}.appearance-card-text{color:#555555;margin:0 0 18px}.appearance-upload-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}.appearance-upload{border:1px dashed #CECECE;border-radius:18px;background:linear-gradient(180deg,#F3F3F3,#fff);padding:22px;min-height:220px;display:flex;flex-direction:column;justify-content:space-between;cursor:pointer}.appearance-upload:hover,.appearance-upload.is-dragover{border-color:#212121;box-shadow:0 14px 30px rgba(37,99,235,.10)}.appearance-upload input[type=file]{position:absolute;opacity:0;pointer-events:none}.appearance-upload-icon{width:48px;height:48px;border-radius:16px;display:grid;place-items:center;background:rgba(37,99,235,.10);color:#212121;font-size:24px;margin-bottom:14px}.appearance-upload-main{font-weight:800;color:#212121}.appearance-upload-help,.appearance-upload-small,.appearance-current{font-size:13px;color:#555555;margin-top:8px}.appearance-upload-button{display:inline-flex;margin-top:8px;min-height:38px;align-items:center;border-radius:999px;border:1px solid rgba(37,99,235,.25);padding:0 15px;color:#212121;background:#fff;font-weight:800}.appearance-toggle{display:flex;gap:12px;align-items:center;padding:14px 16px;border:1px solid #CECECE;border-radius:16px;background:#F3F3F3;margin-bottom:18px}.appearance-toggle input{width:42px;height:22px}.appearance-field label,.appearance-color label{font-size:13px;font-weight:800;color:#212121;margin-bottom:8px}.appearance-input,.appearance-select{min-height:48px;border-radius:14px;border:1px solid #CECECE;padding:10px 14px}.appearance-color-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.appearance-color-control{display:flex;gap:10px;align-items:center;border:1px solid #CECECE;border-radius:14px;background:#F3F3F3;padding:8px 12px}.appearance-color-control input[type=color]{width:42px;height:42px;border:0;background:transparent;padding:0}.appearance-color-value{border:0;background:transparent;font-weight:800;color:#212121;outline:0;width:100%;text-transform:uppercase}.appearance-save{min-height:50px;border:0;border-radius:14px;background:linear-gradient(135deg,#212121,#555555);color:#fff;font-weight:800;padding:0 24px;box-shadow:0 16px 28px rgba(37,99,235,.22)}.appearance-preview-card{position:sticky;top:24px}.appearance-preview-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}.appearance-live{background:rgba(34,197,94,.12);color:#16a34a;border-radius:999px;padding:6px 10px;font-size:12px;font-weight:800}.appearance-preview{border:1px solid #CECECE;border-radius:20px;overflow:hidden;background:var(--preview-bg);color:var(--preview-text)}.appearance-preview-cover{min-height:190px;display:flex;align-items:flex-end;position:relative;background:linear-gradient(135deg,var(--preview-primary),var(--preview-secondary));background-size:cover;background-position:center}.appearance-preview-cover::before{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(15,23,42,.05),rgba(15,23,42,.78))}.appearance-preview-cover.is-text-hidden::before,.appearance-preview-cover.is-text-hidden .appearance-preview-banner-text{display:none}.appearance-preview-banner-text{position:relative;z-index:1;padding:70px 22px 22px;color:#fff}.appearance-preview-inner{padding:22px}.appearance-preview-name{font-size:22px;font-weight:850;letter-spacing:-.03em}.appearance-preview-subtitle{color:rgba(255,255,255,.86);font-size:14px;margin:4px 0 0}.appearance-fake-label{display:block;font-size:12px;font-weight:800;margin:0 0 6px}.appearance-fake-input{min-height:42px;border:1px solid #CECECE;border-radius:12px;background:#fff;margin-bottom:12px}.appearance-submit{width:100%;min-height:46px;border-radius:12px;border:0;color:#fff;background:var(--preview-button);font-weight:800;margin-top:8px}@media(max-width:1199px){.appearance-layout{grid-template-columns:1fr}.appearance-preview-card{position:static}}@media(max-width:767px){.appearance-upload-grid,.appearance-color-grid{grid-template-columns:1fr}.appearance-card-body{padding:18px}.appearance-save{width:100%}}
        </style>
        <div class="mb-4">
            <h2 class="appearance-title">Aparência do formulário</h2>
            <p class="appearance-subtitle">Personalize a apresentação pública deste formulário.</p>
        </div>
        <form method="post" enctype="multipart/form-data" id="formAppearanceForm">
            <input type="hidden" name="action" value="update_appearance">
            <input type="hidden" name="tab" value="appearance">
            <div class="appearance-layout">
                <div class="appearance-stack">
                    <section class="appearance-card"><div class="appearance-card-body"><h3 class="appearance-card-title">Uploads</h3><p class="appearance-card-text">Arquivos próprios deste formulário. Se vazio, herda a identidade do cliente.</p><div class="appearance-upload-grid">
                        <div><h3 class="appearance-card-title">Banner / imagem de capa</h3><label class="appearance-upload" data-drop-zone="cover_image_file" for="cover_image_file"><div><div class="appearance-upload-icon">↑</div><div class="appearance-upload-main">Arraste e solte a imagem aqui</div><div class="appearance-upload-small">ou</div><span class="appearance-upload-button">Selecionar arquivo</span><div class="appearance-upload-help">PNG ou JPG. Recomendado 1600x600px. Máx. 4MB. Ao enviar, a identidade própria do formulário será ativada.</div></div><?php if(!empty($form['cover_image_path'])): ?><div class="appearance-current">Atual: <?= htmlspecialchars($form['cover_image_path']) ?> · <?= $useTenantBranding ? 'salvo, mas usando a capa padrão' : 'ativo' ?></div><?php endif; ?><div class="appearance-current d-none" data-file-name="cover_image_file"></div><input type="file" id="cover_image_file" name="cover_image_file" accept=".jpg,.jpeg,.png,image/jpeg,image/png"></label></div>
                    </div></div></section>
                    <section class="appearance-card"><div class="appearance-card-body">
                        <h3 class="appearance-card-title">Informações complementares</h3>
                        <p class="appearance-card-text">Adicione orientações, avisos ou outros detalhes. Este conteúdo será exibido logo abaixo do banner e antes dos campos.</p>
                        <div class="appearance-field">
                            <label for="additional_information">Conteúdo</label>
                            <textarea class="form-control appearance-input appearance-textarea js-appearance-preview" data-preview="information" id="additional_information" name="additional_information" maxlength="5000" rows="6" placeholder="Ex.: documentos necessários, horários, endereço ou instruções importantes."><?= htmlspecialchars($_POST['additional_information'] ?? ($form['additional_information'] ?? '')) ?></textarea>
                            <div class="form-text">Opcional. As quebras de linha serão preservadas.</div>
                        </div>
                    </div></section>
                    <section class="appearance-card"><div class="appearance-card-body"><h3 class="appearance-card-title">Configurações visuais</h3><p class="appearance-card-text">Sobrescreva textos, cores e estilo ou use a marca padrão do cliente.</p><label class="appearance-toggle"><input type="checkbox" name="use_tenant_branding" id="use_tenant_branding" <?= $useTenantBranding ? 'checked' : '' ?>><span><strong>Usar identidade visual padrão do cliente</strong><br><small class="text-muted">Quando ativo, o formulário herda capa, textos e cores da organização.</small></span></label><label class="appearance-toggle"><input type="checkbox" name="hide_banner_text" id="hide_banner_text" <?= $hideBannerText ? 'checked' : '' ?>><span><strong>Ocultar título e subtítulo no banner</strong><br><small class="text-muted">Exibe somente a imagem de capa, sem textos ou degradê sobre a arte.</small></span></label>
                        <div class="row g-3"><div class="col-md-6 appearance-field"><label for="public_title">Nome público</label><input class="form-control appearance-input js-appearance-preview" data-preview="title" id="public_title" name="public_title" maxlength="120" value="<?= htmlspecialchars($_POST['public_title'] ?? ($form['public_title'] ?? '')) ?>" placeholder="<?= htmlspecialchars($form['title']) ?>"></div><div class="col-md-6 appearance-field"><label for="public_subtitle">Subtítulo</label><input class="form-control appearance-input js-appearance-preview" data-preview="subtitle" id="public_subtitle" name="public_subtitle" maxlength="180" value="<?= htmlspecialchars($_POST['public_subtitle'] ?? ($form['public_subtitle'] ?? '')) ?>" placeholder="<?= htmlspecialchars($appearanceSubtitle) ?>"></div></div>
                        <div class="appearance-color-grid mt-3"><?php foreach(['primary_color'=>'Cor principal','secondary_color'=>'Cor secundária','background_color'=>'Cor de fundo','text_color'=>'Cor do texto','button_color'=>'Cor do botão'] as $colorKey=>$colorLabel): ?><div class="appearance-color"><label for="<?= $colorKey ?>"><?= $colorLabel ?></label><div class="appearance-color-control"><input type="color" id="<?= $colorKey ?>" name="<?= $colorKey ?>" value="<?= htmlspecialchars($_POST[$colorKey] ?? ($form[$colorKey] ?? $appearanceValues[$colorKey])) ?>" data-color-key="<?= $colorKey ?>"><input type="text" class="appearance-color-value" value="<?= htmlspecialchars($_POST[$colorKey] ?? ($form[$colorKey] ?? $appearanceValues[$colorKey])) ?>" data-color-value="<?= $colorKey ?>" maxlength="7"></div></div><?php endforeach; ?></div>
                        <div class="row g-3 mt-1"><div class="col-md-6 appearance-field"><label for="style">Estilo do formulário</label><select class="form-select appearance-select" id="style" name="style"><?php foreach($styles as $styleKey=>$styleLabel): ?><option value="<?= $styleKey ?>" <?= ($_POST['style'] ?? ($form['style'] ?? 'clean')) === $styleKey ? 'selected' : '' ?>><?= $styleLabel ?></option><?php endforeach; ?></select></div></div>
                        <div class="text-end mt-4"><button class="appearance-save" type="submit">Salvar aparência</button></div>
                    </div></section>
                </div>
                <aside class="appearance-card appearance-preview-card"><div class="appearance-card-body"><div class="appearance-preview-head"><h3 class="appearance-card-title mb-0">Prévia do formulário</h3><span class="appearance-live">Ao vivo</span></div><div class="appearance-preview" id="appearancePreview" style="--preview-primary: <?= htmlspecialchars($appearanceValues['primary_color']) ?>;--preview-secondary: <?= htmlspecialchars($appearanceValues['secondary_color']) ?>;--preview-bg: <?= htmlspecialchars($appearanceValues['background_color']) ?>;--preview-text: <?= htmlspecialchars($appearanceValues['text_color']) ?>;--preview-button: <?= htmlspecialchars($appearanceValues['button_color']) ?>;"><div class="appearance-preview-cover <?= $hideBannerText ? 'is-text-hidden' : '' ?>" id="appearancePreviewCover" <?= $appearanceCover ? 'style="background-image:url(' . htmlspecialchars($appearanceCover) . ')"' : '' ?>><div class="appearance-preview-banner-text"><div class="appearance-preview-name" id="appearancePreviewTitle"><?= htmlspecialchars($appearanceTitle) ?></div><div class="appearance-preview-subtitle" id="appearancePreviewSubtitle"><?= htmlspecialchars($appearanceSubtitle) ?></div></div></div><div class="appearance-preview-information" id="appearancePreviewInformation"><?= htmlspecialchars($form['additional_information'] ?? '') ?></div><div class="appearance-preview-inner"><label class="appearance-fake-label">Nome completo</label><div class="appearance-fake-input"></div><label class="appearance-fake-label">E-mail</label><div class="appearance-fake-input"></div><label class="appearance-fake-label">Assunto</label><div class="appearance-fake-input"></div><button type="button" class="appearance-submit">Enviar</button></div></div></div></aside>
            </div>
        </form>
        <script>
            (() => {
                const fallbackTitle = <?= json_encode($appearanceTitle, JSON_UNESCAPED_UNICODE) ?>;
                const fallbackSubtitle = <?= json_encode($appearanceSubtitle, JSON_UNESCAPED_UNICODE) ?>;
                const preview = document.getElementById('appearancePreview');
                const title = document.getElementById('appearancePreviewTitle');
                const subtitle = document.getElementById('appearancePreviewSubtitle');
                const information = document.getElementById('appearancePreviewInformation');
                const cover = document.getElementById('appearancePreviewCover');
                const brandingToggle = document.getElementById('use_tenant_branding');
                const hideTextToggle = document.getElementById('hide_banner_text');
                const vars = {primary_color:'--preview-primary',secondary_color:'--preview-secondary',background_color:'--preview-bg',text_color:'--preview-text',button_color:'--preview-button'};

                hideTextToggle?.addEventListener('change', () => cover.classList.toggle('is-text-hidden', hideTextToggle.checked));
                document.querySelectorAll('.js-appearance-preview').forEach(input => input.addEventListener('input', () => {
                    if (input.dataset.preview === 'title') title.textContent = input.value.trim() || fallbackTitle;
                    if (input.dataset.preview === 'subtitle') subtitle.textContent = input.value.trim() || fallbackSubtitle;
                    if (input.dataset.preview === 'information') information.textContent = input.value.trim();
                }));
                document.querySelectorAll('[data-color-key]').forEach(color => {
                    const textInput = document.querySelector(`[data-color-value="${color.dataset.colorKey}"]`);
                    const sync = value => {
                        if (/^#[0-9a-fA-F]{6}$/.test(value)) {
                            color.value = value;
                            textInput.value = value.toUpperCase();
                            preview.style.setProperty(vars[color.dataset.colorKey], value);
                        }
                    };
                    color.addEventListener('input', () => sync(color.value));
                    textInput.addEventListener('input', () => sync(textInput.value));
                });
                const bindFile = (id, callback) => {
                    const input = document.getElementById(id);
                    input?.addEventListener('change', () => {
                        const file = input.files?.[0];
                        if (!file) return;
                        const fileName = document.querySelector(`[data-file-name="${id}"]`);
                        if (fileName) {
                            fileName.textContent = `Selecionado: ${file.name}`;
                            fileName.classList.remove('d-none');
                        }
                        if (brandingToggle) brandingToggle.checked = false;
                        callback(URL.createObjectURL(file));
                    });
                };
                bindFile('cover_image_file', url => { cover.style.backgroundImage = `url(${url})`; });
                document.querySelectorAll('[data-drop-zone]').forEach(zone => {
                    const input = document.getElementById(zone.dataset.dropZone);
                    ['dragenter', 'dragover'].forEach(name => zone.addEventListener(name, event => {
                        event.preventDefault();
                        zone.classList.add('is-dragover');
                    }));
                    ['dragleave', 'drop'].forEach(name => zone.addEventListener(name, event => {
                        event.preventDefault();
                        zone.classList.remove('is-dragover');
                    }));
                    zone.addEventListener('drop', event => {
                        const file = event.dataTransfer?.files?.[0];
                        if (!file || !input) return;
                        const transfer = new DataTransfer();
                        transfer.items.add(file);
                        input.files = transfer.files;
                        input.dispatchEvent(new Event('change'));
                    });
                });
            })();
        </script>
        <?php endif; ?>

    </div>
</div>

<?php if ($activeTab === 'fields'): ?>
<script>
(function () {
    const typeSelect = document.getElementById('field_type');
    const advanced = document.getElementById('builder-advanced');
    const advancedBody = document.getElementById('builder-advanced-body');
    document.querySelectorAll('.builder-advanced-item').forEach(el => {
        if (!el.classList.contains('conditional-builder')) el.classList.add('col-md-6');
        advancedBody.appendChild(el);
    });

    const layoutTypes = ['step','title','paragraph','divider','banner'];
    const choiceTypes = ['select','radio','checkbox'];
    function toggleGroup(selector, visible) {
        document.querySelectorAll(selector).forEach(el => {
            el.classList.toggle('d-none', !visible);
            el.querySelectorAll('input,select,textarea').forEach(input => input.disabled = !visible);
        });
    }
    function syncBuilder() {
        const type = typeSelect.value;
        const isLayout = layoutTypes.includes(type);
        const isBanner = type === 'banner';
        toggleGroup('.builder-response', !isLayout);
        toggleGroup('.builder-response-flag', !isLayout);
        toggleGroup('.builder-options', choiceTypes.includes(type));
        toggleGroup('.builder-banner', isBanner);
        advanced.classList.toggle('d-none', isLayout);
        advanced.querySelectorAll('input,select,textarea').forEach(input => input.disabled = isLayout);
        const label = document.querySelector('label[for="help_text"]');
        if (label) label.textContent = isBanner ? 'Legenda (opcional)' : (type === 'paragraph' ? 'Texto' : 'Texto de ajuda');
    }
    typeSelect.addEventListener('change', syncBuilder);
    syncBuilder();

    function syncFieldEdit(form) {
        const type = form.querySelector('.field-edit-type')?.value || '';
        const isLayout = layoutTypes.includes(type);
        const hasOptions = choiceTypes.includes(type);
        form.querySelectorAll('.field-edit-response').forEach(el => {
            el.classList.toggle('d-none', isLayout);
            el.querySelectorAll('input,select,textarea').forEach(input => input.disabled = isLayout);
        });
        form.querySelectorAll('.field-edit-options').forEach(el => {
            el.classList.toggle('d-none', !hasOptions);
            el.querySelectorAll('textarea').forEach(input => input.disabled = !hasOptions);
        });
    }
    document.querySelectorAll('.field-edit-form').forEach(form => {
        form.querySelector('.field-edit-type')?.addEventListener('change', () => syncFieldEdit(form));
        syncFieldEdit(form);
    });

    function syncConditionalEditor(toggle) {
        const container = toggle.closest('.conditional-builder, .conditional-editor');
        if (!container) return;
        container.querySelectorAll('.conditional-settings input, .conditional-settings select').forEach(input => {
            input.disabled = !toggle.checked;
        });
        const operator = container.querySelector('.conditional-operator');
        const valueGroup = container.querySelector('.conditional-value-group');
        if (operator && valueGroup) {
            valueGroup.classList.toggle('d-none', ['filled', 'empty'].includes(operator.value));
        }
    }

    document.querySelectorAll('.conditional-enabled-toggle').forEach(toggle => {
        toggle.addEventListener('change', () => syncConditionalEditor(toggle));
        syncConditionalEditor(toggle);
    });
    document.querySelectorAll('.conditional-operator').forEach(operator => {
        operator.addEventListener('change', () => {
            const toggle = operator.closest('.conditional-builder, .conditional-editor')?.querySelector('.conditional-enabled-toggle');
            if (toggle) syncConditionalEditor(toggle);
        });
    });
    document.querySelectorAll('.conditional-editor, .field-edit-editor').forEach(editor => {
        ['pointerdown', 'mousedown', 'touchstart'].forEach(eventName => editor.addEventListener(eventName, event => event.stopPropagation()));
    });

    const list = document.getElementById('sortable-fields');
    if (!list) return;
    const bulkDeleteForm = document.getElementById('bulk-delete-fields-form');
    const selectAllFields = document.getElementById('select-all-fields');
    const bulkDeleteButton = document.getElementById('bulk-delete-fields-button');
    const bulkDeleteCount = document.getElementById('bulk-delete-fields-count');
    const selectableFields = [...list.querySelectorAll('.field-bulk-select:not(:disabled)')];

    function syncBulkSelection() {
        const selectedCount = selectableFields.filter(checkbox => checkbox.checked).length;
        bulkDeleteCount.textContent = String(selectedCount);
        bulkDeleteButton.disabled = selectedCount === 0;
        selectAllFields.checked = selectableFields.length > 0 && selectedCount === selectableFields.length;
        selectAllFields.indeterminate = selectedCount > 0 && selectedCount < selectableFields.length;
        selectAllFields.disabled = selectableFields.length === 0;
    }

    selectAllFields?.addEventListener('change', () => {
        selectableFields.forEach(checkbox => { checkbox.checked = selectAllFields.checked; });
        syncBulkSelection();
    });
    selectableFields.forEach(checkbox => {
        checkbox.addEventListener('change', syncBulkSelection);
        ['pointerdown', 'mousedown', 'touchstart'].forEach(eventName => checkbox.addEventListener(eventName, event => event.stopPropagation()));
    });
    bulkDeleteForm?.addEventListener('submit', event => {
        if (!selectableFields.some(checkbox => checkbox.checked)) {
            event.preventDefault();
        }
    });
    syncBulkSelection();

    const status = document.getElementById('order-status');
    let dragged = null;
    let saving = false;
    let queuedSave = false;

    async function saveOrder() {
        if (saving) { queuedSave = true; return; }
        saving = true;
        status.textContent = 'Salvando…';
        const orderedIds = [...list.querySelectorAll('.field-sort-item')].map(item => Number(item.dataset.fieldId));
        try {
            const response = await fetch(list.dataset.reorderUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({form_id: Number(list.dataset.formId), ordered_ids: orderedIds})
            });
            if (!response.ok) throw new Error();
            status.textContent = 'Ordem salva';
            setTimeout(() => status.textContent = '', 1600);
        } catch (error) {
            status.textContent = 'Não foi possível salvar';
            setTimeout(() => location.reload(), 1200);
        } finally {
            saving = false;
            if (queuedSave) { queuedSave = false; saveOrder(); }
        }
    }

    list.querySelectorAll('.field-width').forEach(select => {
        select.dataset.previous = select.value;
        const item = select.closest('.field-sort-item');
        select.addEventListener('pointerdown', () => item.setAttribute('draggable', 'false'));
        select.addEventListener('blur', () => item.setAttribute('draggable', 'true'));
        select.addEventListener('change', async () => {
            select.disabled = true;
            status.textContent = 'Salvando largura…';
            try {
                const response = await fetch(list.dataset.widthUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        form_id: Number(list.dataset.formId),
                        field_id: Number(select.dataset.fieldId),
                        width: Number(select.value)
                    })
                });
                if (!response.ok) throw new Error();
                select.dataset.previous = select.value;
                status.textContent = 'Largura salva';
                setTimeout(() => status.textContent = '', 1600);
            } catch (error) {
                select.value = select.dataset.previous;
                status.textContent = 'Não foi possível salvar';
            } finally {
                select.disabled = false;
                item.setAttribute('draggable', 'true');
            }
        });
    });

    list.addEventListener('dragstart', event => {
        dragged = event.target.closest('.field-sort-item');
        if (!dragged) return;
        dragged.classList.add('dragging');
        event.dataTransfer.effectAllowed = 'move';
    });
    list.addEventListener('dragover', event => {
        event.preventDefault();
        const target = event.target.closest('.field-sort-item');
        if (!target || target === dragged) return;
        const box = target.getBoundingClientRect();
        list.insertBefore(dragged, event.clientY < box.top + box.height / 2 ? target : target.nextSibling);
    });
    list.addEventListener('dragend', () => {
        if (!dragged) return;
        dragged.classList.remove('dragging');
        dragged = null;
        saveOrder();
    });
    list.addEventListener('click', event => {
        const button = event.target.closest('.move-up,.move-down');
        if (!button) return;
        const item = button.closest('.field-sort-item');
        if (button.classList.contains('move-up') && item.previousElementSibling) list.insertBefore(item, item.previousElementSibling);
        if (button.classList.contains('move-down') && item.nextElementSibling) list.insertBefore(item.nextElementSibling, item);
        saveOrder();
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../../layouts/admin-footer.php'; ?>


