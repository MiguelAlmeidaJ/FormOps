-- FormOps: grupos/departamentos de formulários por tenant.
-- Execute uma vez antes de usar as telas de Grupos.

CREATE TABLE IF NOT EXISTS form_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    slug VARCHAR(255) NOT NULL,
    color VARCHAR(20) DEFAULT '#0d6efd',
    icon VARCHAR(80) NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_form_group_slug_per_tenant (tenant_id, slug),
    KEY idx_form_groups_tenant_status (tenant_id, is_active),
    CONSTRAINT fk_form_groups_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE forms ADD COLUMN form_group_id INT NULL AFTER tenant_id;
ALTER TABLE forms ADD INDEX idx_forms_tenant_group (tenant_id, form_group_id);

-- Os comandos estão separados intencionalmente. Se a coluna ou o índice já
-- existirem, ignore apenas o comando duplicado e execute os demais.
