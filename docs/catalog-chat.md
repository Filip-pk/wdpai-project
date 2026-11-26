# Czat katalogowy

Po zalogowaniu otwórz **Czat** albo formularz rekomendacji na pulpicie. Możesz
napisać „Chcę harem, ponad 20 odcinków, w stylu podobnym do No Game No Life”,
a potem „Jednak bez haremu, bardziej zależy mi na grach i strategii”.
Rozpoznane wymagania widać obok rozmowy. „Pokaż inne” zachowuje te wymagania
i pomija już pokazane wpisy. „Nowa rozmowa” czyści stan w bieżącej sesji.

## Uruchomienie w Windows CMD

Uruchom podstawowe usługi zgodnie z [README](../README.md). Dla istniejącej
bazy zastosuj migrację. Jeśli masz lokalny manifest pełnego katalogu,
możesz też uzupełnić statusy emisji na podstawie wcześniej pobranych danych.

```cmd
docker compose exec php php scripts/migrate-catalog-chat.php
docker compose exec php php scripts/import-anime.php --all --airing-status-only
```

Na Docker Desktop dla Windows możesz użyć karty NVIDIA z backendem WSL 2.
[Konfiguracja GPU w Docker Desktop](https://docs.docker.com/desktop/features/gpu/).

```cmd
docker compose -f docker-compose.yaml -f docker-compose.gpu.yaml up -d ollama
docker compose exec ollama ollama pull qwen3:4b
docker compose exec php php scripts/benchmark-catalog-chat.php
docker compose exec ollama ollama ps
```

W konfiguracji CPU uruchom Ollamę poleceniem `docker compose up -d ollama`.
Nazwa modelu jest konfigurowana przez `OLLAMA_CHAT_MODEL`, a adres przez
`OLLAMA_URL`. Domyślnie PHP łączy się z usługą Ollama wewnątrz sieci Compose.
Port 11434 jest dostępny na hoście pod adresem `127.0.0.1`.

W lokalnym pomiarze na RTX 5050 Laptop GPU model Qwen3 4B pracował w 100% na GPU.
Rozgrzana interpretacja przykładowych wiadomości zajęła około 0,44–1,12 s.
Pierwsza interpretacja po ponownym uruchomieniu Ollamy trwała 43,2 s,
w tym 23,8 s na ładowanie modelu. Pomiar
interpretacji nie obejmuje zapytań PostgreSQL i dodatkowego rankingu fabuły.
W teście HTTP po rozgrzaniu pierwsza odpowiedź z kartami zajęła około 1,3 s,
doprecyzowanie z rankingiem fabuły 3,8 s, a „Pokaż inne” 0,5 s.

## Jak działa dobór

- Ollama zwraca zweryfikowaną w PHP zmianę bieżących wymagań. PostgreSQL stosuje
  ścisłe filtry tagów, liczby odcinków, roku, oceny źródłowej, formatu i statusu
  emisji. „Ponad 20” daje minimum 21 dla jednego wpisu lub sezonu. Brak liczby
  odcinków lub statusu wyklucza wpis, jeśli obowiązuje dany filtr.
- Podobieństwo korzysta ze wspólnych tagów i indeksu tekstowego opisów.
  PostgreSQL aktualizuje indeks wraz ze zmianą opisu. Indeks embeddingów
  pozostaje etapem docelowym.
- Przy prośbie o wątek fabularny, nastrój lub cechę postaci drugi przebieg może
  podnieść w rankingu pozycje z fragmentami opisów wskazanymi przez Ollamę.
  PHP sprawdza identyfikatory i cytaty na danych kandydatów. Gdy brakuje
  potwierdzenia lub drugi przebieg się nie powiedzie, czat pokazuje wyniki
  spełniające filtry i zaznacza niepewność fabularną.
- Bieżące wymagania i ostatnie wiadomości są zapisane w sesji. Model dostaje
  nowe zapytanie, wymagania i dostępne tagi. Aplikacja zwalnia blokady sesji
  i transakcji przed połączeniem z Ollamą. Czat udostępnia przycisk „Anuluj”.

## Sprawdzenie

```cmd
docker compose exec php php tests/catalog-chat.test.php
node --test tests/catalog-chat-http.test.cjs
set ANIVIBE_TEST_OLLAMA=1
node --test tests/catalog-chat-http.test.cjs
set ANIVIBE_TEST_OLLAMA=
```

Test z Ollamą używa rozgrzanego modelu i pełnego importu katalogu.
