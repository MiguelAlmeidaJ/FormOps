<?php

function ensureFormLifecycleColumns(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute(['forms']);
    if ((int) $stmt->fetchColumn() === 0) {
        return;
    }

    $columns = [
        'closes_at' => "ALTER TABLE forms ADD COLUMN closes_at DATETIME NULL AFTER is_active",
    ];

    foreach ($columns as $column => $sql) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute(['forms', $column]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }
}

function ensureUserGroupColumn(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute(['users']);
    if ((int) $stmt->fetchColumn() === 0) {
        return;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute(['users', 'form_group_id']);
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE users ADD COLUMN form_group_id INT NULL AFTER tenant_id');
    }
}

function formClosesAtValue(array $form): ?string
{
    $value = trim((string) ($form['closes_at'] ?? ''));
    return $value !== '' && $value !== '0000-00-00 00:00:00' ? $value : null;
}

function isFormCompleted(array $form): bool
{
    $closesAt = formClosesAtValue($form);
    return $closesAt !== null && strtotime($closesAt) !== false && strtotime($closesAt) <= time();
}

function isFormOpenForResponses(array $form): bool
{
    return (int) ($form['is_active'] ?? 0) === 1 && !isFormCompleted($form);
}

function formStatusLabel(array $form): string
{
    if (isFormCompleted($form)) {
        return 'Concluído';
    }

    return (int) ($form['is_active'] ?? 0) === 1 ? 'Ativo' : 'Inativo';
}

function formStatusClass(array $form): string
{
    if (isFormCompleted($form)) {
        return 'completed';
    }

    return (int) ($form['is_active'] ?? 0) === 1 ? 'active' : 'inactive';
}

function formatDateTimeLocal(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '' || strtotime($value) === false) {
        return '';
    }

    return date('Y-m-d\TH:i', strtotime($value));
}

function parseDateTimeLocal(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $date = DateTime::createFromFormat('Y-m-d\TH:i', $value);
    $errors = DateTime::getLastErrors();
    if (!$date || ($errors !== false && ((int) $errors['warning_count'] > 0 || (int) $errors['error_count'] > 0))) {
        return null;
    }

    return $date->format('Y-m-d H:i:s');
}
