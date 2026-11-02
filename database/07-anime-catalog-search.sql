BEGIN;

CREATE EXTENSION IF NOT EXISTS pg_trgm;

CREATE FUNCTION public.anime_search_text(title TEXT, aliases JSONB)
RETURNS TEXT
LANGUAGE SQL
IMMUTABLE
PARALLEL SAFE
AS $function$
    SELECT COALESCE(title, '') || E'\n'
        || COALESCE(string_agg(value, E'\n' ORDER BY position), '')
    FROM jsonb_array_elements_text(COALESCE(aliases, '[]'::JSONB))
        WITH ORDINALITY AS items(value, position);
$function$;

ALTER TABLE public.anime
    ADD COLUMN search_text TEXT GENERATED ALWAYS AS (
        public.anime_search_text(title, alternative_titles)
    ) STORED;

CREATE INDEX anime_search_text_trgm_idx
    ON public.anime USING GIN (search_text gin_trgm_ops);

ANALYZE public.anime;

COMMIT;
