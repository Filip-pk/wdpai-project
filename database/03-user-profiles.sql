BEGIN;

ALTER TABLE public.users
    ADD COLUMN interests TEXT NOT NULL DEFAULT '',
    ADD COLUMN theme VARCHAR(10) NOT NULL DEFAULT 'system',
    ADD CONSTRAINT users_interests_length_check CHECK (char_length(interests) <= 1000),
    ADD CONSTRAINT users_theme_check CHECK (theme IN ('system', 'light', 'dark'));

CREATE TABLE public.user_watchlist (
    user_id BIGINT NOT NULL REFERENCES public.users (id) ON DELETE CASCADE,
    anime_id INTEGER NOT NULL CHECK (anime_id > 0),
    status VARCHAR(20) NOT NULL DEFAULT 'planned',
    watched INTEGER NOT NULL DEFAULT 0 CHECK (watched >= 0),
    PRIMARY KEY (user_id, anime_id),
    CONSTRAINT user_watchlist_status_check CHECK (status IN ('planned', 'watching', 'completed'))
);

COMMIT;
