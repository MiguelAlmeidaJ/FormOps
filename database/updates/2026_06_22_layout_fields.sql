-- Blocos visuais que organizam o formulário e não salvam respostas.
-- Execute este arquivo uma única vez.

ALTER TABLE form_fields
MODIFY type ENUM(
    'text', 'email', 'phone', 'number', 'date', 'textarea',
    'select', 'radio', 'checkbox', 'title', 'paragraph', 'divider', 'step'
) NOT NULL;

ALTER TABLE form_fields
ADD COLUMN is_layout TINYINT(1) DEFAULT 0 AFTER type;

-- Em instalações onde a coluna já exista, execute somente o MODIFY do ENUM.
