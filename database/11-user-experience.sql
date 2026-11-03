BEGIN;

CREATE TABLE public.user_anime_experience (
    user_id BIGINT NOT NULL REFERENCES public.users (id) ON DELETE CASCADE,
    anime_id BIGINT NOT NULL REFERENCES public.anime (id) ON DELETE CASCADE,
    watched INTEGER NOT NULL DEFAULT 0 CHECK (watched >= 0),
    completed BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, anime_id)
);

CREATE INDEX user_anime_experience_anime_idx ON public.user_anime_experience (anime_id);

-- Dotychczasowe zapisane postępy otrzymują EXP na tych samych zasadach co nowe.
INSERT INTO public.user_anime_experience (user_id, anime_id, watched, completed)
SELECT user_id, anime_id, watched, status = 'completed' FROM public.user_watchlist;

COMMIT;
