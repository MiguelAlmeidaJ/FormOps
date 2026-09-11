<?php

function pricingPostDecimal(string $key): ?float
{
    $value = str_replace(',', '.', trim((string) ($_POST[$key] ?? '')));
    return $value !== '' && is_numeric($value) ? round((float) $value, 2) : null;
}

$pricingAction = (string) ($_POST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($pricingAction, ['save_lot', 'delete_lot', 'save_coupon', 'delete_coupon', 'save_group_discount', 'delete_group_discount'], true)) {
    $activeTab = 'payment';

    if ($pricingAction === 'save_lot') {
        $lotId = filter_var($_POST['lot_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $name = trim((string) ($_POST['lot_name'] ?? ''));
        $price = pricingPostDecimal('lot_price');
        $startsInput = trim((string) ($_POST['lot_starts_at'] ?? ''));
        $endsInput = trim((string) ($_POST['lot_ends_at'] ?? ''));
        $startsAt = parseDateTimeLocal($startsInput);
        $endsAt = parseDateTimeLocal($endsInput);
        $capacityInput = trim((string) ($_POST['lot_capacity'] ?? ''));
        $capacity = $capacityInput === '' ? null : filter_var($capacityInput, FILTER_VALIDATE_INT);
        $sortOrder = max(0, (int) ($_POST['lot_sort_order'] ?? 0));
        $isActive = isset($_POST['lot_is_active']) ? 1 : 0;

        if ($name === '' || strlen($name) > 120) $errors[] = 'Informe um nome de lote com até 120 caracteres.';
        if ($price === null || $price < 0) $errors[] = 'Informe um valor válido para o lote.';
        if ($startsInput !== '' && $startsAt === null) $errors[] = 'Informe uma data inicial válida para o lote.';
        if ($endsInput !== '' && $endsAt === null) $errors[] = 'Informe uma data final válida para o lote.';
        if ($startsAt && $endsAt && strtotime($startsAt) >= strtotime($endsAt)) $errors[] = 'O encerramento do lote deve ser posterior ao início.';
        if ($capacityInput !== '' && ($capacity === false || $capacity < 1)) $errors[] = 'A quantidade de vagas do lote deve ser maior que zero.';

        if (!$errors) {
            if ($lotId) {
                $stmt = $pdo->prepare('SELECT used_quantity FROM form_payment_lots WHERE id = ? AND tenant_id = ? AND form_id = ? LIMIT 1');
                $stmt->execute([$lotId, $tenantId, $formId]);
                $used = $stmt->fetchColumn();
                if ($used === false) $errors[] = 'Lote não encontrado.';
                elseif ($capacity !== null && $capacity < (int) $used) $errors[] = 'A capacidade não pode ser menor que as ' . (int) $used . ' vagas já utilizadas.';
                else {
                    $stmt = $pdo->prepare('UPDATE form_payment_lots SET name = ?, price = ?, starts_at = ?, ends_at = ?, capacity = ?, sort_order = ?, is_active = ? WHERE id = ? AND tenant_id = ? AND form_id = ?');
                    $stmt->execute([$name, $price, $startsAt, $endsAt, $capacity, $sortOrder, $isActive, $lotId, $tenantId, $formId]);
                }
            } else {
                $stmt = $pdo->prepare('INSERT INTO form_payment_lots (tenant_id, form_id, name, price, starts_at, ends_at, capacity, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$tenantId, $formId, $name, $price, $startsAt, $endsAt, $capacity, $sortOrder, $isActive]);
            }
            if (!$errors) redirectTo('form-edit', ['id' => $formId, 'tab' => 'payment', 'success' => 'lot_saved']);
        }
    }

    if ($pricingAction === 'delete_lot') {
        $lotId = filter_var($_POST['lot_id'] ?? null, FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare('SELECT used_quantity FROM form_payment_lots WHERE id = ? AND tenant_id = ? AND form_id = ? LIMIT 1');
        $stmt->execute([$lotId, $tenantId, $formId]);
        $used = $stmt->fetchColumn();
        if ($used !== false) {
            if ((int) $used > 0) {
                $stmt = $pdo->prepare('UPDATE form_payment_lots SET is_active = 0 WHERE id = ? AND tenant_id = ? AND form_id = ?');
            } else {
                $stmt = $pdo->prepare('DELETE FROM form_payment_lots WHERE id = ? AND tenant_id = ? AND form_id = ?');
            }
            $stmt->execute([$lotId, $tenantId, $formId]);
        }
        redirectTo('form-edit', ['id' => $formId, 'tab' => 'payment', 'success' => 'lot_removed']);
    }

    if ($pricingAction === 'save_coupon') {
        $couponId = filter_var($_POST['coupon_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $code = pricingNormalizeCode($_POST['coupon_code'] ?? '');
        $type = in_array($_POST['coupon_type'] ?? '', ['percentage', 'fixed'], true) ? $_POST['coupon_type'] : '';
        $scope = in_array($_POST['coupon_scope'] ?? '', ['registration', 'participant'], true) ? $_POST['coupon_scope'] : 'registration';
        $value = pricingPostDecimal('coupon_value');
        $minPeople = max(1, min(20, (int) ($_POST['coupon_min_people'] ?? 1)));
        $maxUsesInput = trim((string) ($_POST['coupon_max_uses'] ?? ''));
        $maxUses = $maxUsesInput === '' ? null : filter_var($maxUsesInput, FILTER_VALIDATE_INT);
        $startsInput = trim((string) ($_POST['coupon_starts_at'] ?? ''));
        $expiresInput = trim((string) ($_POST['coupon_expires_at'] ?? ''));
        $startsAt = parseDateTimeLocal($startsInput);
        $expiresAt = parseDateTimeLocal($expiresInput);
        $isActive = isset($_POST['coupon_is_active']) ? 1 : 0;

        if ($code === '' || !preg_match('/^[A-Z0-9_-]{2,80}$/', $code)) $errors[] = 'Use de 2 a 80 letras, números, hífen ou sublinhado no cupom.';
        if ($type === '') $errors[] = 'Selecione o tipo de desconto do cupom.';
        if ($value === null || $value <= 0 || ($type === 'percentage' && $value > 100)) $errors[] = 'Informe um desconto válido para o cupom.';
        if ($maxUsesInput !== '' && ($maxUses === false || $maxUses < 1)) $errors[] = 'O limite de usos deve ser maior que zero.';
        if ($startsInput !== '' && $startsAt === null) $errors[] = 'Informe uma data inicial válida para o cupom.';
        if ($expiresInput !== '' && $expiresAt === null) $errors[] = 'Informe uma expiração válida para o cupom.';
        if ($startsAt && $expiresAt && strtotime($startsAt) >= strtotime($expiresAt)) $errors[] = 'A expiração deve ser posterior ao início do cupom.';

        if (!$errors) {
            try {
                if ($couponId) {
                    $stmt = $pdo->prepare('SELECT used_count FROM form_discount_coupons WHERE id = ? AND tenant_id = ? AND form_id = ? LIMIT 1');
                    $stmt->execute([$couponId, $tenantId, $formId]);
                    $used = $stmt->fetchColumn();
                    if ($used === false) $errors[] = 'Cupom não encontrado.';
                    elseif ($maxUses !== null && $maxUses < (int) $used) $errors[] = 'O limite não pode ser menor que os usos já registrados.';
                    else {
                        $stmt = $pdo->prepare('UPDATE form_discount_coupons SET code = ?, discount_type = ?, application_scope = ?, discount_value = ?, min_people = ?, max_uses = ?, starts_at = ?, expires_at = ?, is_active = ? WHERE id = ? AND tenant_id = ? AND form_id = ?');
                        $stmt->execute([$code, $type, $scope, $value, $minPeople, $maxUses, $startsAt, $expiresAt, $isActive, $couponId, $tenantId, $formId]);
                    }
                } else {
                    $stmt = $pdo->prepare('INSERT INTO form_discount_coupons (tenant_id, form_id, code, discount_type, application_scope, discount_value, min_people, max_uses, starts_at, expires_at, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$tenantId, $formId, $code, $type, $scope, $value, $minPeople, $maxUses, $startsAt, $expiresAt, $isActive]);
                }
            } catch (PDOException $exception) {
                if ((int) ($exception->errorInfo[1] ?? 0) === 1062) $errors[] = 'Já existe um cupom com este código.';
                else throw $exception;
            }
            if (!$errors) redirectTo('form-edit', ['id' => $formId, 'tab' => 'payment', 'success' => 'coupon_saved']);
        }
    }

    if ($pricingAction === 'delete_coupon') {
        $couponId = filter_var($_POST['coupon_id'] ?? null, FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare('SELECT used_count FROM form_discount_coupons WHERE id = ? AND tenant_id = ? AND form_id = ? LIMIT 1');
        $stmt->execute([$couponId, $tenantId, $formId]);
        $used = $stmt->fetchColumn();
        if ($used !== false) {
            $sql = (int) $used > 0 ? 'UPDATE form_discount_coupons SET is_active = 0 WHERE id = ? AND tenant_id = ? AND form_id = ?' : 'DELETE FROM form_discount_coupons WHERE id = ? AND tenant_id = ? AND form_id = ?';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$couponId, $tenantId, $formId]);
        }
        redirectTo('form-edit', ['id' => $formId, 'tab' => 'payment', 'success' => 'coupon_removed']);
    }

    if ($pricingAction === 'save_group_discount') {
        $minPeople = max(2, min(20, (int) ($_POST['group_min_people'] ?? 2)));
        $type = in_array($_POST['group_discount_type'] ?? '', ['percentage', 'fixed'], true) ? $_POST['group_discount_type'] : '';
        $value = pricingPostDecimal('group_discount_value');
        $isActive = isset($_POST['group_is_active']) ? 1 : 0;
        if ($type === '') $errors[] = 'Selecione o tipo de desconto para grupo.';
        if ($value === null || $value <= 0 || ($type === 'percentage' && $value > 100)) $errors[] = 'Informe um desconto de grupo válido.';
        if (!$errors) {
            $stmt = $pdo->prepare('INSERT INTO form_group_discounts (tenant_id, form_id, min_people, discount_type, discount_value, is_active) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE discount_type = VALUES(discount_type), discount_value = VALUES(discount_value), is_active = VALUES(is_active)');
            $stmt->execute([$tenantId, $formId, $minPeople, $type, $value, $isActive]);
            redirectTo('form-edit', ['id' => $formId, 'tab' => 'payment', 'success' => 'group_discount_saved']);
        }
    }

    if ($pricingAction === 'delete_group_discount') {
        $discountId = filter_var($_POST['group_discount_id'] ?? null, FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare('DELETE FROM form_group_discounts WHERE id = ? AND tenant_id = ? AND form_id = ?');
        $stmt->execute([$discountId, $tenantId, $formId]);
        redirectTo('form-edit', ['id' => $formId, 'tab' => 'payment', 'success' => 'group_discount_removed']);
    }
}
