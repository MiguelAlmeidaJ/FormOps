<?php
$currentPath = '/' . trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$currentSegment = basename($currentPath);

if (!function_exists('adminMenuActive')) {
    function adminMenuActive(array $routes): string
    {
        global $currentSegment;
        return in_array($currentSegment, $routes, true) ? 'active' : '';
    }
}

if (!function_exists('adminSidebarIcon')) {
    function adminSidebarIcon(string $name): string
    {
        $icons = [
            'dashboard' => '<path d="M3 13h8V3H3v10Zm0 8h8v-6H3v6Zm10 0h8V11h-8v10Zm0-18v6h8V3h-8Z"/>',
            'groups' => '<path d="M3 7a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z"/>',
            'forms' => '<path d="M6 3h8l4 4v14H6V3Zm7 1.5V8h3.5"/><path d="M9 12h6M9 16h6"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'responses' => '<path d="M5 19V9M12 19V5M19 19v-7"/>',
            'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'palette' => '<path d="M12 3a9 9 0 0 0 0 18h1.5a2 2 0 0 0 1.4-3.4 1.4 1.4 0 0 1 1-2.4H17a4 4 0 0 0 4-4C21 6.6 17 3 12 3Z"/><path d="M7.5 10.5h.01M10 7.5h.01M14 7.5h.01M16.5 10.5h.01"/>',
            'settings' => '<path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.88-.34 1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.09a1.7 1.7 0 0 0-1-1.55 1.7 1.7 0 0 0-1.88.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 0-4h.09a1.7 1.7 0 0 0 1.55-1 1.7 1.7 0 0 0-.34-1.88l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.55V3a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1 1.55 1.7 1.7 0 0 0 1.88-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9c.66.2 1.11.8 1.11 1.5v1c0 .7-.45 1.3-1.11 1.5Z"/>',
            'logout' => '<path d="M10 17l5-5-5-5"/><path d="M15 12H3"/><path d="M21 3v18"/>',
            'help' => '<path d="M12 18h.01"/><path d="M9.1 9a3 3 0 1 1 5.4 1.8c-.9.7-1.5 1.2-1.5 2.7"/>',
            'back' => '<path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/>',
        ];
        $content = $icons[$name] ?? $icons['forms'];
        return '<svg class="sidebar-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $content . '</svg>';
    }
}

$contextTenantName = isSuperAdmin()
    ? ($_SESSION['maintenance_tenant_name'] ?? 'Empresa em manutenção')
    : ($currentUser['tenant_name'] ?? 'Organização');

$menuSections = [
    'Principal' => [
        ['label' => 'Painel', 'href' => 'painel', 'icon' => 'dashboard', 'routes' => ['painel', 'dashboard']],
        ['label' => 'Grupos', 'href' => 'groups', 'icon' => 'groups', 'routes' => ['groups', 'group-create', 'group-edit', 'group-forms']],
        ['label' => 'Formulários', 'href' => 'forms', 'icon' => 'forms', 'routes' => ['forms', 'form-edit', 'form-duplicate']],
        ['label' => 'Novo formulário', 'href' => 'form-create', 'icon' => 'plus', 'routes' => ['form-create'], 'featured' => true],
        ['label' => 'Respostas', 'href' => 'responses', 'icon' => 'responses', 'routes' => ['responses', 'response-view']],
        ['label' => 'Usuários', 'href' => 'users', 'icon' => 'users', 'routes' => ['users', 'user-create', 'user-edit', 'user-reset-password']],
    ],
    'Personalização' => [
        ['label' => 'Identidade visual', 'href' => 'brand-settings', 'icon' => 'palette', 'routes' => ['brand-settings']],
    ],
    'Sistema' => [
        ['label' => 'Configurações', 'href' => 'settings', 'icon' => 'settings', 'routes' => ['settings', 'configuracoes', 'migrations', 'migrate', 'cache-clear', 'config-clear', 'route-clear', 'optimize-clear']],
    ],
];

if (!isSuperAdmin() && ($currentUser['role'] ?? null) !== 'admin') {
    $menuSections['Principal'] = array_values(array_filter(
        $menuSections['Principal'],
        fn ($item) => ($item['href'] ?? '') !== 'users'
    ));
}
?>

<aside class="admin-sidebar">
    <div class="admin-sidebar-inner">
        <div class="admin-sidebar-brand">
            <div class="formops-brand-row">
                <img class="formops-logo-mark" src="assets/clients/formops/favicon-formops.jpg" alt="FormOps">
                <div>
                    <div class="formops-brand-title">FormOps</div>
                    <div class="formops-brand-subtitle">Workspace</div>
                </div>
            </div>
        </div>

        <nav class="admin-sidebar-nav" aria-label="Navegação principal">
            <?php foreach ($menuSections as $sectionLabel => $sectionItems): ?>
                <div class="admin-nav-section">
                    <div class="admin-nav-section-label"><?= htmlspecialchars($sectionLabel) ?></div>
                    <?php foreach ($sectionItems as $item): ?>
                        <a href="<?= htmlspecialchars($item['href']) ?>" class="admin-nav-item <?= !empty($item['featured']) ? 'admin-nav-featured' : '' ?> <?= adminMenuActive($item['routes']) ?>" title="<?= htmlspecialchars($item['label']) ?>">
                            <?= adminSidebarIcon($item['icon']) ?>
                            <span><?= htmlspecialchars($item['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <?php if (isSuperAdmin()): ?>
                <div class="admin-nav-divider"></div>
                <a href="system-dashboard" class="admin-nav-item <?= adminMenuActive(['system-dashboard']) ?>" title="Voltar ao sistema">
                    <?= adminSidebarIcon('back') ?>
                    <span>Voltar ao sistema</span>
                </a>
            <?php endif; ?>
        </nav>

        <div class="admin-sidebar-footer">
            <a href="logout" class="admin-nav-item admin-logout-item" title="Sair">
                <?= adminSidebarIcon('logout') ?>
                <span>Sair</span>
            </a>
        </div>
    </div>
</aside>

<div class="admin-main">
    <header class="admin-topbar">
        <div class="container-fluid px-4">
            <div class="d-flex justify-content-between align-items-center">

                <div>
                    <div class="fw-semibold">
                        <?= htmlspecialchars($pageTitle ?? 'Painel') ?>
                    </div>
                    <div class="small text-muted">
                        <?= htmlspecialchars($contextTenantName) ?>
                    </div>
                </div>

                <div class="d-flex align-items-center gap-3">
                    <span class="small text-muted d-none d-md-inline">
                        <?= htmlspecialchars($currentUser['name'] ?? '') ?>
                    </span>

                    <?php if (isSuperAdmin()): ?>
                        <a href="system-maintenance-exit" class="btn btn-sm btn-outline-danger">Sair da manutenção</a>
                    <?php else: ?>
                        <a href="logout" class="btn btn-sm btn-outline-danger">Sair</a>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </header>

    <main class="admin-content">
        <?php if (isSuperAdmin() && activeMaintenanceTenantId()): ?>
            <div class="alert alert-warning d-flex justify-content-between align-items-center">
                <span><strong>Modo manutenção:</strong> você está visualizando a empresa <?= htmlspecialchars($contextTenantName) ?></span>
                <a href="system-maintenance-exit" class="btn btn-sm btn-warning">Sair da manutenção</a>
            </div>
        <?php endif; ?>
