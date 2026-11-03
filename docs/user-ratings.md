# Prywatne oceny anime

Zalogowane konto może wystawić każdemu anime własną ocenę od 1 do 10,
zmienić ją albo usunąć. Oceny są prywatne i zapisane w PostgreSQL.
Na stronie anime i watchliście są oznaczone jako „Twoja ocena”, oddzielnie
od importowanej oceny katalogowej.

Wystawienie oceny nie wymaga dodawania anime do watchlisty ani kolekcji.
Zapis, zmiana i usunięcie oceny nie zmieniają statusu, postępu oglądania,
przypisań do kolekcji ani źródłowej oceny anime. Usunięcie anime z watchlisty
lub kolekcji zachowuje własną ocenę. Usunięcie konta lub anime z katalogu
usuwa powiązane oceny przez klucze obce.

## Migracja istniejącej bazy

Po wcześniejszych migracjach `01`–`09` wykonaj raz w Windows CMD:

```cmd
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\10-user-anime-ratings.sql
```

Migracja tworzy tabelę `user_anime_ratings`, bez zmieniania istniejących
ocen źródłowych, watchlist ani kolekcji. Nowa instalacja wykonuje ją
automatycznie wraz z pozostałymi skryptami inicjalizacji bazy.

## Zapis i prywatność

- `POST /anime/{id}/rating` zapisuje lub aktualizuje pole `rating`.
- `POST /anime/{id}/rating/remove` usuwa własną ocenę danego anime.
- Obie operacje wymagają zalogowania i poprawnego `csrfToken`.
- Właściciel pochodzi z sesji, a nie z pól `userId` ani `user_id` żądania.
- Wartość musi być liczbą całkowitą od 1 do 10; nieprawidłowy formularz
  nie zastępuje wcześniejszej oceny.
- Klucz `(user_id, anime_id)` pozwala zapisać jedną ocenę na konto i tytuł.
- Parametryzowany SQL filtruje właściciela przy każdym odczycie i zapisie.

Brak oceny jest oddzielnym stanem; nie oznacza oceny zero. Funkcja nie
publikuje średnich ocen użytkowników ani publicznych recenzji.

## Sprawdzenie

```cmd
docker compose exec -T php php tests/rating-repository.test.php
node --test tests/ratings-http.test.cjs
```

Testy korzystają wyłącznie z tymczasowych tabel i sekwencji w transakcji.
Sprawdzają prywatność dwóch kont, nadpisywanie i usuwanie oceny, zakres
1–10, klucze obce, odczyt zbiorczy oraz niezależność ocen źródłowych,
watchlist i kolekcji.

W przeglądarce sprawdź formularz na stronie anime i własną ocenę na
watchliście, także po ponownym zalogowaniu, w jasnym i ciemnym motywie
przy szerokościach 375 i 1280 px. Drugie konto powinno widzieć wyłącznie
własne oceny.
