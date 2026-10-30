BEGIN;

-- Kopia ma własną sekwencję identity. Testy nie zmieniają public.projects.
CREATE TEMP TABLE projects_schema_check (
    LIKE public.projects INCLUDING ALL
) ON COMMIT DROP;

DO $check$
DECLARE
    project projects_schema_check%ROWTYPE;
    nextId BIGINT;
BEGIN
    INSERT INTO projects_schema_check (title)
    VALUES ('Test wartości domyślnych')
    RETURNING * INTO project;

    IF project.id IS NULL
        OR project.status IS DISTINCT FROM 'planned'
        OR project.description IS NOT NULL
        OR project.created_at IS NULL
        OR project.updated_at IS NULL THEN
        RAISE EXCEPTION 'Niepoprawne wartości domyślne projektu.';
    END IF;

    INSERT INTO projects_schema_check (title, description, status)
    VALUES ('Test aktywnego projektu', 'Opis projektu', 'active')
    RETURNING id INTO nextId;

    IF nextId IS NULL OR nextId = project.id THEN
        RAISE EXCEPTION 'Identyfikatory projektów nie są generowane poprawnie.';
    END IF;

    INSERT INTO projects_schema_check (title, status)
    VALUES (repeat('ą', 150), 'completed');

    RAISE NOTICE '[OK] Identity, wartości domyślne, opcjonalny opis i trzy statusy.';
    RAISE NOTICE '[OK] Tytuł o długości 150 znaków jest dozwolony.';

    BEGIN
        INSERT INTO projects_schema_check (title, status)
        VALUES ('Test niepoprawnego statusu', 'unknown');
        RAISE EXCEPTION 'Baza zaakceptowała niedozwolony status.';
    EXCEPTION
        WHEN check_violation THEN
            RAISE NOTICE '[OK] Niedozwolony status jest odrzucany.';
    END;

    BEGIN
        INSERT INTO projects_schema_check (title)
        VALUES (NULL);
        RAISE EXCEPTION 'Baza zaakceptowała tytuł NULL.';
    EXCEPTION
        WHEN not_null_violation THEN
            RAISE NOTICE '[OK] Tytuł NULL jest odrzucany.';
    END;

    BEGIN
        INSERT INTO projects_schema_check (title, status)
        VALUES ('Test statusu NULL', NULL);
        RAISE EXCEPTION 'Baza zaakceptowała status NULL.';
    EXCEPTION
        WHEN not_null_violation THEN
            RAISE NOTICE '[OK] Status NULL jest odrzucany.';
    END;

    BEGIN
        INSERT INTO projects_schema_check (title)
        VALUES (repeat('a', 151));
        RAISE EXCEPTION 'Baza zaakceptowała tytuł dłuższy niż 150 znaków.';
    EXCEPTION
        WHEN string_data_right_truncation THEN
            RAISE NOTICE '[OK] Tytuł dłuższy niż 150 znaków jest odrzucany.';
    END;

    BEGIN
        INSERT INTO projects_schema_check (title, created_at)
        VALUES ('Test daty utworzenia NULL', NULL);
        RAISE EXCEPTION 'Baza zaakceptowała created_at NULL.';
    EXCEPTION
        WHEN not_null_violation THEN
            RAISE NOTICE '[OK] Data utworzenia NULL jest odrzucana.';
    END;

    BEGIN
        INSERT INTO projects_schema_check (title, updated_at)
        VALUES ('Test daty zmiany NULL', NULL);
        RAISE EXCEPTION 'Baza zaakceptowała updated_at NULL.';
    EXCEPTION
        WHEN not_null_violation THEN
            RAISE NOTICE '[OK] Data zmiany NULL jest odrzucana.';
    END;

    BEGIN
        INSERT INTO projects_schema_check (id, title)
        OVERRIDING SYSTEM VALUE
        VALUES (project.id, 'Test powtórzonego identyfikatora');
        RAISE EXCEPTION 'Baza zaakceptowała powtórzony identyfikator.';
    EXCEPTION
        WHEN unique_violation THEN
            RAISE NOTICE '[OK] Powtórzony identyfikator jest odrzucany.';
    END;
END;
$check$;

ROLLBACK;
