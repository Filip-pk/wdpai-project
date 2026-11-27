# Konta i sesje użytkowników

Rejestracja i logowanie korzystają z PostgreSQL przez `AuthController`
i `UserRepository`. Zależności pozostają bez zmian. Po utworzeniu konta
aplikacja wysyła [wiadomość powitalną przez Mailpit](welcome-email.md).

## Przygotowanie bazy

Nowa, pusta baza otrzymuje schemat automatycznie przy pierwszym uruchomieniu
PostgreSQL. Docker Compose montuje katalog `database` jako
`/docker-entrypoint-initdb.d` tylko do odczytu; PostgreSQL wykonuje skrypty
`01`–`17` w kolejności nazw.

Jeśli baza była już uruchomiona, ale nie ma jeszcze tabel aplikacji,
z katalogu projektu w Windows CMD wykonaj raz wszystkie siedemnaście skryptów:

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
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\12-watchlist-import.sql
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\13-anime-comments.sql
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\14-private-messages.sql
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\15-community-moderation.sql
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\16-comment-replies.sql
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\17-comment-reactions.sql
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
Migracja `12` dodaje statusy „Wstrzymane” i „Porzucone” potrzebne do
[importu listy MyAnimeList](mal-watchlist-import.md).
Migracja `13` dodaje [komentarze](anime-comments.md), `14`
[prywatne wiadomości](private-messages.md), a `15`
[powiadomienia, blokady, zgłoszenia i rolę moderatora](community-moderation.md).
Migracja `16` dodaje [odpowiedzi na komentarze i powiadomienia autora](comment-replies.md).
Migracja `17` dodaje [like i dislike komentarzy oraz odpowiedzi](comment-reactions.md).

Rejestracja i logowanie wymagają również kolumn `interests` i `theme`
z trzeciego skryptu oraz `avatar_version` z czwartego. Samo działające
połączenie z PostgreSQL lub status
`healthy` nie oznaczają, że schemat aplikacji został utworzony. Brak tabeli
`users` powoduje błąd `42P01` przy poprawnie wypełnionej rejestracji.

Zmiana katalogu inicjalizacji ani restart nie wykonują skryptów na już
zainicjalizowanej bazie. Brakujące migracje zastosuj powyższymi poleceniami,
zachowując obecny wolumen danych.

## HTTPS

Nginx przyjmuje logowanie i rejestrację wyłącznie przez HTTPS. Port HTTP
(`APP_PORT`, domyślnie `8080`) odpowiada `301` na ten sam adres pod
`https://` i portem `APP_HTTPS_PORT` (domyślnie `8443`). Przy pierwszym
starcie kontener tworzy w `docker/nginx/certs` lokalny urząd certyfikacji
`anivibe-ca.crt` (10 lat, ograniczony `nameConstraints` do `localhost`
i `127.0.0.0/8`) oraz podpisany nim `localhost.crt` dla `localhost`
i `127.0.0.1` (397 dni, odnawiany przy starcie przed wygaśnięciem). Klucze nie
trafiają do repozytorium. Przeglądarka ufa stronie po jednorazowym dodaniu
urzędu do zaufanych w Windows:
`certutil -user -addstore Root docker\nginx\certs\anivibe-ca.crt`.
`fastcgi_params` przekazuje PHP `HTTPS=on`, dlatego ciasteczko sesji
dostaje flagę `Secure`. HSTS nie jest ustawiany, bo na `localhost`
wymusiłby HTTPS również w Mailpit i pgAdmin.

Po zmianie konfiguracji przebuduj serwer:

```cmd
docker compose up -d --build server
```

## Błędy PHP

Obraz PHP ustawia `display_errors=Off`, `display_startup_errors=Off`,
`log_errors=On` i `expose_php=Off`, a `public/index.php` dodatkowo wyłącza
wyświetlanie błędów na wypadek uruchomienia bez Dockera. Odwiedzający widzi
tylko ogólną stronę `500` z identyfikatorem błędu; szczegóły trafiają do logu
kontenera i dziennika administratora. Nagłówek `X-Powered-By` nie zdradza
wersji PHP. Zmiana wymaga przebudowy: `docker compose up -d --build php`.

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

