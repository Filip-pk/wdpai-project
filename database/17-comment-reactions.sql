BEGIN;

CREATE TABLE public.comment_reactions (
    user_id BIGINT NOT NULL REFERENCES public.users (id) ON DELETE CASCADE,
    comment_id BIGINT NOT NULL REFERENCES public.anime_comments (id) ON DELETE CASCADE,
    value SMALLINT NOT NULL CHECK (value IN (-1, 1)),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (user_id, comment_id)
);
CREATE INDEX comment_reactions_comment_value_idx ON public.comment_reactions (comment_id, value);

ALTER TABLE public.community_activity DROP CONSTRAINT community_activity_action_check;
ALTER TABLE public.community_activity ADD CONSTRAINT community_activity_action_check
    CHECK (action IN ('message', 'comment', 'report', 'reaction'));

COMMIT;
