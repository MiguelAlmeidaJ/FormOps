ALTER TABLE forms
    ADD COLUMN IF NOT EXISTS hide_banner_text TINYINT(1) NOT NULL DEFAULT 0 AFTER public_subtitle;
