BEGIN;

ALTER TABLE public.users
    ADD COLUMN language VARCHAR(2) NOT NULL DEFAULT 'pl'
        CHECK (language IN ('pl', 'en', 'de', 'fr', 'es', 'ru'));

COMMIT;
