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

W sprawdzonym środowisku katalog `/var/lib/postgresql/data` jest podłączony
do istniejącego anonimowego wolumenu Dockera. Restart kontenera zachowuje
ten wolumen. Zwykłe `docker compose up` przy odtwarzaniu istniejącego
kontenera również wykorzystuje jego dotychczasowy wolumen.

Wolumen `pg-data` zadeklarowany w Compose nie jest obecnie podłączony do bazy.
Przy wykonaniu `docker compose down` i późniejszym `up` anonimowy wolumen
nie zostanie automatycznie przypisany do nowego kontenera. Nie stosuj tej
sekwencji do ponownego uruchamiania obecnej bazy; do restartu użyj:

```cmd
docker compose restart db
```

Przed przejściem na nazwany wolumen trzeba wykonać kopię bazy i zaplanować
jej odtworzenie albo jawnie ponownie podłączyć istniejący wolumen.
Samo podpięcie nowego pustego `pg-data` uruchomi oddzielną bazę.
Na tym etapie zachowujemy dotychczasowe miejsce danych.

Aktualne podłączenie sprawdzisz poleceniem:

```cmd
docker inspect --format "{{json .Mounts}}" my-app-db-1
```
