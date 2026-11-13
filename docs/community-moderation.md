# Powiadomienia i moderacja

AniVibe zapisuje odczyt wiadomości, blokady, zgłoszenia oraz aktywność
antyspamową w PostgreSQL. Zmiana sesji ani ponowne logowanie nie zerują
tych danych. Funkcje wymagają zalogowania; publiczny odczyt komentarzy
i profili pozostaje dostępny.

## Istniejąca baza

Na bazie z zastosowanymi migracjami `01`–`14` wykonaj raz z katalogu
projektu w Windows CMD:

```cmd
docker compose exec -T php php -r "require 'vendor/autoload.php'; (new App\Database())->connect()->exec(file_get_contents('database/15-community-moderation.sql'));"
```

Migracja dodaje tabele `private_message_reads`, `user_blocks`,
`community_activity` i `community_reports`, flagę `users.is_moderator`
oraz datę ukrycia w komentarzach i wiadomościach. Zachowuje istniejące
konta, treści, watchlisty i rozmowy. Nowa pusta baza stosuje skrypt `15`
automatycznie. Nie uruchamiaj migracji ponownie na tej samej bazie.

## Nieprzeczytane wiadomości

- Nawigacja pokazuje łączną liczbę otrzymanych, nieprzeczytanych wiadomości;
  lista rozmów ma osobne liczniki. Własne wysłane wiadomości nie zwiększają
  licznika autora.
- Widoczna karta przeglądarki odświeża licznik przez `GET /notifications`
  co 15 sekund. Sam odczyt tego adresu ani pobranie HTML rozmowy nie oznacza
  wiadomości jako przeczytanych.
- Po otwarciu widocznej rozmowy JavaScript wysyła POST z CSRF na
  `/messages/{id}/read`, wskazując najwyższe ID wiadomości z wyświetlonej
  strony historii. Nowsze wiadomości, których nie ma w tym widoku,
  pozostają nieprzeczytane. Odczyt starszej strony nie cofa zapisanego
  postępu odczytu.
- Bez JavaScript lub przy błędzie sieci dostępny jest przycisk
  „Oznacz jako przeczytane”. Historia wiadomości nadal wymaga przycisku
  „Odśwież rozmowę”; odświeżanie licznika nie pobiera nowej treści rozmowy.

Nieuczestnik nie może potwierdzić odczytu cudzej rozmowy ani jej odczytać.
Potwierdzenie sprawdza również, czy wskazana wiadomość należy do tej rozmowy.

## Powiadomienia o odpowiedziach na komentarze

Po migracji `16` zakładka „Powiadomienia” (`/notifications/comments`) pokazuje
odpowiedzi innych użytkowników na własne główne komentarze. Ma osobny licznik,
niezależny od prywatnych wiadomości. Oba liczniki są odświeżane co 15 sekund
przez `GET /notifications`, gdy karta jest widoczna.

Pobranie listy nie oznacza powiadomień jako przeczytanych. „Otwórz odpowiedź”
wykonuje POST z CSRF i przechodzi do właściwej strony wątku. „Oznacz wszystkie
jako przeczytane” potwierdza własną migawkę ID z formularza, pozostawiając
późniejsze powiadomienia nieprzeczytane. Lista ma 20 pozycji na stronę,
a formularze działają bez JavaScript. Ukryta lub usunięta odpowiedź nie
pojawia się na liście ani w liczniku. Powiadomienia nie zawierają kopii treści.

Szczegóły, migrację i zasady zachowania wątków opisuje
[dokumentacja odpowiedzi na komentarze](comment-replies.md).

## Blokowanie użytkowników

Przycisk blokady jest dostępny na cudzym profilu i w nagłówku rozmowy.
Lista `/messages/blocked` pokazuje po 20 własnych blokad na stronę
i pozwala je usuwać.

