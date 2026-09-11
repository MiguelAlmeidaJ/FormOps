<?php
$tenantSlug = trim((string) ($_GET['tenant'] ?? ''));
$formSlug = trim((string) ($_GET['form'] ?? ''));
$tenant = null;
if ($tenantSlug !== '') {
    $stmt = $pdo->prepare('SELECT * FROM tenants WHERE slug = ? LIMIT 1');
    $stmt->execute([$tenantSlug]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
$controllerName = trim((string) ($tenant['name'] ?? 'a organização responsável pelo formulário'));
$controllerEmail = trim((string) ($tenant['email'] ?? ''));
$backUrl = $tenantSlug !== '' && $formSlug !== '' ? appUrl('f/' . rawurlencode($tenantSlug) . '/' . rawurlencode($formSlug)) : appUrl('login');
$cookieUrl = appUrl('politica-de-cookies', array_filter(['tenant' => $tenantSlug]));
?>
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Política de Privacidade · FormOps</title><style>
*{box-sizing:border-box}body{margin:0;background:#f4f7fb;color:#334155;font-family:Arial,sans-serif}.page{width:min(920px,calc(100% - 24px));margin:24px auto 60px;background:#fff;border:1px solid #dbe6f3;border-radius:22px;overflow:hidden;box-shadow:0 20px 60px rgba(1,38,114,.1)}header{padding:32px;border-top:7px solid #015cc0;border-bottom:1px solid #e7eef7}main{padding:12px 32px 34px}h1,h2{color:#012672}h1{margin:0;font-size:38px}h2{font-size:19px;margin:0 0 8px}p,li{font-size:14px;line-height:1.7}.meta{color:#067ae3;font-weight:800}.section{padding:20px 0;border-top:1px solid #edf2f7}.section:first-child{border-top:0}.note{padding:14px;border-radius:10px;background:#f0fdfa;border-left:4px solid #04a09c}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:24px}.btn{padding:11px 15px;border-radius:10px;border:1px solid #cbd5e1;color:#012672;text-decoration:none;font-weight:800}.btn.primary{background:#015cc0;border-color:#015cc0;color:#fff}@media(max-width:600px){header,main{padding-left:18px;padding-right:18px}h1{font-size:30px}.actions{display:grid}.btn{text-align:center}}
</style></head><body><article class="page"><header><div class="meta">FormOps · versão <?= htmlspecialchars(FORMOPS_PRIVACY_POLICY_VERSION) ?></div><h1>Política de Privacidade</h1><p>Esta política explica o tratamento dos dados informados em formulários publicados pela plataforma.</p></header><main>
<section class="section"><h2>Responsável pelo tratamento</h2><p><strong><?= htmlspecialchars($controllerName) ?></strong> define as finalidades do formulário. O FormOps fornece a infraestrutura tecnológica utilizada para coleta e organização das respostas.</p><?php if ($controllerEmail !== ''): ?><p>Contato informado: <?= htmlspecialchars($controllerEmail) ?>.</p><?php endif; ?></section>
<section class="section"><h2>Dados tratados e finalidades</h2><p>Podem ser tratados os dados preenchidos no formulário, data e hora do envio, endereço IP e, quando habilitados, dados operacionais de pagamento, ingresso e comunicações. O uso ocorre para administrar a solicitação apresentada, prestar as funcionalidades escolhidas, manter segurança e cumprir obrigações aplicáveis.</p></section>
<section class="section"><h2>Base legal e aceite</h2><p>A base legal depende da finalidade concreta do formulário. O aceite obrigatório antes do envio registra que a pessoa leu esta política e está ciente do tratamento descrito. Quando o consentimento for a base legal adotada, o registro também documenta essa manifestação.</p></section>
<section class="section"><h2>Compartilhamento, retenção e segurança</h2><p>Os dados podem ser acessados pela organização responsável e por fornecedores necessários à operação do serviço. Devem ser mantidos apenas pelo período necessário às finalidades, obrigações legais ou exercício de direitos, com medidas de segurança compatíveis com o serviço.</p></section>
<section class="section"><h2>Direitos do titular</h2><p>A pessoa titular pode exercer os direitos previstos na LGPD, conforme aplicável, incluindo confirmação, acesso, correção, informação sobre compartilhamento e pedidos de eliminação, bloqueio ou revogação de consentimento. As solicitações devem ser dirigidas à organização responsável pelo formulário.</p></section>
<section class="section"><h2>Cookies e registros anteriores</h2><p>Consulte a Política de Cookies para conhecer os recursos técnicos utilizados no navegador.</p><div class="note">Respostas enviadas antes da implantação deste controle permanecem preservadas e não recebem aceite retroativo.</div></section>
<div class="actions"><a class="btn primary" href="<?= htmlspecialchars($backUrl) ?>">Voltar ao formulário</a><a class="btn" href="<?= htmlspecialchars($cookieUrl) ?>">Política de Cookies</a></div>
</main></article></body></html>
