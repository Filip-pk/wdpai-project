BEGIN;

CREATE TABLE public.user_showcase_anime (
    user_id BIGINT NOT NULL REFERENCES public.users (id) ON DELETE CASCADE,
    position SMALLINT NOT NULL CHECK (position BETWEEN 1 AND 3),
    anime_id BIGINT NOT NULL REFERENCES public.anime (id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, position),
    UNIQUE (user_id, anime_id)
);

CREATE TABLE public.user_showcase_badges (
    user_id BIGINT NOT NULL,
    position SMALLINT NOT NULL CHECK (position BETWEEN 1 AND 3),
    achievement_code VARCHAR(64) NOT NULL,
    PRIMARY KEY (user_id, position),
    UNIQUE (user_id, achievement_code),
    FOREIGN KEY (user_id, achievement_code)
        REFERENCES public.user_achievements (user_id, achievement_code) ON DELETE CASCADE
);

COMMIT;
