-- Execute estes comandos uma única vez em instalações antigas.
-- O MySQL/MariaDB de algumas versões não aceita ADD COLUMN IF NOT EXISTS.

ALTER TABLE users MODIFY tenant_id INT NULL;

ALTER TABLE tenants ADD COLUMN public_title VARCHAR(255) NULL;
ALTER TABLE tenants ADD COLUMN public_subtitle VARCHAR(255) NULL;
ALTER TABLE tenants ADD COLUMN background_color VARCHAR(20) DEFAULT '#F3F3F3';
ALTER TABLE tenants ADD COLUMN text_color VARCHAR(20) DEFAULT '#212121';
ALTER TABLE tenants ADD COLUMN button_color VARCHAR(20) DEFAULT '#212121';
ALTER TABLE tenants ADD COLUMN form_style ENUM('clean', 'premium', 'minimal', 'church', 'business') DEFAULT 'clean';

-- Se uma coluna já existir, ignore o erro desse comando e prossiga para a próxima.
