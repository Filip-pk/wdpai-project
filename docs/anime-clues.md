# Rozpoznaj anime po wskazówkach

`GET /guess` pokazuje gościowi opis i logowanie, a zalogowanemu graczowi
bieżącą rundę albo wynik ukończonego dnia. Wejście jest dostępne w nawigacji
i na dashboardzie. Gra działa bez JavaScript i jest dostępna w sześciu
językach AniVibe.

## Zasady i punktacja

- Pula zawiera 200 różnych anime, każde z trzema autorskimi wskazówkami
  po polsku, angielsku, niemiecku, francusku, hiszpańsku i rosyjsku.
- Wszyscy dostają ten sam trwały zestaw pięciu anime na dzień w strefie
  `Europe/Warsaw`. Każde anime pochodzi z innej franczyzy.
- Pierwsza wskazówka jest dostępna od razu. Błędna odpowiedź odkrywa następną;
  można też odkryć ją ręcznie, rezygnując z próby na bieżącym poziomie.
- Poprawna odpowiedź po jednej, dwóch lub trzech wskazówkach daje
  odpowiednio **3, 2 lub 1 punkt**. Maksymalny wynik dnia to **15/15**.
- Każda runda ma do trzech prób, po jednej na wskazówkę. Błędy po pierwszej
  i drugiej wskazówce pozostają widoczne i pozwalają zgadywać dalej.
  Dopiero trzeci błąd lub pominięcie kończy rundę z zerem punktów.
  Pusty lub nieprawidłowy formularz nie zużywa próby.
- Po wpisaniu co najmniej dwóch znaków lista podpowiada pasujące tytuły
  z całej puli 200 anime, również po fragmencie alternatywnej nazwy.
  Obsługuje strzałki, Enter, Escape oraz wybór myszą lub dotykiem.
  Bez JavaScript tytuł nadal można wpisać samodzielnie.
  Akceptowane są nazwa kanoniczna
  i zapisane popularne aliasy. Wielkość liter, odstępy, interpunkcja
  i diakrytyka są normalizowane. Nie stosujemy dopasowania podobnych słów.
- Zakończenie wszystkich pięciu rund zalicza dzień do serii, także przy
  wyniku zero. Za częściowy zestaw pozostają zapisane punkty i odpowiedzi,
  ale dzień nie przedłuża serii.
- O północy pojawia się nowy zestaw. Próba wysłania starego formularza
  zwraca 409 z możliwością otwarcia bieżącej gry.

Punkty i serie tej gry są przechowywane osobno od quizu dnia. Nie zmieniają
EXP ani istniejącego rankingu quizowego. Strona pokazuje wynik dzienny,
sumę punktów, liczbę rozpoznanych anime na wszystkie rozegrane rundy oraz
aktualną i rekordową serię dni.

## Ranking tygodniowy

`GET /guess/ranking` wymaga zalogowania i pokazuje ranking punktów
za bieżący tydzień: od poniedziałku 00:00 do następnego poniedziałku
00:00 w strefie `Europe/Warsaw`. Widok podaje daty poniedziałku i niedzieli.
Okres wyznacza serwer, według daty dziennego zestawu, nie znacznika UTC
odpowiedzi. Przyszłe zestawy nie są uwzględniane.

Liczą się zapisane rundy, także z nieukończonego zestawu. Samo otwarcie
gry lub odkrycie wskazówki bez odpowiedzi nie tworzy miejsca w rankingu.
Uczestnik z rozegraną rundą i wynikiem zero również może się znaleźć
na liście. Kolejność ustala suma punktów; remisy mają wspólne miejsce,
a ID konta zapewnia stałą kolejność wierszy.

Lista zawiera najwyżej 100 uczestników. Własna pozycja jest pokazana
osobno również poza TOP 100. Publiczne dane rankingu to nazwa, avatar
i wyniki tej gry, bez adresów e-mail, tytułów odpowiedzi i podpowiedzi.
Ranking jest aktualny przy każdym odczycie i nie resetuje historii
punktów ani zdobytych odznak. Nie wymaga dodatkowej migracji.

## Pula i tłumaczenia

`src/Data/AnimeClues.php` łączy dwie części banku:
`AnimeCluesPartOne.php` i `AnimeCluesPartTwo.php`. Stabilny identyfikator,
nazwa, aliasy i franczyza są wspólne dla języków, a każda wskazówka ma
pełną wersję językową. Nazwy własne pozostają w powszechnie używanym zapisie.
Źródła do kontroli faktów są w dokumentach
[części A](anime-clues-sources-a.md) i [części B](anime-clues-sources-b.md).
Nie są wyświetlane jako podpowiedzi ani odnośniki w grze.

Losowanie preferuje anime nigdy nieużyte, następnie najdawniej użyte.
Po wykorzystaniu puli tytuły mogą wracać. Opublikowany zestaw zawiera
pełny snapshot, więc późniejsza zmiana źródłowej puli nie zmienia
trwających gier ani zapisanych wyników.

## Zapis i ochrona wyniku

Migracja 26 dodaje trzy tabele:

- `daily_anime_clues` — jeden wspólny zestaw na datę.
- `anime_clue_attempts` — bieżąca runda, liczba odkrytych wskazówek,
  wynik i ukończenie zestawu dla konta.
- `anime_clue_answers` — końcowa odpowiedź i punkty każdej rozegranej rundy.

