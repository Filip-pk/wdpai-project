# Połączenie z PostgreSQL — etap 3

`config.php` zwraca konfigurację odczytaną ze zmiennych środowiskowych.
Nie zawiera danych logowania i nie odczytuje pliku `.env`. Zmienne są już
przekazywane kontenerowi PHP przez Docker Compose:

| Zmienna | Znaczenie |
| --- | --- |
| `POSTGRES_HOST` | Host bazy; w obecnej sieci Dockera `db`. |
| `POSTGRES_PORT` | Port wewnętrzny PostgreSQL; obecnie `5432`. |
| `POSTGRES_DB` | Nazwa istniejącej bazy. |
| `POSTGRES_USER` | Użytkownik bazy. |
| `POSTGRES_PASSWORD` | Hasło użytkownika bazy. |

`App\Database` w konstruktorze wczytuje konfigurację i zapamiętuje parametry.
Wymaga kompletu niepustych wartości, portu od 1 do 65535 oraz hosta i nazwy
bazy bez średników i znaków sterujących.

Metoda `connect()` tworzy i zwraca połączenie PDO. Ustawia:

- sterownik `pgsql`, limit połączenia 5 sekund i `sslmode=prefer`;
- wyjątki przy błędach: `PDO::ERRMODE_EXCEPTION`;
- domyślne wyniki jako tablice asocjacyjne: `PDO::FETCH_ASSOC`;
- natywne prepared statements: `PDO::ATTR_EMULATE_PREPARES => false`.

Istniejące mapowanie Composera `App\` → `src/` obsługuje tę klasę.
Przykład użycia po załadowaniu `vendor/autoload.php`:

```php
use App\Database;

$connection = (new Database())->connect();
```

Niepełna lub błędna konfiguracja powoduje `RuntimeException` z ogólnym
komunikatem. Błąd sterownika PDO jest opakowywany w `RuntimeException`
o treści „Nie udało się połączyć z bazą danych.”. Poprzedni wyjątek pozostaje
dostępny dla diagnostyki serwerowej; nie należy wyświetlać jego treści ani
śladu wywołań w HTML. Skrypt diagnostyczny wypisuje wyłącznie stałe komunikaty.

## Weryfikacja w Windows CMD

W katalogu projektu uruchom:

```cmd
docker compose exec php php scripts/check-postgres.php
docker compose exec php php tests/database.test.php
```

Pierwszy skrypt korzysta z `App\Database` i zachowuje pięć komunikatów `[OK]`
z [etapu 1](postgresql-environment.md). Sprawdza sterownik, konfigurację, DNS,
połączenie i `SELECT 1`.

Testy sprawdzają połączenie, ustawienia PDO, zapytanie parametryzowane,
wielokrotne tworzenie obiektów oraz odrzucanie niepoprawnej konfiguracji
i bezpieczny komunikat przy braku połączenia. Wykonują tylko zapytania
odczytowe. Zmiany zmiennych środowiskowych w testach dotyczą wyłącznie
procesu testowego i są przywracane po każdym przypadku.
