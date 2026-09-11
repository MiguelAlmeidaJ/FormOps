-- FormOps: modelos de ingresso por formulário e ingressos emitidos.
ALTER TABLE forms ADD COLUMN ticket_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE forms ADD COLUMN ticket_title VARCHAR(160) NULL;
ALTER TABLE forms ADD COLUMN ticket_subtitle VARCHAR(255) NULL;
ALTER TABLE forms ADD COLUMN ticket_event_at DATETIME NULL;
ALTER TABLE forms ADD COLUMN ticket_location VARCHAR(255) NULL;
ALTER TABLE forms ADD COLUMN ticket_instructions TEXT NULL;
ALTER TABLE forms ADD COLUMN ticket_background_path VARCHAR(500) NULL;
ALTER TABLE forms ADD COLUMN ticket_primary_color VARCHAR(20) NULL DEFAULT '#212121';
ALTER TABLE forms ADD COLUMN ticket_text_color VARCHAR(20) NULL DEFAULT '#212121';
ALTER TABLE forms ADD COLUMN ticket_name_field_id INT NULL;
ALTER TABLE forms ADD COLUMN ticket_email_field_id INT NULL;
ALTER TABLE forms ADD COLUMN ticket_email_subject VARCHAR(190) NULL;
ALTER TABLE forms ADD COLUMN ticket_email_message TEXT NULL;

CREATE TABLE form_tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    form_id INT NOT NULL,
    response_id INT NOT NULL,
    code VARCHAR(32) NOT NULL,
    token CHAR(64) NOT NULL,
    participant_name VARCHAR(255) NULL,
    participant_email VARCHAR(255) NULL,
    status ENUM('valid','used','cancelled') NOT NULL DEFAULT 'valid',
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    email_attempted_at DATETIME NULL,
    emailed_at DATETIME NULL,
    email_error TEXT NULL,
    checked_in_at DATETIME NULL,
    checked_in_by INT NULL,
    UNIQUE KEY uq_form_tickets_response (tenant_id, response_id),
    UNIQUE KEY uq_form_tickets_code (code),
    UNIQUE KEY uq_form_tickets_token (token),
    KEY idx_form_tickets_form_status (tenant_id, form_id, status),
    KEY idx_form_tickets_participant_email (participant_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
