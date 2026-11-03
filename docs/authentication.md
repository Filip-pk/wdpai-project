# Konta i sesje użytkowników

Rejestracja i logowanie korzystają z PostgreSQL przez `AuthController`
i `UserRepository`. Zależności pozostają bez zmian. Po utworzeniu konta
aplikacja wysyła [wiadomość powitalną przez Mailpit](welcome-email.md).

## Przygotowanie bazy

Nowa, pusta baza otrzymuje schemat automatycznie przy pierwszym uruchomieniu
PostgreSQL. Docker Compose montuje katalog `database` jako
`/docker-entrypoint-initdb.d` tylko do odczytu; PostgreSQL wykonuje skrypty
`01`–`11` w kolejności nazw.

Jeśli baza była już uruchomiona, ale nie ma jeszcze tabel aplikacji,
z katalogu projektu w Windows CMD wykonaj raz wszystkie jedenaście skryptów:

```cmd
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\01-create-projects.sql
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\02-create-users.sql
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\03-user-profiles.sql
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\04-user-avatars.sql
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\05-anime-catalog.sql
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\06-anime-catalog-sources.sql
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\07-anime-catalog-search.sql
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\08-anime-catalog-sort.sql
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\09-user-collections.sql
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\10-user-anime-ratings.sql
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\11-user-experience.sql
```

Skrypty tworzą projekty, konta, pola profilu, watchlisty i zdjęcia. Nie uruchamiaj
ponownie skryptów już zastosowanych do istniejącej bazy. Jeśli są w niej
tabele `users` i `projects`, ale brakuje pól profilu, wykonaj tylko skrypt
`03`, a następnie migrację zdjęć `04`, zgodnie z
[dokumentacją profili](user-profiles.md).

Migracja `05` dodaje katalog, `06` mapowania źródeł pełnego importu,
`07` indeks wyszukiwania, a `08` indeks sortowania według oceny. Dane
pobierz według [instrukcji katalogu anime](anime-catalog.md).
Migracja `09` dodaje [prywatne kolekcje](user-collections.md), a `10`
[prywatne oceny anime](user-ratings.md).
Migracja `11` dodaje [EXP, poziomy i ranking TOP 100](user-experience.md)
oraz uwzględnia dotychczasowe postępy na watchlistach.

Rejestracja i logowanie wymagają również kolumn `interests` i `theme`
z trzeciego skryptu oraz `avatar_version` z czwartego. Samo działające
połączenie z PostgreSQL lub status
`healthy` nie oznaczają, że schemat aplikacji został utworzony. Brak tabeli
`users` powoduje błąd `42P01` przy poprawnie wypełnionej rejestracji.

Zmiana katalogu inicjalizacji ani restart nie wykonują skryptów na już
zainicjalizowanej bazie. Brakujące migracje zastosuj powyższymi poleceniami,
zachowując obecny wolumen danych.

## Przepływ

| Żądanie | Zachowanie |
| --- | --- |
| `GET /register` | Formularz rejestracji z CSRF. |
| `POST /register` | Walidacja, `password_hash()`, zapis konta, powitanie SMTP i `303 /login?registered=1&mail=sent` lub `mail=failed`. |
| `GET /login` | Formularz logowania z CSRF. |
| `POST /login` | `password_verify()`, regeneracja sesji i tokenu CSRF, `303 /projects`. |
| `GET /projects`, `GET /projects/{id}` | Dostęp po zalogowaniu; gość otrzymuje `303 /login`. |
| `POST /logout` | Sprawdzenie CSRF, usunięcie sesji i ciasteczka, `303 /login`. |

Nieprawidłowy CSRF zwraca `403`, błędy rejestracji `422`, a błędne dane
logowania `401` z jednym ogólnym komunikatem. `GET /logout` nie wylogowuje.
Pola haseł pozostają puste po błędzie. Nazwa ma 1–120 znaków, e-mail do 255,
a hasło minimum 8 znaków i maksimum 72 bajty, bez NUL (limit bcrypt).
Unikalność e-maila egzekwuje również baza, z obsługą równoległej rejestracji.

Sesja przechowuje ID, nazwę, e-mail, czas zalogowania i token CSRF, bez hasła
ani jego hasha. Ciasteczko ma `HttpOnly`, `SameSite=Lax`, czas życia `0`
i `Secure` przy HTTPS. Odpowiedzi aplikacji mają `Cache-Control: no-store`.
Katalog i rozmowy demonstracyjne pozostają dostępne. Profil i watchlista
wymagają zalogowania; dane i ustawienia są przypisane do konta. Szczegóły
opisuje [dokumentacja profili](user-profiles.md).

To uwierzytelnianie i kontrola dostępu do strony dla zalogowanych. Projekty
są wspólne; model nie zawiera właściciela ani ról. Uprawnienia do edycji
konkretnych zasobów, reset hasła i ograniczanie prób logowania to
osobne etapy. Laboratorium nie podłącza AI.

## Weryfikacja

```cmd
docker compose exec php php tests/user-repository.test.php
docker compose exec php php tests/projects-application.test.php
node --test tests/auth-http.test.cjs tests/projects-http.test.cjs tests/assets.test.cjs tests/theme.test.cjs tests/mail-http.test.cjs
```

Testy repozytorium używają tabeli i sekwencji tymczasowej oraz rollbacku.
Każdy zestaw HTTP tworzy jedno losowe konto w lokalnej bazie; uruchamiaj je
na środowisku deweloperskim. Testy HTTP projektów wymagają trzech przykładów
z istniejącego `scripts/seed-projects.php`.
