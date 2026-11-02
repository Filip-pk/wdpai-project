BEGIN;

CREATE INDEX anime_score_desc_idx
    ON public.anime (score DESC NULLS LAST, LOWER(title), id);

ANALYZE public.anime;

COMMIT;
