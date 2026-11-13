BEGIN;

ALTER TABLE public.users
    ADD COLUMN message_policy VARCHAR(16) NOT NULL DEFAULT 'everyone'
        CHECK (message_policy IN ('everyone', 'existing', 'nobody')),
    ADD COLUMN public_ratings BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN public_statistics BOOLEAN NOT NULL DEFAULT TRUE;

COMMIT;
