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
    $groupId = $_SESSION['user']['form_group_id'] ?? null;
    return $groupId ? (int) $groupId : null;
}

function shouldScopeTenantUserToGroup(): bool
{
    if (!isTenantUser()) {
        return false;
    }

    return currentUserFormGroupId() !== null;
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
