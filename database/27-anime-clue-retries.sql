BEGIN;

ALTER TABLE anime_clue_attempts
    ADD COLUMN wrong_answers JSONB NOT NULL DEFAULT '[]'::jsonb,
    ADD CONSTRAINT anime_clue_attempts_wrong_answers_check
        CHECK (jsonb_typeof(wrong_answers) = 'array' AND jsonb_array_length(wrong_answers) <= 15);

COMMIT;
