<?php
requireSuperAdmin();
$pageTitle = 'Criar empresa';
$errors = [];
$fields = ['name','slug','document','email','phone','logo','public_title','public_subtitle','primary_color','secondary_color','background_color','text_color','button_color','form_style'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [];
    foreach ($fields as $field) $data[$field] = trim($_POST[$field] ?? '');
    $adminName = trim($_POST['admin_name'] ?? ''); $adminEmail = trim($_POST['admin_email'] ?? ''); $adminPassword = $_POST['admin_password'] ?? '';
    if ($data['name'] === '' || $data['slug'] === '' || $adminName === '' || $adminEmail === '' || $adminPassword === '') $errors[] = 'Preencha os campos obrigatórios.';
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $data['slug'])) $errors[] = 'Use apenas letras minúsculas, números e hífens no slug.';
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Informe um e-mail válido para o administrador.';
    if (strlen($adminPassword) < 8) $errors[] = 'A senha inicial deve ter pelo menos 8 caracteres.';
    if (!in_array($data['form_style'], ['clean','premium','minimal','church','business'], true)) $data['form_style'] = 'clean';
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $stmt=$pdo->prepare('INSERT INTO tenants (name,slug,document,email,phone,logo,public_title,public_subtitle,primary_color,secondary_color,background_color,text_color,button_color,form_style,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([$data['name'],$data['slug'],$data['document']?:null,$data['email']?:null,$data['phone']?:null,$data['logo']?:null,$data['public_title']?:null,$data['public_subtitle']?:null,$data['primary_color']?:'#212121',$data['secondary_color']?:'#555555',$data['background_color']?:'#F3F3F3',$data['text_color']?:'#212121',$data['button_color']?:'#212121',$data['form_style'],isset($_POST['is_active'])?1:0]);
            $tenantId=(int)$pdo->lastInsertId();
            $stmt=$pdo->prepare("INSERT INTO users (tenant_id,name,email,password,role,is_active) VALUES (?,?,?,?, 'admin',1)");
            $stmt->execute([$tenantId,$adminName,$adminEmail,password_hash($adminPassword,PASSWORD_DEFAULT)]);
            $pdo->commit(); redirectTo('system-tenants');
        } catch (PDOException $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $errors[] = $e->getCode()==='23000' ? 'Slug ou e-mail já cadastrado.' : 'Não foi possível criar a empresa.'; }
    }
}
require __DIR__ . '/../../layouts/system-header.php'; require __DIR__ . '/../../layouts/system-sidebar.php';
require __DIR__ . '/tenant-form.php';
require __DIR__ . '/../../layouts/system-footer.php';
