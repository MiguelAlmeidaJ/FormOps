-- FormOps: múltiplas inscrições por envio.
ALTER TABLE forms ADD COLUMN allow_multiple_people TINYINT(1) DEFAULT 0 AFTER requires_login;
ALTER TABLE form_responses ADD COLUMN submission_group VARCHAR(64) NULL AFTER response_number;
ALTER TABLE form_responses ADD COLUMN person_index INT DEFAULT 1 AFTER submission_group;
ALTER TABLE form_responses ADD COLUMN people_count INT DEFAULT 1 AFTER person_index;
ALTER TABLE form_responses ADD INDEX idx_form_responses_submission_group (tenant_id, form_id, submission_group);
