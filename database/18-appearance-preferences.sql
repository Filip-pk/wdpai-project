BEGIN;

ALTER TABLE public.users
    ADD COLUMN font_size VARCHAR(16) NOT NULL DEFAULT 'standard'
        CHECK (font_size IN ('standard', 'large', 'largest')),
    ADD COLUMN line_height VARCHAR(16) NOT NULL DEFAULT 'standard'
        CHECK (line_height IN ('standard', 'relaxed', 'spacious')),
    ADD COLUMN interface_density VARCHAR(16) NOT NULL DEFAULT 'comfortable'
        CHECK (interface_density IN ('compact', 'comfortable', 'spacious'));

COMMIT;
