<?php
requireTenantContext();

$tenantId = currentTenantIdForData();
$pageTitle = 'Identidade visual';
$errors = [];
$success = isset($_GET['saved']) && $_GET['saved'] === '1';
$styles = ['clean' => 'Clean', 'premium' => 'Premium', 'minimal' => 'Minimal', 'church' => 'Church', 'business' => 'Business'];
$colorDefaults = [
    'primary_color' => '#212121',
    'secondary_color' => '#555555',
    'background_color' => '#F3F3F3',
    'text_color' => '#212121',
    'button_color' => '#212121',
];

$stmt = $pdo->prepare('SELECT * FROM tenants WHERE id = ? LIMIT 1');
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tenant) {
    http_response_code(404);
    die('Empresa não encontrada.');
}

function brandingPublicPath(?string $path): string
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

function brandingColor(array $source, string $key, array $defaults): string
{
    $value = trim((string) ($source[$key] ?? ''));
    return preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtoupper($value) : $defaults[$key];
}

function optimizedImageFromUpload(string $tmpName, string $extension, string $absolutePath, int $maxWidth, int $quality = 82): bool
{
    if (!extension_loaded('gd') || !function_exists('imagewebp')) {
        return false;
    }

    $extension = strtolower($extension);
    $source = match ($extension) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($tmpName),
        'png' => @imagecreatefrompng($tmpName),
        default => false,
    };

    if (!$source) {
        return false;
    }

    $width = imagesx($source);
    $height = imagesy($source);
    if ($width <= 0 || $height <= 0) {
        imagedestroy($source);
        return false;
    }

    $targetWidth = min($width, $maxWidth);
    $targetHeight = (int) round($height * ($targetWidth / $width));
    $target = imagecreatetruecolor($targetWidth, $targetHeight);

    imagealphablending($target, false);
    imagesavealpha($target, true);
    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
    imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);

    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
    $saved = imagewebp($target, $absolutePath, $quality);

    imagedestroy($source);
    imagedestroy($target);

    return $saved;
}

