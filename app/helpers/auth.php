<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (!headers_sent()) {
        session_set_cookie_params([
            'httponly' => true,
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'samesite' => 'Lax',
        ]);
    }
    session_start();
}

function requireLogin(): void
{
    if (empty($_SESSION['user'])) {
        redirectTo('login');
    }
}

function requireTenant(): void
{
    requireLogin();

    if (isSuperAdmin() || empty($_SESSION['user']['tenant_id'])) {
        redirectTo(isSuperAdmin() ? 'system-tenants' : 'login');
    }
}

function requireSuperAdmin(): void
{
    requireLogin();

    if (!isSuperAdmin()) {
        redirectTo('painel');
    }
}

function requireTenantContext(): void
{
    requireLogin();

    if (isSuperAdmin()) {
        if (!activeMaintenanceTenantId()) {
            redirectTo('system-tenants');
        }
        return;
    }

    if (empty($_SESSION['user']['tenant_id'])) {
        redirectTo('login');
    }
}

function user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function tenantId(): ?int
{
    $tenantId = $_SESSION['user']['tenant_id'] ?? null;
    return $tenantId ? (int) $tenantId : null;
}

function isSuperAdmin(): bool
{
    return ($_SESSION['user']['role'] ?? null) === 'super_admin';
}

function isTenantUser(): bool
{
    return !isSuperAdmin() && !empty($_SESSION['user']['tenant_id']);
}

function canManageTenantData(): bool
{
    if (isSuperAdmin()) {
        return activeMaintenanceTenantId() !== null;
    }

    return isTenantUser() && in_array($_SESSION['user']['role'] ?? null, ['admin', 'editor'], true);
}

function currentUserFormGroupId(): ?int
{
    $groupIds = currentUserFormGroupIds();
    return $groupIds[0] ?? null;
}

function currentUserFormGroupIds(): array
{
    $storedIds = $_SESSION['user']['form_group_ids'] ?? null;
    if (is_array($storedIds)) {
        return array_values(array_unique(array_filter(array_map('intval', $storedIds), static fn (int $id): bool => $id > 0)));
    }

    $legacyId = (int) ($_SESSION['user']['form_group_id'] ?? 0);
    return $legacyId > 0 ? [$legacyId] : [];
}

function shouldScopeTenantUserToGroup(): bool
{
    if (!isTenantUser()) {
        return false;
    }

    return currentUserFormGroupIds() !== [];
}

function currentUserCanAccessFormGroup(?int $groupId): bool
{
    if (!shouldScopeTenantUserToGroup()) {
        return true;
    }

    return $groupId !== null && in_array($groupId, currentUserFormGroupIds(), true);
}

function currentUserFormGroupScopeSql(string $column = 'form_group_id'): string
{
    $groupIds = currentUserFormGroupIds();
    if (!$groupIds) {
        return '1 = 1';
    }

    return $column . ' IN (' . implode(', ', array_fill(0, count($groupIds), '?')) . ')';
}

function activeMaintenanceTenantId(): ?int
{
    if (!isSuperAdmin() || empty($_SESSION['maintenance_tenant_id'])) {
        return null;
    }

    return (int) $_SESSION['maintenance_tenant_id'];
}

function setMaintenanceTenant($tenantId): void
{
    requireSuperAdmin();
    $_SESSION['maintenance_tenant_id'] = (int) $tenantId;
}

function clearMaintenanceTenant(): void
{
    unset(
        $_SESSION['maintenance_tenant_id'],
        $_SESSION['maintenance_tenant_name'],
        $_SESSION['maintenance_tenant_slug']
    );
}

function currentTenantIdForData(): ?int
{
    return isSuperAdmin() ? activeMaintenanceTenantId() : tenantId();
}

function ensurePasswordResetInfrastructure(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_reset_codes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        code_hash VARCHAR(255) NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        requested_ip VARCHAR(45) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_password_reset_user_active (user_id, used_at, expires_at),
        CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function passwordResetCode(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($index = 0; $index < 8; $index++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}

function normalizedPasswordResetCode(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
}

function maskedEmailAddress(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if ($domain === '') {
        return $email;
    }
    $visible = mb_substr($local, 0, min(2, mb_strlen($local)));
    return $visible . str_repeat('*', max(3, mb_strlen($local) - mb_strlen($visible))) . '@' . $domain;
}

function sendPasswordResetCodeEmail(array $user, string $code): bool
{
    $safeName = htmlspecialchars((string) ($user['name'] ?? 'Usuário'), ENT_QUOTES, 'UTF-8');
    $safeTenant = htmlspecialchars((string) ($user['tenant_name'] ?? 'FormOps'), ENT_QUOTES, 'UTF-8');
    $primary = ticketColor($user['primary_color'] ?? null, '#212121');
    $html = '<!doctype html><html lang="pt-br"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
        . '<body style="margin:0;background:#F3F3F3;font-family:Arial,Helvetica,sans-serif;color:#212121">'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" style="padding:32px 14px">'
        . '<table role="presentation" width="560" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:560px;background:#fff;border:1px solid #CECECE;border-radius:18px">'
        . '<tr><td style="padding:34px 38px"><div style="color:#777;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em">' . $safeTenant . '</div>'
        . '<h1 style="margin:12px 0 8px;font-size:26px;line-height:1.25">Recuperação de acesso</h1>'
        . '<p style="margin:0;color:#555;font-size:15px;line-height:1.6">Olá, ' . $safeName . '. Use o código abaixo para criar uma nova senha:</p>'
        . '<div style="margin:26px 0;padding:20px;border-radius:14px;background:#F3F3F3;color:' . $primary . ';font-size:32px;font-weight:800;letter-spacing:.2em;text-align:center">' . htmlspecialchars($code) . '</div>'
        . '<p style="margin:0;color:#555;font-size:13px;line-height:1.6">Este código expira em <strong>15 minutos</strong> e pode ser utilizado uma única vez.</p>'
        . '<p style="margin:18px 0 0;color:#999;font-size:12px;line-height:1.6">Se você não solicitou a recuperação, ignore este e-mail. Sua senha atual continuará válida.</p>'
        . '</td></tr></table></td></tr></table></body></html>';

    $result = sendFormOpsEmail(
        (string) $user['email'],
        'Seu código de recuperação do FormOps',
        $html,
        filter_var($user['tenant_email'] ?? null, FILTER_VALIDATE_EMAIL) ? $user['tenant_email'] : null,
        (string) ($user['tenant_name'] ?? 'FormOps')
    );
    return (bool) ($result['success'] ?? false);
}
