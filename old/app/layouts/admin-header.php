<?php
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

$currentUser = user();
$pageTitle = $pageTitle ?? 'Painel';
$pageStyles = $pageStyles ?? [];
$maintenanceTenantName = $_SESSION['maintenance_tenant_name'] ?? null;
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle) ?> · FormOps</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="icon" href="assets/clients/formops/favicon-formops.jpg">
    <link rel="stylesheet" href="assets/brand.css">
    <?php foreach ($pageStyles as $style): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($style) ?>">
    <?php endforeach; ?>
</head>
<body>

<div class="admin-wrapper">