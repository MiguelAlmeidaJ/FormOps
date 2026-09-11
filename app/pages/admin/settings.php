<?php
requireTenantContext();

$tenantId = currentTenantIdForData();
$currentUser = user();
$pageTitle = 'Configurações';
$errors = [];
$success = null;
$maintenanceOutput = null;
$allowedMaintenanceActions = ['migrate_status', 'migrate_run', 'cache_clear', 'config_clear', 'route_clear', 'optimize_clear'];

$stmt = $pdo->prepare('SELECT * FROM tenants WHERE id = ? LIMIT 1');
$stmt->execute([$tenantId]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

function settingsTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function ensureMaintenanceLogTable(PDO $pdo): void
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

function writeMaintenanceLog(PDO $pdo, array $user, ?int $tenantId, string $action, string $command, string $output, string $status): void
{
    ensureMaintenanceLogTable($pdo);
    $stmt = $pdo->prepare('INSERT INTO system_maintenance_logs (user_id, tenant_id, action, command, output, status, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([(int)($user['id'] ?? 0) ?: null, $tenantId, $action, $command, $output, $status, $_SERVER['REMOTE_ADDR'] ?? null]);
}

function splitSqlStatements(string $sql): array
{
    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    return array_values($statements);
}

function migrationFiles(): array
{
    $files = glob(__DIR__ . '/../../../database/updates/*.sql') ?: [];
    sort($files, SORT_NATURAL);
    return $files;
}

function migrationStatus(PDO $pdo): array
{
    $hasLog = settingsTableExists($pdo, 'system_maintenance_logs');
    $ran = [];
    if ($hasLog) {
        $stmt = $pdo->query("SELECT command FROM system_maintenance_logs WHERE action = 'migrate_file' AND status = 'success'");
        $ran = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
    $items = [];
    foreach (migrationFiles() as $file) {
        $name = basename($file);
        $items[] = ['file' => $name, 'status' => in_array($name, $ran, true) ? 'executada' : 'pendente'];
    }
    return $items;
}

function execMigrationStatement(PDO $pdo, string $statement): string
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

function runPendingMigrations(PDO $pdo, array $user, ?int $tenantId): string
{
    ensureMaintenanceLogTable($pdo);
    $output = [];
    foreach (migrationStatus($pdo) as $item) {
        if ($item['status'] !== 'pendente') {
            continue;
        }
        $file = __DIR__ . '/../../../database/updates/' . $item['file'];
        $sql = file_get_contents($file);
        $executed = 0;
        $skipped = 0;
        try {
            foreach (splitSqlStatements((string)$sql) as $statement) {
                if (execMigrationStatement($pdo, $statement) === 'skipped') {
                    $skipped++;
                } else {
                    $executed++;
                }
            }
            $message = $item['file'] . ': executada (' . $executed . ' comandos' . ($skipped ? ', ' . $skipped . ' já existiam' : '') . ').';
            writeMaintenanceLog($pdo, $user, $tenantId, 'migrate_file', $item['file'], $message, 'success');
            $output[] = $message;
        } catch (Throwable $exception) {
            $message = $item['file'] . ': erro - ' . $exception->getMessage();
            writeMaintenanceLog($pdo, $user, $tenantId, 'migrate_file', $item['file'], $message, 'error');
            $output[] = $message;
        }
    }
    return $output ? implode("\n", $output) : 'Nenhuma migration pendente.';
}

function clearDirectoryContents(string $path): int
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
        } else {
            if (@unlink($item->getPathname())) $removed++;
        }
    }
    return $removed;
}

function runCacheAction(string $action): string
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
            $removed += clearDirectoryContents($absolute);
        } elseif (is_file($absolute)) {
            $target = realpath($absolute);
            if ($root && $target && str_starts_with($target, $root) && @unlink($target)) $removed++;
        }
    }
    return 'Arquivos de cache removidos: ' . $removed . '.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['maintenance_action'])) {
    $action = $_POST['maintenance_action'];
    if (!isSuperAdmin()) {
        $errors[] = 'Você não tem permissão para executar esta ação.';
    } elseif (!in_array($action, $allowedMaintenanceActions, true)) {
        $errors[] = 'Ação de manutenção inválida.';
    } else {
        try {
            if ($action === 'migrate_status') {
                $items = migrationStatus($pdo);
                $pending = array_filter($items, fn($item) => $item['status'] === 'pendente');
                $maintenanceOutput = implode("\n", array_map(fn($item) => $item['file'] . ' — ' . $item['status'], $items));
                $success = count($pending) . ' migration(s) pendente(s).';
                writeMaintenanceLog($pdo, $currentUser, $tenantId, $action, 'database/updates/*.sql status', $maintenanceOutput, 'success');
            } elseif ($action === 'migrate_run') {
                $maintenanceOutput = runPendingMigrations($pdo, $currentUser, $tenantId);
                $success = 'Execução de migrations finalizada.';
                writeMaintenanceLog($pdo, $currentUser, $tenantId, $action, 'database/updates/*.sql run', $maintenanceOutput, 'success');
            } else {
                $maintenanceOutput = runCacheAction($action);
                $success = 'Ação executada com sucesso.';
                writeMaintenanceLog($pdo, $currentUser, $tenantId, $action, $action, $maintenanceOutput, 'success');
            }
        } catch (Throwable $exception) {
            $errors[] = 'Erro ao executar manutenção: ' . $exception->getMessage();
            writeMaintenanceLog($pdo, $currentUser, $tenantId, $action, $action, $exception->getMessage(), 'error');
        }
    }
}

