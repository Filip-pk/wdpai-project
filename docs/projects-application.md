# Projekty w aplikacji — etap 6

Lista i szczegóły projektów korzystają z PostgreSQL przez
`ProjectController` i `ProjectRepository`. Połączenie i repozytorium są
przygotowywane przy obsłudze trasy projektu, a kontroler przekazuje dane do widoku.

| Adres | Zachowanie |
| --- | --- |
| `GET /projects` | Lista zapisanych projektów albo informacja o pustej liście. |
| `GET /projects/{id}` | Tytuł, opis, status, identyfikator i daty wybranego projektu. |
| Nieistniejący projekt | Odpowiedź HTTP `404`. |
| Błąd obsługi żądania | Odpowiedź HTTP `500` z ogólnym komunikatem, bez szczegółów połączenia i SQL. |

Statusy mają polskie etykiety: `planned` — „Planowany”, `active` — „W trakcie”,
`completed` — „Ukończony”. Dane z modeli są zabezpieczane podczas wyświetlania
w HTML. Widoki obsługują jasny i ciemny motyw oraz układ na telefonie.

## Dodanie projektów ćwiczeniowych

W Windows CMD, w katalogu projektu, uruchom:

```cmd
docker compose exec php php scripts/seed-projects.php
```

Skrypt dodaje przez repozytorium trzy przykłady:

| Projekt | Status |
| --- | --- |
| AniVibe — rekomendacje anime | `planned` |
| AniVibe — rozmowy z postaciami | `active` |
| AniVibe — katalog anime | `completed` |

W pustej tabeli wynik to `Dodano projektów: 3. Pominięto istniejących: 0.`.
Ponowne uruchomienie pomija znalezione tytuły i wypisuje
`Dodano projektów: 0. Pominięto istniejących: 3.`. Skrypt zachowuje istniejące
rekordy; dodawanie brakujących przykładów odbywa się w jednej transakcji.
W razie błędu zwraca kod `1` i ogólny komunikat, bez treści wyjątku sterownika.

Żądania `GET` odczytują dane. Ponowne otwarcie strony lub odświeżenie nie
uruchamia skryptu i nie dodaje kolejnych rekordów.

Projekty służą do ćwiczenia trwałej warstwy danych. Katalog anime, logowanie
i AI pozostają w zakresie dotychczasowego prototypu AniVibe; statusy przykładów
opisują rekordy ćwiczeniowe.

## Weryfikacja

Dla domyślnego portu aplikacji otwórz `http://localhost:8080/projects`.
Sprawdź trzy statusy, przejdź do szczegółów wybranego projektu i odśwież stronę.
Tytuły oraz identyfikatory powinny pozostać takie same. Otwórz adres projektu
z nieistniejącym ID i sprawdź odpowiedź `404`.

Testy aplikacji i HTTP uruchom w Windows CMD:

```cmd
docker compose exec php php tests/projects-application.test.php
node --test tests/projects-http.test.cjs
```

Ograniczenia tabeli można ponownie sprawdzić skryptem z etapu 2:

```cmd
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < scripts\check-projects.sql
```

Testy ograniczeń korzystają z tabeli tymczasowej i nie zmieniają zapisanych
projektów ani ich sekwencji ID.

Sposób restartu bazy i ograniczenia istniejącego anonimowego wolumenu opisuje
[dokumentacja środowiska](postgresql-environment.md#przechowywanie-danych).
