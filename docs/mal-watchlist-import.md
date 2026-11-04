# Import watchlisty z MyAnimeList

Zakładka „Import MAL” w profilu pozwala przenieść własną listę z pliku
XML lub XML.GZ wyeksportowanego przez MyAnimeList. Import obejmuje statusy,
liczbę obejrzanych odcinków i własne oceny. Nie wymaga hasła do konta MAL,
łączenia kont ani klucza API.

## Korzystanie

1. Po zalogowaniu na MAL otwórz [eksport listy](https://myanimelist.net/panel.php?go=export),
   wybierz listę anime i pobierz plik XML lub XML.GZ.
2. W AniVibe otwórz `/profile/import` i wybierz pobrany plik.
3. Domyślnie import dopisuje brakujące tytuły. Opcja aktualizacji istniejących
   wpisów pozwala zastąpić również zapisane postępy i własne oceny.
4. Sprawdź podgląd zmian, w tym pozycje pominięte, i zatwierdź import.
5. Raport pokaże liczbę dodanych, zmienionych, zachowanych i pominiętych
   pozycji oraz EXP przed importem i po nim.

Wgranie pliku i podgląd nie zmieniają watchlisty ani ocen. Podgląd jest
prywatny, przypisany do zalogowanej sesji i ważny przez 30 minut. Można go
anulować. Gdy dane zmienią się po przygotowaniu podglądu, aplikacja wymaga
sprawdzenia odświeżonego podglądu przed zapisem.

Plik może mieć maksymalnie 10 MiB. Po rozpakowaniu limit wynosi 50 MiB,
a lista może zawierać najwyżej 40 000 pozycji. Nginx dopuszcza nieco większe
żądanie, aby uwzględnić narzut przesyłania pliku formularzem.

## Dopasowanie i statusy

Tytuły są dopasowywane po `series_animedb_id` do identyfikatora MAL
w istniejącym katalogu AniVibe. Nazwa z eksportu nie służy do zgadywania
pasującego tytułu. Nieznane ID, niepoprawne wpisy i duplikaty są widoczne
w raporcie; pozostałe poprawne pozycje można zaimportować.

| Status MAL | Watchlista AniVibe |
| --- | --- |
| Watching | Oglądam |
| Completed | Ukończone |
| On-Hold | Wstrzymane |
| Dropped | Porzucone |
| Plan to Watch | Planowane |

Własna ocena `0` w eksporcie oznacza brak oceny; wartości 1–10 oznaczają
ocenę użytkownika. „Planowane” ma 0 obejrzanych odcinków. Dla ukończonego
tytułu o znanej długości zapis obejmuje wszystkie odcinki z katalogu.
Postęp przekraczający znaną długość serii jest pomijany z wyjaśnieniem.
Przy nieznanej długości używana jest poprawna liczba z eksportu.

Opisy, okładki, oceny katalogowe i kolekcje pozostają osobnymi danymi.
Import aktualizuje tylko prywatną bibliotekę zalogowanego konta.

## Zachowanie istniejących danych

Domyślny tryb zachowuje istniejące wpisy watchlisty i własne oceny, także
ocenę tytułu znajdującego się poza watchlistą. Jeżeli tytuł usunięto wcześniej
z watchlisty, zapamiętany postęp i ukończenie używane do EXP są zachowane
przy ponownym dodaniu przez import.

Jawne włączenie aktualizacji istniejących wpisów może zastąpić status,
postęp i ocenę wartościami z eksportu. W tym trybie ocena `0` usuwa
dotychczasową własną ocenę. Zmiany są pokazywane przed zatwierdzeniem.

EXP jest przeliczany według obecnych zasad: 5 za odcinek i dodatkowe 100
za ukończenie tytułu. Powtórny import nie nalicza drugi raz tych samych
punktów. Zastąpienie postępu starszą wartością w trybie aktualizacji może
obniżyć EXP i poziom. Zapis całego importu jest transakcyjny.

## Migracja istniejącej bazy

Po wcześniejszych migracjach `01`–`11` wykonaj raz w Windows CMD:

```cmd
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\12-watchlist-import.sql
docker compose up -d --build --no-deps server
```

Migracja dodaje statusy „Wstrzymane” i „Porzucone”, zachowując dotychczasowe
listy. Przebudowanie serwera wczytuje limit przesyłania plików importu.
Nowa baza wykonuje migrację automatycznie przy pierwszym starcie.

## Sprawdzenie

```cmd
docker compose exec -T php php tests/mal-parser.test.php
docker compose exec -T php php tests/mal-import.test.php
node --test tests/mal-import-http.test.cjs
```

Test HTTP tworzy dwa losowe konta z prefiksem `mal-import-` i nazwami
„Import MAL A” oraz „Import MAL B”. Sprawdza podgląd bez zapisu, plik gzip,
poprawny plik większy niż 5 MiB, odrzucanie błędnych plików i DTD, prywatność,
CSRF, konflikty, powtórny import, zachowanie ocen oraz naliczanie EXP.
Uruchamiaj testy na środowisku deweloperskim i usuń utworzone konta testowe
po zakończeniu sprawdzeń.

Sprawdź również podgląd i raport przy szerokościach 375 i 1280 px, w jasnym
i ciemnym motywie, oraz etykiety formularzy i obsługę klawiaturą.