Migracja 27 dodaje do `anime_clue_attempts` historię błędnych prób
`wrong_answers`, również dla bieżącej rundy. Zachowuje istniejące wyniki
i nie otwiera ponownie wcześniej zakończonych rund.

`AnimeClueController` obsługuje formularze `POST /guess/reveal`,
`POST /guess/answer` i `POST /guess/skip`, pobierając konto z sesji
i sprawdzając CSRF. `AnimeClueService` sprawdza datę, kolejność rund,
tytuł i punktację na serwerze. Blokada wiersza oraz unikalne klucze
chronią wynik przy równoległych żądaniach i ponownym wysłaniu formularza.
Powtórzenie tej samej prośby o podpowiedź nie odkrywa dwóch wskazówek.
Formularz odpowiedzi przekazuje także oczekiwaną liczbę wskazówek.
Ponowienie starego formularza po błędzie nie zużywa następnej próby.

`GET /guess/suggestions?q=nar` wymaga zalogowania i zwraca najwyżej
dziesięć pasujących tytułów i dopasowanych nazw alternatywnych.
Wynik nie zależy od dziennego zestawu, bieżącej rundy ani jej odpowiedzi.
Nie zawiera identyfikatorów anime, wskazówek ani oznaczenia poprawnego tytułu.

Stan rozgrywki zawiera odkryte wskazówki, własne błędne próby i zakończone rundy.
Nie ujawnia ukrytych podpowiedzi, aliasów, identyfikatorów anime ani
tytułów kolejnych rund. Lista dopasowanych tytułów pochodzi z osobnej
wyszukiwarki całej puli. Dopiero zakończenie lub pominięcie wskazuje
poprawny tytuł tej konkretnej rundy. Zmiana języka i odświeżenie strony zachowują stan
zapisany na koncie, także w innej karcie lub po ponownym zalogowaniu.

Serwis respektuje transakcję `AccountAccessGuard`. Odpowiedź błędu
nie tworzy nowego zestawu, którego identyfikator zostałby następnie
wycofany. Konto objęte blokadą nie może kontynuować gry.

## Pięć nowych odznak

| Kod | Odznaka | Warunek |
| --- | --- | --- |
| `guess_first` | Pierwsze śledztwo | Pierwszy ukończony zestaw pięciu rund. |
| `guess_sharp` | Sokole oko | Rozpoznanie anime po pierwszej wskazówce. |
| `guess_perfect` | Perfekcyjny detektyw | Wynik 15/15 w jednym zestawie. |
| `guess_correct_50` | Znawca tropów | Łącznie 50 poprawnie rozpoznanych anime. |
| `guess_streak_7` | Tydzień śledztw | Siedem kolejnych ukończonych dni. |

Trafienia liczą się po zakończeniu rundy, również w częściowym zestawie.
Odznaki za pełny dzień i serię wymagają wszystkich pięciu rund.
Pierwszy zapis odznaki zachowuje swoją datę; ponowne żądanie nie powiela
punktów ani odznak. Publiczna widoczność podlega tym samym zasadom
prywatności co pozostałe [osiągnięcia](achievements.md).

## Migracja istniejącej bazy

Po migracjach 01–25 wykonaj **raz** z katalogu projektu w Windows CMD:

```cmd
docker compose exec -T php php -r "require 'vendor/autoload.php'; (new App\Database())->connect()->exec(file_get_contents('database/26-anime-clue-game.sql'));"
```

Pusta baza wykona skrypt w standardowej inicjalizacji. Restart istniejącej
bazy nie stosuje nowych migracji. Skrypt dodaje tabele nowej gry
i zachowuje wcześniejsze dane aplikacji.

Po migracji 26 wykonaj **raz** aktualizację trzech prób:

```cmd
docker compose exec -T php php -r "require 'vendor/autoload.php'; (new App\Database())->connect()->exec(file_get_contents('database/27-anime-clue-retries.sql'));"
```

## Sprawdzenie

Przy uruchomionych usługach `db`, `php` i `server`:

```cmd
docker compose exec -T php php tests/anime-clue-data.test.php
docker compose exec -T php php tests/anime-clue-service.test.php
docker compose exec -T php php tests/anime-clue-title-search.test.php
docker compose exec -T php php tests/anime-clue-repository.test.php
docker compose exec -T php php tests/anime-clue-ranking.test.php
docker compose exec -T php php tests/anime-clue-achievements.test.php
docker compose exec -T php php tests/translations.test.php
node --test tests/anime-clues-http.test.cjs
node --test tests/anime-clues-responsive.test.cjs
```

Test danych sprawdza 200 tytułów, aliasy, 3600 wskazówek i dobór po
wykorzystaniu puli. Testy serwisu, bazy i odznak obejmują punktację,
ujawnianie, kolejność rund, wygaśnięcie dnia, konta, transakcje,
ponowienia i rzeczywiste równoległe połączenia. Testy HTTP sprawdzają
rzeczywiste formularze, CSRF, brak wycieku ukrytych danych i tłumaczenia.
Test przeglądarkowy wymaga Chrome lub Edge i sprawdza telefon, tablet,
desktop, oba motywy oraz obsługę bez JavaScript, w tym widok rankingu.

Fixture’y tworzą oznaczone konta i ewentualne własne historyczne zestawy,
sprawdzając ich tożsamość przed posprzątaniem. Nie nadpisują bieżącego
wspólnego zestawu. Opcjonalne zmienne testów: `ANIVIBE_TEST_URL`
i `ANIVIBE_BROWSER`.
