-- FormOps: lotes, cupons de desconto e descontos por quantidade de pessoas.
ALTER TABLE form_responses ADD COLUMN payment_method VARCHAR(30) NULL;
ALTER TABLE form_responses ADD COLUMN pricing_lot_id INT NULL;
ALTER TABLE form_responses ADD COLUMN pricing_lot_name VARCHAR(120) NULL;
ALTER TABLE form_responses ADD COLUMN pricing_subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE form_responses ADD COLUMN pricing_group_discount DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE form_responses ADD COLUMN pricing_coupon_discount DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE form_responses ADD COLUMN pricing_discount_total DECIMAL(10,2) NOT NULL DEFAULT 0.00;
ALTER TABLE form_responses ADD COLUMN pricing_coupon_code VARCHAR(80) NULL;
ALTER TABLE form_responses ADD COLUMN pricing_group_rule VARCHAR(160) NULL;

CREATE TABLE form_payment_lots (
    id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, form_id INT NOT NULL,
    name VARCHAR(120) NOT NULL, price DECIMAL(10,2) NOT NULL, starts_at DATETIME NULL, ends_at DATETIME NULL,
    capacity INT NULL, used_quantity INT NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_payment_lots_form (tenant_id, form_id, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE form_discount_coupons (
    id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, form_id INT NOT NULL,
    code VARCHAR(80) NOT NULL, discount_type ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
    discount_value DECIMAL(10,2) NOT NULL, min_people INT NOT NULL DEFAULT 1,
    max_uses INT NULL, used_count INT NOT NULL DEFAULT 0, starts_at DATETIME NULL, expires_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_form_coupon_code (tenant_id, form_id, code),
    KEY idx_form_coupons_active (tenant_id, form_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE form_group_discounts (
    id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, form_id INT NOT NULL,
    min_people INT NOT NULL, discount_type ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
    discount_value DECIMAL(10,2) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_form_group_min_people (tenant_id, form_id, min_people),
    KEY idx_form_group_discounts (tenant_id, form_id, is_active, min_people)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE form_coupon_redemptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, form_id INT NOT NULL,
    coupon_id INT NOT NULL, submission_group VARCHAR(64) NOT NULL, discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    redeemed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_coupon_submission (coupon_id, submission_group),
    KEY idx_coupon_redemptions_form (tenant_id, form_id, redeemed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
