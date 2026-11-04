BEGIN;

ALTER TABLE public.user_watchlist
    DROP CONSTRAINT user_watchlist_status_check,
    ADD CONSTRAINT user_watchlist_status_check
        CHECK (status IN ('planned', 'watching', 'completed', 'on_hold', 'dropped'));

COMMIT;
