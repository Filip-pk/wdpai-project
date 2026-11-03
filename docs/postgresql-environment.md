# PostgreSQL — etap 1

Środowisko korzysta z PostgreSQL 16 oraz PHP 8.3 ze sterownikiem `pdo_pgsql`.
Kontener PHP łączy się z usługą `db` na porcie `5432`. Port `POSTGRES_PORT`
użyty do publikacji bazy na Windows jest niezależny od portu wewnątrz sieci Dockera.

Docker Compose przekazuje ustawienia `POSTGRES_DB`, `POSTGRES_USER`
i `POSTGRES_PASSWORD` do obu kontenerów. Kontener PHP otrzymuje także
`POSTGRES_HOST=db` oraz `POSTGRES_PORT=5432`. Nie wpisuj danych logowania
do kodu ani do tej dokumentacji.

Usługa `db` ma kontrolę gotowości przez `pg_isready`. PHP jest uruchamiane
po uzyskaniu przez bazę stanu `healthy`.

## Schemat aplikacji

Przy pierwszym uruchomieniu pustego wolumenu PostgreSQL wykonuje skrypty
z katalogu `database`, zamontowanego w `/docker-entrypoint-initdb.d` tylko
do odczytu. Kolejność `01` → `02` → `03` → `04` → `05` → `06` → `07` → `08`
tworzy projekty, konta, watchlisty, zdjęcia, katalog anime, mapowania jego
źródeł oraz indeksy wyszukiwania i sortowania według oceny.

Istniejący wolumen nie jest ponownie inicjalizowany. Jeśli połączenie działa,
ale brakuje tabel, zastosuj brakujące skrypty według
[instrukcji przygotowania bazy](authentication.md#przygotowanie-bazy).
`pg_isready` i `scripts/check-postgres.php` sprawdzają dostępność połączenia,
nie kompletność schematu aplikacji.

## Sprawdzenie środowiska

Polecenia uruchom z katalogu projektu w Windows CMD:

```cmd
docker compose up -d --no-build db php
docker compose ps db php
docker compose exec php php -m | findstr /I "PDO pdo_pgsql"
docker compose exec php php scripts/check-postgres.php
```

Skrypt diagnostyczny sprawdza sterownik, kompletność konfiguracji, DNS,
uwierzytelnienie PDO i zapytanie `SELECT 1`. Nie odczytuje pliku `.env`,
nie wypisuje danych logowania i nie zmienia danych w bazie. Zwraca kod `0`
przy powodzeniu albo `1` przy błędzie.

## Przechowywanie danych

Usługa `db` montuje nazwany wolumen `pg-data` w `/var/lib/postgresql/data`.
Docker Compose dodaje do jego nazwy nazwę projektu, np. `my-app_pg-data`.
W tym wolumenie znajdują się konta, watchlisty, zdjęcia profilowe i katalog anime.

Do codziennego zatrzymywania i wznawiania środowiska używaj `docker compose stop`
i `docker compose start`. Do restartu samej bazy służy:

```cmd
docker compose restart db
```

Zwykłe `docker compose down` usuwa kontenery i sieć, ale pozostawia nazwany
wolumen. Kolejne `up` z tą samą nazwą projektu korzysta z zachowanej bazy.
Zmiana nazwy katalogu projektu, opcji `-p` lub `COMPOSE_PROJECT_NAME` może
spowodować użycie innego wolumenu, dlatego zachowuj tę samą nazwę projektu.

`docker compose down -v` usuwa również zadeklarowane wolumeny i zapisane
w nich dane. `docker volume prune -a` może usunąć nieużywane nazwane wolumeny,
np. po wykonaniu `down`. Przed takimi operacjami wykonaj kopię zapasową bazy.

### Aktualizacja starszej instalacji

Starsza konfiguracja korzystała z anonimowego wolumenu PostgreSQL.
Przed uruchomieniem konfiguracji z nazwanym `pg-data` wykonaj kopię starej
bazy i przenieś lub odtwórz dane w nazwanym wolumenie. Nie usuwaj starego
wolumenu, dopóki nie potwierdzisz obecności katalogu, kont, watchlist i zdjęć.

Samo podłączenie pustego `pg-data` nie przenosi poprzednich danych: inicjalizuje
oddzielną bazę z sześcioma anime bez okładek. Ponowny import anime nie odzyskuje
kont ani watchlist. Jeśli po aktualizacji widzisz tylko sześć początkowych
tytułów, najpierw sprawdź podłączenie wolumenu i możliwość odzyskania starej bazy.

Nazwę kontenera bazy i aktualne podłączenie sprawdzisz poleceniami:

```cmd
docker compose ps db
docker inspect --format "{{json .Mounts}}" my-app-db-1
```

W drugim poleceniu zastąp `my-app-db-1` nazwą z kolumny `NAME` pierwszego
polecenia. W montowaniach powinien być widoczny nazwany wolumen projektu
z punktem docelowym `/var/lib/postgresql/data`.
