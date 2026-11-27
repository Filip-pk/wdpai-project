BEGIN;

-- Zdjęcia profilowe są zapisywane jako pliki w storage/uploads/avatars,
-- a baza przechowuje tylko ich względną ścieżkę. Dawne zdjęcia z kolumny
-- avatar_data przenosi skrypt scripts/migrate-avatars.php (docs/user-profiles.md).
ALTER TABLE public.users
    ADD COLUMN IF NOT EXISTS avatar_path VARCHAR(64) NULL
        CONSTRAINT users_avatar_path_check CHECK (avatar_path ~ '^avatars/[a-f0-9]{32}\.png$');

COMMIT;