Akcje `login` i `register` mają atrybut `#[AllowedMethods('GET', 'POST')]`,
a `logout` `#[AllowedMethods('POST')]` (`src/Attribute/AllowedMethods.php`).
Router sprawdza go obok tabeli tras: GET tylko wyświetla formularz, dane są
czytane wyłącznie z POST. Gdy adres istnieje, ale metoda nie pasuje,
aplikacja odpowiada `405` z nagłówkiem `Allow` (np. `GET /logout` →
`Allow: POST`); nieznany adres nadal daje `404`.
Pola haseł pozostają puste po błędzie. Nazwa ma 1–120 znaków, e-mail do 255,
a hasło minimum 8 znaków i maksimum 72 bajty, bez NUL (limit bcrypt).
Nowe hasło (rejestracja i zmiana w ustawieniach) musi też zawierać literę
i cyfrę oraz nie może być adresem e-mail ani jego częścią przed `@`.
Logowanie sprawdza tylko limity długości, więc starsze hasła nadal działają.
Reguły są w `AccountSecurityService`.
Unikalność e-maila egzekwuje również baza, z obsługą równoległej rejestracji.

Rejestracja nie zdradza, czy adres ma już konto. Dla zajętego adresu
odpowiedź jest taka sama jak dla nowego (`303 /login?registered=1&mail=…`,
neutralny komunikat), hasło jest haszowane w obu przypadkach, a zapisane
konto i jego hasło pozostają bez zmian. Właściciel dostaje wtedy wiadomość
o próbie rejestracji w języku swojego konta.

Sesja przechowuje ID, nazwę, e-mail, czas zalogowania i token CSRF, bez hasła
ani jego hasha. Hasła przesłane w formularzach (także w profilu) nie trafiają
do danych przekazywanych widokom. Ciasteczko ma `HttpOnly`, `SameSite=Lax`, czas życia `0`
i `Secure` przy HTTPS. Odpowiedzi aplikacji mają `Cache-Control: no-store`.
Katalog i rozmowy demonstracyjne pozostają dostępne. Profil i watchlista
wymagają zalogowania; dane i ustawienia są przypisane do konta. Szczegóły
opisuje [dokumentacja profili](user-profiles.md).

Projekty są wspólne; ich model nie zawiera właściciela. Profil, watchlisty,
kolekcje, oceny i komentarze sprawdzają właściciela, a prywatne rozmowy
uczestników. Rola moderatora daje dostęp do zgłoszonych fragmentów treści
oraz ich ukrywania, bez dostępu do pozostałej historii cudzych rozmów.
Reset hasła to osobny etap. Laboratorium nie podłącza AI.

## Limit prób logowania i audyt

Po 5 nieudanych próbach dla tego samego e-maila z jednego adresu IP albo
po 100 nieudanych próbach z jednego adresu IP w ciągu 15 minut `POST /login`
zwraca `429` z nagłówkiem `Retry-After`. Limit działa przed sprawdzeniem
hasła, więc poprawne hasło w czasie blokady też nie przechodzi, a komunikat
jest taki sam dla istniejących i nieistniejących kont. Udane logowanie
czyści licznik tego konta z danego adresu.

Tabela `login_attempts` (migracja `32`) przechowuje tylko skróty SHA-256
e-maila i adresu IP; rekordy starsze niż doba są usuwane przy kolejnych
zapisach. Każda nieudana i zablokowana próba trafia do dziennika
administratora (moduł `auth`) jako 16-znakowe skróty konta i adresu,
bez hasła, e-maila i IP. Istniejącą bazę uzupełnij poleceniem:

```cmd
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\32-login-attempts.sql
```

## Weryfikacja

Testy HTTP łączą się z `https://127.0.0.1:8443` (zmiana przez
`ANIVIBE_TEST_URL`), więc Node musi ufać lokalnemu urzędowi certyfikacji. Ustaw
zmienną raz w danym oknie CMD:

```cmd
set NODE_EXTRA_CA_CERTS=%cd%\docker\nginx\certs\anivibe-ca.crt
docker compose exec php php tests/user-repository.test.php
docker compose exec php php tests/login-throttle.test.php
docker compose exec php php tests/routing-methods.test.php
docker compose exec php php tests/projects-application.test.php
node --test tests/auth-http.test.cjs tests/projects-http.test.cjs tests/assets.test.cjs tests/theme.test.cjs tests/mail-http.test.cjs
```

Testy repozytorium używają tabeli i sekwencji tymczasowej oraz rollbacku.
Każdy zestaw HTTP tworzy jedno losowe konto w lokalnej bazie; uruchamiaj je
na środowisku deweloperskim. Testy HTTP projektów wymagają trzech przykładów
z istniejącego `scripts/seed-projects.php`.
