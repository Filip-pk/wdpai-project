# Panel administratora

Panel `/admin` obejmuje przegląd aplikacji, ręczną diagnostykę usług,
ostatnie błędy, użytkowników i uprawnienia moderatorów, braki danych
w katalogu oraz historię operacji administracyjnych.

## Przygotowanie

Polecenia wykonuj w Windows CMD w katalogu projektu, przy uruchomionych
usługach `db`, `php`, `server` i `mailpit`.

Na istniejącej bazie po migracji `28` zastosuj migrację `29`:

```cmd
docker compose exec -T php php scripts/migrate-admin.php
docker compose exec -T php php scripts/prepare-admin-logs.php
```

Migracja jest transakcyjna i może zostać wykonana ponownie. Dodaje flagę
`users.is_admin`, tabelę `admin_actions` i indeksy, zachowując dotychczasowe
dane i role. Nowa pusta baza wykonuje ją automatycznie podczas inicjalizacji.
Przygotowanie katalogu logów jest potrzebne również na nowej instalacji.
Skrypt ustawia prawa tylko `storage/logs` i rozpoznanych plików logów;
w kontenerze uruchomionym jako root przypisuje je procesowi `www-data`.
Nie zmienia konfiguracji Dockera ani uprawnień innych katalogów.

## Pierwszy administrator i role

Utwórz zwykłe konto w aplikacji i odczytaj jego ID z adresu publicznego
profilu. Następnie nadaj mu rolę; `123` zastąp właściwym ID:

```cmd
docker compose exec -T php php scripts/set-admin.php 123
```

Przy kolejnym wejściu na stronę pojawi się pozycja „Administracja” w menu.
Nie ma domyślnego loginu ani hasła administratora. Rejestracja, profil
i formularze HTTP nie pozwalają nadać sobie tej roli. Odebranie roli:

```cmd
docker compose exec -T php php scripts/set-admin.php 123 --revoke
```

Nie można odebrać uprawnień ostatniemu aktywnemu administratorowi ani
nadać ich zbanowanemu kontu. Rola jest odczytywana z bazy przy każdym
żądaniu, więc odebranie działa również w już otwartej sesji.

Administrator i moderator to osobne uprawnienia. Admin może w panelu
nadać lub odebrać rolę moderatora innemu, niezbanowanemu użytkownikowi,
podając powód. Operacja oraz jej historia są zapisywane w jednej transakcji.
Nie może w ten sposób zmieniać swojej roli ani roli innego administratora.
Przycisk „Moderacja” pojawia się wyłącznie przy osobno nadanej roli moderatora.
Dotychczasowe zasady prywatności wiadomości pozostają w mocy.
Administratora nie można banować, ostrzegać ani blokować jako użytkownika.
Nie można zgłaszać jego komentarzy, odpowiedzi ani prywatnych wiadomości;
moderator nie może również usuwać jego treści. Ochrona dotyczy aktualnej
roli zapisanej w bazie i obowiązuje także przy ręcznym podaniu adresu
operacji lub wysłaniu starego formularza otwartego przed nadaniem roli.
Przyciski tych operacji są ukryte w profilu, komentarzach, rozmowach
i panelu moderacji. Zasada jest utrwalona również w [AGENTS.md](../AGENTS.md).

Historyczne zgłoszenia i blokady nie są usuwane. Dawne zgłoszenie można
oddalić, zachowując historię, ale nie można na jego podstawie ukarać
administratora. Istniejąca blokada skierowana do administratora jest
pomijana w liście blokad i przy wysyłaniu wiadomości; po odebraniu roli
znów obowiązuje. Blokady nałożone przez administratora na zwykłe konta
działają nadal. Administrator zachowuje możliwość edycji i usuwania
własnych komentarzy, a zwykłe reakcje i odpowiedzi na jego wpisy pozostają
dostępne. Rola admina nadal nie daje wglądu w cudze prywatne rozmowy.

## Diagnostyka

Wejście do panelu nie uruchamia testów ani wysyłki. „Sprawdź usługi”
wykonuje cztery niezależne próby i zapisuje wyniki w bieżącej sesji:

- PostgreSQL: połączenie, `SELECT 1`, wszystkie tabele tworzone przez
  migracje z katalogu `database` oraz kolumny dodane do istniejących tabel.
  Brakujący element pojawia się z nazwą w szczegółach karty, np.
  `login_attempts`, gdy na istniejącej bazie nie zastosowano migracji `32`.
  Listy są w `DiagnosticService::REQUIRED_TABLES` i `REQUIRED_COLUMNS`;
  test diagnostyki porównuje je z plikami migracji, więc nowa migracja
  bez aktualizacji listy nie przejdzie testu. Zapytania kontrolne mają limit
  czasu i nie zapisują danych.
- Poczta: połączenie SMTP, powitanie serwera i `EHLO`, bez wysyłki wiadomości.
- Ollama: żądanie `/api/tags`, ograniczony rozmiar odpowiedzi, brak
  przekierowań i limit czasu. Karta sprawdza, czy pobrany jest model czatu
  katalogu z `OLLAMA_CHAT_MODEL` (domyślnie `qwen3:4b`); nazwa bez tagu
  oznacza `:latest`, tak jak w Ollamie. Niedostępna usługa, pusta lista
  modeli albo brak modelu czatu są ostrzeżeniem: nie działa wtedy tylko
  czat `/chat`, pozostała część aplikacji działa normalnie. Brakujący model
  pobierzesz poleceniem z [catalog-chat.md](catalog-chat.md).
