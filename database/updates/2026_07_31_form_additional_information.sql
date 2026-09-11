-- FormOps: conteúdo opcional exibido abaixo da capa do formulário público.
ALTER TABLE forms
    ADD COLUMN additional_information TEXT NULL AFTER public_subtitle;