Blokada uniemożliwia obu stronom rozpoczęcie i kontynuowanie wysyłania
wiadomości między tą parą. Dotychczasowa historia pozostaje dostępna
uczestnikom, również na potrzeby zgłoszenia. Każdy może usunąć wyłącznie
własną blokadę: jeśli druga osoba też zablokowała tę parę, wysyłanie nadal
pozostanie niedostępne. Blokada nie ukrywa publicznego profilu ani komentarzy
i nie dotyczy rozmów z pozostałymi osobami.

## Zgłoszenia i panel moderatora

„Zgłoś komentarz” jest dostępne przy cudzym komentarzu lub odpowiedzi. W rozmowie można
zgłosić wyłącznie otrzymaną wiadomość. Formularz wymaga powodu od 1 do
1000 znaków; puste, niepoprawne UTF-8 i niedozwolone znaki sterujące są
odrzucane. Jedna osoba może zgłosić dany wpis raz. Ponowienie tego samego
zgłoszenia nie tworzy duplikatu ani nie zużywa kolejnego limitu.

Zgłoszenie zachowuje kopię treści z chwili zgłaszania. Panel `/moderation`
udostępnia wyłącznie zgłoszenia publicznych komentarzy i odpowiedzi.
Prywatne wiadomości są wykluczone z zapytań, liczników i decyzji moderatora.
Podanie ID zgłoszenia wiadomości bezpośrednio w żądaniu również nie pozwala
go rozpatrzyć. Rola moderatora nie daje dostępu do cudzej rozmowy ani do
zgłoszonego fragmentu. Moderator korzysta ze swoich rozmów na takich samych
zasadach jak inni użytkownicy. Historyczne zgłoszenia wiadomości pozostają
w bazie, ale nie są ujawniane moderatorom ani obsługiwane przez ich panel.

Panel ma zakładki „Zgłoszenia komentarzy”, „Najnowsze komentarze”,
„Użytkownicy” i „Historia decyzji”. Listy zawierają po 20 pozycji; filtry
pozwalają szukać autora, anime i statusu komentarza. Można działać bez
wcześniejszego zgłoszenia. Usunięcie komentarza, wystawienie ostrzeżenia,
nałożenie i cofnięcie bana oraz odrzucenie zgłoszenia wymagają osobnego
powodu. Każda z tych decyzji tworzy wpis w historii moderacji.

Usunięcie ustawia `anime_comments.moderated_at`, zachowuje strukturę dyskusji
i rozpatruje wszystkie otwarte zgłoszenia dotyczące komentarza. Usunięty
komentarz pozostaje zastąpiony informacją o moderacji. Autor nie może
przywrócić go przez edycję; nadal może usunąć własny wpis. Decyzja i trwały
komunikat dla autora są zapisywane w tej samej transakcji. Ostrzeżenie jest
osobną decyzją i samo nie blokuje konta.

## Migracja banów i wdrożenie

Na istniejącej bazie stosującej migracje `01`–`22` wykonaj raz:

```cmd
docker compose exec -T php php -r "require 'vendor/autoload.php'; (new App\Database())->connect()->exec(file_get_contents('database/23-moderation-and-timed-bans.sql'));"
```

Migracja dodaje `user_bans`, `moderation_actions` i `moderation_notices`.
Historia zapisuje moderatora, użytkownika, typ decyzji, powód i migawkę
tytułu anime; nie kopiuje treści prywatnych wiadomości. Zawiera też unikalny
klucz formularza `request_key`: ponowienie tej samej decyzji nie dopisuje
drugiej akcji ani komunikatu. Ponowne usunięcie już ukrytego komentarza
i cofnięcie nieaktywnej blokady również nie tworzą drugiej decyzji.

Kolejność wdrożenia: najpierw kod odbierający moderatorom dostęp do prywatnych
wiadomości, następnie migracja `23`, kontrola banów przy każdym żądaniu,
a na końcu panel oraz komunikaty. Po wdrożeniu kod korzystający z nowych
tabel wymaga migracji; samego ograniczenia dostępu do wiadomości można użyć
wcześniej. Nie uruchamiaj migracji ponownie na tej samej bazie.

