BEGIN;

CREATE TABLE public.user_email_changes (
    user_id BIGINT PRIMARY KEY REFERENCES public.users (id) ON DELETE CASCADE,
    new_email VARCHAR(255) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    requested_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMPTZ NOT NULL,
    CONSTRAINT user_email_changes_expiry_check CHECK (expires_at > requested_at)
);

COMMIT;
