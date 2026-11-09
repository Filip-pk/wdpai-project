# Odpowiedzi na komentarze

Komentarze pod anime tworzą wątki z jednym poziomem odpowiedzi. Wątki są
publiczne, a dodawanie, edycja i usuwanie wymagają zalogowania. Dotychczasowe
komentarze stają się głównymi wpisami bez zmiany ich treści ani autorów.

## Istniejąca baza

Na bazie z zastosowanymi migracjami `01`–`15` wykonaj raz z katalogu projektu
w Windows CMD:

```cmd
docker compose exec -T php php -r "require 'vendor/autoload.php'; (new App\Database())->connect()->exec(file_get_contents('database/16-comment-replies.sql'));"
```

Migracja dodaje `parent_id` i `deleted_at` do `anime_comments`, reguły
rodzica wątku oraz tabelę `comment_reply_notifications`. Zachowuje istniejące
dane. Nowa, pusta baza wykonuje skrypt `16` automatycznie. Nie uruchamiaj
go ponownie na tej samej bazie.

## Korzystanie

- Główna lista anime pokazuje po 10 głównych komentarzy na stronę,
  od najnowszych. Liczba odpowiedzi i link dyskusji odnoszą się do danego
  komentarza; odpowiedzi nie zmniejszają liczby głównych wpisów na stronie.
- „Odpowiedz” otwiera `/anime/{id}/comments/{commentId}`. Strona dyskusji
  pokazuje główny komentarz i po 10 odpowiedzi na stronę, chronologicznie
  od najstarszych. Przy tej samej dacie decyduje rosnące ID.
- Odpowiadać można na aktywny główny komentarz tego samego anime. Nie można
  tworzyć kolejnych poziomów zagnieżdżenia ani dopisywać do komentarza
  usuniętego lub ukrytego przez moderatora.
- Odpowiedź ma od 1 do 2000 znaków i jest zwykłym tekstem. Obowiązują te same
  zasady walidacji, edycji własnej treści, usuwania i zgłaszania jak przy
  [komentarzach głównych](anime-comments.md).
- Zapis nowej odpowiedzi przechodzi do jej strony i pozycji we wątku.
  Błędy zachowują wpisany tekst. Formularze, potwierdzenia i paginacja
  działają bez JavaScript.

Dodanie komentarza, dodanie odpowiedzi i edycje obu rodzajów wpisów korzystają
ze wspólnego limitu konta: 15 zapisów na 60 sekund. Identycznej treści nie
można powtórzyć w tym rodzaju aktywności przez 30 sekund. Nieudany zapis nie
zużywa limitu; odmowa zwraca `429` i `Retry-After`. Usuwanie własnych wpisów
nie zużywa tego limitu.

## Usunięcie i moderacja

Usunięcie głównego komentarza z odpowiedziami zastępuje jego treść informacją
o usunięciu. Zachowuje odpowiedzi innych osób i ich możliwość edycji,
usunięcia lub zgłoszenia. Autor nie może przywrócić usuniętego wpisu przez
edycję. Po usunięciu ostatniej odpowiedzi znika również pusty, usunięty wątek.
Komentarz bez odpowiedzi oraz pojedyncza odpowiedź są usuwane bez pozostawiania
pustej karty.

Ukrycie głównego komentarza przez moderatora również zachowuje istniejące
odpowiedzi i zatrzymuje dopisywanie nowych. Ukrycie jednej odpowiedzi
zastępuje tylko jej treść informacją o moderacji. Zgłoszenia zachowują własną
kopię zgłoszonego wpisu, zgodnie z [zasadami moderacji](community-moderation.md).

## Powiadomienia autora

Nowa odpowiedź innej osoby tworzy powiadomienie dla autora głównego komentarza.
Odpowiedź na własny komentarz nie powiadamia autora. Edycja nie tworzy drugiego
powiadomienia, a unikalność ID odpowiedzi zapobiega duplikatom.

Zakładka „Powiadomienia” prowadzi do `/notifications/comments` i pokazuje
po 20 wpisów na stronę. Zawiera nazwę autora odpowiedzi, tytuł anime,
datę i status odczytu; nie przechowuje kopii treści odpowiedzi. Ukryte lub
usunięte odpowiedzi nie pojawiają się na liście ani w liczniku.

Licznik powiadomień jest niezależny od nieprzeczytanych prywatnych wiadomości.
Widoczna karta odświeża oba co 15 sekund. Pobranie listy i odświeżenie licznika
nie oznaczają odczytu. Przycisk „Otwórz odpowiedź” wysyła POST z CSRF,
oznacza własne powiadomienie i przechodzi na stronę z właściwą odpowiedzią.

„Oznacz wszystkie jako przeczytane” wysyła najwyższe ID własnego powiadomienia
z chwili wyświetlenia listy. Nowsze powiadomienia pozostają nieprzeczytane.
Inne konto nie może otworzyć ani potwierdzić odczytu cudzych powiadomień.
Oba przyciski działają także bez JavaScript. Powiadomienia te są wewnątrz
aplikacji; nie wysyłają wiadomości e-mail.

## Sprawdzenie

```cmd
docker compose exec -T php php tests/comment-replies-repository.test.php
docker compose exec -T php php tests/reply-notification-repository.test.php
node --test --test-concurrency=1 tests/comments-http.test.cjs tests/comment-replies-http.test.cjs tests/notifications.test.cjs
```

Testy repozytoriów korzystają z tabel tymczasowych, niezależnych sekwencji
i wycofywanych transakcji. Test HTTP tworzy własne losowe konta oraz wpisy
testowe. Jak w pozostałych testach HTTP projektu konta pozostają w lokalnej
bazie. Przy kontroli widoków sprawdź długie nazwy i treści, jasny i ciemny
motyw przy 375 i 1280 px oraz fokus klawiatury na formularzach i paginacji.
