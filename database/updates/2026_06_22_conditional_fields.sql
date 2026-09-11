-- Execute este arquivo uma única vez.

ALTER TABLE form_fields
ADD COLUMN conditional_enabled TINYINT(1) DEFAULT 0 AFTER is_readonly,
ADD COLUMN conditional_field_id INT NULL AFTER conditional_enabled,
ADD COLUMN conditional_operator VARCHAR(50) NULL AFTER conditional_field_id,
ADD COLUMN conditional_value VARCHAR(255) NULL AFTER conditional_operator;

