# Osiągnięcia i odznaki

`GET /achievements` wymaga zalogowania. Pokazuje piętnaście osiągnięć,
warunki zdobycia, postęp i daty odblokowania. Odnośniki prowadzą do niego
z nawigacji i zakładek profilu. Zdobyte odznaki są też widoczne na własnym
profilu i publicznej stronie użytkownika.

## Warunki

| Kod | Warunek |
| --- | --- |
| `quiz_first` | Ukończenie pierwszego quizu, także z wynikiem 0/5. |
| `quiz_perfect` | Co najmniej jeden quiz z kompletem poprawnych odpowiedzi. |
| `quiz_correct_100` | Łącznie 100 poprawnych odpowiedzi w quizach. |
| `quiz_streak_7` | Rekord udziału przez 7 kolejnych dni kalendarzowych. |
| `quiz_streak_30` | Rekord udziału przez 30 kolejnych dni kalendarzowych. |
| `guess_first` | Ukończenie pierwszego zestawu rozpoznawania anime. |
| `guess_sharp` | Poprawne rozpoznanie po pierwszej wskazówce. |
| `guess_perfect` | Wynik 15/15 w jednym zestawie rozpoznawania. |
| `guess_correct_50` | Łącznie 50 rozpoznanych anime. |
| `guess_streak_7` | Siedem kolejnych ukończonych zestawów dziennych. |
| `anime_first` | Pierwszy ukończony tytuł anime. |
| `anime_completed_10` | 10 ukończonych tytułów. |
| `anime_completed_50` | 50 ukończonych tytułów. |
| `anime_genres_5` | Ukończone tytuły reprezentujące łącznie 5 różnych gatunków. |
| `anime_episodes_100` | Łącznie 100 zapisanych obejrzanych odcinków. |

Katalog i progi znajdują się w `src/Data/Achievements.php`. Tytuły,
opisy i interfejs korzystają z sześciu języków AniVibe.
Odznaki nie przyznają EXP ani punktów w rankingach.

## Naliczanie i trwałość

Serwer sprawdza warunki po poprawnej akcji w grze rozpoznawania,
zakończeniu quizu, zapisie lub usunięciu
wpisu watchlisty oraz potwierdzeniu importu MAL. Podgląd importu i błędne
formularze nie przyznają osiągnięć. Nagrody i zapis aktywności należą do
tej samej transakcji żądania.

Otwarcie własnych osiągnięć lub profilu uwzględnia również starszą aktywność,
zapisaną przed dodaniem tej funkcji. Cudzy profil jest widokiem tylko do
odczytu: nie przyznaje odznak w imieniu jego właściciela.

`user_achievements` zapisuje kod i czas pierwszego wykrycia spełnionego
warunku. Klucz `(user_id, achievement_code)` i `ON CONFLICT DO NOTHING`
chronią przed powieleniem odznaki i zmianą jej daty przy ponownym żądaniu
lub równoległych zapisach. Dla starszej aktywności data oznacza pierwsze
sprawdzenie osiągnięcia, a nie odtworzoną historyczną datę spełnienia warunku.

Odblokowana odznaka pozostaje zdobyta po późniejszych korektach i usunięciu
tytułów z listy. Jej pasek pozostaje pełny. Postęp niezdobytych odznak
wynika z aktualnie zapisanych danych i może się zmniejszyć po korekcie.
Quizowe serie liczone są z dat kalendarzowych w `Europe/Warsaw`, również
przy zmianie czasu; brany jest rekord serii, więc późniejsza przerwa go nie
odbiera. Przyszłe zestawy nie zwiększają postępu osiągnięć.

Metryki oglądania korzystają z zachowanego wkładu `user_anime_experience`,
tak jak EXP. Samo usunięcie wpisu z watchlisty nie usuwa tego wkładu.
Gatunki są liczone jako unikalne identyfikatory powiązane z ukończonymi
tytułami. Postęp oglądania jest deklaracją użytkownika w aplikacji.

## Prywatność i dostępność

Właściciel zawsze widzi własne odznaki i postęp. Publiczny profil pokazuje
wyłącznie zdobyte odznaki, bez postępu do pozostałych. Ustawienie
„Publiczne statystyki” obejmuje także tę sekcję: po wyłączeniu jej dane nie
trafiają do HTML oglądanego przez gościa ani inne konto. Blokady kont
obejmują również stronę osiągnięć i zapisy przyznające odznaki.

Strona działa bez JavaScript. Paski mają widoczne etykiety, statusy są
opisane tekstowo, a interfejs respektuje motyw i ustawienia dostępności.
Daty odblokowania są wyświetlane w strefie `Europe/Warsaw`.

## Migracja istniejącej bazy

Po migracjach 01–24 wykonaj **raz** z katalogu projektu w Windows CMD:

```cmd
docker compose exec -T php php -r "require 'vendor/autoload.php'; (new App\Database())->connect()->exec(file_get_contents('database/25-user-achievements.sql'));"
```

Migracja dodaje jedną tabelę i zachowuje obecne konta, quizy, watchlisty
oraz EXP. Nowa, pusta baza wykona ją podczas standardowej inicjalizacji.
Samo ponowne uruchomienie istniejącej bazy nie stosuje nowych migracji.

## Sprawdzenie

Przy uruchomionych lokalnych usługach `db`, `php` i `server`:

```cmd
docker compose exec -T php php tests/achievement-service.test.php
docker compose exec -T php php tests/achievement-repository.test.php
docker compose exec -T php php tests/translations.test.php
node --test tests/achievements-http.test.cjs
node --test tests/achievements-responsive.test.cjs
```

Testy PHP sprawdzają progi, daty, metryki, korekty, izolację kont,
transakcje i wyścig dwóch połączeń. Testy HTTP obejmują prawdziwe zapisy
quizu, watchlisty i importu MAL, brak skutków błędnego CSRF, trwałość
odznak, prywatność oraz języki. Test przeglądarkowy sprawdza oba motywy,
320/768/1440 px, etykiety, fokus, profile i działanie bez JavaScript.

Testy używają własnych oznaczonych kont, tytułów, gatunków i historycznych
zestawów, których tożsamość sprawdzają przed posprzątaniem. Nie nadpisują
istniejących zestawów dnia. Test widoków wymaga Chrome lub Edge;
opcjonalnie `ANIVIBE_BROWSER` wskazuje ścieżkę przeglądarki, a
`ANIVIBE_TEST_URL` adres aplikacji (domyślnie `http://127.0.0.1:8080`).

Pięć odznak rozpoznawania anime wymaga także migracji 26. Zasady punktacji,
stan rund i osobny test integracji odznak opisuje
[dokumentacja gry](anime-clues.md).
