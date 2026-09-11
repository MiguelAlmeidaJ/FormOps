-- Execute este arquivo uma única vez.
-- Cria uma numeração sequencial independente para cada formulário de cada tenant.

ALTER TABLE form_responses
ADD COLUMN response_number INT NULL AFTER form_id;

SET @current_form := 0;
SET @current_tenant := 0;
SET @counter := 0;

UPDATE form_responses fr
JOIN (
    SELECT
        ordered.id,
        ordered.tenant_id,
        ordered.form_id,
        @counter := IF(
            @current_tenant = ordered.tenant_id AND @current_form = ordered.form_id,
            @counter + 1,
            1
        ) AS new_number,
        @current_tenant := ordered.tenant_id,
        @current_form := ordered.form_id
    FROM (
        SELECT id, tenant_id, form_id
        FROM form_responses
        ORDER BY tenant_id, form_id, submitted_at, id
    ) ordered
) numbered ON numbered.id = fr.id
SET fr.response_number = numbered.new_number;

ALTER TABLE form_responses
ADD UNIQUE KEY unique_response_number_per_form
(tenant_id, form_id, response_number);

