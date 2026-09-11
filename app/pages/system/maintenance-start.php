<?php
requireSuperAdmin();
$tenantId=filter_input(INPUT_GET,'tenant_id',FILTER_VALIDATE_INT);
$stmt=$pdo->prepare('SELECT id,name,slug FROM tenants WHERE id=?');$stmt->execute([$tenantId]);$tenant=$stmt->fetch(PDO::FETCH_ASSOC);
if(!$tenant){http_response_code(404);require __DIR__.'/../errors/404.php';exit;}
setMaintenanceTenant($tenant['id']);$_SESSION['maintenance_tenant_name']=$tenant['name'];$_SESSION['maintenance_tenant_slug']=$tenant['slug'];
$redirect=($_GET['redirect']??'')==='forms'?'forms':'painel';redirectTo($redirect);
