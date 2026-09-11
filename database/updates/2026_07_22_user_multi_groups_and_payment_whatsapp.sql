-- FormOps: múltiplos grupos por usuário e WhatsApp para comprovantes.
CREATE TABLE IF NOT EXISTS user_form_groups (
    tenant_id INT NOT NULL,
    user_id INT NOT NULL,
    form_group_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, form_group_id),
    KEY idx_user_form_groups_tenant_group (tenant_id, form_group_id),
    KEY idx_user_form_groups_tenant_user (tenant_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO user_form_groups (tenant_id, user_id, form_group_id)
SELECT tenant_id, id, form_group_id
FROM users
WHERE tenant_id IS NOT NULL AND form_group_id IS NOT NULL;

ALTER TABLE forms ADD COLUMN payment_whatsapp VARCHAR(30) NULL AFTER pix_key;
