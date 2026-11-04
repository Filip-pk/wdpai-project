# AniVibe

AniVibe to aplikacja dla fanów anime: pozwala odkrywać tytuły, prowadzić
własną watchlistę i śledzić postępy oglądania. Projekt rozwijamy o rekomendacje
AI i rozmowy z postaciami.

## Funkcjonalności

- Katalog anime ze szczegółami, opisami, okładkami, gatunkami i ocenami źródłowymi.
- Wyszukiwanie po tytule i nazwach alternatywnych, gatunku, formacie oraz
  zakresach liczby odcinków, oceny i roku premiery.
- Sortowanie oceną, tytułem, odcinkami i rokiem w obu kierunkach;
  domyślnie najlepiej oceniane anime. Paginacja zachowuje wybrane kryteria.
- Rejestracja, logowanie i własna watchlista: dodawanie i usuwanie anime,
  statusy „Planowane”, „Oglądam”, „Ukończone”, „Wstrzymane” i „Porzucone”
  oraz zapis obejrzanych odcinków.
- Import własnej listy MyAnimeList z XML/XML.GZ: podgląd i zatwierdzenie,
  zachowanie istniejących danych lub jawna aktualizacja oraz raport i EXP.
- Edycja profilu, zainteresowania i zdjęcie profilowe JPG/PNG do 4 MB.
- Publiczne profile otwierane przez nazwę lub avatar: poziom, EXP, statystyki
  i watchlista do przeglądania. Przycisk „Napisz wiadomość” otwiera prywatną rozmowę.
- Zakładka „Wiadomości”: własne rozmowy i wysyłanie tekstu, jedna rozmowa na parę
  użytkowników oraz dostęp wyłącznie dla uczestników. Lista i historia mają paginację.
- Komentarze pod anime: publiczna lista z paginacją, dodawanie po zalogowaniu,
  edycja i usuwanie własnych wpisów oraz przejście do profilu autora.
- Prywatne kolekcje anime: własne nazwy, opisy i miniatury; jeden tytuł może
  należeć do kilku kolekcji niezależnie od statusu i postępu na watchliście.
- Prywatne oceny anime w skali 1–10: zapis, zmiana i usuwanie, z podglądem
  na stronie anime, w profilu i na watchliście, niezależnie od ocen katalogowych.
- Poziomy i EXP: 5 punktów za odcinek oraz 100 za ukończony tytuł;
  rosnące progi poziomów i aktualny ranking TOP 100 użytkowników.
- Jasny, ciemny i systemowy motyw oraz układ dostosowany do telefonu i komputera.
- Wiadomość powitalna po rejestracji, przechwytywana lokalnie przez Mailpit.

Asystent AI, rozmowy z postaciami, personalizowane rekomendacje
i automatyczna synchronizacja listy MyAnimeList są obecnie demonstracyjne
lub wyłączone. Katalog korzysta z importowanych ocen; Ollama nie jest jeszcze
podłączona do aplikacji.

## Technologie

PHP 8.3, Composer, PostgreSQL 16, HTML, CSS, JavaScript, Nginx i Docker Compose.
Node.js służy do pobrania danych katalogu. pgAdmin i Mailpit ułatwiają lokalną
pracę z bazą i pocztą; konfiguracja zawiera też usługę Ollama na przyszłość.

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

Hasła te dotyczą lokalnej bazy i pgAdmin. Konto w AniVibe utworzysz później
przez formularz rejestracji. Plik `.env` jest ignorowany przez Git.

### 4. Zbuduj i uruchom usługi

```cmd
docker compose up -d --build db php server mailpit pgadmin-wdpai
docker compose exec php composer install --no-interaction --prefer-dist --optimize-autoloader
docker compose ps
docker compose exec php php scripts/check-postgres.php
```

Poczekaj na zakończenie każdego polecenia. Pierwsza budowa pobiera obrazy
i przygotowuje PHP, więc może potrwać kilka minut. Usługa `db` powinna być
`healthy`, a pozostałe wymienione usługi uruchomione.

