<?php

const FORMOPS_PRIVACY_POLICY_VERSION = '2026-09-11';

function formOpsEnsureLgpdInfrastructure(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute(['form_responses']);
        if ((int) $stmt->fetchColumn() === 0) {
            return;
        }

        $columns = [
            'lgpd_consent' => 'TINYINT(1) NULL DEFAULT NULL AFTER submitted_by_ip',
            'lgpd_consent_at' => 'DATETIME NULL DEFAULT NULL AFTER lgpd_consent',
            'lgpd_policy_version' => 'VARCHAR(40) NULL DEFAULT NULL AFTER lgpd_consent_at',
        ];
        $columnExists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        foreach ($columns as $column => $definition) {
            $columnExists->execute(['form_responses', $column]);
            if ((int) $columnExists->fetchColumn() === 0) {
                $pdo->exec('ALTER TABLE form_responses ADD COLUMN ' . $column . ' ' . $definition);
            }
        }

        $triggerName = 'trg_form_responses_lgpd_before_insert';
        $triggerExists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?');
        $triggerExists->execute([$triggerName]);
        if ((int) $triggerExists->fetchColumn() === 0) {
            $pdo->exec(
                'CREATE TRIGGER ' . $triggerName . ' BEFORE INSERT ON form_responses FOR EACH ROW '
                . 'SET NEW.lgpd_consent = IF(COALESCE(@formops_lgpd_consent, 0) = 1, 1, NULL), '
                . 'NEW.lgpd_consent_at = IF(COALESCE(@formops_lgpd_consent, 0) = 1, NOW(), NULL), '
                . 'NEW.lgpd_policy_version = IF(COALESCE(@formops_lgpd_consent, 0) = 1, @formops_lgpd_policy_version, NULL)'
            );
        }
    } catch (Throwable $exception) {
        // A camada de consentimento continua bloqueando submissões sem aceite mesmo
        // quando o usuário do banco não possui permissão para alterar o schema.
        error_log('FormOps LGPD infrastructure warning: ' . $exception->getMessage());
    }
}

function formOpsPublicConsentAccepted(): bool
{
    return isset($_POST['lgpd_consent']) && hash_equals('1', (string) $_POST['lgpd_consent']);
}

function formOpsSetLgpdRequestContext(PDO $pdo, bool $accepted): void
{
    try {
        $stmt = $pdo->prepare('SET @formops_lgpd_consent = ?, @formops_lgpd_policy_version = ?');
        $stmt->execute([$accepted ? 1 : 0, $accepted ? FORMOPS_PRIVACY_POLICY_VERSION : null]);
    } catch (Throwable $exception) {
        error_log('FormOps LGPD request context warning: ' . $exception->getMessage());
    }
}

function formOpsClearLgpdRequestContext(PDO $pdo): void
{
    try {
        $pdo->exec('SET @formops_lgpd_consent = NULL, @formops_lgpd_policy_version = NULL');
    } catch (Throwable $exception) {
        error_log('FormOps LGPD request context cleanup warning: ' . $exception->getMessage());
    }
}

function formOpsRenderConsentRequired(string $tenantSlug, string $formSlug): never
{
    http_response_code(422);
    $formUrl = $tenantSlug !== '' && $formSlug !== ''
        ? appUrl('f/' . rawurlencode($tenantSlug) . '/' . rawurlencode($formSlug))
        : appUrl('form-public', array_filter(['tenant' => $tenantSlug, 'slug' => $formSlug]));
    $privacyUrl = appUrl('politica-de-privacidade', array_filter(['tenant' => $tenantSlug, 'form' => $formSlug]));
    ?>
    <!doctype html>
    <html lang="pt-br">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Aceite necessário · FormOps</title>
        <style>
            *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#f4f7fb;color:#012672;font-family:Arial,Helvetica,sans-serif}.box{width:min(100%,620px);background:#fff;border:1px solid #dbe6f3;border-radius:20px;padding:32px;box-shadow:0 24px 60px rgba(1,38,114,.12)}h1{font-size:26px;margin:0 0 10px}p{color:#475569;line-height:1.6}.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:24px}.btn{display:inline-flex;min-height:44px;align-items:center;justify-content:center;padding:10px 16px;border-radius:11px;text-decoration:none;font-weight:800;border:1px solid #cbd5e1;color:#012672;background:#fff}.btn.primary{background:#015CC0;border-color:#015CC0;color:#fff}@media(max-width:520px){.box{padding:24px 18px}.actions{display:grid}.btn{width:100%}}
        </style>
    </head>
    <body>
        <main class="box">
            <h1>É necessário aceitar a Política de Privacidade</h1>
            <p>Para concluir o envio, marque o campo de aceite exibido no final do formulário. Nenhuma resposta foi registrada nesta tentativa.</p>
            <div class="actions">
                <a class="btn primary" href="<?= htmlspecialchars($formUrl) ?>" onclick="if(history.length>1){history.back();return false;}">Voltar ao formulário</a>
                <a class="btn" href="<?= htmlspecialchars($privacyUrl) ?>" target="_blank" rel="noopener">Ler Política de Privacidade</a>
            </div>
        </main>
    </body>
    </html>
    <?php
    exit;
}

