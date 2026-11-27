# AniVibe

AniVibe to aplikacja dla fanów anime: pozwala odkrywać tytuły, prowadzić
własną watchlistę, śledzić postępy oglądania i korzystać z rekomendacji AI.

## Funkcjonalności

- Katalog anime ze szczegółami, opisami, okładkami, gatunkami i ocenami źródłowymi.
- Wyszukiwanie po tytule i nazwach alternatywnych, gatunku, formacie oraz
  zakresach liczby odcinków, oceny i roku premiery.
- Sortowanie oceną źródłową, tytułem, odcinkami i rokiem w obu kierunkach;
  domyślnie najlepiej oceniane anime. Paginacja zachowuje wybrane kryteria.
- Czat katalogowy z lokalną Ollamą. Pamięta bieżące wymagania, wyszukuje
  w PostgreSQL i wyświetla karty anime wraz z uzasadnieniem.
- Rejestracja, logowanie i własna watchlista: dodawanie i usuwanie anime,
  statusy „Planowane”, „Oglądam”, „Ukończone”, „Wstrzymane” i „Porzucone”
  oraz zapis obejrzanych odcinków.
- Import własnej listy MyAnimeList z XML/XML.GZ: podgląd i zatwierdzenie,
  zachowanie istniejących danych lub jawna aktualizacja oraz raport i EXP.
- Edycja profilu, zainteresowania i zdjęcie profilowe JPG/PNG do 4 MB.
- Gablotka profilu: do 3 ulubionych anime i 3 zdobytych odznak z animowanym
  neonowym obramowaniem, wyszukiwarką i zapisem na koncie.
- Publiczne profile otwierane przez nazwę lub avatar: poziom, EXP, statystyki
  i watchlista do przeglądania. Przycisk „Napisz wiadomość” otwiera prywatną rozmowę.
- Zakładka „Wiadomości”: własne rozmowy i wysyłanie tekstu, jedna rozmowa na parę
  użytkowników oraz dostęp wyłącznie dla uczestników. Lista i historia mają paginację.
- Licznik nieprzeczytanych wiadomości, blokowanie i odblokowywanie użytkowników,
  zgłaszanie komentarzy i wiadomości oraz panel moderatora do rozpatrywania zgłoszeń.
- Ograniczenia częstotliwości wysyłania wiadomości, dodawania i edycji komentarzy
  oraz zgłoszeń, z ochroną przed szybkim powtarzaniem tej samej treści.
- Komentarze pod anime: publiczna lista z paginacją, dodawanie po zalogowaniu,
  edycja i usuwanie własnych wpisów oraz przejście do profilu autora.
- Odpowiedzi na komentarze w osobnych wątkach, z paginacją i zachowaniem
  cudzych odpowiedzi po usunięciu komentarza otwierającego dyskusję.
  Zakładka „Powiadomienia” informuje autora komentarza o nowych odpowiedziach.
- Like i dislike komentarzy oraz odpowiedzi: publiczne liczniki i jeden
  własny głos na wpis, z możliwością zmiany lub cofnięcia.
- Prywatne kolekcje anime: własne nazwy, opisy i miniatury; jeden tytuł może
  należeć do kilku kolekcji niezależnie od statusu i postępu na watchliście.
- Własne oceny anime w skali 1–10: zapis, zmiana i usuwanie, z podglądem
  na stronie anime, w profilu i na watchliście, niezależnie od ocen katalogowych.
- Oceny społeczności AniVibe: publiczna średnia i liczba ocen na kartach
  anime oraz w szczegółach. Lista głosujących i ich indywidualne oceny
  nie są publikowane; własną ocenę widzi właściciel konta.
- Publiczne rankingi anime: najlepiej oceniane przez społeczność AniVibe
  (domyślnie od 5 ocen) oraz najczęściej oglądane, z filtrami gatunku i roku
  oraz paginacją. Ranking użytkowników i EXP pozostaje osobną funkcją.
- Poziomy i EXP: 5 punktów za odcinek oraz 100 za ukończony tytuł;
  rosnące progi poziomów i aktualny ranking TOP 100 użytkowników.
