ALTER TABLE form_responses ADD COLUMN lgpd_consent TINYINT(1) NULL DEFAULT NULL AFTER submitted_by_ip;
ALTER TABLE form_responses ADD COLUMN lgpd_consent_at DATETIME NULL DEFAULT NULL AFTER lgpd_consent;
ALTER TABLE form_responses ADD COLUMN lgpd_policy_version VARCHAR(40) NULL DEFAULT NULL AFTER lgpd_consent_at;
