-- Flexibilidade visual e comportamental dos campos de formulário.
-- Execute este arquivo uma única vez.

ALTER TABLE form_fields ADD COLUMN width INT DEFAULT 12 AFTER type;
ALTER TABLE form_fields ADD COLUMN mask VARCHAR(50) DEFAULT 'none' AFTER width;
ALTER TABLE form_fields ADD COLUMN help_text VARCHAR(255) NULL AFTER placeholder;
ALTER TABLE form_fields ADD COLUMN default_value TEXT NULL AFTER help_text;
ALTER TABLE form_fields ADD COLUMN is_visible TINYINT(1) DEFAULT 1 AFTER is_required;
ALTER TABLE form_fields ADD COLUMN is_readonly TINYINT(1) DEFAULT 0 AFTER is_visible;
ALTER TABLE form_fields ADD COLUMN css_class VARCHAR(255) NULL AFTER is_readonly;

-- Os comandos estão separados intencionalmente. Se sua versão do MySQL/MariaDB
-- não aceitar uma alteração, execute as demais colunas uma por vez e ignore
-- somente a coluna que já existir.
