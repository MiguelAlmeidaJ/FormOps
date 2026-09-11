<?php
$systemRoute = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');

function systemMenuActive(string $route): string
{
    global $systemRoute;
    return str_contains($systemRoute, $route) ? 'active' : '';
}

$systemMenu = [
    [
        'label' => 'Dashboard',
        'href' => 'system-dashboard',
        'icon' => 'dashboard',
        'active' => systemMenuActive('system-dashboard'),
        'section' => 'principal',
    ],
    [
        'label' => 'Empresas',
        'href' => 'system-tenants',
        'icon' => 'companies',
        'active' => systemMenuActive('system-tenants'),
        'section' => 'principal',
    ],
    [
        'label' => 'Criar empresa',
        'href' => 'system-tenant-create',
        'icon' => 'plus',
        'active' => systemMenuActive('system-tenant-create'),
        'section' => 'principal',
        'featured' => true,
    ],
];
?>

<aside class="system-sidebar">
    <div class="system-sidebar-inner">

        <div class="system-brand">
            <div class="system-brand-row">
                <div class="system-logo-wrap">
                    <img class="system-logo-mark" src="assets/clients/formops/favicon-formops.png" alt="FormOps">
                </div>

                <div>
                    <div class="system-brand-title">FormOps</div>
                    <div class="system-brand-subtitle">Administração</div>
                </div>
            </div>

            <div class="system-admin-badge">
                <span class="system-admin-dot"></span>
                Painel do sistema
            </div>
        </div>

        <nav class="system-nav">
            <div class="system-nav-section">
                <div class="system-nav-label">Principal</div>

                <?php foreach ($systemMenu as $item): ?>
                    <a
                        class="system-nav-link <?= htmlspecialchars($item['active']) ?> <?= !empty($item['featured']) ? 'featured' : '' ?>"
                        href="<?= htmlspecialchars($item['href']) ?>"
                    >
                        <span class="system-nav-icon">
                            <?php if ($item['icon'] === 'dashboard'): ?>
                                <svg viewBox="0 0 24 24" fill="none"><path d="M4 13h7V4H4v9Zm0 7h7v-5H4v5Zm9 0h7v-9h-7v9Zm0-16v5h7V4h-7Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                            <?php elseif ($item['icon'] === 'companies'): ?>
                                <svg viewBox="0 0 24 24" fill="none"><path d="M4 20V6.5A2.5 2.5 0 0 1 6.5 4h5A2.5 2.5 0 0 1 14 6.5V20M14 9h3.5A2.5 2.5 0 0 1 20 11.5V20M3 20h18M7 8h3M7 12h3M7 16h3M16 13h1" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?php elseif ($item['icon'] === 'plus'): ?>
                                <svg viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z" stroke="currentColor" stroke-width="1.8"/></svg>
                            <?php endif; ?>
                        </span>

                        <span><?= htmlspecialchars($item['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="system-nav-section">
                <div class="system-nav-label">Sistema</div>

                <a class="system-nav-link <?= systemMenuActive('system-settings') ?>" href="system-settings">
                    <span class="system-nav-icon">
                        <svg viewBox="0 0 24 24" fill="none"><path d="M12 15.5A3.5 3.5 0 1 0 12 8a3.5 3.5 0 0 0 0 7.5Z" stroke="currentColor" stroke-width="1.8"/><path d="M19.4 15a1.8 1.8 0 0 0 .36 1.98l.05.05a2.1 2.1 0 0 1-2.97 2.97l-.05-.05a1.8 1.8 0 0 0-1.98-.36 1.8 1.8 0 0 0-1.1 1.66V21.4a2.1 2.1 0 0 1-4.2 0v-.08a1.8 1.8 0 0 0-1.1-1.66 1.8 1.8 0 0 0-1.98.36l-.05.05a2.1 2.1 0 0 1-2.97-2.97l.05-.05A1.8 1.8 0 0 0 4.6 15a1.8 1.8 0 0 0-1.66-1.1H2.86a2.1 2.1 0 0 1 0-4.2h.08A1.8 1.8 0 0 0 4.6 8a1.8 1.8 0 0 0-.36-1.98l-.05-.05A2.1 2.1 0 0 1 7.16 3l.05.05A1.8 1.8 0 0 0 9.2 3.4a1.8 1.8 0 0 0 1.1-1.66V1.66a2.1 2.1 0 0 1 4.2 0v.08a1.8 1.8 0 0 0 1.1 1.66 1.8 1.8 0 0 0 1.98-.36l.05-.05A2.1 2.1 0 0 1 20.6 6l-.05.05A1.8 1.8 0 0 0 20.2 8a1.8 1.8 0 0 0 1.66 1.1h.08a2.1 2.1 0 0 1 0 4.2h-.08A1.8 1.8 0 0 0 20.2 15Z" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </span>
                    <span>Configurações</span>
                </a>
            </div>
        </nav>

        <div class="system-sidebar-footer">
            <div class="system-help-card">
                <div class="system-help-icon">?</div>
                <div>
                    <strong>Central FormOps</strong>
                    <span>Gerencie empresas e manutenção.</span>
                </div>
            </div>

            <a class="system-logout" href="logout">
                <span class="system-nav-icon">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M10 6H6.5A2.5 2.5 0 0 0 4 8.5v7A2.5 2.5 0 0 0 6.5 18H10M15 8l4 4-4 4M19 12H9" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <span>Sair</span>
            </a>
        </div>
    </div>
</aside>

<div class="system-main">
    <header class="system-topbar">
        <div class="container-fluid px-4 d-flex justify-content-between align-items-center">
            <div>
                <strong><?= htmlspecialchars($pageTitle) ?></strong>
                <small>Painel administrativo do sistema</small>
            </div>

            <div class="system-user-pill">
                <span><?= htmlspecialchars($currentUser['name'] ?? 'Administrador') ?></span>
            </div>
        </div>
    </header>

    <main class="system-content">
