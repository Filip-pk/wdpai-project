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

„Zgłoś komentarz” jest dostępne przy cudzym komentarzu. W rozmowie można
zgłosić wyłącznie otrzymaną wiadomość. Formularz wymaga powodu od 1 do
1000 znaków; puste, niepoprawne UTF-8 i niedozwolone znaki sterujące są
odrzucane. Jedna osoba może zgłosić dany wpis raz. Ponowienie tego samego
zgłoszenia nie tworzy duplikatu ani nie zużywa kolejnego limitu.

Zgłoszenie zachowuje kopię treści z chwili zgłaszania. Panel `/moderation`
pokazuje po 20 oczekujących zgłoszeń na stronę, powód, zgłaszającego
i autora. W przypadku prywatnej wiadomości moderator widzi wyłącznie
zgłoszony fragment; rola nie daje dostępu do całej cudzej rozmowy.

Moderator może odrzucić zgłoszenie lub usunąć wskazaną treść z widoków.
Usunięcie zastępuje komentarz lub wiadomość informacją o moderacji,
również w podglądzie ostatniej wiadomości, i rozpatruje wszystkie otwarte
zgłoszenia dotyczące tego wpisu. Autor nie może przywrócić ukrytego
komentarza przez edycję; nadal może usunąć własny wpis. Zapisana kopia
zgłoszenia pozostaje w bazie po usunięciu oryginalnego komentarza.

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
| Dodanie lub edycja komentarza, liczone razem | 15 w ciągu 60 sekund |
| Nowe zgłoszenie | 5 w ciągu 600 sekund |
| Ponowienie identycznej treści w danym rodzaju aktywności | 30 sekund odstępu |

Nieudane zapisy nie zużywają limitu. Przekroczenie zwraca HTTP `429`
z nagłówkiem `Retry-After` i informacją, po ilu sekundach można spróbować
ponownie. Formularz zachowuje wpisaną treść. Usuwanie własnego komentarza,
blokowanie i rozpatrywanie zgłoszeń nie podlegają tym limitom.

Walidacja, CSRF, uprawnienia uczestników i blokady są sprawdzane po stronie
serwera. Zapis treści i zajęcie limitu są jedną transakcją. Blokady wierszy
użytkowników chronią przed obchodzeniem limitów równoległymi żądaniami
i przed wysłaniem nowej wiadomości po zatwierdzonej blokadzie.

## Sprawdzenie

```cmd
docker compose exec -T php php tests/community-repository.test.php
node --test --test-concurrency=1 tests/community-http.test.cjs
node --test tests/notifications.test.cjs
```

Test repozytorium używa tabel tymczasowych i nie zmienia danych aplikacji.
Test HTTP tworzy losowe konta testowe i prywatne wiadomości; usuwa własne
komentarze testowe i odbiera tymczasową rolę moderatora w sekcji kończącej.
Konta i prywatne rozmowy pozostają w lokalnej bazie, tak jak w pozostałych
testach HTTP projektu.

Przy kontroli widoków sprawdź jasny i ciemny motyw przy 375 i 1280 px,
obsługę klawiatury, odczyt starszej strony historii przy nowszej wiadomości,
blokadę z obu stron, zgłoszenie wiadomości i komentarza oraz odmowę dostępu
z konta nieuczestniczącego w rozmowie i bez roli moderatora.
