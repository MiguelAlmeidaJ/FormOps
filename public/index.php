<?php
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

/**
 * Front controller unico da aplicacao.
 * Funciona com public/ como DocumentRoot e tambem pelo bridge da raiz do projeto.
 */
$scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
$appBasePath = $scriptDirectory === '/' || $scriptDirectory === '.' ? '' : rtrim($scriptDirectory, '/');

if (!defined('APP_BASE_PATH')) {
    define('APP_BASE_PATH', $appBasePath);
}

function appUrl(string $path = '', array $query = []): string
{
    $url = APP_BASE_PATH . '/' . ltrim($path, '/');
    if ($path === '' || $path === '/') {
        $url = APP_BASE_PATH . '/';
    }
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    return $url;
}

function redirectTo(string $path, array $query = [], int $status = 302): never
{
    header('Location: ' . appUrl($path, $query), true, $status);
    exit;
}

function absoluteAppUrl(string $path = '', array $query = []): string
{
    $forwardedProto = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $forwardedProto === 'https';
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . appUrl($path, $query);
}

require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/helpers/auth.php';
require_once __DIR__ . '/../app/helpers/forms.php';
require_once __DIR__ . '/../app/helpers/pricing.php';
require_once __DIR__ . '/../app/helpers/compliance.php';

ensureFormLifecycleColumns($pdo);
ensureUserGroupColumn($pdo);
ensureTicketInfrastructure($pdo);
ensurePricingInfrastructure($pdo);
ensurePasswordResetInfrastructure($pdo);
formOpsEnsureLgpdInfrastructure($pdo);
formOpsClearLgpdRequestContext($pdo);

$requestPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$requestPath = '/' . ltrim(preg_replace('#/+#', '/', $requestPath), '/');

if (APP_BASE_PATH !== '' && ($requestPath === APP_BASE_PATH || str_starts_with($requestPath, APP_BASE_PATH . '/'))) {
    $requestPath = substr($requestPath, strlen(APP_BASE_PATH)) ?: '/';
}

$route = '/' . trim($requestPath, '/');
if ($route === '//' || $route === '/index.php') {
    $route = '/';
}

$aliases = [
    '/forms/criar' => '/form-create',
    '/forms/editar' => '/form-edit',
    '/formulario' => '/form-public',
    '/form' => '/form-public',
];

if (isset($aliases[$route])) {
    $query = $_GET;
    redirectTo($aliases[$route], $query, 301);
}

$servePublicForm = static function (string $tenantSlug, string $formSlug) use ($pdo): never {
    $_GET['tenant'] = $tenantSlug;
    $_GET['slug'] = $formSlug;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!formOpsPublicConsentAccepted()) {
            formOpsRenderConsentRequired($tenantSlug, $formSlug);
        }
        formOpsSetLgpdRequestContext($pdo, true);
    }

    ob_start(static fn (string $html): string => formOpsInjectPublicCompliance($html, $tenantSlug, $formSlug));
    require __DIR__ . '/../app/pages/public/form-public.php';
    ob_end_flush();
    formOpsClearLgpdRequestContext($pdo);
    exit;
};

if (preg_match('#^/f/([^/]+)/([^/]+)$#', $route, $matches)) {
    $servePublicForm(rawurldecode($matches[1]), rawurldecode($matches[2]));
}

if ($route === '/form-public') {
    $servePublicForm(trim((string) ($_GET['tenant'] ?? '')), trim((string) ($_GET['slug'] ?? '')));
}

$routes = [
    '/' => 'auth/login.php',
    '/login' => 'auth/login.php',
    '/logout' => 'auth/logout.php',
    '/recuperar-acesso' => 'auth/forgot-password.php',
    '/redefinir-senha' => 'auth/reset-password.php',
    '/politica-de-privacidade' => 'public/privacy-policy.php',
    '/politica-de-cookies' => 'public/cookie-policy.php',

    '/painel' => 'admin/painel.php',
    '/dashboard' => 'admin/painel.php',
    '/groups' => 'admin/groups.php',
    '/group-create' => 'admin/group-create.php',
    '/group-edit' => 'admin/group-edit.php',
    '/group-forms' => 'admin/group-forms.php',
    '/forms' => 'admin/forms.php',
    '/form-create' => 'admin/form-create.php',
    '/form-duplicate' => 'admin/form-duplicate.php',
    '/form-edit' => 'admin/form-edit.php',
    '/form-delete' => 'admin/form-delete.php',
    '/form-mark-completed' => 'admin/form-mark-completed.php',
    '/form-dashboard' => 'admin/form-dashboard.php',
    '/form-attendance' => 'admin/form-attendance.php',
    '/ticket-check-in' => 'admin/ticket-check-in.php',
    '/form-field-delete' => 'admin/form-field-delete.php',
    '/form-fields-reorder' => 'admin/form-fields-reorder.php',
    '/form-field-width' => 'admin/form-field-width.php',
    '/form-reset-responses' => 'admin/form-reset-responses.php',
    '/form-toggle-status' => 'admin/form-toggle-status.php',
    '/responses' => 'admin/responses.php',
    '/response-view' => 'admin/response-view.php',
    '/users' => 'admin/users.php',
    '/user-create' => 'admin/user-create.php',
    '/user-edit' => 'admin/user-edit.php',
    '/user-reset-password' => 'admin/user-reset-password.php',
    '/brand-settings' => 'admin/brand-settings.php',
    '/settings' => 'admin/settings.php',
    '/configuracoes' => 'admin/settings.php',
    '/settings/maintenance/migrations' => 'admin/settings.php',
    '/settings/maintenance/migrate' => 'admin/settings.php',
    '/settings/maintenance/cache-clear' => 'admin/settings.php',
    '/settings/maintenance/config-clear' => 'admin/settings.php',
    '/settings/maintenance/route-clear' => 'admin/settings.php',
    '/settings/maintenance/optimize-clear' => 'admin/settings.php',

    '/system-dashboard' => 'system/dashboard.php',
    '/system-tenants' => 'system/tenants.php',
    '/system-tenant-create' => 'system/tenant-create.php',
    '/system-tenant-edit' => 'system/tenant-edit.php',
    '/system-tenant-forms' => 'system/tenant-forms.php',
    '/system-tenant-responses' => 'system/tenant-responses.php',
    '/system-users' => 'system/users.php',
    '/system-user-create' => 'system/user-create.php',
    '/system-user-edit' => 'system/user-edit.php',
    '/system-user-reset-password' => 'system/user-reset-password.php',
    '/system-settings' => 'system/settings.php',
    '/system-settings/logs' => 'system/settings.php',
    '/system-settings/migrations/status' => 'system/settings.php',
    '/system-settings/migrations/run' => 'system/settings.php',
    '/system-settings/cache/clear' => 'system/settings.php',
    '/system-settings/config/clear' => 'system/settings.php',
    '/system-settings/routes/clear' => 'system/settings.php',
    '/system-settings/optimize/clear' => 'system/settings.php',
    '/system-maintenance-start' => 'system/maintenance-start.php',
    '/system-maintenance-exit' => 'system/maintenance-exit.php',

    '/ingresso' => 'public/ticket-public.php',
    '/ingresso-pdf' => 'public/ticket-pdf.php',
];

if (isset($routes[$route])) {
    require __DIR__ . '/../app/pages/' . $routes[$route];
    exit;
}

http_response_code(404);
require __DIR__ . '/../app/pages/errors/404.php';

