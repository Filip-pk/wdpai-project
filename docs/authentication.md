# Konta i sesje użytkowników

Rejestracja i logowanie korzystają z PostgreSQL przez `AuthController`
i `UserRepository`. CSS i zależności pozostają bez zmian.

## Przygotowanie bazy

W działającym środowisku, z katalogu projektu w Windows CMD, wykonaj raz:

```cmd
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\02-create-users.sql
```

Skrypt tworzy `users` oraz unikalny indeks `LOWER(email)`. Nie uruchamiaj go
ponownie na istniejącej tabeli. Projekty wymagają również wcześniejszego
skryptu `database\01-create-projects.sql`, jeśli baza jest pusta.

## Przepływ

| Żądanie | Zachowanie |
| --- | --- |
| `GET /register` | Formularz rejestracji z CSRF. |
| `POST /register` | Walidacja, `password_hash()`, zapis konta i `303 /login?registered=1`. |
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
konkretnych zasobów, reset hasła, OAuth i ograniczanie prób logowania to
osobne etapy. Laboratorium nie podłącza AI.

## Weryfikacja

```cmd
docker compose exec php php tests/user-repository.test.php
docker compose exec php php tests/projects-application.test.php
node --test tests/auth-http.test.cjs tests/projects-http.test.cjs tests/assets.test.cjs tests/theme.test.cjs
```

Testy repozytorium używają tabeli i sekwencji tymczasowej oraz rollbacku.
Każdy zestaw HTTP tworzy jedno losowe konto w lokalnej bazie; uruchamiaj je
na środowisku deweloperskim. Testy HTTP projektów wymagają trzech przykładów
z istniejącego `scripts/seed-projects.php`.
