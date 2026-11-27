# Zadanie cykliczne: czyszczenie danych

Usługa `cron` w Docker Compose co godzinę uruchamia
[scripts/cleanup.php](../scripts/cleanup.php). Zadanie usuwa dane, które
straciły znaczenie, ale bez niego zostawałyby w bazie:

| Co usuwa | Kiedy | Dlaczego |
| --- | --- | --- |
| `login_attempts` | starsze niż 24 h | Limit logowań czyta tylko ostatnie 15 minut, a wpisy znikały dotąd tylko przy kolejnej nieudanej próbie. |
| `user_email_changes` | po `expires_at` | Wygasłej zmiany nie da się potwierdzić, a wiersz nadal przechowuje skrót tokenu i hasha hasła. |
| `community_activity` | starsze niż 24 h | Limity komentarzy, wiadomości, zgłoszeń i reakcji sprawdzają najwyżej 10 minut. |
| Pliki w `storage/uploads/avatars` | bez konta w `users.avatar_path`, starsze niż 1 h | Pliki zastąpionych zdjęć i zapisów, których transakcja została wycofana. |

Zadanie nie usuwa kont, treści użytkowników, zgłoszeń ani historii blokad.

## Harmonogram i działanie

[docker/cron/crontab](../docker/cron/crontab) ustawia uruchomienie w 15.
minucie każdej godziny, w czasie UTC kontenera:

```
15 * * * * . /run/anivibe-cron.env && su -p -s /bin/sh www-data -c 'php /app/scripts/cleanup.php' > /proc/1/fd/1 2> /proc/1/fd/2
```

- Usługa `cron` korzysta z obrazu PHP i uruchamia `busybox crond`.
- Zadania crona startują bez zmiennych kontenera, dlatego
  [start.sh](../docker/cron/start.sh) zapisuje zmienne `POSTGRES_*` do pliku
  `/run/anivibe-cron.env`, dostępnego tylko dla roota.
- Skrypt działa jako `www-data`, ten sam użytkownik co PHP-FPM, więc pliki,
  które zmienia, pozostają dostępne dla aplikacji.
- Blokada doradcza PostgreSQL nie dopuszcza dwóch uruchomień naraz: drugie
  kończy się komunikatem o pominięciu.
- Każde zapytanie można powtórzyć. Ponowne uruchomienie tylko znajduje mniej
  danych do usunięcia.

Zmiana harmonogramu wymaga edycji `docker/cron/crontab` i przebudowy:
`docker compose up -d --build cron`.

## Wynik i log

Podsumowanie każdego uruchomienia trafia do logu kontenera:

```cmd
docker compose logs cron
```

```
2026-11-27T15:15:01Z cleanup finished: login_attempts=12 email_changes=1 community_activity=340 avatar_files=1
```

Ten sam wynik, z liczbą usuniętych wierszy i plików, zapisuje się w logu
aplikacji. W panelu administratora wybierz **Błędy aplikacji**, a w filtrze
moduł `maintenance`. Wpis ma poziom „Informacja”, a nieudane uruchomienie
poziom „Błąd” z samą nazwą klasy wyjątku.

## Uruchomienie ręczne i testy

Zadanie uruchomisz bez czekania na harmonogram (Windows CMD):

```cmd
docker compose exec -u www-data cron php scripts/cleanup.php
```

Opcja `-u www-data` jest ważna: skrypt uruchomiony jako root utworzyłby pliki
logów niedostępne dla PHP-FPM.

```cmd
docker compose exec php php tests/cleanup.test.php
docker compose exec php php tests/avatar-storage.test.php
```

Test zadania tworzy tabele tymczasowe z przeterminowanymi i aktualnymi
wierszami oraz katalog tymczasowy z plikami. Sprawdza, co zostaje usunięte,
ponowne uruchomienie, pominięcie przy zajętej blokadzie i zapis błędu.
Wszystko jest wycofywane, więc test nie usuwa prawdziwych danych.