function uploadedBrandFile(string $field, int $tenantId, array $allowedExtensions, int $maxBytes, array &$errors): ?string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$field];
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errors[] = 'Não foi possível enviar o arquivo de ' . ($field === 'logo_file' ? 'logo.' : 'capa.');
        return null;
    }

    if (($file['size'] ?? 0) <= 0) {
        $errors[] = 'O arquivo enviado est? vazio.';
        return null;
    }

    if (($file['size'] ?? 0) > $maxBytes) {
        $errors[] = $field === 'logo_file' ? 'A logo deve ter no máximo 2MB.' : 'A imagem de capa deve ter no máximo 4MB.';
        return null;
    }

    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        $errors[] = $field === 'logo_file'
            ? 'A logo deve ser JPG, PNG ou SVG.'
            : 'A imagem de capa deve ser JPG ou PNG.';
        return null;
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_file($tmpName)) {
        $errors[] = 'Upload inválido.';
        return null;
    }

    $directory = 'storage/tenants/' . $tenantId . '/branding';
    $absoluteDirectory = __DIR__ . '/../../../public/' . $directory;
    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true)) {
        $errors[] = 'Não foi possível criar a pasta de uploads.';
        return null;
    }

    $prefix = $field === 'logo_file' ? 'logo' : 'cover';
    $baseName = $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));

    if ($extension === 'svg') {
        $svgContent = file_get_contents($tmpName);
        if ($svgContent === false || stripos($svgContent, '<svg') === false || preg_match('/<script|on\w+\s*=|javascript:/i', $svgContent)) {
            $errors[] = 'O SVG enviado não é válido.';
            return null;
        }

        $filename = $baseName . '.svg';
        $absolutePath = $absoluteDirectory . '/' . $filename;
        if (!move_uploaded_file($tmpName, $absolutePath) && !rename($tmpName, $absolutePath)) {
            $errors[] = 'Não foi possível salvar a logo enviada.';
            return null;
        }

        return $directory . '/' . $filename;
    }

    $imageInfo = @getimagesize($tmpName);
    if ($imageInfo === false) {
        $errors[] = 'O arquivo enviado não parece ser uma imagem válida.';
        return null;
    }

    $filename = $baseName . '.webp';
    $absolutePath = $absoluteDirectory . '/' . $filename;
    $maxWidth = $field === 'logo_file' ? 900 : 1800;
    $quality = $field === 'logo_file' ? 88 : 82;

    if (!optimizedImageFromUpload($tmpName, $extension, $absolutePath, $maxWidth, $quality)) {
        $fallbackExtension = $extension === 'jpeg' ? 'jpg' : $extension;
        $filename = $baseName . '.' . $fallbackExtension;
        $absolutePath = $absoluteDirectory . '/' . $filename;
        if (!move_uploaded_file($tmpName, $absolutePath) && !rename($tmpName, $absolutePath)) {
            $errors[] = 'Não foi possível salvar o arquivo enviado.';
            return null;
        }
    }

    return $directory . '/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!canManageTenantData()) {
        $errors[] = 'Seu perfil não pode alterar a identidade visual.';
    } else {
        $values = [
            'public_title' => trim($_POST['public_title'] ?? ''),
            'public_subtitle' => trim($_POST['public_subtitle'] ?? ''),
            'form_style' => trim($_POST['form_style'] ?? 'clean'),
        ];

        if (strlen($values['public_title']) > 120) {
            $errors[] = 'O título público deve ter no máximo 120 caracteres.';
        }
        if (strlen($values['public_subtitle']) > 180) {
            $errors[] = 'O subtítulo público deve ter no máximo 180 caracteres.';
        }
        if (!array_key_exists($values['form_style'], $styles)) {
            $errors[] = 'Estilo inválido.';
        }

        foreach (array_keys($colorDefaults) as $key) {
            $values[$key] = strtoupper(trim($_POST[$key] ?? $colorDefaults[$key]));
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $values[$key])) {
                $errors[] = 'Informe cores válidas.';
            }
        }

        $logoPath = uploadedBrandFile('logo_file', $tenantId, ['jpg', 'jpeg', 'png', 'svg'], 2 * 1024 * 1024, $errors);
        $coverPath = uploadedBrandFile('cover_image_file', $tenantId, ['jpg', 'jpeg', 'png'], 4 * 1024 * 1024, $errors);

        if (!$errors) {
            try {
                $stmt = $pdo->prepare('UPDATE tenants
                    SET public_title = ?, public_subtitle = ?, primary_color = ?, secondary_color = ?, background_color = ?, text_color = ?, button_color = ?, form_style = ?, logo = COALESCE(?, logo), logo_path = COALESCE(?, logo_path), cover_image_path = COALESCE(?, cover_image_path), updated_at = NOW()
                    WHERE id = ?');
                $stmt->execute([
                    $values['public_title'] ?: null,
                    $values['public_subtitle'] ?: null,
                    $values['primary_color'],
                    $values['secondary_color'],
                    $values['background_color'],
                    $values['text_color'],
                    $values['button_color'],
                    $values['form_style'],
                    $logoPath,
                    $logoPath,
                    $coverPath,
                    $tenantId,
                ]);

                if (!isSuperAdmin()) {
                    $_SESSION['user']['primary_color'] = $values['primary_color'];
                    $_SESSION['user']['secondary_color'] = $values['secondary_color'];
                }

                redirectTo('brand-settings', ['saved' => 1]);
            } catch (PDOException $exception) {
                $errors[] = 'N?o foi poss?vel salvar a identidade visual: ' . $exception->getMessage();
            }
        }
    }
}

$previewLogo = brandingPublicPath($tenant['logo_path'] ?? ($tenant['logo'] ?? ''));
$previewCover = brandingPublicPath($tenant['cover_image_path'] ?? '');
$previewTitle = $tenant['public_title'] ?: $tenant['name'];
$previewSubtitle = $tenant['public_subtitle'] ?: 'Formulários inteligentes para empresas organizadas.';

require __DIR__ . '/../../layouts/admin-header.php';
require __DIR__ . '/../../layouts/admin-sidebar.php';
?>

