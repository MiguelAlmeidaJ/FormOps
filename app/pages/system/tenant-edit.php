<?php
requireSuperAdmin();
$tenantId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$tenantId) { http_response_code(404); require __DIR__.'/../errors/404.php'; exit; }
$stmt=$pdo->prepare('SELECT * FROM tenants WHERE id=?'); $stmt->execute([$tenantId]); $tenant=$stmt->fetch(PDO::FETCH_ASSOC);
if (!$tenant) { http_response_code(404); require __DIR__.'/../errors/404.php'; exit; }
$pageTitle='Editar empresa'; $errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $fields=['name','slug','document','email','phone','logo','public_title','public_subtitle','primary_color','secondary_color','background_color','text_color','button_color','form_style']; $data=[];
    foreach($fields as $field) $data[$field]=trim($_POST[$field]??'');
    if($data['name']===''||!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$data['slug'])) $errors[]='Informe nome e um slug válido.';
    if(!in_array($data['form_style'],['clean','premium','minimal','church','business'],true)) $errors[]='Estilo inválido.';
    if(!$errors){try{$stmt=$pdo->prepare('UPDATE tenants SET name=?,slug=?,document=?,email=?,phone=?,logo=?,public_title=?,public_subtitle=?,primary_color=?,secondary_color=?,background_color=?,text_color=?,button_color=?,form_style=?,is_active=?,updated_at=NOW() WHERE id=?');$stmt->execute([$data['name'],$data['slug'],$data['document']?:null,$data['email']?:null,$data['phone']?:null,$data['logo']?:null,$data['public_title']?:null,$data['public_subtitle']?:null,$data['primary_color'],$data['secondary_color'],$data['background_color'],$data['text_color'],$data['button_color'],$data['form_style'],isset($_POST['is_active'])?1:0,$tenantId]);redirectTo('system-tenants');}catch(PDOException $e){$errors[]=$e->getCode()==='23000'?'Slug já cadastrado.':'Não foi possível salvar.';}}
}
require __DIR__.'/../../layouts/system-header.php'; require __DIR__.'/../../layouts/system-sidebar.php'; require __DIR__.'/tenant-form.php'; require __DIR__.'/../../layouts/system-footer.php';
