<?php

function ensurePricingInfrastructure(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute(['forms']);
    if ((int) $stmt->fetchColumn() === 0) return;

    $columns = [
        'payment_method' => 'VARCHAR(30) NULL',
        'pricing_lot_id' => 'INT NULL',
        'pricing_lot_name' => 'VARCHAR(120) NULL',
        'pricing_subtotal' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'pricing_group_discount' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'pricing_coupon_discount' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'pricing_discount_total' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'pricing_coupon_code' => 'VARCHAR(80) NULL',
        'pricing_group_rule' => 'VARCHAR(160) NULL',
        'pricing_participant_subtotal' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'pricing_participant_group_discount' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'pricing_participant_coupon_discount' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'pricing_participant_total' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00',
        'pricing_participant_coupon_code' => 'VARCHAR(80) NULL',
    ];
    $columnExists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    foreach ($columns as $column => $definition) {
        $columnExists->execute(['form_responses', $column]);
        if ((int) $columnExists->fetchColumn() === 0) $pdo->exec('ALTER TABLE form_responses ADD COLUMN ' . $column . ' ' . $definition);
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS form_payment_lots (
        id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, form_id INT NOT NULL,
        name VARCHAR(120) NOT NULL, price DECIMAL(10,2) NOT NULL, starts_at DATETIME NULL, ends_at DATETIME NULL,
        capacity INT NULL, used_quantity INT NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_payment_lots_form (tenant_id, form_id, is_active, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS form_discount_coupons (
        id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, form_id INT NOT NULL,
        code VARCHAR(80) NOT NULL, discount_type ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
        application_scope ENUM('registration','participant') NOT NULL DEFAULT 'registration',
        discount_value DECIMAL(10,2) NOT NULL, min_people INT NOT NULL DEFAULT 1,
        max_uses INT NULL, used_count INT NOT NULL DEFAULT 0, starts_at DATETIME NULL, expires_at DATETIME NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_form_coupon_code (tenant_id, form_id, code),
        KEY idx_form_coupons_active (tenant_id, form_id, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS form_group_discounts (
        id INT AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, form_id INT NOT NULL,
        min_people INT NOT NULL, discount_type ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
        discount_value DECIMAL(10,2) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_form_group_min_people (tenant_id, form_id, min_people),
        KEY idx_form_group_discounts (tenant_id, form_id, is_active, min_people)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS form_coupon_redemptions (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id INT NOT NULL, form_id INT NOT NULL,
        coupon_id INT NOT NULL, submission_group VARCHAR(64) NOT NULL, person_index INT NOT NULL DEFAULT 0,
        discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        redeemed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_coupon_submission_person (coupon_id, submission_group, person_index),
        KEY idx_coupon_redemptions_form (tenant_id, form_id, redeemed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
        ['form_discount_coupons', 'application_scope', "ENUM('registration','participant') NOT NULL DEFAULT 'registration'"],
        ['form_coupon_redemptions', 'person_index', 'INT NOT NULL DEFAULT 0'],
    ] as [$table, $column, $definition]) {
        $columnExists->execute([$table, $column]);
        if ((int) $columnExists->fetchColumn() === 0) $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    $indexExists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $indexExists->execute(['form_coupon_redemptions', 'uq_coupon_submission']);
    if ((int) $indexExists->fetchColumn() > 0) $pdo->exec('ALTER TABLE form_coupon_redemptions DROP INDEX uq_coupon_submission');
    $indexExists->execute(['form_coupon_redemptions', 'uq_coupon_submission_person']);
    if ((int) $indexExists->fetchColumn() === 0) $pdo->exec('ALTER TABLE form_coupon_redemptions ADD UNIQUE KEY uq_coupon_submission_person (coupon_id, submission_group, person_index)');
}

function pricingNormalizeCode(?string $code): string
{
    $code = function_exists('mb_strtoupper') ? mb_strtoupper(trim((string) $code), 'UTF-8') : strtoupper(trim((string) $code));
    return preg_replace('/\s+/', '', $code) ?? '';
}

function pricingDiscountAmount(float $base, string $type, float $value): float
{
    $discount = $type === 'percentage' ? $base * min(100, max(0, $value)) / 100 : max(0, $value);
    return round(min($base, $discount), 2);
}

function pricingCouponScopes(PDO $pdo, int $tenantId, int $formId): array
{
    $stmt = $pdo->prepare('SELECT application_scope, COUNT(*) AS total FROM form_discount_coupons WHERE tenant_id = ? AND form_id = ? AND is_active = 1 GROUP BY application_scope');
    $stmt->execute([$tenantId, $formId]);
    $scopes = ['registration' => false, 'participant' => false];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (array_key_exists($row['application_scope'], $scopes)) $scopes[$row['application_scope']] = (int) $row['total'] > 0;
    }
    return $scopes;
}

function formPricingQuote(PDO $pdo, array $form, int $peopleCount, string $couponCode = '', bool $forUpdate = false, array $participantCouponCodes = []): array
{
    $peopleCount = max(1, min(20, $peopleCount));
    $tenantId = (int) $form['tenant_id'];
    $formId = (int) $form['id'];
    $basePrice = max(0, (float) ($form['payment_amount'] ?? 0));
    $lot = null;
    $now = time();

    $sql = 'SELECT * FROM form_payment_lots WHERE tenant_id = ? AND form_id = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC';
    if ($forUpdate) $sql .= ' FOR UPDATE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tenantId, $formId]);
    $configuredLots = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($configuredLots as $candidate) {
        $starts = trim((string) ($candidate['starts_at'] ?? ''));
        $ends = trim((string) ($candidate['ends_at'] ?? ''));
        if ($starts !== '' && strtotime($starts) > $now) continue;
        if ($ends !== '' && strtotime($ends) < $now) continue;
        $capacity = $candidate['capacity'] !== null ? (int) $candidate['capacity'] : null;
        if ($capacity !== null && (int) $candidate['used_quantity'] + $peopleCount > $capacity) continue;
        $lot = $candidate;
        break;
    }
    if ($configuredLots && !$lot) throw new DomainException('Nenhum lote possui vagas disponíveis neste momento.');
    if ($lot) $basePrice = max(0, (float) $lot['price']);

    $subtotal = round($basePrice * $peopleCount, 2);
    $stmt = $pdo->prepare('SELECT * FROM form_group_discounts WHERE tenant_id = ? AND form_id = ? AND is_active = 1 AND min_people <= ? ORDER BY min_people DESC, id DESC LIMIT 1');
    $stmt->execute([$tenantId, $formId, $peopleCount]);
    $groupRule = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $groupDiscount = $groupRule ? pricingDiscountAmount($subtotal, (string) $groupRule['discount_type'], (float) $groupRule['discount_value']) : 0.0;
    $afterGroup = round(max(0, $subtotal - $groupDiscount), 2);

    $subtotalCents = (int) round($subtotal * 100);
    $groupDiscountCents = (int) round($groupDiscount * 100);
    $unitCents = (int) round($basePrice * 100);
    $groupShareBase = intdiv($groupDiscountCents, $peopleCount);
    $groupShareRemainder = $groupDiscountCents % $peopleCount;
    $participants = [];
    for ($personIndex = 1; $personIndex <= $peopleCount; $personIndex++) {
        $participantSubtotalCents = $personIndex === $peopleCount ? $subtotalCents - ($unitCents * ($peopleCount - 1)) : $unitCents;
        $participantGroupCents = $groupShareBase + ($personIndex <= $groupShareRemainder ? 1 : 0);
        $participantAfterGroup = max(0, $participantSubtotalCents - $participantGroupCents) / 100;
        $participants[$personIndex] = [
            'person_index' => $personIndex, 'subtotal' => round($participantSubtotalCents / 100, 2),
            'group_discount' => round($participantGroupCents / 100, 2), 'after_group' => round($participantAfterGroup, 2),
            'coupon_id' => null, 'coupon_code' => null, 'coupon_type' => null, 'coupon_value' => null,
            'coupon_discount' => 0.0, 'total' => round($participantAfterGroup, 2),
        ];
    }

    $normalizedParticipantCodes = [];
    foreach ($participantCouponCodes as $personIndex => $participantCode) {
        $personIndex = (int) $personIndex;
        if ($personIndex < 1 || $personIndex > $peopleCount) continue;
        $participantCode = pricingNormalizeCode((string) $participantCode);
        if ($participantCode !== '') $normalizedParticipantCodes[$personIndex] = $participantCode;
    }
    $participantCoupons = [];
    $participantCouponDiscount = 0.0;
    $loadedParticipantCoupons = [];
    foreach (array_count_values($normalizedParticipantCodes) as $participantCode => $requestedUses) {
        $sql = 'SELECT * FROM form_discount_coupons WHERE tenant_id = ? AND form_id = ? AND code = ? LIMIT 1';
        if ($forUpdate) $sql .= ' FOR UPDATE';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tenantId, $formId, $participantCode]);
        $participantCoupon = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$participantCoupon || (int) $participantCoupon['is_active'] !== 1 || ($participantCoupon['application_scope'] ?? 'registration') !== 'participant') throw new DomainException('Cupom individual inválido ou inativo.');
        if (!empty($participantCoupon['starts_at']) && strtotime($participantCoupon['starts_at']) > $now) throw new DomainException('Um cupom individual ainda não está disponível.');
        if (!empty($participantCoupon['expires_at']) && strtotime($participantCoupon['expires_at']) < $now) throw new DomainException('Um cupom individual expirou.');
        if ((int) $participantCoupon['min_people'] > $peopleCount) throw new DomainException('O cupom individual ' . $participantCoupon['code'] . ' exige no mínimo ' . (int) $participantCoupon['min_people'] . ' pessoas.');
        if ($participantCoupon['max_uses'] !== null && (int) $participantCoupon['used_count'] + (int) $requestedUses > (int) $participantCoupon['max_uses']) throw new DomainException('O limite de uso do cupom individual ' . $participantCoupon['code'] . ' foi atingido.');
        $loadedParticipantCoupons[$participantCode] = $participantCoupon;
    }
    foreach ($normalizedParticipantCodes as $personIndex => $participantCode) {
        $participantCoupon = $loadedParticipantCoupons[$participantCode];
        $discount = pricingDiscountAmount((float) $participants[$personIndex]['after_group'], (string) $participantCoupon['discount_type'], (float) $participantCoupon['discount_value']);
        $participants[$personIndex]['coupon_id'] = (int) $participantCoupon['id'];
        $participants[$personIndex]['coupon_code'] = $participantCoupon['code'];
        $participants[$personIndex]['coupon_type'] = $participantCoupon['discount_type'];
        $participants[$personIndex]['coupon_value'] = (float) $participantCoupon['discount_value'];
        $participants[$personIndex]['coupon_discount'] = $discount;
        $participants[$personIndex]['total'] = round(max(0, (float) $participants[$personIndex]['after_group'] - $discount), 2);
        $participantCouponDiscount = round($participantCouponDiscount + $discount, 2);
        $participantCoupons[] = ['person_index' => $personIndex, 'coupon_id' => (int) $participantCoupon['id'], 'coupon_code' => $participantCoupon['code'], 'discount_amount' => $discount];
    }
    $afterParticipantCoupons = round(max(0, $afterGroup - $participantCouponDiscount), 2);

    $coupon = null;
    $registrationCouponDiscount = 0.0;
    $couponCode = pricingNormalizeCode($couponCode);
    if ($couponCode !== '') {
        $sql = 'SELECT * FROM form_discount_coupons WHERE tenant_id = ? AND form_id = ? AND code = ? LIMIT 1';
        if ($forUpdate) $sql .= ' FOR UPDATE';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$tenantId, $formId, $couponCode]);
        $coupon = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$coupon || (int) $coupon['is_active'] !== 1 || ($coupon['application_scope'] ?? 'registration') !== 'registration') throw new DomainException('Cupom por inscrição inválido ou inativo.');
        if (!empty($coupon['starts_at']) && strtotime($coupon['starts_at']) > $now) throw new DomainException('Este cupom ainda não está disponível.');
        if (!empty($coupon['expires_at']) && strtotime($coupon['expires_at']) < $now) throw new DomainException('Este cupom expirou.');
        if ((int) $coupon['min_people'] > $peopleCount) throw new DomainException('Este cupom exige no mínimo ' . (int) $coupon['min_people'] . ' pessoas.');
        if ($coupon['max_uses'] !== null && (int) $coupon['used_count'] >= (int) $coupon['max_uses']) throw new DomainException('O limite de uso deste cupom foi atingido.');
        $registrationCouponDiscount = pricingDiscountAmount($afterParticipantCoupons, (string) $coupon['discount_type'], (float) $coupon['discount_value']);
    }

    $total = round(max(0, $afterParticipantCoupons - $registrationCouponDiscount), 2);
    $groupLabel = null;
    if ($groupRule) {
        $valueLabel = $groupRule['discount_type'] === 'percentage'
            ? rtrim(rtrim(number_format((float) $groupRule['discount_value'], 2, ',', '.'), '0'), ',') . '%'
            : 'R$ ' . number_format((float) $groupRule['discount_value'], 2, ',', '.');
        $groupLabel = 'A partir de ' . (int) $groupRule['min_people'] . ' pessoas · ' . $valueLabel;
    }
    return [
        'people_count' => $peopleCount, 'unit_price' => $basePrice, 'subtotal' => $subtotal,
        'lot_id' => $lot ? (int) $lot['id'] : null, 'lot_name' => $lot['name'] ?? null,
        'group_rule_id' => $groupRule ? (int) $groupRule['id'] : null, 'group_rule_label' => $groupLabel,
        'group_discount' => $groupDiscount, 'coupon_id' => $coupon ? (int) $coupon['id'] : null,
        'coupon_code' => $coupon['code'] ?? null, 'coupon_type' => $coupon['discount_type'] ?? null,
        'coupon_value' => $coupon ? (float) $coupon['discount_value'] : null,
        'registration_coupon_discount' => $registrationCouponDiscount,
        'participant_coupon_discount' => $participantCouponDiscount,
        'coupon_discount' => round($registrationCouponDiscount + $participantCouponDiscount, 2),
        'participant_coupons' => $participantCoupons, 'participants' => $participants,
        'discount_total' => round($groupDiscount + $participantCouponDiscount + $registrationCouponDiscount, 2), 'total' => $total,
    ];
}

