BEGIN;

ALTER TABLE public.users
    ADD COLUMN high_contrast VARCHAR(1) NOT NULL DEFAULT '0' CHECK (high_contrast IN ('0', '1')),
    ADD COLUMN reduced_motion VARCHAR(1) NOT NULL DEFAULT '0' CHECK (reduced_motion IN ('0', '1')),
    ADD COLUMN strong_focus VARCHAR(1) NOT NULL DEFAULT '0' CHECK (strong_focus IN ('0', '1')),
    ADD COLUMN large_controls VARCHAR(1) NOT NULL DEFAULT '0' CHECK (large_controls IN ('0', '1')),
    ADD COLUMN message_text_size VARCHAR(16) NOT NULL DEFAULT 'standard'
        CHECK (message_text_size IN ('standard', 'large', 'largest')),
    ADD COLUMN comment_text_size VARCHAR(16) NOT NULL DEFAULT 'standard'
        CHECK (comment_text_size IN ('standard', 'large', 'largest'));

COMMIT;
