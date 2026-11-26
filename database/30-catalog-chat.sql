BEGIN;

ALTER TABLE public.anime
    ADD COLUMN IF NOT EXISTS airing_status VARCHAR(12) NULL
        CHECK (airing_status IN ('finished', 'airing', 'upcoming')),
    ADD COLUMN IF NOT EXISTS chat_synopsis TSVECTOR GENERATED ALWAYS AS (
        CASE WHEN description_language = 'en'
            THEN to_tsvector('english'::regconfig, LEFT(description, 3500))
            ELSE to_tsvector('simple'::regconfig, LEFT(description, 3500))
        END
    ) STORED;

CREATE INDEX IF NOT EXISTS anime_chat_synopsis_idx ON public.anime USING GIN (chat_synopsis);

COMMIT;
