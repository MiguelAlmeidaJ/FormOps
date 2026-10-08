<?php
/**
 * Global FormOps visual identity. Stored outside the repository so Git deploys
 * don't replace the images selected by the system administrator.
 */
function formopsSystemBrandDirectory(): string
{
    return dirname(__DIR__, 2) . '/public/storage/system/branding';
}

function formopsSystemBrandSettings(): array
{
    $file = formopsSystemBrandDirectory() . '/settings.json';
    $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
    return is_array($data) ? $data : [];
}

function formopsSystemBrandUrl(string $type): string
{
    $defaults = [
        'logo' => 'assets/clients/formops/logo-formops.png',
        'favicon' => 'assets/clients/formops/favicon-formops.png',
    ];
    $settings = formopsSystemBrandSettings();
    $path = $settings[$type] ?? $defaults[$type] ?? '';
    // Only paths in the controlled upload folder or shipped defaults are allowed.
    if (!in_array($path, $defaults, true) &&
        !preg_match('#^storage/system/branding/(logo|favicon)-[a-f0-9]{24}\\.(png|jpg|webp)$#', $path)) {
        $path = $defaults[$type] ?? '';
    }
    return appUrl($path);
}

function formopsSaveSystemBrandUploads(array &$errors): bool
{
    $settings = formopsSystemBrandSettings();
    $directory = formopsSystemBrandDirectory();
    $uploads = [];
    foreach (['logo' => 'Logo da sidebar', 'favicon' => 'Favicon da sidebar'] as $type => $label) {
        $file = $_FILES[$type . '_file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) < 1 || $file['size'] > 2 * 1024 * 1024) {
            $errors[] = $label . ': arquivo inválido ou maior que 2 MB.';
            continue;
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) {
            $errors[] = $label . ': upload inválido.';
            continue;
        }
        $info = @getimagesize($tmp);
        $mime = $info['mime'] ?? '';
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$mime] ?? null;
        if (!$extension || ($info[0] ?? 0) > 6000 || ($info[1] ?? 0) > 6000) {
            $errors[] = $label . ': envie uma imagem PNG, JPG ou WebP válida (até 6000 px).';
            continue;
        }
        $uploads[$type] = [$tmp, $extension];
    }
    if ($errors || !$uploads) return false;
    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        $errors[] = 'Não foi possível criar a pasta de imagens do sistema.';
        return false;
    }
    foreach ($uploads as $type => [$tmp, $ext]) {
        $name = $type . '-' . bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file($tmp, $directory . '/' . $name)) {
            $errors[] = 'Não foi possível salvar a imagem de ' . $type . '.';
            continue;
        }
        $settings[$type] = 'storage/system/branding/' . $name;
    }
    if ($errors) return false;
    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($directory . '/settings.json', $json, LOCK_EX) === false) {
        $errors[] = 'Não foi possível salvar as configurações de identidade visual.';
        return false;
    }
    return true;
}
