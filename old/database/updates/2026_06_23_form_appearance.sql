-- FormOps: aparência por formulário.
ALTER TABLE forms ADD COLUMN use_tenant_branding TINYINT(1) DEFAULT 1 AFTER success_message;
ALTER TABLE forms ADD COLUMN logo_path VARCHAR(255) NULL AFTER use_tenant_branding;
ALTER TABLE forms ADD COLUMN cover_image_path VARCHAR(255) NULL AFTER logo_path;
ALTER TABLE forms ADD COLUMN public_title VARCHAR(120) NULL AFTER cover_image_path;
ALTER TABLE forms ADD COLUMN public_subtitle VARCHAR(180) NULL AFTER public_title;
ALTER TABLE forms ADD COLUMN primary_color VARCHAR(20) NULL AFTER public_subtitle;
ALTER TABLE forms ADD COLUMN secondary_color VARCHAR(20) NULL AFTER primary_color;
ALTER TABLE forms ADD COLUMN background_color VARCHAR(20) NULL AFTER secondary_color;
ALTER TABLE forms ADD COLUMN text_color VARCHAR(20) NULL AFTER background_color;
ALTER TABLE forms ADD COLUMN button_color VARCHAR(20) NULL AFTER text_color;
ALTER TABLE forms ADD COLUMN style VARCHAR(50) DEFAULT 'clean' AFTER button_color;
