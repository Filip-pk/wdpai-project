# Quiz dnia

`GET /quiz` pokazuje gościowi opis i logowanie, a zalogowanemu użytkownikowi
dzisiejszy formularz albo jego zapisany wynik. Kafelek na `/dashboard` pokazuje
aktualną i najdłuższą serię, informację o dzisiejszym udziale i łączny wynik,
np. 42 poprawne odpowiedzi na 60 udzielonych (`42/60`). Nie powstaje osobna
lista historii pojedynczych wyników.

## Zasady

- Pięć pytań, po cztery odpowiedzi, jedna poprawna. Jeden punkt za dobrą odpowiedź.
- Jeden wspólny zestaw na datę kalendarzową w strefie `Europe/Warsaw`.
- Nowe zestawy mają dwa łatwe, dwa średnie i jedno trudne pytanie, każde
  z innego anime. Trzy pytania dotyczą wiedzy o anime, jedno rozpoznawania
  postaci, a jedno dopasowania tytułu do krótkiego opisu.
- Jedno ukończenie na konto i zestaw; całość wysyłana przez `POST /quiz` z CSRF.
- Udział oznacza odpowiedź na wszystkie pytania, również przy wyniku 0/5.
- Udział w kolejnych dniach zwiększa serię. Seria zakończona wczoraj pozostaje
  aktywna przez cały dzisiejszy dzień. Pominięty pełny dzień zeruje bieżącą
  serię; rekord pozostaje zachowany.
- Zestaw wygasa o północy. Stary formularz zwraca 409 i możliwość otwarcia
  aktualnego quizu. Dni obliczane są kalendarzowo, także przy zmianie czasu.
- Brak stopera i nagród EXP. Obecne poziomy i ranking działają niezależnie.

## Pytania i zapis

`src/Data/QuizQuestions.php` łączy 60 oryginalnych pytań z 140 nowymi rekordami
z `QuizQuestionExpansion.php`. Łącznie jest dokładnie 200 pytań. Pytania,
odpowiedzi i objaśnienia są dostępne w sześciu językach AniVibe:
polskim, angielskim, niemieckim, francuskim, hiszpańskim i rosyjskim.
Nazwy własne i lata pozostają wspólne tam, gdzie nie wymagają tłumaczenia.
Pochodzenie faktów zachowano w [spisie źródeł pytań](quiz-question-sources.md)
i danych technicznych; widok wyników nie pokazuje linków do źródeł.

Nowa część puli zawiera 100 pytań o studia animacji, lata emisji konkretnych
adaptacji, reżyserów i autorów; 30 pytań o postaci oraz 10 krótkich autorskich
opisów. Trudniejsze pytania dotyczą szczegółów produkcji, a łatwiejsze
rozpoznawania postaci i założeń fabuły.

Pierwsze poprawne otwarcie danego dnia losuje pięć pytań i kolejność odpowiedzi.
Tabela `daily_quizzes` zachowuje pełny snapshot, więc zmiana puli źródłowej
nie zmienia już opublikowanych zestawów ani wyników. Unikalna data rozstrzyga
równoczesne pierwsze wejścia. Nie potrzeba crona ani nowej usługi.
Dobór preferuje pytania nigdy nieużywane, następnie najdawniej użyte,
z zachowaniem trudności, typów i różnych anime. Gdy dana część puli się
wyczerpie, dopuszczone są powtórki. Dziesięć opisów nie blokuje tworzenia
quizów po dziesiątym dniu. Nie jest to bezwzględny zakaz powtórzeń całej puli.

Zestawy opublikowane przed rozszerzeniem zachowują pytania, kolejność opcji
i wyniki. Nowe reguły doboru dotyczą kolejnych tworzonych zestawów. Starsze
polskie snapshoty otrzymują tłumaczenie podczas prezentacji tylko wtedy,
gdy ID, tekst i znaczenie wszystkich opcji nadal odpowiadają oryginałowi.
Nowe snapshoty zawierają pełne tłumaczenia, tasowane w tej samej kolejności.
Zmiana języka nie zmienia punktacji ani indeksów poprawnych odpowiedzi.

Skrypt `public/js/quiz.js` opcjonalnie zachowuje zaznaczenia w `sessionStorage`
na czas zmiany języka lub odświeżenia tej samej karty. Klucz jest ograniczony
do konta i zestawu; ukończenie usuwa szkic. Brak dostępu do pamięci lub
wyłączony JavaScript nie blokują standardowego formularza.

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

## Łączny wynik i ranking

`GET /quiz/ranking` wymaga zalogowania i pokazuje TOP 100 uczestników oraz
własną pozycję, również poza pierwszą setką. Zakładka „Ogólny” sumuje wszystkie
poprawne odpowiedzi. Zakładka „Miesięczny” (`?period=monthly`) obejmuje bieżący
miesiąc kalendarzowy w Warszawie, według daty zestawu, a nie znacznika UTC
ukończenia. Granice miesiąca są wyznaczane na serwerze.

Każda poprawna odpowiedź daje jeden punkt niezależnie od trudności.
Uczestnicy z równą liczbą punktów dzielą miejsce; kolejność ich wierszy
ustala ID konta. Wynik 0/5 też oznacza udział, a konto bez ukończonego quizu
nie zajmuje miejsca. Ranking pokazuje nazwę, avatar i wynik quizowy,
bez adresów e-mail, prywatnych list i innych statystyk. Zasady publikacji
są analogiczne do istniejącego rankingu EXP.

Łączne wyniki i rankingi wynikają bezpośrednio z zapisanych ukończeń.
Ponowny POST nie dodaje punktów. Rozszerzenie nie wymaga nowej migracji,
resetowania tabel ani przeliczania już zapisanych wyników; wystarczy
istniejący schemat migracji 24.

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
docker compose exec -T php php tests/quiz-translations.test.php
docker compose exec -T php php tests/quiz-selection.test.php
docker compose exec -T php php tests/quiz-ranking-repository.test.php
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
Sprawdza też obie zakładki rankingu i zachowanie wyborów po zmianie języka.
Adres testowanej aplikacji można zmienić przez `ANIVIBE_TEST_URL`
(domyślnie `http://127.0.0.1:8080`).
