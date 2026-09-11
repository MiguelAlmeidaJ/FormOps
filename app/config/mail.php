<?php

function mailEnvironmentValue(string $key, mixed $default = null): mixed
{
    $value = getenv($key);
    return $value === false || $value === '' ? $default : $value;
}

$mailConfig = [
    'host' => mailEnvironmentValue('MAIL_HOST', ''),
    'port' => (int) mailEnvironmentValue('MAIL_PORT', 587),
    'encryption' => strtolower((string) mailEnvironmentValue('MAIL_ENCRYPTION', 'tls')),
    'username' => mailEnvironmentValue('MAIL_USERNAME', ''),
    'password' => mailEnvironmentValue('MAIL_PASSWORD', ''),
    'from_email' => mailEnvironmentValue('MAIL_FROM_ADDRESS', ''),
    'from_name' => mailEnvironmentValue('MAIL_FROM_NAME', 'FormOps'),
    'timeout' => (int) mailEnvironmentValue('MAIL_TIMEOUT', 15),
];

$localConfigPath = __DIR__ . '/mail.local.php';
if (is_file($localConfigPath)) {
    $localConfig = require $localConfigPath;
    if (is_array($localConfig)) {
        $mailConfig = array_replace($mailConfig, $localConfig);
    }
}

return $mailConfig;