## Bany czasowe

Ban blokuje korzystanie z konta na godzinę, dobę, 3, 7 albo 30 dni.
Moderator nie może zablokować siebie ani innego moderatora. Nadanie roli
nadal odbywa się wyłącznie skryptem CLI. Decyzje ponownie sprawdzają rolę
i stan konta w bazie, również gdy formularz został otwarty przed odebraniem
uprawnień.

Po poprawnej weryfikacji hasła zbanowany użytkownik trafia na
`/account/banned`, gdzie widzi powód i termin końca blokady. Kontrola działa
przy każdym żądaniu sesji, również otwartej przed nałożeniem bana.
Zbanowane konto może przeglądać własne decyzje (`/account/notices`),
potwierdzać ich przeczytanie, zmienić język i się wylogować. Pozostałe strony
przekierowują na ekran blokady; żądania POST i żądania JSON otrzymują `403`,
a odpowiedź JSON zawiera adres przekierowania. Po wylogowaniu publiczne
strony pozostają dostępne.

Zapisy odbywają się w transakcji ze sprawdzeniem blokady i synchronizacją
z nakładaniem bana. Żądanie równoległe do decyzji nie może zapisać danych
po jej zatwierdzeniu bez ponownej kontroli. Termin jest porównywany
z `clock_timestamp()` bazy; wygaśnięcie i cofnięcie działają przy następnym
żądaniu bez wylogowania i bez zadania okresowego. Historia pozostaje
zachowana po wygaśnięciu lub cofnięciu blokady.

## Trwałe komunikaty dotyczące konta

Po logowaniu oczekujące komunikaty są widoczne w interfejsie; wszystkie
decyzje dotyczące konta można odczytać pod `/account/notices`.
Usunięcie komentarza, ostrzeżenie, nałożenie i cofnięcie bana tworzą
komunikat w tej samej transakcji co decyzja. Przeczytanie zapisuje datę
`read_at`, ale nie usuwa historii. Lista i potwierdzanie zawsze korzystają
z ID zalogowanego właściciela; przekazanie cudzego ID konta lub powiadomienia
nie daje do niego dostępu. Wszystkie etykiety i szablony komunikatów
obsługują polski, angielski, niemiecki, francuski, hiszpański i rosyjski.
Tytuły anime i powody pozostają w oryginalnym brzmieniu oraz są escapowane
przy wyświetlaniu.

### Nadanie roli moderatora

Domyślnie żadne konto nie ma tej roli. Nadaj ją świadomie wybranemu kontu
poleceniem CLI, używając ID widocznego w adresie jego publicznego profilu.
Poniższy przykład dotyczy konta ID `123` — zastąp je właściwą liczbą:

```cmd
docker compose exec -T php php scripts/set-moderator.php 123
```

Przy kolejnym wejściu na stronę konto zobaczy zakładkę „Moderacja”.
Aby odebrać rolę:

```cmd
docker compose exec -T php php scripts/set-moderator.php 123 --revoke
```

Skrypt działa wyłącznie z CLI. Rejestracja i edycja profilu nie pozwalają
samodzielnie nadać sobie roli. Dostęp i każda decyzja moderatora sprawdzają
aktualne uprawnienia w bazie.

## Ograniczenie spamu

Limity dotyczą konta i obowiązują we wszystkich jego sesjach:

| Operacja | Limit |
| --- | --- |
| Wysłanie wiadomości, łącznie do wszystkich odbiorców | 40 w ciągu 60 sekund |
| Dodanie komentarza lub odpowiedzi oraz edycja obu rodzajów wpisów, liczone razem | 15 w ciągu 60 sekund |
| Nowe zgłoszenie | 5 w ciągu 600 sekund |
| Zmiana lub cofnięcie reakcji na komentarz lub odpowiedź | 60 faktycznych zmian w ciągu 60 sekund |
| Ponowienie identycznej treści komentarza, wiadomości lub zgłoszenia | 30 sekund odstępu |

