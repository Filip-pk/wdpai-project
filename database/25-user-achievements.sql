BEGIN;

CREATE TABLE public.user_achievements (
    user_id BIGINT NOT NULL REFERENCES public.users (id) ON DELETE CASCADE,
    achievement_code VARCHAR(64) NOT NULL CHECK (achievement_code ~ '^[a-z][a-z0-9_]*$'),
    unlocked_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (user_id, achievement_code)
);

COMMIT;
