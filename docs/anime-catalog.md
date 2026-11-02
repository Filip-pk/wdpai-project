# Katalog anime i wyszukiwarka

Wyszukiwarka `/anime` czyta lokalny PostgreSQL. Pełny katalog łączy
[Anime Offline Database — cedya77](https://github.com/cedya77/anime-offline-database),
kontynuację [manami-project](https://github.com/manami-project/anime-offline-database),
ze [snapshotem opisów moviebrain01](https://huggingface.co/datasets/moviebrain01/anime-dataset-2025).
Zachowuje również dane istniejącej próbki
[TheRealVigilante / Quan Than](https://huggingface.co/datasets/TheRealVigilante/Processed_Top_15k_Anime).
Pobieranie i import są oddzielnymi zadaniami CLI. Nie synchronizują kont
ani list MyAnimeList i nie podłączają Ollamy.

Pełny katalog obejmuje wszystkie pozycje pobranych źródeł po połączeniu
identyfikatorów. Nie gwarantuje obecności wszystkich anime na świecie.
Brak opisu, liczby odcinków lub grafiki ma czytelny stan zastępczy.

Publiczny serwer Jikan został [wyłączony 1 października 2026](https://github.com/jikan-me/jikan-rest/issues/613).
Importer nie używa go jako źródła. Dane AOD odpowiadają wskazanemu wydaniu;
opisy i oceny pochodzą ze snapshotów i mogą być starsze.

## Przygotowanie

Nowa baza wykonuje skrypty z `database` automatycznie. Na istniejącej bazie
z migracjami `01`–`04` uruchom raz z katalogu projektu w Windows CMD:

```cmd
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\05-anime-catalog.sql
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\06-anime-catalog-sources.sql
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\07-anime-catalog-search.sql
```

Migracja tworzy `anime`, `genres` i `anime_genres`, zachowuje identyfikatory
sześciu dotychczasowych tytułów i wiąże watchlistę kluczem obcym z katalogiem.
Nie zmienia wpisów użytkowników. Migracja `06` dodaje mapowania identyfikatorów
pozostałych dostawców. Migracja `07` dodaje indeks wyszukiwania. Jeśli `05`
jest już wykonana, zastosuj tylko brakujące `06` i `07`.
Nie uruchamiaj ponownie wykonanych migracji.

## Pełny import

Polecenia dla Windows CMD:

```cmd
node scripts/download-anime.cjs --all
docker compose exec php php scripts/import-anime.php --all --dry-run
docker compose exec php php scripts/import-anime.php --all
```

Downloader pobiera bieżące wydanie AOD JSONL i dwa pliki CSV metadanych.
Używa natywnego Zstandard w Node.js, jeśli jest dostępny; w przeciwnym razie
pobiera AOD bez kompresji. Nie wymaga dodatkowych paczek. Pliki trafiają do
`storage/imports/anime-full`; manifest zapisuje wydanie, źródła i SHA256.
Opcjonalne `--reuse-cache` wykorzystuje posiadane pliki źródłowe.

PHP sprawdza sumy kontrolne i czyta AOD rekordami. Łączy tytuły i aktualne
fakty AOD z opisami, ocenami i gatunkami HF po identyfikatorach MAL.
Rekordy bez MAL otrzymują stabilne mapowania AniList, AniDB, Kitsu lub
innych dostawców. Pozycje HF nieobecne w AOD są dopisywane.

Import jest transakcyjny. Sprzeczne mapowania przerywają zapis zamiast
automatycznie scalać istniejące anime. Brakujące pola nie wymazują starych
opisów i znanych faktów; aliasy i gatunki są łączone. Istniejące ID oraz
watchlisty pozostają zachowane. Błąd dowolnego rekordu wycofuje całą partię.

Własny manifest można wskazać przez
`--all --file=storage/imports/anime-full/manifest.json`. Pełny import
nie ma limitu 5000 pozycji trybu próbki i nie łączy się z internetem.
Ponowny import tych samych źródeł aktualizuje istniejące rekordy.

Weryfikowane wydanie AOD `2026-44` z 1 listopada 2026 zawiera 38 337 rekordów.
Po połączeniu z 42 dodatkowymi pozycjami HF katalog ma 38 379 anime:
23 547 z opisem, 38 138 z adresem okładki i 7417 bez MAL ID. Zachowano
wszystkie 200 identyfikatorów pierwotnej próbki i istniejące watchlisty.
To wynik konkretnego zestawu plików; następne wydania mogą zmienić liczniki.

AOD jest udostępniane na ODbL 1.0 i DbCL 1.0 z atrybucją obu projektów.
Karta moviebrain01 deklaruje CC-BY-4.0. Źródła są wskazane również
w szczegółach anime; oryginalna próbka zachowuje atrybucję autorów.

## Opcjonalny import próbki

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
ograniczenia żądań i ponawia przejściowe błędy. Zmiana limitu dotyczy próbki;
cały katalog obsługuje `--all`. Większy import nie jest wykonywany
podczas otwierania strony ani wyszukiwania.

## Działanie

- Tytuł jest wyszukiwany również w nazwach alternatywnych, bez rozróżniania
  wielkości liter. Znaki `%` i `_` są zwykłą treścią zapytania.
- Dokładny tytuł i dokładny alias mają pierwszeństwo przed dopasowaniami
  częściowymi. Od trzech znaków wyszukiwanie korzysta z indeksu GIN
  `pg_trgm`, dostarczanego przez obecny obraz PostgreSQL. Krótsze zapytania
  zachowują działanie bez filtra indeksowego. Tekst indeksu jest obliczany
  automatycznie z tytułu i aliasów, również po kolejnych importach.
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
docker compose exec php php tests/full-anime-import.test.php
node --test tests/anime-http.test.cjs
node --test tests/auth-http.test.cjs tests/profile-http.test.cjs tests/avatar-http.test.cjs tests/assets.test.cjs tests/theme.test.cjs
```

Test repozytorium działa na tabelach i sekwencjach tymczasowych z rollbackiem.
Test HTTP odczytuje aktualny rozmiar katalogu i tworzy jedno konto testowe,
które pozostaje w bazie. Opcjonalne `ANIVIBE_EXPECTED_CATALOG_COUNT` sprawdza
dokładny wynik importu. Sprawdź również układ przy 375 i 1280 px,
jasny i ciemny motyw oraz obsługę formularza klawiaturą.