<style>
    .brand-page-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 20px;
        margin-bottom: 26px;
    }

    .brand-page-eyebrow {
        color: #212121;
        font-size: 13px;
        font-weight: 800;
        letter-spacing: .08em;
        text-transform: uppercase;
        margin-bottom: 8px;
    }

    .brand-page-title {
        color: #212121;
        font-size: clamp(28px, 3vw, 38px);
        font-weight: 850;
        letter-spacing: -.04em;
        margin: 0 0 8px;
    }

    .brand-page-subtitle {
        color: #555555;
        font-size: 16px;
        margin: 0;
        max-width: 720px;
    }

    .brand-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 390px;
        gap: 24px;
        align-items: start;
    }

    .brand-stack {
        display: grid;
        gap: 22px;
    }

    .brand-card {
        background: #fff;
        border: 1px solid #CECECE;
        border-radius: 18px;
        box-shadow: 0 18px 40px rgba(33, 33, 33, .06);
        overflow: hidden;
    }

    .brand-card-body {
        padding: 24px;
    }

    .brand-card-title {
        color: #212121;
        font-size: 18px;
        font-weight: 800;
        margin: 0 0 4px;
    }

    .brand-card-text {
        color: #555555;
        margin: 0 0 18px;
    }

    .upload-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 18px;
    }

    .upload-box {
        border: 1px dashed #CECECE;
        border-radius: 18px;
        background: linear-gradient(180deg, #F3F3F3, #FFFFFF);
        padding: 22px;
        min-height: 260px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    }

    .upload-box:hover,
    .upload-box.is-dragover {
        border-color: #212121;
        box-shadow: 0 14px 30px rgba(33, 33, 33, .10);
        transform: translateY(-1px);
    }

    .upload-icon {
        width: 48px;
        height: 48px;
        border-radius: 16px;
        display: grid;
        place-items: center;
        color: #212121;
        background: rgba(33, 33, 33, .10);
        font-size: 24px;
        margin-bottom: 14px;
    }

    .upload-box h3 {
        font-size: 16px;
        font-weight: 800;
        color: #212121;
        margin: 0 0 12px;
    }

    .upload-main {
        color: #212121;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .upload-or,
    .upload-help,
    .upload-note,
    .upload-current {
        color: #555555;
        font-size: 13px;
        margin-bottom: 8px;
    }

    .upload-current {
        margin-top: 12px;
        word-break: break-all;
    }

    .upload-box input[type=file] {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .upload-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 40px;
        border-radius: 999px;
        padding: 0 16px;
        color: #212121;
        border: 1px solid rgba(33, 33, 33, .25);
        background: #fff;
        font-weight: 700;
        cursor: pointer;
        width: fit-content;
    }

    .brand-field label,
    .color-field label {
        color: #212121;
        font-size: 13px;
        font-weight: 800;
        margin-bottom: 8px;
    }

    .brand-input,
    .brand-select {
        min-height: 48px;
        border-radius: 14px;
        border: 1px solid #CECECE;
        padding: 10px 14px;
    }

    .color-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
    }

    .color-control {
        display: flex;
        align-items: center;
        gap: 10px;
        border: 1px solid #CECECE;
        border-radius: 14px;
        padding: 8px 12px;
        background: #F3F3F3;
    }

    .color-control input[type=color] {
        width: 42px;
        height: 42px;
        border: 0;
        background: transparent;
        padding: 0;
        cursor: pointer;
    }

    .color-value {
        border: 0;
        background: transparent;
        color: #212121;
        font-weight: 800;
        width: 100%;
        text-transform: uppercase;
        outline: 0;
    }

    .brand-savebar {
        display: flex;
        justify-content: flex-end;
        margin-top: 22px;
    }

    .brand-save-button {
        min-height: 50px;
        border: 0;
        border-radius: 14px;
        background: linear-gradient(135deg, #212121, #555555);
        color: #fff;
        font-weight: 800;
        padding: 0 24px;
        box-shadow: 0 16px 28px rgba(33, 33, 33, .22);
    }

    .preview-card {
        position: sticky;
        top: 24px;
    }

    .preview-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        margin-bottom: 16px;
    }

    .live-badge {
        background: rgba(34, 197, 94, .12);
        color: #16A34A;
        border-radius: 999px;
        padding: 6px 10px;
        font-size: 12px;
        font-weight: 800;
    }

    .public-preview {
        border: 1px solid #CECECE;
        border-radius: 20px;
        overflow: hidden;
        background: var(--preview-bg, #FFFFFF);
        color: var(--preview-text, #212121);
    }

    .preview-cover {
        height: 112px;
        background: linear-gradient(135deg, var(--preview-primary, #212121), var(--preview-secondary, #555555));
        background-size: cover;
        background-position: center;
    }

    .preview-inner {
        padding: 22px;
    }

    .preview-logo {
        max-width: 150px;
        max-height: 58px;
        object-fit: contain;
        margin-bottom: 14px;
    }

    .preview-name {
        font-size: 22px;
        font-weight: 850;
        letter-spacing: -.03em;
        margin-bottom: 4px;
    }

    .preview-subtitle {
        color: #555555;
        font-size: 14px;
        margin-bottom: 18px;
    }

    .preview-field {
        margin-bottom: 12px;
    }

    .preview-field label {
        display: block;
        color: inherit;
        font-size: 12px;
        font-weight: 800;
        margin-bottom: 6px;
    }

    .preview-fake-input {
        min-height: 42px;
        border: 1px solid #CECECE;
        border-radius: 12px;
        background: #fff;
    }

    .preview-submit {
        width: 100%;
        min-height: 46px;
        border-radius: 12px;
        border: 0;
        color: #fff;
        background: var(--preview-button, #212121);
        font-weight: 800;
        margin-top: 8px;
    }

    @media (max-width: 1199px) {
        .brand-layout {
            grid-template-columns: 1fr;
        }

        .preview-card {
            position: static;
        }
    }

    @media (max-width: 767px) {
        .brand-page-header,
        .brand-savebar {
            display: block;
        }

        .upload-grid,
        .color-grid {
            grid-template-columns: 1fr;
        }

        .brand-card-body {
            padding: 18px;
        }

        .brand-save-button {
            width: 100%;
        }
    }
</style>

<div class="brand-page-header">
    <div>
        <div class="brand-page-eyebrow">FormOps</div>
        <h1 class="brand-page-title">Identidade visual</h1>
        <p class="brand-page-subtitle">Personalize a aparência dos formulários públicos da sua organização.</p>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success">Identidade visual atualizada.</div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-danger"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php endif; ?>

<form method="post" action="<?= htmlspecialchars(appUrl('brand-settings')) ?>" enctype="multipart/form-data" id="brandForm">
    <div class="brand-layout">
        <div class="brand-stack">
            <section class="brand-card">
                <div class="brand-card-body">
                    <h2 class="brand-card-title">Uploads</h2>
                    <p class="brand-card-text">Envie os arquivos que compõem a presença visual dos formulários públicos.</p>

                    <div class="upload-grid">
                        <div>
                            <h3 class="brand-card-title">Upload da logo</h3>
                            <label class="upload-box" for="logo_file" data-drop-zone="logo_file">
                                <div>
                                    <div class="upload-icon">↑</div>
                                    <div class="upload-main">Arraste e solte sua logo aqui</div>
                                    <div class="upload-or">ou</div>
                                    <span class="upload-button">Selecionar arquivo</span>
                                    <div class="upload-help mt-3">PNG, JPG ou SVG. Máx. 2MB.</div>
                                    <div class="upload-note">A logo será exibida no topo dos formulários públicos.</div>
                                </div>
                                <?php if ($previewLogo): ?>
                                    <div class="upload-current">Atual: <?= htmlspecialchars($tenant['logo_path'] ?? $tenant['logo'] ?? '') ?></div>
                                <?php endif; ?>
                                <div class="upload-current d-none" data-file-name="logo_file"></div>
                                <input type="file" name="logo_file" id="logo_file" accept=".jpg,.jpeg,.png,.svg,image/jpeg,image/png,image/svg+xml">
                            </label>
                        </div>

                        <div>
                            <h3 class="brand-card-title">Imagem de capa (opcional)</h3>
                            <label class="upload-box" for="cover_image_file" data-drop-zone="cover_image_file">
                                <div>
                                    <div class="upload-icon">↑</div>
                                    <div class="upload-main">Arraste e solte a imagem aqui</div>
                                    <div class="upload-or">ou</div>
                                    <span class="upload-button">Selecionar arquivo</span>
                                    <div class="upload-help mt-3">PNG ou JPG. Recomendado 1600x600px.</div>
                                    <div class="upload-note">A imagem de capa será exibida no topo do formulário.</div>
                                </div>
                                <?php if ($previewCover): ?>
                                    <div class="upload-current">Atual: <?= htmlspecialchars($tenant['cover_image_path'] ?? '') ?></div>
                                <?php endif; ?>
                                <div class="upload-current d-none" data-file-name="cover_image_file"></div>
                                <input type="file" name="cover_image_file" id="cover_image_file" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
                            </label>
                        </div>
                    </div>
                </div>
            </section>

            <section class="brand-card">
                <div class="brand-card-body">
                    <h2 class="brand-card-title">Dados visuais</h2>
                    <p class="brand-card-text">Configure textos, cores e estilo usados nos formulários públicos.</p>

                    <div class="row g-3">
                        <div class="col-md-6 brand-field">
                            <label for="public_title">Título público</label>
                            <input class="form-control brand-input js-preview" id="public_title" name="public_title" maxlength="120" data-preview="title" value="<?= htmlspecialchars($tenant['public_title'] ?? '') ?>" placeholder="<?= htmlspecialchars($tenant['name']) ?>">
                        </div>
                        <div class="col-md-6 brand-field">
                            <label for="public_subtitle">Subtítulo público</label>
                            <input class="form-control brand-input js-preview" id="public_subtitle" name="public_subtitle" maxlength="180" data-preview="subtitle" value="<?= htmlspecialchars($tenant['public_subtitle'] ?? '') ?>" placeholder="Formulários inteligentes para empresas organizadas">
                        </div>
                    </div>

                    <div class="color-grid mt-3">
                        <?php foreach ([
                            'primary_color' => 'Cor principal',
                            'secondary_color' => 'Cor secundária',
                            'background_color' => 'Cor de fundo',
                            'text_color' => 'Cor do texto',
                            'button_color' => 'Cor do botão',
                        ] as $key => $label): ?>
                            <?php $color = brandingColor($tenant, $key, $colorDefaults); ?>
                            <div class="color-field">
                                <label for="<?= $key ?>"><?= $label ?></label>
                                <div class="color-control">
                                    <input type="color" id="<?= $key ?>" name="<?= $key ?>" value="<?= htmlspecialchars($color) ?>" data-color-key="<?= $key ?>">
                                    <input type="text" class="color-value" value="<?= htmlspecialchars($color) ?>" data-color-value="<?= $key ?>" maxlength="7" aria-label="Valor <?= htmlspecialchars($label) ?>">
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-6 brand-field">
                            <label for="form_style">Estilo</label>
                            <select class="form-select brand-select" id="form_style" name="form_style">
                                <?php foreach ($styles as $styleKey => $styleLabel): ?>
                                    <option value="<?= htmlspecialchars($styleKey) ?>" <?= ($tenant['form_style'] ?? 'clean') === $styleKey ? 'selected' : '' ?>><?= htmlspecialchars($styleLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <?php if (canManageTenantData()): ?>
                        <div class="brand-savebar">
                            <button type="submit" name="save_branding" value="1" class="brand-save-button">Salvar alterações</button>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <aside class="brand-card preview-card">
            <div class="brand-card-body">
                <div class="preview-header">
                    <h2 class="brand-card-title mb-0">Prévia do formulário público</h2>
                    <span class="live-badge">Ao vivo</span>
                </div>

                <div class="public-preview" id="publicPreview" style="--preview-primary: <?= htmlspecialchars(brandingColor($tenant, 'primary_color', $colorDefaults)) ?>; --preview-secondary: <?= htmlspecialchars(brandingColor($tenant, 'secondary_color', $colorDefaults)) ?>; --preview-bg: <?= htmlspecialchars(brandingColor($tenant, 'background_color', $colorDefaults)) ?>; --preview-text: <?= htmlspecialchars(brandingColor($tenant, 'text_color', $colorDefaults)) ?>; --preview-button: <?= htmlspecialchars(brandingColor($tenant, 'button_color', $colorDefaults)) ?>;">
                    <div class="preview-cover" id="previewCover" <?= $previewCover ? 'style="background-image: url(' . htmlspecialchars($previewCover) . ')"' : '' ?>></div>
                    <div class="preview-inner">
                        <?php if ($previewLogo): ?>
                            <img class="preview-logo" id="previewLogo" src="<?= htmlspecialchars($previewLogo) ?>" alt="Logo">
                        <?php else: ?>
                            <img class="preview-logo d-none" id="previewLogo" alt="Logo">
                        <?php endif; ?>
                        <div class="preview-name" id="previewTitle"><?= htmlspecialchars($previewTitle) ?></div>
                        <div class="preview-subtitle" id="previewSubtitle"><?= htmlspecialchars($previewSubtitle) ?></div>

                        <div class="preview-field"><label>Nome completo</label><div class="preview-fake-input"></div></div>
                        <div class="preview-field"><label>E-mail</label><div class="preview-fake-input"></div></div>
                        <div class="preview-field"><label>Assunto</label><div class="preview-fake-input"></div></div>
                        <button type="button" class="preview-submit">Enviar</button>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</form>

<script>
    const fallbackTitle = <?= json_encode($tenant['name'], JSON_UNESCAPED_UNICODE) ?>;
    const fallbackSubtitle = 'Formulários inteligentes para empresas organizadas.';
    const preview = document.getElementById('publicPreview');
    const previewTitle = document.getElementById('previewTitle');
    const previewSubtitle = document.getElementById('previewSubtitle');
    const previewLogo = document.getElementById('previewLogo');
    const previewCover = document.getElementById('previewCover');

    document.querySelectorAll('.js-preview').forEach((input) => {
        input.addEventListener('input', () => {
            if (input.dataset.preview === 'title') {
                previewTitle.textContent = input.value.trim() || fallbackTitle;
            }
            if (input.dataset.preview === 'subtitle') {
                previewSubtitle.textContent = input.value.trim() || fallbackSubtitle;
            }
        });
    });

    const cssVars = {
        primary_color: '--preview-primary',
        secondary_color: '--preview-secondary',
        background_color: '--preview-bg',
        text_color: '--preview-text',
        button_color: '--preview-button'
    };

    document.querySelectorAll('[data-color-key]').forEach((colorInput) => {
        const textInput = document.querySelector(`[data-color-value="${colorInput.dataset.colorKey}"]`);
        const sync = (value) => {
            if (/^#[0-9A-Fa-f]{6}$/.test(value)) {
                colorInput.value = value;
                textInput.value = value.toUpperCase();
                preview.style.setProperty(cssVars[colorInput.dataset.colorKey], value);
            }
        };
        colorInput.addEventListener('input', () => sync(colorInput.value));
        textInput.addEventListener('input', () => sync(textInput.value));
    });

    const previewFile = (inputId, callback) => {
        const input = document.getElementById(inputId);
        input?.addEventListener('change', () => {
            const file = input.files?.[0];
            if (!file) return;
            const fileName = document.querySelector(`[data-file-name="${inputId}"]`);
            if (fileName) {
                fileName.textContent = `Selecionado: ${file.name}`;
                fileName.classList.remove('d-none');
            }
            const url = URL.createObjectURL(file);
            callback(url);
        });
    };

    document.querySelectorAll('[data-drop-zone]').forEach((zone) => {
        const input = document.getElementById(zone.dataset.dropZone);
        ['dragenter', 'dragover'].forEach((eventName) => {
            zone.addEventListener(eventName, (event) => {
                event.preventDefault();
                zone.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach((eventName) => {
            zone.addEventListener(eventName, (event) => {
                event.preventDefault();
                zone.classList.remove('is-dragover');
            });
        });
        zone.addEventListener('drop', (event) => {
            const file = event.dataTransfer?.files?.[0];
            if (!file || !input) return;
            const transfer = new DataTransfer();
            transfer.items.add(file);
            input.files = transfer.files;
            input.dispatchEvent(new Event('change'));
        });
    });

    previewFile('logo_file', (url) => {
        previewLogo.src = url;
        previewLogo.classList.remove('d-none');
    });

    previewFile('cover_image_file', (url) => {
        previewCover.style.backgroundImage = `url(${url})`;
    });
</script>

<?php require __DIR__ . '/../../layouts/admin-footer.php'; ?>