- Środowisko: PHP, wymagane rozszerzenia, autoloader i zapis logów.

Każda karta pokazuje status tekstowy, czas odpowiedzi, czas sprawdzenia
i szczegóły. Wynik pochodzi z ostatniego ręcznego testu w danej sesji,
nie stanowi ciągłego monitoringu. Daty są wyświetlane w strefie używanej
przez aplikację. Awaria jednego testu nie zatrzymuje pozostałych.

Domyślny adres Ollamy to `http://ollama:11434`; opcjonalne `OLLAMA_URL`
jest odczytywane wyłącznie z konfiguracji procesu serwera. Panel nie pozwala
wpisać dowolnego adresu testowanego serwera.

„Wyślij wiadomość testową” wysyła pocztę wyłącznie na aktualny adres
zalogowanego administratora. Podane w żądaniu inne adresy są ignorowane.
Sukces oznacza przyjęcie przez SMTP, a nie potwierdzone dostarczenie
do skrzynki. W środowisku lokalnym wiadomość przechwytuje Mailpit.
Ponowienie wysyłki w tej samej sesji wymaga 60 sekund odstępu,
a ponowienie diagnostyki 10 sekund. Formularze wymagają poprawnego CSRF.

Pełna awaria PostgreSQL uniemożliwia zweryfikowanie uprawnień i wejście
do panelu; system nie obchodzi wtedy autoryzacji. Do takiej diagnozy
pozostają logi aplikacji oraz `docker compose logs`.

## Błędy i historia

Logi JSONL znajdują się w `storage/logs`, poza katalogiem publicznym,
i są ignorowane przez Git. Obejmują wyjątki obsługi żądań, błędy poczty
i diagnostyki oraz ostrzeżenia i błędy krytyczne PHP po uruchomieniu
obsługi błędów. Nie importują wcześniejszych logów kontenerów Nginx/PHP.

Wpis zawiera losowy identyfikator, czas UTC, poziom, moduł, stały komunikat
i ograniczone metadane: klasę wyjątku oraz zanonimizowaną ścieżkę.
Pomijane są treść wyjątku, SQL, stos wywołań, parametry URL, hasła,
tokeny, adresy e-mail i treści prywatnych wiadomości. Identyfikator błędu
jest pokazywany również na ekranie 500, jeśli obsługa żądania mogła go zapisać.

Domyślnie zachowujemy maksymalnie 8 plików po około 1 MB przez 7 dni.
Rotacja i usuwanie starych plików następują przy kolejnym zapisie;
odczyt pomija przeterminowane pliki i ma ograniczony rozmiar.
Listy mają filtrowanie i paginację po 20 pozycji. Moduł wybierasz z listy
stałych modułów loggera (`ApplicationLogger::MODULES`, np. `auth`, `mail`,
`database`); nieznana wartość w adresie oznacza wszystkie moduły. Brak prawa zapisu lub
chwilowo zajęta blokada nie zastępuje pierwotnej odpowiedzi aplikacji
innym błędem; w takiej sytuacji wpis może nie powstać.

Historia administracyjna w bazie jest oddzielna od technicznych logów.
Zawiera zmiany ról, wykonanie diagnostyki i wynik testu poczty, z autorem,
czasem i uzasadnieniem zmiany. CLI jest oznaczone jako osobny wykonawca.
Awaria audytu po przyjęciu wiadomości przez SMTP nie zmienia wyniku
wysyłki; panel pokazuje dodatkowy komunikat, a aplikacja próbuje zapisać log.

## Katalog

Zakładka katalogu wyszukuje po tytule lub ID, pokazuje liczbę brakujących
opisów i okładek oraz pozwala odfiltrować te pozycje. To podgląd obecnych
danych. Edycja metadanych, historia importów i uruchamianie importów
z przeglądarki pozostają poza obecną wersją; dotychczasowy import CLI działa
bez zmian.

## Weryfikacja

```cmd
docker compose exec -T php php tests/admin-repository.test.php
docker compose exec -T php php tests/admin-diagnostics.test.php
docker compose exec -T php php tests/application-logger.test.php
docker compose exec -T php php tests/application-error-handler.test.php
docker compose exec -T php php tests/translations.test.php
node --test tests/admin-http.test.cjs
node --test tests/admin-protection-http.test.cjs
node --test tests/admin-responsive.test.cjs
```

Test repozytorium używa tabel tymczasowych; testy usług tworzą własne
lokalne serwery i tymczasowe pliki. Testy HTTP i przeglądarkowe tworzą
własne konta i anime oraz usuwają je wraz z własnymi wpisami audytu
w sekcji kończącej. Test HTTP wysyła jedną wiadomość do lokalnego Mailpit;
wiadomość pozostaje w jego skrzynce. Nie nadają uprawnień istniejącym kontom.
Testy wymagające bazy uruchamiaj po migracji `29`.