$dbConnected = true;
try { $pdo->query('SELECT 1'); } catch (Throwable $exception) { $dbConnected = false; }
$maintenanceLogs = [];
$lastMaintenance = null;
if (settingsTableExists($pdo, 'system_maintenance_logs')) {
    $maintenanceLogs = $pdo->query('SELECT * FROM system_maintenance_logs ORDER BY created_at DESC, id DESC LIMIT 6')->fetchAll(PDO::FETCH_ASSOC);
    $lastMaintenance = $maintenanceLogs[0]['created_at'] ?? null;
}
$migrationItems = isSuperAdmin() ? migrationStatus($pdo) : [];
$pendingMigrations = array_filter($migrationItems, fn($item) => $item['status'] === 'pendente');

require __DIR__ . '/../../layouts/admin-header.php';
require __DIR__ . '/../../layouts/admin-sidebar.php';
?>

<style>
    .settings-hero{display:flex;justify-content:space-between;gap:20px;margin-bottom:24px}.settings-title{font-size:34px;font-weight:850;letter-spacing:-.04em;color:#212121;margin:0}.settings-subtitle{color:#555555;margin:6px 0 0}.settings-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px}.settings-card{background:#fff;border:1px solid #CECECE;border-radius:22px;box-shadow:0 18px 40px rgba(15,23,42,.06);padding:22px}.settings-card.full{grid-column:1/-1}.settings-card h2{font-size:18px;font-weight:850;color:#212121;margin:0 0 14px}.settings-list{display:grid;gap:12px}.settings-row{display:flex;justify-content:space-between;gap:16px;padding:12px 0;border-top:1px solid #F3F3F3}.settings-row:first-child{border-top:0}.settings-label{color:#555555;font-size:13px;font-weight:800}.settings-value{color:#212121;font-weight:800;text-align:right}.maintenance-actions{display:flex;flex-wrap:wrap;gap:10px;margin:16px 0}.maintenance-actions .btn{border-radius:12px;font-weight:800}.settings-output{white-space:pre-wrap;background:#212121;color:#CECECE;border-radius:16px;padding:16px;max-height:320px;overflow:auto;font-size:13px}.maintenance-status{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:14px 0}.maintenance-pill{border:1px solid #CECECE;border-radius:16px;padding:14px;background:#F3F3F3}.maintenance-pill span{display:block;color:#555555;font-size:12px;font-weight:800;text-transform:uppercase}.maintenance-pill strong{display:block;margin-top:4px;color:#212121}.log-table{width:100%;border-collapse:collapse}.log-table th,.log-table td{padding:12px;border-top:1px solid #F3F3F3;font-size:14px}.log-table th{color:#555555;text-transform:uppercase;font-size:11px;letter-spacing:.08em}.status-ok{color:#16a34a;font-weight:800}.status-error{color:#dc2626;font-weight:800}@media(max-width:991px){.settings-grid,.maintenance-status{grid-template-columns:1fr}.settings-hero{display:block}.settings-value{text-align:left}.settings-row{display:block}}
</style>

<section class="settings-hero">
    <div><h1 class="settings-title">Configurações</h1><p class="settings-subtitle">Gerencie preferências, segurança e manutenção do sistema.</p></div>
</section>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php foreach ($errors as $error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endforeach; ?>

<section class="settings-grid">
    <article class="settings-card">
        <h2>Organização</h2>
        <div class="settings-list">
            <div class="settings-row"><span class="settings-label">Nome do tenant</span><span class="settings-value"><?= htmlspecialchars($tenant['name'] ?? '-') ?></span></div>
            <div class="settings-row"><span class="settings-label">Slug do tenant</span><span class="settings-value"><?= htmlspecialchars($tenant['slug'] ?? '-') ?></span></div>
            <div class="settings-row"><span class="settings-label">Status</span><span class="settings-value"><?= (int)($tenant['is_active'] ?? 1) === 1 ? 'Ativo' : 'Inativo' ?></span></div>
            <div class="settings-row"><span class="settings-label">E-mail de contato</span><span class="settings-value"><?= htmlspecialchars($tenant['contact_email'] ?? $tenant['email'] ?? '-') ?></span></div>
        </div>
    </article>

    <article class="settings-card">
        <h2>Segurança</h2>
        <div class="settings-list">
            <div class="settings-row"><span class="settings-label">Usuário logado</span><span class="settings-value"><?= htmlspecialchars($currentUser['name'] ?? '-') ?></span></div>
            <div class="settings-row"><span class="settings-label">E-mail</span><span class="settings-value"><?= htmlspecialchars($currentUser['email'] ?? '-') ?></span></div>
            <div class="settings-row"><span class="settings-label">Perfil de acesso</span><span class="settings-value"><?= htmlspecialchars($currentUser['role'] ?? '-') ?></span></div>
            <div class="settings-row"><span class="settings-label">Permissões</span><span class="settings-value"><?= isSuperAdmin() ? 'Administrador do sistema' : 'Restrito ao tenant' ?></span></div>
        </div>
    </article>

    <?php if (isSuperAdmin()): ?>
        <article class="settings-card full">
            <h2>Manutenção do sistema</h2>
            <p class="text-muted mb-2">Execute tarefas administrativas com cuidado. Algumas ações podem afetar a estrutura do sistema.</p>
            <div class="maintenance-status">
                <div class="maintenance-pill"><span>Ambiente</span><strong><?= htmlspecialchars($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'local') ?></strong></div>
                <div class="maintenance-pill"><span>Banco conectado</span><strong><?= $dbConnected ? 'Sim' : 'Não' ?></strong></div>
                <div class="maintenance-pill"><span>Última manutenção</span><strong><?= $lastMaintenance ? date('d/m/Y H:i', strtotime($lastMaintenance)) : 'Nenhuma' ?></strong></div>
                <div class="maintenance-pill"><span>Migrations pendentes</span><strong><?= count($pendingMigrations) ?></strong></div>
            </div>
            <div class="maintenance-actions">
                <form method="post"><input type="hidden" name="maintenance_action" value="migrate_status"><button class="btn btn-outline-primary">Verificar migrations</button></form>
                <form method="post" data-confirm="Tem certeza que deseja executar as migrations pendentes? Essa ação pode alterar a estrutura do banco de dados."><input type="hidden" name="maintenance_action" value="migrate_run"><button class="btn btn-primary">Executar migrations</button></form>
                <form method="post"><input type="hidden" name="maintenance_action" value="cache_clear"><button class="btn btn-outline-secondary">Limpar cache</button></form>
                <form method="post"><input type="hidden" name="maintenance_action" value="config_clear"><button class="btn btn-outline-secondary">Limpar configurações</button></form>
                <form method="post"><input type="hidden" name="maintenance_action" value="route_clear"><button class="btn btn-outline-secondary">Limpar rotas</button></form>
                <form method="post"><input type="hidden" name="maintenance_action" value="optimize_clear"><button class="btn btn-outline-secondary">Optimize clear</button></form>
            </div>
            <?php if ($maintenanceOutput !== null): ?><div class="settings-output"><?= htmlspecialchars($maintenanceOutput) ?></div><?php endif; ?>
        </article>

        <article class="settings-card full">
            <h2>Logs de manutenção</h2>
            <?php if ($maintenanceLogs): ?>
                <div class="table-responsive"><table class="log-table"><thead><tr><th>Data</th><th>Ação</th><th>Comando</th><th>Status</th><th>IP</th></tr></thead><tbody>
                    <?php foreach ($maintenanceLogs as $log): ?><tr><td><?= date('d/m/Y H:i', strtotime($log['created_at'])) ?></td><td><?= htmlspecialchars($log['action']) ?></td><td><?= htmlspecialchars($log['command']) ?></td><td class="<?= $log['status'] === 'success' ? 'status-ok' : 'status-error' ?>"><?= htmlspecialchars($log['status']) ?></td><td><?= htmlspecialchars($log['ip_address'] ?? '-') ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            <?php else: ?>
                <p class="text-muted mb-0">Nenhum log registrado ainda.</p>
            <?php endif; ?>
        </article>
    <?php else: ?>
        <article class="settings-card full"><h2>Manutenção do sistema</h2><p class="text-muted mb-0">Área disponível apenas para administradores do sistema.</p></article>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../../layouts/admin-footer.php'; ?>