function reserveFormPricing(PDO $pdo, array $quote, int $tenantId, int $formId, string $submissionGroup): void
{
    if (!empty($quote['lot_id'])) {
        $stmt = $pdo->prepare('UPDATE form_payment_lots SET used_quantity = used_quantity + ? WHERE id = ? AND tenant_id = ? AND form_id = ? AND is_active = 1 AND (capacity IS NULL OR used_quantity + ? <= capacity)');
        $stmt->execute([(int) $quote['people_count'], (int) $quote['lot_id'], $tenantId, $formId, (int) $quote['people_count']]);
        if ($stmt->rowCount() !== 1) throw new DomainException('As últimas vagas deste lote acabaram. Atualize a página e tente novamente.');
    }
    if (!empty($quote['coupon_id'])) {
        $stmt = $pdo->prepare('UPDATE form_discount_coupons SET used_count = used_count + 1 WHERE id = ? AND tenant_id = ? AND form_id = ? AND is_active = 1 AND (max_uses IS NULL OR used_count < max_uses)');
        $stmt->execute([(int) $quote['coupon_id'], $tenantId, $formId]);
        if ($stmt->rowCount() !== 1) throw new DomainException('O limite de uso deste cupom foi atingido.');
        $stmt = $pdo->prepare('INSERT INTO form_coupon_redemptions (tenant_id, form_id, coupon_id, submission_group, person_index, discount_amount) VALUES (?, ?, ?, ?, 0, ?)');
        $stmt->execute([$tenantId, $formId, (int) $quote['coupon_id'], $submissionGroup, (float) ($quote['registration_coupon_discount'] ?? $quote['coupon_discount'])]);
    }
    $participantCouponsById = [];
    foreach (($quote['participant_coupons'] ?? []) as $participantCoupon) {
        $couponId = (int) $participantCoupon['coupon_id'];
        $participantCouponsById[$couponId] = ($participantCouponsById[$couponId] ?? 0) + 1;
    }
    foreach ($participantCouponsById as $couponId => $uses) {
        $stmt = $pdo->prepare("UPDATE form_discount_coupons SET used_count = used_count + ? WHERE id = ? AND tenant_id = ? AND form_id = ? AND is_active = 1 AND application_scope = 'participant' AND (max_uses IS NULL OR used_count + ? <= max_uses)");
        $stmt->execute([$uses, $couponId, $tenantId, $formId, $uses]);
        if ($stmt->rowCount() !== 1) throw new DomainException('O limite de uso de um cupom individual foi atingido.');
    }
    if (!empty($quote['participant_coupons'])) {
        $stmt = $pdo->prepare('INSERT INTO form_coupon_redemptions (tenant_id, form_id, coupon_id, submission_group, person_index, discount_amount) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($quote['participant_coupons'] as $participantCoupon) {
            $stmt->execute([$tenantId, $formId, (int) $participantCoupon['coupon_id'], $submissionGroup, (int) $participantCoupon['person_index'], (float) $participantCoupon['discount_amount']]);
        }
    }
}

function pricingGroupRules(PDO $pdo, int $tenantId, int $formId): array
{
    $stmt = $pdo->prepare('SELECT min_people, discount_type, discount_value FROM form_group_discounts WHERE tenant_id = ? AND form_id = ? AND is_active = 1 ORDER BY min_people ASC');
    $stmt->execute([$tenantId, $formId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
