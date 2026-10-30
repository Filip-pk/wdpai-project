BEGIN;

CREATE TABLE public.projects (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    description TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'planned',
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT projects_status_check
        CHECK (status IN ('planned', 'active', 'completed'))
);

CREATE INDEX projects_status_idx ON public.projects (status);
CREATE INDEX projects_created_at_idx ON public.projects (created_at DESC);

COMMIT;
