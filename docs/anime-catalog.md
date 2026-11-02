# Katalog anime i wyszukiwarka

Katalog pilotażowy zawiera 200 anime. Wyszukiwarka `/anime` czyta wyłącznie
lokalny PostgreSQL. Pobieranie metadanych jest oddzielnym zadaniem CLI.
Źródłem próbki jest [Processed Top 15k Anime](https://huggingface.co/datasets/TheRealVigilante/Processed_Top_15k_Anime),
publiczny snapshot metadanych MyAnimeList przygotowany przez TheRealVigilante
na podstawie zbioru Quan Than z marca 2025. Karta autora deklaruje Apache 2.0.
Ten etap nie synchronizuje kont ani list MyAnimeList i nie podłącza Ollamy.

Publiczny serwer Jikan został [wyłączony 1 października 2026](https://github.com/jikan-me/jikan-rest/issues/613).
Importer nie używa go jako źródła. Opisy, oceny i liczby odcinków w próbce
odzwierciedlają stan snapshotu, a nie bieżące dane serwisu.

## Przygotowanie

Nowa baza wykonuje skrypty z `database` automatycznie. Na istniejącej bazie
z migracjami `01`–`04` uruchom raz z katalogu projektu w Windows CMD:

```cmd
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\05-anime-catalog.sql
```

Migracja tworzy `anime`, `genres` i `anime_genres`, zachowuje identyfikatory
sześciu dotychczasowych tytułów i wiąże watchlistę kluczem obcym z katalogiem.
Nie zmienia wpisów użytkowników. Nie uruchamiaj ponownie wykonanej migracji.

## Import próbki

```cmd
node scripts/download-anime.cjs --limit=200
docker compose exec php php scripts/import-anime.php --limit=200 --file=storage/imports/anime-source-200.json --dry-run --save=storage/imports/anime-200.json
docker compose exec php php scripts/import-anime.php --limit=200 --file=storage/imports/anime-200.json
```

Skrypt pobierający działa w Node.js na Windows; nie wymaga dodatkowych paczek.
Jest to przydatne również wtedy, gdy kontener PHP nie ma dostępu do internetu.
PHP może też pobrać próbkę bezpośrednio, jeśli sieć kontenera na to pozwala:

```cmd
docker compose exec php php scripts/import-anime.php --limit=200 --save=storage/imports/anime-200.json
```

Importer uwzględnia sześć dotychczasowych anime i uzupełnia katalog ocenianymi
tytułami do żądanego limitu. Pobiera tytuły i aliasy, opisy źródłowe,
gatunki, adresy okładek, liczbę odcinków, rok, typ, studio oraz ocenę.
Nieznane dane pozostają puste; interfejs pokazuje odpowiedni stan zastępczy.
Opisy z tego źródła mają oznaczenie języka angielskiego.

Import aktualizuje rekordy po ID dostawcy, zachowując własne ID aplikacji.
Ponowne uruchomienie nie powiela tych samych anime i nie usuwa wcześniejszych
pozycji ani wpisów watchlist. Zapis partii jest transakcyjny. Przy błędzie
pobierania nie zapisuje niekompletnej próbki.

Zapisany plik pozwala powtórzyć import bez dostępu do API:

```cmd
docker compose exec php php scripts/import-anime.php --limit=200 --file=storage/imports/anime-200.json
```

Katalog `storage/imports` jest ignorowany przez Git. Importer respektuje
ograniczenia żądań i ponawia przejściowe błędy. Zmiana limitu służy późniejszej
rozbudowie, po ocenie jakości próbki. Większy import nie jest wykonywany
podczas otwierania strony ani wyszukiwania.

## Działanie

- Tytuł jest wyszukiwany również w nazwach alternatywnych, bez rozróżniania
  wielkości liter. Znaki `%` i `_` są zwykłą treścią zapytania.
- Gatunek i tytuł działają wspólnie. Gatunki źródłowe mają lokalne
  etykiety; nazwy bez tłumaczenia zachowują oryginalną pisownię.
- Jedna strona zawiera 20 wyników. Paginacja zachowuje wybrane filtry.
- Szczegóły, pulpit i watchlista odczytują te same rekordy katalogu.
- Okładki są pobierane przez przeglądarkę ze źródłowego CDN. Przy błędzie
  pozostaje obraz zastępczy. Pliki okładek nie są kopiowane do bazy.
- Przy nieznanej liczbie odcinków zapis postępu nie zakłada zerowego limitu.

## Weryfikacja

```cmd
docker compose exec php php tests/anime-repository.test.php
docker compose exec php php tests/anime-import.test.php
node --test tests/anime-http.test.cjs
node --test tests/auth-http.test.cjs tests/profile-http.test.cjs tests/avatar-http.test.cjs tests/assets.test.cjs tests/theme.test.cjs
```

Test repozytorium działa na tabelach i sekwencjach tymczasowych z rollbackiem.
Test HTTP zakłada próbkę 200 pozycji i tworzy jedno konto testowe, które
pozostaje w lokalnej bazie. Sprawdź również układ przy 375 i 1280 px,
jasny i ciemny motyw oraz obsługę formularza klawiaturą.
