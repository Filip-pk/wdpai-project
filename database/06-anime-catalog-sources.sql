BEGIN;

CREATE TABLE public.anime_external_ids (
    provider VARCHAR(32) NOT NULL CHECK (provider ~ '^[a-z0-9][a-z0-9_-]*$'),
    external_id VARCHAR(255) NOT NULL CHECK (external_id <> '' AND external_id = BTRIM(external_id)),
    anime_id BIGINT NOT NULL REFERENCES public.anime (id) ON DELETE CASCADE,
    PRIMARY KEY (provider, external_id)
);

CREATE INDEX anime_external_ids_anime_idx ON public.anime_external_ids (anime_id);

INSERT INTO public.anime_external_ids (provider, external_id, anime_id)
SELECT 'mal', mal_id::TEXT, id
FROM public.anime
WHERE mal_id IS NOT NULL;

COMMIT;
