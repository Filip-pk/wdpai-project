# Języki interfejsu

Gałąź `feat/multilingual-interface` dodaje polski, angielski, niemiecki, francuski, hiszpański i rosyjski. Domyślny język to polski. Przełącznik znajduje się w menu nawigacji oraz w ustawieniach konta.

## Baza danych

Dla działającej bazy uruchom z katalogu projektu w Windows CMD:

```cmd
docker compose exec -T php php scripts/migrate-interface-language.php
```

Polecenie stosuje migrację `database/22-interface-language.sql`, jeżeli kolumna języka jeszcze nie istnieje. Nowa baza otrzymuje ją podczas inicjalizacji. Migracja zachowuje dotychczasowe dane i nadaje istniejącym kontom język polski.

## Zapis i działanie

Konto przechowuje wybór w `users.language`. Jest on odczytywany przy każdym żądaniu, więc zmiana jest widoczna także w innych sesjach i na innych urządzeniach. Preferencja konta ma pierwszeństwo przed ciasteczkiem. Nowe konto przejmuje język wybrany przez gościa.

Dla gościa wybór zapisuje ciasteczko `anivibe_language` na rok z `HttpOnly`, `SameSite=Lax` i `Secure` przy HTTPS. Formularz `POST /language` wymaga poprawnego tokenu CSRF i jednego z sześciu kodów języka. Powrót po zmianie jest ograniczony do lokalnego adresu aplikacji. Zmiana języka nie wymaga JavaScriptu.

## Słowniki i treści

Sześć plików `translations/{pl,en,de,fr,es,ru}.php` zawiera identyczne klucze. W widoku używaj `$t('navigation.settings')`, a parametry przekazuj jako tablicę: `$t('navigation.profile_name', ['user' => $name])`. Wynik jest surowym tekstem i wymaga `$escape()` przy wyświetlaniu w HTML. Parametry nie są ponownie interpretowane jako szablon.

`$translateText()` służy istniejącym etykietom aplikacji ze stałych map. Nie należy przekazywać do niego komentarzy, wiadomości, opisów kolekcji ani innych treści użytkowników. Nowe komunikaty powinny korzystać z nazwanych kluczy.

Powiadomienia o odpowiedziach są składane przy wyświetlaniu z szablonu i danych użytkownika. Wiadomości e-mail powstają w języku odbiorcy. JavaScript otrzymuje wyłącznie słownik `js.*` przez bezpiecznie kodowany JSON i `window.AniVibeI18n.t()`; do DOM należy wstawiać tekst przez `textContent`.

Opisy anime są wspólne dla wszystkich języków i pozostają dokładnie w postaci zapisanej w bazie. Angielskie opisy mają `lang="en"`. Istniejące dane demonstracyjne mogą zawierać opisy polskie; mechanizm języków nie zmienia danych katalogu. Nazwy anime, nazwy użytkowników, komentarze, wiadomości i prywatne opisy kolekcji zachowują oryginalną treść.

## Weryfikacja

```cmd
docker compose exec -T php php tests/translations.test.php
docker compose exec -T php php tests/language-repository.test.php
node --test tests/language-http.test.cjs tests/i18n.test.cjs
```

Kontrola PHP sprawdza kompletność słowników, zgodność parametrów, UTF-8, podstawienia i składnię plików PHP. Testy HTTP sprawdzają sześć języków, ciasteczka, CSRF, walidację, lokalne przekierowania, pierwszeństwo konta i zapis między sesjami oraz niezmienność opisów anime. Testy kont tworzą losowe konta w bazie deweloperskiej, tak jak wcześniejsze testy aplikacji.

Pełne testy HTTP korzystają ze wspólnego katalogu i komentarzy. Uruchamiaj pliki kolejno, aby dane przygotowywane przez jeden test nie zmieniały wyników drugiego:

```cmd
node --test --test-concurrency=1 tests
```

Podczas wdrożenia sprawdzono 1174 klucze w każdym języku, 326 przypadków w 29 plikach PHP oraz wszystkie 227 przypadków JS/HTTP. Po dodaniu nowego zasobu zaktualizowano listę oczekiwanych plików w teście zasobów. Zestawy katalogu i komentarzy wymagały ponowienia kolejno z powodu kolizji danych przy równoległym uruchomieniu; powtórzony podzestaw zakończył się wynikiem 30/30. Kontrola Chrome obejmowała 252 warianty stron przy szerokościach 375 i 1440 px, etykiety JavaScriptu, brak poziomego przewijania oraz zmianę języka formularzem przy wyłączonym JavaScripcie. Dodatkowy test widoku sprawdza tłumaczenie starszych raportów MAL zapisanych w sesji bez pola `reasonParts`.
