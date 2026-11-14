# Quiz dnia

`GET /quiz` pokazuje gościowi opis i logowanie, a zalogowanemu użytkownikowi
dzisiejszy formularz albo jego zapisany wynik. Kafelek na `/dashboard` pokazuje
aktualną i najdłuższą serię oraz informację o dzisiejszym udziale.

## Zasady

- Pięć pytań, po cztery odpowiedzi, jedna poprawna. Jeden punkt za dobrą odpowiedź.
- Jeden wspólny zestaw na datę kalendarzową w strefie `Europe/Warsaw`.
- Jedno ukończenie na konto i zestaw; całość wysyłana przez `POST /quiz` z CSRF.
- Udział oznacza odpowiedź na wszystkie pytania, również przy wyniku 0/5.
- Udział w kolejnych dniach zwiększa serię. Seria zakończona wczoraj pozostaje
  aktywna przez cały dzisiejszy dzień. Pominięty pełny dzień zeruje bieżącą
  serię; rekord pozostaje zachowany.
- Zestaw wygasa o północy. Stary formularz zwraca 409 i możliwość otwarcia
  aktualnego quizu. Dni obliczane są kalendarzowo, także przy zmianie czasu.
- Brak stopera i nagród EXP. Obecne poziomy i ranking działają niezależnie.

## Pytania i zapis

`src/Data/QuizQuestions.php` zawiera 60 pytań po polsku z objaśnieniami
i odnośnikami do źródeł. Ich pochodzenie opisano w
[spisie źródeł pytań](quiz-question-sources.md). Interfejs korzysta ze wszystkich
sześciu języków AniVibe; w innych językach widoczna jest informacja o polskiej
treści pytań. Dalsze tłumaczenia treści można przygotować osobno.

Pierwsze poprawne otwarcie danego dnia losuje pięć pytań i kolejność odpowiedzi.
Tabela `daily_quizzes` zachowuje pełny snapshot, więc zmiana puli źródłowej
nie zmienia już opublikowanych zestawów ani wyników. Unikalna data rozstrzyga
równoczesne pierwsze wejścia. Nie potrzeba crona ani nowej usługi.

`quiz_attempts` przechowuje odpowiedzi, wynik oraz czas ukończenia. Klucz
`(user_id, quiz_id)` i zapis `ON CONFLICT DO NOTHING` zachowują pierwszy
wynik również przy ponownym wysłaniu formularza z innej sesji. Serie są
wyliczane z dat zapisanych ukończeń. Poprawne odpowiedzi są ujawniane
dopiero w omówieniu po ukończeniu quizu.

Kontroler pobiera konto z sesji. Serwis waliduje komplet identyfikatorów pytań
i wartości odpowiedzi oraz sam liczy wynik. Repozytorium używa parametryzowanych
zapytań i respektuje transakcję otwartą przez `AccountAccessGuard`.
Odpowiedź błędu nie tworzy nowego zestawu, który zostałby następnie wycofany
przez tę transakcję. Blokady kont obejmują także quiz.

## Migracja istniejącej bazy

Po wcześniejszych migracjach 01–23 wykonaj **raz** z katalogu projektu w Windows CMD:

```cmd
docker compose exec -T php php -r "require 'vendor/autoload.php'; (new App\Database())->connect()->exec(file_get_contents('database/24-daily-quiz.sql'));"
```

Migracja dodaje tylko tabele quizu i indeks. Nowa, pusta baza wykonuje ją
automatycznie razem z innymi skryptami inicjalizacji. Restart istniejącej
bazy nie wykonuje nowych migracji.

## Sprawdzenie

Przy uruchomionych usługach `db`, `php`, `server` i `mailpit`:

```cmd
docker compose exec -T php php tests/quiz-service.test.php
docker compose exec -T php php tests/quiz-repository.test.php
docker compose exec -T php php tests/translations.test.php
node --test tests/quiz-http.test.cjs
node --test tests/quiz-responsive.test.cjs
```

Test serwisu sprawdza punktację, walidację, pulę i serie z lukami oraz zmianami
czasu. Test repozytorium korzysta z tabel tymczasowych i własnego oznaczonego
schematu do sprawdzenia równoległego zapisu przez dwa procesy.
Testy HTTP i widoków tworzą własne konta z losowym znacznikiem i usuwają
wyłącznie te konta po sprawdzeniu ich tożsamości. Dzisiejszy wspólny zestaw
pozostaje dostępny dla aplikacji. Żadne testy nie wymagają wysyłania poczty.

Test widoków wymaga zainstalowanego Chrome lub Edge; opcjonalnie ustaw
`ANIVIBE_BROWSER` na ścieżkę do przeglądarki. Sprawdza telefon, tablet i desktop,
oba motywy, etykiety i fokus, a także obsługę formularza bez JavaScript.
Adres testowanej aplikacji można zmienić przez `ANIVIBE_TEST_URL`
(domyślnie `http://127.0.0.1:8080`).
