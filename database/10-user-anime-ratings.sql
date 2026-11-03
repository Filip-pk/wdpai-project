BEGIN;

CREATE TABLE public.user_anime_ratings (
    user_id BIGINT NOT NULL REFERENCES public.users (id) ON DELETE CASCADE,
    anime_id BIGINT NOT NULL REFERENCES public.anime (id) ON DELETE CASCADE,
    rating SMALLINT NOT NULL CHECK (rating BETWEEN 1 AND 10),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, anime_id)
);

CREATE INDEX user_anime_ratings_anime_idx ON public.user_anime_ratings (anime_id);

COMMIT;