Nieudane zapisy nie zużywają limitu. Przekroczenie zwraca HTTP `429`
z nagłówkiem `Retry-After` i informacją, po ilu sekundach można spróbować
ponownie. Formularz zachowuje wpisaną treść. Usuwanie własnego komentarza,
blokowanie i rozpatrywanie zgłoszeń nie podlegają tym limitom.

Reakcje wymagają migracji `17`. Ponowienie tego samego głosu jest idempotentne
i nie zużywa limitu. Zmiana like na dislike lub cofnięcie głosu działa od razu;
do reakcji nie stosuje się blokady duplikatów przez 30 sekund.
Szczegóły opisuje [dokumentacja reakcji](comment-reactions.md).

Walidacja, CSRF, uprawnienia uczestników i blokady są sprawdzane po stronie
serwera. Zapis treści i zajęcie limitu są jedną transakcją. Blokady wierszy
użytkowników chronią przed obchodzeniem limitów równoległymi żądaniami
i przed wysłaniem nowej wiadomości po zatwierdzonej blokadzie.

## Sprawdzenie

```cmd
docker compose exec -T php php tests/community-repository.test.php
docker compose exec -T php php tests/moderation-repository.test.php
docker compose exec -T php php tests/moderation-concurrency.test.php
docker compose exec -T php php tests/translations.test.php
node --test --test-concurrency=1 tests/community-http.test.cjs
node --test --test-concurrency=1 tests/moderation-http.test.cjs
node --test tests/moderation-responsive.test.cjs
node --test tests/notifications.test.cjs
```

Test repozytorium używa tabel tymczasowych i nie zmienia danych aplikacji.
Test HTTP tworzy losowe konta testowe i prywatne wiadomości; usuwa własne
komentarze testowe i odbiera tymczasową rolę moderatora w sekcji kończącej.
Konta i prywatne rozmowy z dotychczasowego testu społeczności pozostają
w lokalnej bazie. Nowy test HTTP moderacji tworzy własny tytuł anime oraz
losowe konta z jednoznacznymi adresami `moderation-…@example.test`
i usuwa je w sekcji kończącej po ponownym sprawdzeniu ID i adresu e-mail.
Testy HTTP korzystające z najnowszych wpisów katalogu uruchamiaj kolejno,
aby sprzątanie danych jednego zestawu nie usunęło tytułu wybranego przez drugi.

Sześć scenariuszy współbieżności używa własnego schematu PostgreSQL,
oddzielnych połączeń i procesów. Weryfikują kolejność zapisu i bana,
oczekujące zapisy po blokadzie, snapshot importu, zwolnienie blokad po
zatwierdzeniu i wycofaniu oraz równoległe niedozwolone próby banowania
moderatorów. Schemat jest usuwany po zakończeniu testu. Test responsywności
korzysta z lokalnego Chrome lub Edge; nie wymaga nowej zależności projektu.

Testy obejmują odebraną rolę, sesję otwartą przed banem, wszystkie kategorie
zapisów oraz żądania JSON, wygaśnięcie i cofnięcie blokady, CSRF,
powtórzenie decyzji, odczyt cudzych komunikatów i zgłoszenie prywatnej
wiadomości przekazane bezpośrednio przez ID.

Przy kontroli widoków sprawdź jasny i ciemny motyw przy 375 i 1280 px,
obsługę klawiatury, odczyt starszej strony historii przy nowszej wiadomości,
blokadę z obu stron, zgłoszenie wiadomości i komentarza oraz odmowę dostępu
z konta nieuczestniczącego w rozmowie i bez roli moderatora. Panel moderacji,
ekran bana i historię komunikatów sprawdź także przy szerokości 375 px oraz
po zmianie języka w trakcie blokady.