function formOpsInjectPublicCompliance(string $html, string $tenantSlug, string $formSlug): string
{
    if ($html === '') {
        return $html;
    }

    $privacyUrl = appUrl('politica-de-privacidade', array_filter(['tenant' => $tenantSlug, 'form' => $formSlug]));
    $cookieUrl = appUrl('politica-de-cookies', array_filter(['tenant' => $tenantSlug]));
    $privacyUrlSafe = htmlspecialchars($privacyUrl, ENT_QUOTES, 'UTF-8');
    $cookieUrlSafe = htmlspecialchars($cookieUrl, ENT_QUOTES, 'UTF-8');
    $checked = formOpsPublicConsentAccepted() ? ' checked' : '';

    $consentMarkup = <<<HTML
<div class="formops-consent" id="formopsConsentBlock">
    <div class="form-check formops-consent-check">
        <input class="form-check-input" type="checkbox" name="lgpd_consent" id="lgpd_consent" value="1" required{$checked}>
        <label class="form-check-label" for="lgpd_consent">
            Li e aceito a <a href="{$privacyUrlSafe}" target="_blank" rel="noopener">Política de Privacidade</a> e estou ciente do tratamento dos meus dados pessoais pela organização responsável por este formulário para as finalidades relacionadas a esta solicitação, nos termos da LGPD. <strong>*</strong>
        </label>
    </div>
    <div class="formops-consent-note">O envio só será concluído após este aceite. Consulte também a <a href="{$cookieUrlSafe}" target="_blank" rel="noopener">Política de Cookies</a>.</div>
</div>
HTML;

    if (str_contains($html, 'submit-btn') && !str_contains($html, 'id="lgpd_consent"')) {
        $html = preg_replace_callback(
            '#(<button\s+type="submit"[^>]*class="[^"]*\bsubmit-btn\b[^"]*"[^>]*>.*?</button>)#is',
            static fn (array $matches): string => $consentMarkup . $matches[1],
            $html,
            1
        ) ?? $html;
    }

    $stylesheet = '<link rel="stylesheet" href="' . htmlspecialchars(appUrl('assets/public-compliance.css'), ENT_QUOTES, 'UTF-8') . '">';
    if (!str_contains($html, 'public-compliance.css') && str_contains($html, '</head>')) {
        $html = str_replace('</head>', '    ' . $stylesheet . "\n</head>", $html);
    }

    if (!str_contains($html, 'data-formops-cookie-notice') && str_contains($html, '</body>')) {
        $banner = <<<HTML
<div class="formops-cookie-notice" data-formops-cookie-notice hidden role="region" aria-label="Aviso de cookies">
    <div class="formops-cookie-copy">
        <strong>Cookies e armazenamento local</strong>
        <span>Usamos apenas recursos essenciais para segurança e funcionamento do formulário. Não ativamos cookies publicitários ou de perfilamento por padrão.</span>
        <a href="{$cookieUrlSafe}" target="_blank" rel="noopener">Ver Política de Cookies</a>
    </div>
    <button type="button" class="formops-cookie-button" data-formops-cookie-ack>Entendi</button>
</div>
<script src="<?= htmlspecialchars(appUrl('assets/public-compliance.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
HTML;
        // Heredoc não interpreta PHP; substitui o placeholder do src antes de enviar.
        $scriptUrl = htmlspecialchars(appUrl('assets/public-compliance.js'), ENT_QUOTES, 'UTF-8');
        $banner = str_replace('<?= htmlspecialchars(appUrl(\'assets/public-compliance.js\'), ENT_QUOTES, \'UTF-8\') ?>', $scriptUrl, $banner);
        $html = str_replace('</body>', $banner . "\n</body>", $html);
    }

    return $html;
}
