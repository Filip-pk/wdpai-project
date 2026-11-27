# AGENTS.md

## Cel projektu
AniVibe — aplikacja dla fanów anime: katalog, watchlista, oceny,
rekomendacje AI.

Technologie: PHP 8.3, Composer, HTML, CSS, JavaScript, PostgreSQL,
Docker Compose, Nginx i Ollama.

Aplikacja korzysta z PostgreSQL. Ollama obsługuje rekomendacje katalogowe.

## Struktura
- public/index.php — punkt wejścia i rejestracja tras.
- public/views/ — widoki PHP/HTML.
- public/views/partials/ — wspólne fragmenty widoków.
- public/css/ — arkusze stylów.
- src/Routing.php — router.
- src/ControllerFactory.php — tworzenie kontrolerów i ich zależności.
- src/controllers/ — kontrolery w namespace App\Controller.
- src/repositories/ — repozytoria z zapytaniami SQL (App\Repository).
- src/Service/ — serwisy z logiką aplikacji (App\Service).
- src/Data/ — stałe dane aplikacji: pytania quizu, zagadki, osiągnięcia.
- database/ — migracje i dane demonstracyjne (33-demo-data.sql).
- docker/ — konfiguracja kontenerów.
- docker-compose.yaml — definicje usług.

## Zasady kodu
- Stosuj declare(strict_types=1) w klasach PHP i punkcie wejścia.
- Klasy ładuj przez Composer i PSR-4.
- Kontrolery przygotowują dane, widoki odpowiadają za prezentację.
- Docelowo SQL umieszczaj w repozytoriach, logikę biznesową w serwisach.
- Dane wyświetlane w HTML zabezpieczaj przez htmlspecialchars() lub $escape().
- Używaj semantycznego HTML, zewnętrznych arkuszy i zmiennych CSS.
- Buduj układ mobile first przez Flexbox lub Grid.
- Zachowuj etykiety formularzy i widoczny fokus klawiatury.
- Stosuj 4 spacje, PascalCase dla klas i camelCase dla metod i zmiennych.
- Nie dodawaj zależności ani frameworków bez uzgodnienia.

## Sposób pracy
- Wprowadzaj małe zmiany związane z zadaniem.
- Nie usuwaj istniejącej treści ani funkcji bez uzgodnienia.
- Odpowiadaj po polsku i krótko wyjaśniaj cel zmian.
- Domyślnie podawaj krótkie podsumowanie zmian i odnośniki do plików, bez ZIP-ów.
- Pełny kod zmienionych i nowych plików wklejaj w czacie tylko na wyraźną prośbę.
- Polecenia dostosowuj do Windows CMD.
- Stosuj małe commity z prefiksami feat:, fix:, docs:, build: lub chore:.

## Bezpieczeństwo
- Administratora nie można banować ani blokować jako użytkownika,
  zgłaszać jego komentarzy, odpowiedzi lub wiadomości ani karać przez
  ostrzeżenia i moderacyjne usuwanie jego treści. Weryfikuj aktualną rolę
  na serwerze również pod blokadą transakcji; samo ukrycie przycisków nie wystarcza.
  Zachowuj historyczne zgłoszenia i blokady, pomijając blokady skierowane
  do konta podczas aktywnej roli administratora. Właściciel nadal może edytować
  i usuwać własne treści; zwykłe reakcje i odpowiedzi pozostają dostępne.
- Nie odczytuj, nie ujawniaj i nie commituj sekretów z .env.
- Nie edytuj ręcznie vendor/ ani .git/.
- Nie zmieniaj konfiguracji Dockera i zależności poza zakresem zadania.
- Nie nadpisuj niezacommitowanych zmian użytkownika.
- Operacje destrukcyjne wymagają wyraźnej zgody.
- Przy wdrażaniu backendu stosuj walidację, parametryzowany SQL,
  hashowanie haseł, autoryzację po stronie serwera i ochronę CSRF.

## Ukończenie zadania
- Zrealizuj uzgodniony zakres i zachowaj wcześniejsze funkcje.
- Zweryfikuj zmienioną logikę oraz responsywność zmienianych widoków.
- Podsumuj zmiany i ograniczenia.
- Nie deklaruj testów, których nie wykonano.
