BEGIN;

-- Od migracji 34 zdjęcia profilowe są plikami, a baza przechowuje tylko ich ścieżki.
-- Stara kolumna z obrazem jest usuwana dopiero wtedy, gdy każde zdjęcie zapisane w niej
-- ma już plik; w przeciwnym razie migracja przerywa się i nic nie zmienia.
DO $$
DECLARE
    pending BOOLEAN;
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns
               WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'avatar_data') THEN
        EXECUTE 'SELECT EXISTS (SELECT 1 FROM public.users WHERE avatar_data IS NOT NULL AND avatar_path IS NULL)'
            INTO pending;
        IF pending THEN
            RAISE EXCEPTION 'Najpierw przenieś zdjęcia do plików: docker compose exec -u www-data php php scripts/migrate-avatars.php';
        END IF;
    END IF;
END
$$;

ALTER TABLE public.users DROP COLUMN IF EXISTS avatar_data;

COMMIT;
