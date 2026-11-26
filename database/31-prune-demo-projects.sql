BEGIN;

DELETE FROM public.projects
WHERE title = 'AniVibe — rozmowy z postaciami'
  AND description = 'Projekt ćwiczeniowy: opracowanie rozmów z wirtualnymi postaciami.'
  AND status = 'active';

COMMIT;
