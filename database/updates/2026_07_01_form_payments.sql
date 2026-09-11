-- FormOps: pagamentos manuais por formulário.
ALTER TABLE forms ADD COLUMN payment_enabled TINYINT(1) DEFAULT 0 AFTER allow_multiple_people;
ALTER TABLE forms ADD COLUMN payment_amount DECIMAL(10,2) DEFAULT 0.00 AFTER payment_enabled;
ALTER TABLE forms ADD COLUMN payment_methods SET('pix','transferencia','dinheiro') NULL AFTER payment_amount;
ALTER TABLE forms ADD COLUMN pix_key VARCHAR(255) NULL AFTER payment_methods;
ALTER TABLE forms ADD COLUMN payment_instructions TEXT NULL AFTER pix_key;

ALTER TABLE form_responses ADD COLUMN payment_amount DECIMAL(10,2) DEFAULT 0.00 AFTER people_count;
ALTER TABLE form_responses ADD COLUMN payment_total DECIMAL(10,2) DEFAULT 0.00 AFTER payment_amount;
ALTER TABLE form_responses ADD COLUMN payment_method VARCHAR(30) NULL AFTER payment_total;
ALTER TABLE form_responses ADD COLUMN payment_status ENUM('pending','approved') DEFAULT 'pending' AFTER payment_total;
ALTER TABLE form_responses ADD COLUMN payment_approved_by INT NULL AFTER payment_status;
ALTER TABLE form_responses ADD COLUMN payment_approved_at DATETIME NULL AFTER payment_approved_by;
ALTER TABLE form_responses ADD INDEX idx_form_responses_payment_status (tenant_id, form_id, payment_status);
