ALTER TABLE form_responses ADD COLUMN lgpd_consent TINYINT(1) NULL DEFAULT NULL AFTER submitted_by_ip;
ALTER TABLE form_responses ADD COLUMN lgpd_consent_at DATETIME NULL DEFAULT NULL AFTER lgpd_consent;
ALTER TABLE form_responses ADD COLUMN lgpd_policy_version VARCHAR(40) NULL DEFAULT NULL AFTER lgpd_consent_at;
DROP TRIGGER IF EXISTS trg_form_responses_lgpd_before_insert;
CREATE TRIGGER trg_form_responses_lgpd_before_insert BEFORE INSERT ON form_responses FOR EACH ROW SET NEW.lgpd_consent = IF(COALESCE(@formops_lgpd_consent, 0) = 1, 1, NULL), NEW.lgpd_consent_at = IF(COALESCE(@formops_lgpd_consent, 0) = 1, NOW(), NULL), NEW.lgpd_policy_version = IF(COALESCE(@formops_lgpd_consent, 0) = 1, @formops_lgpd_policy_version, NULL);
