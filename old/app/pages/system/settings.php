<?php
requireSuperAdmin();

$pageTitle = 'Configurações do sistema';
$pageStyles = ['assets/system-settings.css'];
$currentUser = user();
$errors = [];
$success = null;
$maintenanceOutput = null;
$allowedActions = ['migrate_status', 'migrate_run', 'cache_clear', 'config_clear', 'route_clear', 'optimize_clear'];
$currentRoute = '/' . trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$showAllLogs = str_contains($currentRoute, 'system-settings/logs');

function systemSettingsTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function systemSettingsCount(PDO $pdo, string $table): int
{
    if (!systemSettingsTableExists($pdo, $table)) {
        return 0;
    }

    return (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
}

function systemEnsureMaintenanceLogs(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS system_maintenance_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        tenant_id INT NULL,
        action VARCHAR(80) NOT NULL,
        command VARCHAR(255) NOT NULL,
        output LONGTEXT NULL,
        status VARCHAR(30) NOT NULL,
        ip_address VARCHAR(45) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_system_maintenance_action (action),
        KEY idx_system_maintenance_user (user_id),
        KEY idx_system_maintenance_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function systemWriteMaintenanceLog(PDO $pdo, array $user, string $action, string $command, string $output, string $status): void
{
    systemEnsureMaintenanceLogs($pdo);
    $stmt = $pdo->prepare('INSERT INTO system_maintenance_logs (user_id, tenant_id, action, command, output, status, ip_address) VALUES (?, NULL, ?, ?, ?, ?, ?)');
    $stmt->execute([(int)($user['id'] ?? 0) ?: null, $action, $command, $output, $status, $_SERVER['REMOTE_ADDR'] ?? null]);
}

function systemMigrationFiles(): array
{
    $files = glob(__DIR__ . '/../../../database/updates/*.sql') ?: [];
    sort($files, SORT_NATURAL);
    return $files;
}

function systemSplitSqlStatements(string $sql): array
{
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    return array_values(array_filter(array_map('trim', explode(';', $sql))));
}

function systemMigrationStatus(PDO $pdo): array
{
    $ran = [];
    if (systemSettingsTableExists($pdo, 'system_maintenance_logs')) {
        $stmt = $pdo->query("SELECT command FROM system_maintenance_logs WHERE action = 'migrate_file' AND status = 'success'");
        $ran = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    $items = [];
    foreach (systemMigrationFiles() as $file) {
        $name = basename($file);
        $items[] = ['file' => $name, 'status' => in_array($name, $ran, true) ? 'executada' : 'pendente'];
    }
    return $items;
}

function systemExecMigrationStatement(PDO $pdo, string $statement): string
{
    try {
        $pdo->exec($statement);
        return 'executed';
    } catch (PDOException $exception) {
        $mysqlCode = (int) ($exception->errorInfo[1] ?? 0);
        if (in_array($mysqlCode, [1050, 1060, 1061], true)) {
            return 'skipped';
        }
        throw $exception;
    }
}

function systemRunPendingMigrations(PDO $pdo, array $user): string
{
    systemEnsureMaintenanceLogs($pdo);
    $output = [];

    foreach (systemMigrationStatus($pdo) as $item) {
        if ($item['status'] !== 'pendente') {
            continue;
        }

        $file = __DIR__ . '/../../../database/updates/' . $item['file'];
        $executed = 0;
        $skipped = 0;

        try {
            foreach (systemSplitSqlStatements((string) file_get_contents($file)) as $statement) {
                if (systemExecMigrationStatement($pdo, $statement) === 'skipped') {
                    $skipped++;
                } else {
                    $executed++;
                }
            }
            $message = $item['file'] . ': executada (' . $executed . ' comandos' . ($skipped ? ', ' . $skipped . ' já existiam' : '') . ').';
            systemWriteMaintenanceLog($pdo, $user, 'migrate_file', $item['file'], $message, 'success');
            $output[] = $message;
        } catch (Throwable $exception) {
            $message = $item['file'] . ': erro - ' . $exception->getMessage();
            systemWriteMaintenanceLog($pdo, $user, 'migrate_file', $item['file'], $message, 'error');
            $output[] = $message;
        }
    }

    return $output ? implode("\n", $output) : 'Nenhuma migration pendente.';
}

function systemClearDirectoryContents(string $path): int
{
    $root = realpath(__DIR__ . '/../../../');
    $target = realpath($path);
    if (!$root || !$target || !str_starts_with($target, $root) || !is_dir($target)) {
        return 0;
    }

    $removed = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } elseif (@unlink($item->getPathname())) {
            $removed++;
        }
    }
    return $removed;
}

function systemRunCacheAction(string $action): string
{
    $paths = match ($action) {
        'cache_clear' => ['storage/cache', 'storage/framework/cache', 'bootstrap/cache'],
        'config_clear' => ['bootstrap/cache/config.php'],
        'route_clear' => ['bootstrap/cache/routes.php', 'bootstrap/cache/routes-v7.php'],
        'optimize_clear' => ['storage/cache', 'storage/framework/cache', 'bootstrap/cache'],
        default => [],
    };

    $removed = 0;
    $root = realpath(__DIR__ . '/../../../');
    foreach ($paths as $relativePath) {
        $absolute = __DIR__ . '/../../../' . $relativePath;
        if (is_dir($absolute)) {
            $removed += systemClearDirectoryContents($absolute);
        } elseif (is_file($absolute)) {
            $target = realpath($absolute);
            if ($root && $target && str_starts_with($target, $root) && @unlink($target)) {
                $removed++;
            }
        }
    }
    return 'Arquivos removidos: ' . $removed . '.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['maintenance_action'])) {
    $action = (string) $_POST['maintenance_action'];

    if (!in_array($action, $allowedActions, true)) {
        $errors[] = 'Ação de manutenção inválida.';
    } else {
        try {
            if ($action === 'migrate_status') {
                $items = systemMigrationStatus($pdo);
                $pending = array_filter($items, fn ($item) => $item['status'] === 'pendente');
                $maintenanceOutput = implode("\n", array_map(fn ($item) => $item['file'] . ' - ' . $item['status'], $items));
                $success = 'Status das migrations verificado com sucesso. Pendentes: ' . count($pending) . '.';
                systemWriteMaintenanceLog($pdo, $currentUser, $action, 'database/updates/*.sql status', $maintenanceOutput, 'success');
            } elseif ($action === 'migrate_run') {
                $maintenanceOutput = systemRunPendingMigrations($pdo, $currentUser);
                $success = 'Execução de migrations finalizada.';
                systemWriteMaintenanceLog($pdo, $currentUser, $action, 'database/updates/*.sql run', $maintenanceOutput, 'success');
            } else {
                $maintenanceOutput = systemRunCacheAction($action);
                $labels = [
                    'cache_clear' => 'Cache limpo com sucesso.',
                    'config_clear' => 'Configurações limpas com sucesso.',
                    'route_clear' => 'Rotas limpas com sucesso.',
                    'optimize_clear' => 'Otimizações limpas com sucesso.',
                ];
                $success = $labels[$action] ?? 'Ação executada com sucesso.';
                systemWriteMaintenanceLog($pdo, $currentUser, $action, $action, $maintenanceOutput, 'success');
            }
        } catch (Throwable $exception) {
            $errors[] = 'Erro ao executar manutenção. Verifique os logs.';
            $maintenanceOutput = $exception->getMessage();
            systemWriteMaintenanceLog($pdo, $currentUser, $action, $action, $maintenanceOutput, 'error');
        }
    }
}

$dbConnected = false;
$databaseName = '-';
$environment = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'local';

try {
    $databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $dbConnected = $databaseName !== '';
} catch (Throwable $exception) {
    $dbConnected = false;
}

$totalTenants = systemSettingsCount($pdo, 'tenants');
$totalUsers = systemSettingsCount($pdo, 'users');
$totalForms = systemSettingsCount($pdo, 'forms');
$totalResponses = systemSettingsCount($pdo, 'form_responses');
$migrationItems = systemMigrationStatus($pdo);
$pendingMigrations = array_filter($migrationItems, fn ($item) => $item['status'] === 'pendente');
$maintenanceLogs = [];
$lastMaintenance = null;

if (systemSettingsTableExists($pdo, 'system_maintenance_logs')) {
    $logLimit = $showAllLogs ? 100 : 8;
    $maintenanceLogs = $pdo->query('SELECT l.*, u.name AS user_name FROM system_maintenance_logs l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.created_at DESC, l.id DESC LIMIT ' . $logLimit)->fetchAll(PDO::FETCH_ASSOC);
    $lastMaintenance = $maintenanceLogs[0]['created_at'] ?? null;
}

$checkedAt = date('d/m/Y H:i');

require __DIR__ . '/../../layouts/system-header.php';
require __DIR__ . '/../../layouts/system-sidebar.php';
?>

<section class="system-settings-hero premium">
    <div>
        <span class="system-settings-kicker">Administração global</span>
        <h1>Configurações do sistema</h1>
        <p>Gerencie informações operacionais, segurança, manutenção e auditoria do FormOps.</p>
    </div>
    <div class="system-hero-actions">
        <span class="system-admin-role-badge">Super Admin</span>
        <a class="btn btn-primary" href="system-dashboard">Voltar ao dashboard</a>
    </div>
</section>

<?php if ($success): ?><div class="alert alert-success system-alert"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger system-alert"><?= htmlspecialchars($error) ?></div><?php endforeach; ?>

<section class="system-settings-grid premium">
    <article class="system-settings-card">
        <div class="system-card-title"><span class="system-card-icon">ENV</span><h2>Ambiente</h2></div>
        <div class="system-settings-list">
            <div><span>Aplicação</span><strong>FormOps</strong></div>
            <div><span>Ambiente</span><strong class="system-badge muted"><?= htmlspecialchars($environment) ?></strong></div>
            <div><span>Banco conectado</span><strong class="system-badge <?= $dbConnected ? 'success' : 'danger' ?>"><?= $dbConnected ? 'Conectado' : 'Indisponível' ?></strong></div>
            <div><span>Banco atual</span><strong><?= htmlspecialchars($databaseName) ?></strong></div>
            <div><span>Versão PHP</span><strong><?= htmlspecialchars(PHP_VERSION) ?></strong></div>
            <div><span>Última verificação</span><strong><?= htmlspecialchars($checkedAt) ?></strong></div>
        </div>
    </article>

    <article class="system-settings-card">
        <div class="system-card-title"><span class="system-card-icon">SEC</span><h2>Segurança</h2></div>
        <div class="system-settings-list">
            <div><span>Usuário logado</span><strong><?= htmlspecialchars($currentUser['name'] ?? '-') ?></strong></div>
            <div><span>E-mail</span><strong><?= htmlspecialchars($currentUser['email'] ?? '-') ?></strong></div>
            <div><span>Perfil</span><strong class="system-badge primary"><?= htmlspecialchars($currentUser['role'] ?? '-') ?></strong></div>
            <div><span>Permissão</span><strong class="system-badge success">Administrador do sistema</strong></div>
            <div><span>Último acesso</span><strong><?= htmlspecialchars($currentUser['last_login_at'] ?? '-') ?></strong></div>
        </div>
        <div class="system-security-note">Esta área é restrita a administradores globais.</div>
    </article>

    <article class="system-settings-card full">
        <div class="system-card-title"><span class="system-card-icon">SUM</span><h2>Resumo da plataforma</h2></div>
        <div class="system-summary-grid premium">
            <div><span class="metric-icon">TEN</span><strong><?= $totalTenants ?></strong><small>Empresas</small><em>Tenants cadastrados</em></div>
            <div><span class="metric-icon">USR</span><strong><?= $totalUsers ?></strong><small>Usuários</small><em>Usuários registrados</em></div>
            <div><span class="metric-icon">FRM</span><strong><?= $totalForms ?></strong><small>Formulários</small><em>Formulários criados</em></div>
            <div><span class="metric-icon">RSP</span><strong><?= $totalResponses ?></strong><small>Respostas</small><em>Respostas recebidas</em></div>
        </div>
    </article>

    <article class="system-settings-card full">
        <div class="system-card-title"><span class="system-card-icon">OPS</span><h2>Manutenção do sistema</h2></div>
        <p class="system-card-description">Execute rotinas administrativas com segurança. Essas ações ficam restritas a super administradores.</p>

        <div class="system-maintenance-status">
            <div><span>Última manutenção</span><strong><?= $lastMaintenance ? date('d/m/Y H:i', strtotime($lastMaintenance)) : 'Nenhuma registrada' ?></strong></div>
            <div><span>Logs recentes</span><strong><?= count($maintenanceLogs) ?></strong></div>
            <div><span>Migrations pendentes</span><strong><?= count($pendingMigrations) ?></strong></div>
            <div><span>Ambiente atual</span><strong><?= htmlspecialchars($environment) ?></strong></div>
            <div><span>Banco conectado</span><strong><?= $dbConnected ? 'Sim' : 'Não' ?></strong></div>
        </div>

        <div class="system-maintenance-actions">
            <form method="post"><input type="hidden" name="maintenance_action" value="migrate_status"><button class="btn btn-outline-primary">Verificar migrations</button></form>
            <form method="post" onsubmit="return confirm('Tem certeza que deseja executar as migrations pendentes? Essa ação pode alterar a estrutura do banco de dados.');"><input type="hidden" name="maintenance_action" value="migrate_run"><button class="btn btn-primary">Executar migrations pendentes</button></form>
            <form method="post" onsubmit="return confirm('Tem certeza que deseja limpar o cache da aplicação?');"><input type="hidden" name="maintenance_action" value="cache_clear"><button class="btn btn-outline-secondary">Limpar cache</button></form>
            <form method="post"><input type="hidden" name="maintenance_action" value="config_clear"><button class="btn btn-outline-secondary">Limpar configurações</button></form>
            <form method="post"><input type="hidden" name="maintenance_action" value="route_clear"><button class="btn btn-outline-secondary">Limpar rotas</button></form>
            <form method="post"><input type="hidden" name="maintenance_action" value="optimize_clear"><button class="btn btn-outline-secondary">Limpar otimizações</button></form>
        </div>

        <?php if ($maintenanceOutput !== null): ?><pre class="system-settings-output"><?= htmlspecialchars($maintenanceOutput) ?></pre><?php endif; ?>

        <div class="tenant-maintenance-box">
            <div><strong>Manutenção por tenant</strong><span>Abra a tela de manutenção para executar rotinas controladas no tenant selecionado.</span></div>
            <a class="btn btn-outline-primary" href="settings">Abrir manutenção do tenant selecionado</a>
        </div>
    </article>

    <article class="system-settings-card full">
        <div class="system-card-title between"><div><span class="system-card-icon">LOG</span><h2><?= $showAllLogs ? 'Auditoria de manutenção' : 'Logs recentes' ?></h2></div><?php if (!$showAllLogs): ?><a class="btn btn-sm btn-outline-primary" href="system-settings/logs">Ver todos os logs</a><?php else: ?><a class="btn btn-sm btn-outline-primary" href="system-settings">Voltar às configurações</a><?php endif; ?></div>
        <?php if ($maintenanceLogs): ?>
            <div class="table-responsive system-log-table-wrap">
                <table class="table align-middle mb-0 system-log-table">
                    <thead><tr><th>Data</th><th>Usuário</th><th>Ação</th><th>Status</th><th>Ambiente</th><th>Detalhes</th></tr></thead>
                    <tbody>
                        <?php foreach ($maintenanceLogs as $log): ?>
                            <tr>
                                <td><?= date('d/m/Y H:i', strtotime($log['created_at'])) ?></td>
                                <td><?= htmlspecialchars($log['user_name'] ?? 'Sistema') ?></td>
                                <td><?= htmlspecialchars($log['action']) ?></td>
                                <td><span class="system-badge <?= $log['status'] === 'success' ? 'success' : ($log['status'] === 'warning' ? 'warning' : 'danger') ?>"><?= htmlspecialchars($log['status']) ?></span></td>
                                <td><?= htmlspecialchars($environment) ?></td>
                                <td><?= htmlspecialchars($log['command'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="system-empty-state"><div>LOG</div><strong>Nenhum log de manutenção registrado.</strong><span>As ações administrativas executadas aparecerão aqui.</span></div>
        <?php endif; ?>
    </article>
</section>

<?php require __DIR__ . '/../../layouts/system-footer.php'; ?>
