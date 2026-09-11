ALTER TABLE form_discount_coupons
    ADD COLUMN application_scope ENUM('registration','participant') NOT NULL DEFAULT 'registration' AFTER discount_type;

ALTER TABLE form_responses
    ADD COLUMN pricing_participant_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN pricing_participant_group_discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN pricing_participant_coupon_discount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN pricing_participant_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    ADD COLUMN pricing_participant_coupon_code VARCHAR(80) NULL;

ALTER TABLE form_coupon_redemptions
    ADD COLUMN person_index INT NOT NULL DEFAULT 0 AFTER submission_group,
    DROP INDEX uq_coupon_submission,
    ADD UNIQUE KEY uq_coupon_submission_person (coupon_id, submission_group, person_index);
