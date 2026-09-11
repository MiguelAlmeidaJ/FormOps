<?php

$responseOperationErrors = [];
$openParticipantModal = false;
$responseAction = (string) ($_POST['response_action'] ?? '');

function manualParticipantOptions(array $field): array
{
    $options = preg_split('/\r\n|\r|\n/', (string) ($field['options'] ?? ''));
    return array_values(array_filter(array_map('trim', $options), fn ($option) => $option !== ''));
}

function manualParticipantValue(array $field)
{
    if ((int) ($field['is_readonly'] ?? 0) === 1) return trim((string) ($field['default_value'] ?? ''));
    $data = is_array($_POST['participant'] ?? null) ? $_POST['participant'] : [];
    $value = $data[(int) $field['id']] ?? '';
    if ($field['type'] === 'checkbox') {
        $values = is_array($value) ? array_map('strval', $value) : [];
        return array_values(array_intersect($values, manualParticipantOptions($field)));
    }
    $value = is_array($value) ? '' : trim((string) $value);
    if (in_array($field['type'], ['select', 'radio'], true) && $value !== '' && !in_array($value, manualParticipantOptions($field), true)) return '';
    return $value;
}

function manualParticipantComparable($value): array
{
    $values = is_array($value) ? $value : [$value];
    return array_map(fn ($item) => function_exists('mb_strtolower') ? mb_strtolower(trim((string) $item), 'UTF-8') : strtolower(trim((string) $item)), $values);
}

function manualParticipantConditionMet(array $field, array $fieldsById, array $activeFields): bool
{
    if ((int) ($field['conditional_enabled'] ?? 0) !== 1) return true;
    $referenceId = (int) ($field['conditional_field_id'] ?? 0);
    if (!isset($fieldsById[$referenceId]) || empty($activeFields[$referenceId])) return false;
    $actual = manualParticipantComparable(manualParticipantValue($fieldsById[$referenceId]));
    $expected = manualParticipantComparable($field['conditional_value'] ?? '')[0] ?? '';
    $filled = count(array_filter($actual, fn ($value) => $value !== '')) > 0;
    $equals = in_array($expected, $actual, true);
    $contains = $expected !== '' && count(array_filter($actual, fn ($value) => str_contains($value, $expected))) > 0;
    return match ($field['conditional_operator'] ?? 'equals') {
        'equals' => $equals, 'not_equals' => !$equals, 'contains' => $contains,
        'not_contains' => !$contains, 'filled' => $filled, 'empty' => !$filled, default => false,
    };
}

function responseActionRedirectParams(?int $formId, ?int $groupId, array $extra = []): array
{
    return array_merge(array_filter(['form_id' => $formId, 'group_id' => $groupId], fn ($value) => $value !== null), $extra);
}

function responseActionTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $responseAction !== '') {
    if (!canManageTenantData()) {
        redirectTo('responses', responseActionRedirectParams($formId, $selectedGroupId, ['error' => 'permission']));
    }

    if ($responseAction === 'add_participant') {
        $openParticipantModal = true;
        $fieldsById = [];
        foreach ($fields as $field) $fieldsById[(int) $field['id']] = $field;
        $activeFields = [];
        $activeAnswerFields = [];
        foreach ($fields as $field) {
            $active = manualParticipantConditionMet($field, $fieldsById, $activeFields);
            $activeFields[(int) $field['id']] = $active;
            if (!$active) continue;
            $activeAnswerFields[] = $field;
            $value = manualParticipantValue($field);
            $empty = is_array($value) ? count($value) === 0 : $value === '';
            if ((int) ($field['is_required'] ?? 0) === 1 && $empty) $responseOperationErrors[] = 'O campo "' . $field['label'] . '" é obrigatório.';
            if ($field['type'] === 'email' && !$empty && !filter_var($value, FILTER_VALIDATE_EMAIL)) $responseOperationErrors[] = 'Informe um e-mail válido em "' . $field['label'] . '".';
        }

        if (!$responseOperationErrors) {
            $ticketToEmail = null;
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT * FROM forms WHERE id = ? AND tenant_id = ? FOR UPDATE');
                $stmt->execute([$formId, $tenantId]);
                $lockedForm = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$lockedForm) throw new DomainException('Formulário não encontrado.');
                $paymentEnabled = (int) ($lockedForm['payment_enabled'] ?? 0) === 1;
                $quote = $paymentEnabled ? formPricingQuote($pdo, $lockedForm, 1, '', true) : null;
                $paymentAmount = (float) ($quote['unit_price'] ?? 0);
                $paymentTotal = (float) ($quote['total'] ?? 0);
                $paymentStatus = $paymentEnabled && $paymentTotal > 0 ? 'pending' : 'approved';
                $submissionGroup = bin2hex(random_bytes(16));

                $stmt = $pdo->prepare('SELECT COALESCE(MAX(response_number), 0) + 1 FROM form_responses WHERE tenant_id = ? AND form_id = ?');
                $stmt->execute([$tenantId, $formId]);
                $responseNumber = (int) $stmt->fetchColumn();
                $participantPricing = $quote['participants'][1] ?? [];
                $stmt = $pdo->prepare('INSERT INTO form_responses (tenant_id, form_id, response_number, submission_group, person_index, people_count, payment_amount, payment_total, payment_method, payment_status, pricing_lot_id, pricing_lot_name, pricing_subtotal, pricing_group_discount, pricing_coupon_discount, pricing_discount_total, pricing_coupon_code, pricing_group_rule, pricing_participant_subtotal, pricing_participant_group_discount, pricing_participant_coupon_discount, pricing_participant_total, pricing_participant_coupon_code, submitted_by_ip) VALUES (?, ?, ?, ?, 1, 1, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$tenantId, $formId, $responseNumber, $submissionGroup, $paymentAmount, $paymentTotal, $paymentStatus, $quote['lot_id'] ?? null, $quote['lot_name'] ?? null, $quote['subtotal'] ?? 0, $quote['group_discount'] ?? 0, $quote['coupon_discount'] ?? 0, $quote['discount_total'] ?? 0, $quote['coupon_code'] ?? null, $quote['group_rule_label'] ?? null, $participantPricing['subtotal'] ?? $paymentAmount, $participantPricing['group_discount'] ?? 0, $participantPricing['coupon_discount'] ?? 0, $participantPricing['total'] ?? $paymentAmount, $participantPricing['coupon_code'] ?? null, $_SERVER['REMOTE_ADDR'] ?? null]);
                $newResponseId = (int) $pdo->lastInsertId();
                $answerStmt = $pdo->prepare('INSERT INTO form_response_answers (tenant_id, response_id, field_id, answer) VALUES (?, ?, ?, ?)');
                foreach ($activeAnswerFields as $field) {
                    $answer = manualParticipantValue($field);
                    if (is_array($answer)) $answer = implode(', ', $answer);
                    $answerStmt->execute([$tenantId, $newResponseId, (int) $field['id'], $answer]);
                }
                if ($quote) reserveFormPricing($pdo, $quote, (int) $tenantId, (int) $formId, $submissionGroup);
                if ($paymentStatus === 'approved' && (int) ($lockedForm['ticket_enabled'] ?? 0) === 1) {
                    $ticketToEmail = issueTicketForResponse($pdo, (int) $tenantId, $newResponseId);
                }
                $pdo->commit();
                if ($ticketToEmail) sendTicketEmail($pdo, (int) $ticketToEmail['id']);
                redirectTo('responses', responseActionRedirectParams($formId, $selectedGroupId, ['success' => 'participant_added']));
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('FormOps manual participant error: ' . $exception->getMessage());
                $responseOperationErrors[] = $exception instanceof DomainException ? $exception->getMessage() : 'Não foi possível adicionar o participante.';
            }
        }
    }

    if (in_array($responseAction, ['delete_response', 'delete_responses'], true)) {
        $postedCsrfToken = (string) ($_POST['csrf_token'] ?? '');
        if (!isset($responseCsrfToken) || !hash_equals($responseCsrfToken, $postedCsrfToken)) {
            redirectTo('responses', responseActionRedirectParams($formId, $selectedGroupId, ['error' => 'invalid_request']));
        }

        $rawResponseIds = $responseAction === 'delete_response'
            ? [$_POST['response_id'] ?? null]
            : (is_array($_POST['response_ids'] ?? null) ? $_POST['response_ids'] : []);
        $deleteResponseIds = [];
        foreach ($rawResponseIds as $rawResponseId) {
            $validResponseId = filter_var($rawResponseId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($validResponseId !== false) {
                $deleteResponseIds[] = (int) $validResponseId;
            }
        }
        $deleteResponseIds = array_values(array_unique($deleteResponseIds));

        if (!$deleteResponseIds) {
            redirectTo('responses', responseActionRedirectParams($formId, $selectedGroupId, ['error' => 'selection_required']));
        }

        try {
            $pdo->beginTransaction();
            $placeholders = implode(',', array_fill(0, count($deleteResponseIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT id, submission_group, person_index, pricing_lot_id
                 FROM form_responses
                 WHERE tenant_id = ? AND form_id = ? AND id IN ($placeholders)
                 FOR UPDATE"
            );
            $stmt->execute(array_merge([$tenantId, $formId], $deleteResponseIds));
            $responsesToDelete = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$responsesToDelete || ($responseAction === 'delete_response' && count($responsesToDelete) !== 1)) {
                throw new DomainException('Participante não encontrado.');
            }

            $validDeleteIds = array_map('intval', array_column($responsesToDelete, 'id'));
            $validPlaceholders = implode(',', array_fill(0, count($validDeleteIds), '?'));
            $deleteParams = array_merge([$tenantId], $validDeleteIds);

            if (responseActionTableExists($pdo, 'form_tickets')) {
                $stmt = $pdo->prepare("DELETE FROM form_tickets WHERE tenant_id = ? AND response_id IN ($validPlaceholders)");
                $stmt->execute($deleteParams);
            }
            if (responseActionTableExists($pdo, 'form_response_answers')) {
                $stmt = $pdo->prepare("DELETE FROM form_response_answers WHERE tenant_id = ? AND response_id IN ($validPlaceholders)");
                $stmt->execute($deleteParams);
            }

            $stmt = $pdo->prepare("DELETE FROM form_responses WHERE tenant_id = ? AND form_id = ? AND id IN ($validPlaceholders)");
            $stmt->execute(array_merge([$tenantId, $formId], $validDeleteIds));
            if ($stmt->rowCount() !== count($validDeleteIds)) {
                throw new RuntimeException('A exclusão afetou uma quantidade inesperada de inscrições.');
            }

            if (responseActionTableExists($pdo, 'form_payment_lots')) {
                $deletedByLot = [];
                foreach ($responsesToDelete as $deletedResponse) {
                    $lotId = (int) ($deletedResponse['pricing_lot_id'] ?? 0);
                    if ($lotId > 0) {
                        $deletedByLot[$lotId] = ($deletedByLot[$lotId] ?? 0) + 1;
                    }
                }
                $stmt = $pdo->prepare('UPDATE form_payment_lots SET used_quantity = GREATEST(0, used_quantity - ?) WHERE id = ? AND tenant_id = ? AND form_id = ?');
                foreach ($deletedByLot as $lotId => $deletedCount) {
                    $stmt->execute([$deletedCount, $lotId, $tenantId, $formId]);
                }
            }

            $affectedSubmissionGroups = array_values(array_unique(array_filter(
                array_map(static fn (array $response): string => trim((string) ($response['submission_group'] ?? '')), $responsesToDelete),
                static fn (string $group): bool => $group !== ''
            )));
            if ($affectedSubmissionGroups && responseActionTableExists($pdo, 'form_coupon_redemptions')) {
                $groupPlaceholders = implode(',', array_fill(0, count($affectedSubmissionGroups), '?'));
                $stmt = $pdo->prepare(
                    "SELECT cr.id, cr.coupon_id
                     FROM form_coupon_redemptions cr
                     WHERE cr.tenant_id = ? AND cr.form_id = ?
                       AND cr.submission_group IN ($groupPlaceholders)
                       AND (
                           (cr.person_index = 0 AND NOT EXISTS (
                               SELECT 1 FROM form_responses r
                               WHERE r.tenant_id = cr.tenant_id AND r.form_id = cr.form_id
                                 AND r.submission_group = cr.submission_group
                           ))
                           OR
                           (cr.person_index > 0 AND NOT EXISTS (
                               SELECT 1 FROM form_responses r
                               WHERE r.tenant_id = cr.tenant_id AND r.form_id = cr.form_id
                                 AND r.submission_group = cr.submission_group AND r.person_index = cr.person_index
                           ))
                       )
                     FOR UPDATE"
                );
                $stmt->execute(array_merge([$tenantId, $formId], $affectedSubmissionGroups));
                $redemptionsToDelete = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if ($redemptionsToDelete) {
                    $redemptionIds = array_map('intval', array_column($redemptionsToDelete, 'id'));
                    $redemptionPlaceholders = implode(',', array_fill(0, count($redemptionIds), '?'));
                    $stmt = $pdo->prepare("DELETE FROM form_coupon_redemptions WHERE tenant_id = ? AND id IN ($redemptionPlaceholders)");
                    $stmt->execute(array_merge([$tenantId], $redemptionIds));

                    if (responseActionTableExists($pdo, 'form_discount_coupons')) {
                        $deletedByCoupon = [];
                        foreach ($redemptionsToDelete as $redemption) {
                            $couponId = (int) $redemption['coupon_id'];
                            $deletedByCoupon[$couponId] = ($deletedByCoupon[$couponId] ?? 0) + 1;
                        }
                        $stmt = $pdo->prepare('UPDATE form_discount_coupons SET used_count = GREATEST(0, used_count - ?) WHERE id = ? AND tenant_id = ? AND form_id = ?');
                        foreach ($deletedByCoupon as $couponId => $deletedCount) {
                            $stmt->execute([$deletedCount, $couponId, $tenantId, $formId]);
                        }
                    }
                }
            }

            $pdo->commit();
            $successCode = count($validDeleteIds) === 1 ? 'response_deleted' : 'responses_deleted';
            redirectTo('responses', responseActionRedirectParams($formId, $selectedGroupId, ['success' => $successCode, 'deleted' => count($validDeleteIds)]));
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('FormOps response delete error: ' . $exception->getMessage());
            $errorCode = $exception instanceof DomainException ? 'response_not_found' : 'delete_failed';
            redirectTo('responses', responseActionRedirectParams($formId, $selectedGroupId, ['error' => $errorCode]));
        }
    }

    if (in_array($responseAction, ['approve_participant', 'resend_ticket'], true)) {
        $actionResponseId = filter_var($_POST['response_id'] ?? null, FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare('SELECT r.*, f.ticket_enabled FROM form_responses r INNER JOIN forms f ON f.id = r.form_id AND f.tenant_id = r.tenant_id WHERE r.id = ? AND r.form_id = ? AND r.tenant_id = ? LIMIT 1');
        $stmt->execute([$actionResponseId, $formId, $tenantId]);
        $actionResponse = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$actionResponse) redirectTo('responses', responseActionRedirectParams($formId, $selectedGroupId, ['error' => 'response_not_found']));

        if ($responseAction === 'approve_participant') {
            $ticketToEmail = null;
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("UPDATE form_responses SET payment_status = 'approved', payment_approved_by = ?, payment_approved_at = NOW() WHERE id = ? AND form_id = ? AND tenant_id = ?");
                $stmt->execute([(int) ($currentUser['id'] ?? 0), $actionResponseId, $formId, $tenantId]);
                if ((int) ($actionResponse['ticket_enabled'] ?? 0) === 1) $ticketToEmail = issueTicketForResponse($pdo, (int) $tenantId, (int) $actionResponseId);
                $pdo->commit();
                if ($ticketToEmail) sendTicketEmail($pdo, (int) $ticketToEmail['id']);
                redirectTo('responses', responseActionRedirectParams($formId, $selectedGroupId, ['success' => 'participant_approved']));
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('FormOps participant approval error: ' . $exception->getMessage());
                redirectTo('responses', responseActionRedirectParams($formId, $selectedGroupId, ['error' => 'approval_failed']));
            }
        }

        $stmt = $pdo->prepare('SELECT * FROM form_tickets WHERE tenant_id = ? AND form_id = ? AND response_id = ? LIMIT 1');
        $stmt->execute([$tenantId, $formId, $actionResponseId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) redirectTo('responses', responseActionRedirectParams($formId, $selectedGroupId, ['error' => 'ticket_not_found']));
        $sent = sendTicketEmail($pdo, (int) $ticket['id']);
        redirectTo('responses', responseActionRedirectParams($formId, $selectedGroupId, ['success' => $sent ? 'ticket_resent' : 'ticket_resend_failed']));
    }
}
