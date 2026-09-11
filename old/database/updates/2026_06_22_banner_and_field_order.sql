-- Adiciona o bloco visual de banner. A ordenação já usa field_order.
ALTER TABLE form_fields
MODIFY type ENUM(
    'text','email','phone','number','date','textarea','select','radio','checkbox',
    'title','paragraph','divider','step','banner'
) NOT NULL;

-- Banner usa:
-- default_value = URL/caminho da imagem
-- label         = texto alternativo/título
-- help_text     = legenda opcional