- Jasny, ciemny i systemowy motyw oraz układ dostosowany do telefonu i komputera.
- Wiadomość powitalna po rejestracji, przechwytywana lokalnie przez Mailpit.
- Panel administratora: diagnostyka usług, ostatnie błędy, zarządzanie
  rolą moderatora, podgląd braków katalogu i historia operacji.
- Zadanie cykliczne (cron) co godzinę usuwa przeterminowane dane bezpieczeństwa
  i nieużywane pliki zdjęć, zapisując wynik w logu.

Watchlistę MyAnimeList można zaimportować ręcznie z pliku. Katalog korzysta
z importowanych ocen.

## Technologie

PHP 8.3, Composer, PostgreSQL 16, HTML, CSS, JavaScript, Nginx, Docker Compose i Ollama.
Node.js służy do pobrania danych katalogu. pgAdmin i Mailpit ułatwiają lokalną
pracę z bazą i pocztą.

## Uruchomienie od zera — Windows

Poniższe polecenia wykonuj w **Windows CMD (Wiersz polecenia)**, w katalogu
projektu. Instrukcja dotyczy nowej instalacji na lokalnym komputerze.

### 1. Zainstaluj wymagane narzędzia

1. Zainstaluj [Docker Desktop](https://docs.docker.com/desktop/setup/install/windows-install/)
   i skonfiguruj kontenery Linux z WSL 2 zgodnie z instrukcją instalatora.
   Uruchom Docker Desktop i zaczekaj na gotowość silnika.
2. Zainstaluj [Node.js w wersji LTS](https://nodejs.org/en/download).
   Jest potrzebny do pobrania pełnego katalogu anime.
3. Po instalacji otwórz nowe okno CMD i sprawdź:

```cmd
docker compose version
node --version
```

PHP, Composer, PostgreSQL i Nginx uruchomią się w kontenerach. Nie trzeba
instalować ich osobno ani wykonywać `npm install`. Przy pierwszym uruchomieniu
potrzebne jest połączenie z internetem do pobrania obrazów i danych anime;
okładki są ładowane ze źródłowych serwerów również podczas przeglądania.

### 2. Pobierz ZIP z GitHuba

1. Na stronie tego repozytorium kliknij **Code → Download ZIP**.
2. Rozpakuj archiwum, np. do `C:\projekty\anivibe`.
3. Otwórz CMD i przejdź do rozpakowanego katalogu:

```cmd
cd /d "C:\projekty\anivibe"
```

Dostosuj ścieżkę do swojego komputera. W tym katalogu muszą znajdować się
`docker-compose.yaml`, `composer.json` i `.env.example`. Git nie jest wymagany
przy pobieraniu projektu jako ZIP.

### 3. Przygotuj konfigurację lokalną

Utwórz własny plik `.env` na podstawie dołączonego szablonu:

```cmd
copy .env.example .env
notepad .env
```

Zmień `POSTGRES_PASSWORD` i `PGADMIN_DEFAULT_PASSWORD` na własne hasła,
a `PGADMIN_DEFAULT_EMAIL` na adres do logowania do pgAdmin. Zapisz plik
pod nazwą `.env`, bez końcówki `.txt`. Pozostałe wartości możesz pozostawić
zgodne z szablonem.

Hasła te dotyczą lokalnej bazy i pgAdmin, a nie kont w AniVibe.
Plik `.env` jest ignorowany przez Git i nie trafia do obrazów Dockera.

| Zmienna | Domyślnie | Znaczenie |
| --- | --- | --- |
| `APP_PORT`, `APP_HTTPS_PORT` | `8080`, `8443` | Porty aplikacji na komputerze; HTTP przekierowuje na HTTPS. |
| `POSTGRES_DB`, `POSTGRES_USER`, `POSTGRES_PASSWORD` | — | Baza i jej konto; PHP łączy się z usługą `db` po nazwie. |
| `POSTGRES_PORT` | `5433` | Port bazy na komputerze (np. dla pgAdmin zainstalowanego w Windows). |
| `PGADMIN_DEFAULT_EMAIL`, `PGADMIN_DEFAULT_PASSWORD`, `PGADMIN_PORT`, `PGADMIN_CONTAINER_NAME` | `5050` | Logowanie i port pgAdmin. |
| `MAILPIT_PORT` | `8025` | Panel Mailpit. |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_FROM`, `MAIL_FROM_NAME` | `mailpit`, `1025` | Serwer SMTP i nadawca wiadomości aplikacji. |
| `OLLAMA_URL`, `OLLAMA_CHAT_MODEL` | `http://ollama:11434`, `qwen3:4b` | Usługa i model czatu `/chat`. |

### 4. Zbuduj i uruchom usługi

```cmd
docker compose up -d --build
docker compose ps
docker compose exec php php scripts/check-postgres.php
```

Pierwsza budowa pobiera obrazy i przygotowuje PHP, więc może potrwać kilka
minut. Usługa `db` powinna być `healthy`, a pozostałe uruchomione. Kontener
`php` przy starcie sam instaluje autoloader Composera i przygotowuje katalogi
logów i zdjęć, więc nie trzeba wykonywać `composer install`. Usługa `cron`
uruchamia [zadanie czyszczące](docs/maintenance.md). Polecenie uruchamia też
Ollamę; samą aplikację bez czatu AI uruchomisz poleceniem
`docker compose up -d --build db php server mailpit cron`.

Przy pierwszym starcie pustej bazy PostgreSQL automatycznie wykonuje skrypty
z katalogu `database/`: tworzy schemat, zapisuje **6 początkowych anime**
i [konta demonstracyjne](#konta-demonstracyjne) z przykładową aktywnością.
Nie trzeba ręcznie wykonywać migracji na nowej bazie.

Ollamę uruchom zgodnie z [instrukcją czatu katalogowego](docs/catalog-chat.md),
jeśli chcesz korzystać z rekomendacji na stronie `/chat`.

### 5. Zaimportuj pełny katalog anime

**Po samym uruchomieniu aplikacji dostępnych jest tylko 6 anime.
Pełny katalog, około 38 tysięcy pozycji, wymaga poniższego importu.**

```cmd
node scripts/download-anime.cjs --all
docker compose exec php php scripts/import-anime.php --all
```

Pierwsze polecenie pobiera Anime Offline Database i dodatkowe metadane
z Hugging Face. Samodzielnie tworzy `storage/imports/anime-full`, pliki
źródłowe i `manifest.json`. Poczekaj na komunikat o zapisaniu manifestu,
a następnie wykonaj drugie polecenie, które zapisuje katalog w PostgreSQL.

Po imporcie zobaczysz komunikat z liczbą dodanych i zaktualizowanych anime
oraz łączną liczbą pozycji. Sprawdzony zestaw danych dawał **38 379 anime**;
liczba zależy od aktualnego wydania źródeł. Nie każda pozycja ma opis, ocenę
lub okładkę, a część opisów jest po angielsku.

Import wykonujesz raz dla danej bazy, a później ewentualnie przy aktualizacji
katalogu. Nie odbywa się automatycznie przy starcie strony. Pliki w
`storage/imports` nie trafiają do repozytorium i po imporcie nie są potrzebne
do zwykłego działania aplikacji. Konta, watchlisty i katalog są przechowywane
w PostgreSQL, a pliki zdjęć profilowych w `storage/uploads` (baza zapisuje
tylko ich ścieżki).

Źródła, licencje, dodatkowe opcje i aktualizacje opisuje
[dokumentacja katalogu anime](docs/anime-catalog.md).

### 6. Otwórz aplikację i utwórz konto

| Usługa | Domyślny adres | Zastosowanie |
| --- | --- | --- |
| AniVibe | [localhost:8443](https://localhost:8443) | Strona aplikacji (HTTP z portu 8080 przekierowuje na HTTPS) |
| Katalog | [localhost:8443/anime](https://localhost:8443/anime) | Wyszukiwanie anime i liczba pozycji po imporcie |
| Mailpit | [localhost:8025](http://localhost:8025) | Podgląd lokalnych wiadomości powitalnych |
| pgAdmin | [localhost:5050](http://localhost:5050) | Opcjonalny podgląd bazy danych |

Aplikacja działa tylko przez HTTPS. Przy pierwszym starcie kontener `server`
tworzy w `docker/nginx/certs` (katalog ignorowany przez Git) lokalny urząd
certyfikacji `anivibe-ca.crt` i podpisany nim certyfikat dla `localhost`.
Aby przeglądarka nie pokazywała ostrzeżenia „Połączenie nie jest prywatne”,
raz dodaj ten urząd do zaufanych na swoim koncie Windows i uruchom
przeglądarkę ponownie:

```cmd
certutil -user -addstore Root docker\nginx\certs\anivibe-ca.crt
```

Windows poprosi o potwierdzenie. Urząd może poświadczać wyłącznie
`localhost` i adresy `127.x.x.x`, więc nie wpływa na inne strony. Nie
udostępniaj pliku `anivibe-ca.key`. Usunięcie zaufania:
`certutil -user -delstore Root "AniVibe local development CA"`.
Własny certyfikat (np. z `mkcert`) zapisz jako `localhost.crt` i
`localhost.key` w tym katalogu, a następnie wykonaj `docker compose restart server`.

Zaloguj się [kontem demonstracyjnym](#konta-demonstracyjne) albo wejdź
w **Zarejestruj się** i utwórz własne konto. Następnie otwórz **Odkrywaj**,
wybierz anime i dodaj je do watchlisty. W **Ulubionych** możesz zmienić status
i postęp oglądania, a w **Profilu** ustawić dane i zdjęcie. Katalog można
przeglądać również bez logowania.

Mailpit przechwytuje pocztę lokalnie — wiadomość powitalna będzie widoczna
w jego panelu, zamiast na zewnętrznej skrzynce e-mail.

Jeżeli chcesz obejrzeć bazę w pgAdmin, zaloguj się wartościami
`PGADMIN_DEFAULT_EMAIL` i `PGADMIN_DEFAULT_PASSWORD` z własnego `.env`.
Dodaj serwer o dowolnej nazwie, z hostem **`db`**, portem **`5432`** oraz
nazwą bazy, użytkownikiem i hasłem z `POSTGRES_DB`, `POSTGRES_USER`
i `POSTGRES_PASSWORD`. Program zainstalowany bezpośrednio na Windows łączy
się natomiast przez `localhost:5433` przy domyślnej konfiguracji.

## Konta demonstracyjne

Nowa baza zawiera cztery konta z migracji
[33-demo-data.sql](database/33-demo-data.sql). Służą do lokalnego
uruchomienia i prezentacji; na publicznym wdrożeniu zmień ich hasła
albo je usuń.

| Rola | E-mail | Hasło | Co pokazać |
| --- | --- | --- | --- |
| Użytkownik | `user@demo.anivibe.test` | `AniVibe-User-2026` | Watchlista z EXP, oceny, kolekcja, komentarze, powiadomienie o odpowiedzi, rozmowy |
| Moderator | `moderator@demo.anivibe.test` | `AniVibe-Mod-2026` | Panel `/moderation` z otwartym zgłoszeniem komentarza |
| Administrator | `admin@demo.anivibe.test` | `AniVibe-Admin-2026` | Panel `/admin`: diagnostyka, role, zgłoszenie wiadomości |
| Spamer (zwykły użytkownik) | `spammer@demo.anivibe.test` | `AniVibe-Spam-2026` | Autor zgłoszonego spoilera i spamu |

Na bazie utworzonej przed tą migracją dodasz konta i przykładowe dane
poleceniem poniżej. Skrypt można wykonać ponownie: nie tworzy duplikatów
i nie zmienia istniejących kont.

```cmd
docker compose exec -T db sh -c "psql -v ON_ERROR_STOP=1 -U $POSTGRES_USER -d $POSTGRES_DB -f /docker-entrypoint-initdb.d/33-demo-data.sql"
```

## Eksport i import bazy

[database/export/anivibe.sql](database/export/anivibe.sql) to zrzut
`pg_dump` schematu, 6 początkowych anime i kont demonstracyjnych. Powstaje
z tymczasowej bazy zbudowanej wyłącznie z plików `database/*.sql`, więc nie
zawiera lokalnych kont ani zaimportowanego katalogu. Nowa instalacja w Dockerze
go nie potrzebuje, bo wykonuje migracje sama.

Import do nowej, pustej bazy (nazwę `anivibe_import` możesz zmienić):

```cmd
docker compose exec -T db sh -c "createdb -U $POSTGRES_USER anivibe_import"
docker compose exec -T db sh -c "psql -v ON_ERROR_STOP=1 -U $POSTGRES_USER -d anivibe_import" < database\export\anivibe.sql
```

Po zmianie plików w `database/` wygeneruj eksport ponownie i sprawdź,
czy jest zgodny z migracjami:

```cmd
docker compose exec -T db sh /docker-entrypoint-initdb.d/tools/export-demo.sh > database\export\anivibe.sql
docker compose exec -T php php tests/database-export.test.php
docker compose exec -T php php tests/demo-data.test.php
```

## Testy

Wszystkie testy uruchamia jedno polecenie (CMD, w katalogu projektu, przy
działających kontenerach):

```cmd
node tests/run-all.cjs
```

Najpierw wykonują się testy PHP w kontenerze `php`, potem testy Node, plik po
pliku. Polecenie samo wskazuje Node lokalny urząd certyfikacji z
`docker/nginx/certs`. Wynik kończy się linią „Wszystkie testy przeszły.” albo
listą plików z błędami; kod wyjścia jest różny od zera przy błędzie.

| Opcja | Co uruchamia |
| --- | --- |
| `--php` | Tylko testy PHP |
| `--node` | Tylko testy Node |
| `--no-browser` | Bez testów `*-responsive`, które potrzebują Chrome lub Edge |

Rodzaje testów:

- Jednostkowe, bez bazy, np. `shared-helpers`, `transaction`, `mal-parser`,
  `recommendation-ranker`, `avatar-storage` oraz `password-form` dla JavaScriptu.
  Każdy plik sprawdza przypadki poprawne i błędne.
- Repozytoriów i zadań, np. `cleanup` i `admin-repository`: działają na
  tabelach tymczasowych w transakcji wycofywanej na końcu, więc nie zmieniają
  danych.
- HTTP (`*-http.test.cjs`): przechodzą przez Nginx i PHP tak jak przeglądarka,
  tworzą własne konta testowe i w większości je usuwają.
- Przeglądarkowe (`*-responsive.test.cjs`): układ na telefonie, tablecie
  i komputerze, obsługa klawiaturą oraz działanie bez JavaScriptu.

Pojedynczy plik uruchomisz bezpośrednio:

```cmd
docker compose exec -T php php tests/cleanup.test.php
set NODE_EXTRA_CA_CERTS=%cd%\docker\nginx\certs\anivibe-ca.crt
node --test tests/auth-http.test.cjs
```

## Kolejne uruchomienia i zatrzymanie

Uruchom Docker Desktop, otwórz CMD w katalogu projektu i włącz istniejące
kontenery:

```cmd
docker compose start db php server mailpit cron pgadmin-wdpai
```

Aby zatrzymać środowisko:

```cmd
docker compose stop
```

Nie trzeba ponownie pobierać katalogu ani wykonywać importu. Do zwykłego
restartu możesz też użyć `docker compose restart`.

**Baza zapisuje dane w nazwanym wolumenie `pg-data`.** Jego nazwa w Dockerze
zależy od nazwy projektu Compose, np. `my-app_pg-data`. Do codziennej pracy
używaj `stop` i `start`. Zwykłe `docker compose down` i ponowne `up` również
zachowują bazę, jeśli używasz tej samej nazwy projektu.

Zdjęcia profilowe leżą w katalogu projektu, w `storage/uploads` (ignorowanym
przez Git), więc kopia zapasowa obejmuje bazę i ten katalog.

`docker compose down -v` usuwa wolumen i dane bazy. Polecenie
`docker volume prune -a` może usunąć nieużywany nazwany wolumen.
Przed usuwaniem wolumenów wykonaj kopię zapasową.

Jeśli aktualizujesz starszą instalację z anonimowym wolumenem, najpierw
wykonaj kopię starej bazy i przenieś ją do nazwanego wolumenu. Samo podłączenie
pustego `pg-data` nie przenosi kont, watchlist ani katalogu. Szczegóły są w
[opisie przechowywania danych](docs/postgresql-environment.md#przechowywanie-danych).

## Najczęstsze problemy

| Problem | Co sprawdzić |
| --- | --- |
| Nie znaleziono `docker` lub `node` | Dokończ instalację narzędzia i otwórz nowe okno CMD. |
| Docker nie może połączyć się z silnikiem | Uruchom Docker Desktop i zaczekaj na jego gotowość; projekt wymaga kontenerów Linux. |
| Brak `vendor/autoload.php` | Kontener `php` instaluje go przy starcie; sprawdź `docker compose logs php` i uruchom go ponownie poleceniem `docker compose restart php`. |
| Katalog pokazuje tylko 6 anime | Na nowej instalacji wykonaj import z kroku 5. Jeśli katalog był już zaimportowany, najpierw sprawdź podłączenie właściwego wolumenu i odzyskaj poprzednią bazę; sam import nie przywróci kont ani watchlist. |
| Brak `manifest.json` | Najpierw zakończ pobieranie poleceniem Node.js; sprawdź dostęp do GitHuba i Hugging Face. |
| Port jest zajęty | Zmień odpowiedni port w `.env`: `APP_PORT`, `POSTGRES_PORT`, `PGADMIN_PORT` lub `MAILPIT_PORT`, a potem ponów polecenie `up` z kroku 4. Adres w przeglądarce musi używać nowego portu. |
| Brakuje tabel w istniejącej bazie | Skrypty inicjalizacyjne działają tylko na pustej bazie. Zastosuj brakujące migracje według [instrukcji bazy](docs/authentication.md#przygotowanie-bazy). |

Logi i kontrolę połączenia z bazą sprawdzisz poleceniami:

```cmd
docker compose logs --tail=50 server php db
docker compose exec php php scripts/check-postgres.php
```

## Więcej informacji

- [Katalog anime, import i wyszukiwanie](docs/anime-catalog.md)
- [Autorzy źródeł i licencje danych](docs/anime-catalog.md#źródła-i-licencje)
- [Konta i logowanie](docs/authentication.md)
- [Profile, zdjęcia i watchlisty](docs/user-profiles.md)
- [Gablotka profilu i migracja bazy](docs/profile-showcase.md)
- [Prywatne kolekcje anime i migracja istniejącej bazy](docs/user-collections.md)
- [Własne oceny anime i migracja istniejącej bazy](docs/user-ratings.md)
- [Oceny społeczności AniVibe](docs/community-ratings.md)
- [Rankingi anime: najlepiej oceniane i najczęściej oglądane](docs/anime-rankings.md)
- [Komentarze pod anime i migracja istniejącej bazy](docs/anime-comments.md)
- [Odpowiedzi na komentarze i powiadomienia o odpowiedziach](docs/comment-replies.md)
- [Like i dislike komentarzy oraz odpowiedzi](docs/comment-reactions.md)
- [Prywatne wiadomości i migracja istniejącej bazy](docs/private-messages.md)
- [Powiadomienia, blokady, zgłoszenia i rola moderatora](docs/community-moderation.md)
- [Poziomy, EXP i ranking TOP 100](docs/user-experience.md)
- [Quiz dnia: 200 tłumaczonych pytań, serie i rankingi](docs/daily-quiz.md)
- [Osiągnięcia, odznaki i postęp użytkownika](docs/achievements.md)
- [Rozpoznaj anime: 200 tytułów, wskazówki i punktacja](docs/anime-clues.md)
- [Import listy MyAnimeList z XML/XML.GZ](docs/mal-watchlist-import.md)
- [Wiadomości powitalne i Mailpit](docs/welcome-email.md)
- [Zadanie cykliczne (cron): czyszczenie danych](docs/maintenance.md)
- [Panel administratora, uprawnienia i diagnostyka](docs/admin-panel.md)
- [Środowisko PostgreSQL i przechowywanie danych](docs/postgresql-environment.md)