**Nie pomijaj polecenia `composer install`**: tworzy ono lokalny katalog
`vendor`, którego nie ma w ZIP-ie. Wykonaj je również wtedy, gdy Composer
uruchomił się już podczas budowania obrazu.

Przy pierwszym starcie pustej bazy PostgreSQL automatycznie wykonuje skrypty
`database/01`–`14`: tworzy tabele oraz **6 początkowych anime**. Nie trzeba
ręcznie wykonywać migracji na nowej bazie. Konta użytkowników nie są
dołączane do repozytorium.

Usługę Ollama pomijamy w tym uruchomieniu, ponieważ funkcje AI są obecnie
demonstracyjne i nie wymagają modelu językowego.

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
do zwykłego działania aplikacji. Konta, watchlisty, zdjęcia profilowe oraz
katalog są przechowywane w PostgreSQL.

Źródła, licencje, dodatkowe opcje i aktualizacje opisuje
[dokumentacja katalogu anime](docs/anime-catalog.md).

### 6. Otwórz aplikację i utwórz konto

| Usługa | Domyślny adres | Zastosowanie |
| --- | --- | --- |
| AniVibe | [localhost:8080](http://localhost:8080) | Strona aplikacji |
| Katalog | [localhost:8080/anime](http://localhost:8080/anime) | Wyszukiwanie anime i liczba pozycji po imporcie |
| Mailpit | [localhost:8025](http://localhost:8025) | Podgląd lokalnych wiadomości powitalnych |
| pgAdmin | [localhost:5050](http://localhost:5050) | Opcjonalny podgląd bazy danych |

Wejdź w **Zarejestruj się**, utwórz konto i zaloguj się. Następnie otwórz
**Odkrywaj**, wybierz anime i dodaj je do watchlisty. W **Ulubionych** możesz
zmienić status i postęp oglądania, a w **Profilu** ustawić dane i zdjęcie.
Katalog można przeglądać również bez logowania.

Mailpit przechwytuje pocztę lokalnie — wiadomość powitalna będzie widoczna
w jego panelu, zamiast na zewnętrznej skrzynce e-mail.

Jeżeli chcesz obejrzeć bazę w pgAdmin, zaloguj się wartościami
`PGADMIN_DEFAULT_EMAIL` i `PGADMIN_DEFAULT_PASSWORD` z własnego `.env`.
Dodaj serwer o dowolnej nazwie, z hostem **`db`**, portem **`5432`** oraz
nazwą bazy, użytkownikiem i hasłem z `POSTGRES_DB`, `POSTGRES_USER`
i `POSTGRES_PASSWORD`. Program zainstalowany bezpośrednio na Windows łączy
się natomiast przez `localhost:5433` przy domyślnej konfiguracji.

## Kolejne uruchomienia i zatrzymanie

Uruchom Docker Desktop, otwórz CMD w katalogu projektu i włącz istniejące
kontenery:

```cmd
docker compose start db php server mailpit pgadmin-wdpai
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
| Brak `vendor/autoload.php` | Wykonaj polecenie `composer install` z kroku 4. |
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
- [Prywatne kolekcje anime i migracja istniejącej bazy](docs/user-collections.md)
- [Prywatne oceny anime i migracja istniejącej bazy](docs/user-ratings.md)
- [Komentarze pod anime i migracja istniejącej bazy](docs/anime-comments.md)
- [Prywatne wiadomości i migracja istniejącej bazy](docs/private-messages.md)
- [Poziomy, EXP i ranking TOP 100](docs/user-experience.md)
- [Import listy MyAnimeList z XML/XML.GZ](docs/mal-watchlist-import.md)
- [Wiadomości powitalne i Mailpit](docs/welcome-email.md)
- [Środowisko PostgreSQL i przechowywanie danych](docs/postgresql-environment.md)

