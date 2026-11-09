# Komentarze pod anime

Komentarze są zapisywane w PostgreSQL i widoczne na stronie anime również
bez logowania. Zalogowany użytkownik może dodać wpis oraz edytować i usuwać
wyłącznie własne komentarze i odpowiedzi. Avatar i nazwa autora otwierają `/users/{id}`.

## Istniejąca baza

Na istniejącej instalacji wykonaj raz w Windows CMD, z katalogu projektu:

```cmd
docker compose exec -T php php -r "require 'vendor/autoload.php'; (new App\Database())->connect()->exec(file_get_contents('database/13-anime-comments.sql'));"
```

Migracja dodaje tabelę `anime_comments`, klucze obce i indeksy, zachowując
dotychczasowe dane. Nowa, pusta baza uruchamia skrypt `13` automatycznie.
Konfiguracja Dockera i zależności pozostają bez zmian.

## Korzystanie

- Pod szczegółami anime znajduje się sekcja „Komentarze”. Po zalogowaniu
  dostępny jest formularz dodawania wpisu.
- Treść musi mieć od 1 do 2000 znaków i nie może zawierać samych białych
  znaków ani niedozwolonych znaków sterujących. Nowe linie są zachowywane;
  skrajne spacje i nowe linie są usuwane przed zapisem.
- Komentarze są zwykłym tekstem. Znaczniki HTML są wyświetlane jako tekst.
- Lista pokazuje 10 głównych komentarzy na stronę, od najnowszych; przy tej samej dacie
  decyduje malejące ID. Edycja nie zmienia pozycji komentarza na liście.
- „Edytuj” otwiera formularz z dotychczasową treścią. „Anuluj edycję” wraca
  do listy. Zapis zachowuje stronę; błędy zachowują wpisaną treść.
- „Usuń” rozwija potwierdzenie, które trzeba zatwierdzić osobnym przyciskiem.
  Komentarz bez odpowiedzi jest usuwany; jeśli ma odpowiedzi, pozostaje
  informacja o usunięciu, zachowująca cudze wpisy. Po usunięciu ostatniego
  wpisu ze strony aplikacja wraca na istniejącą stronę.

Dodanie komentarza przekierowuje na pierwszą stronę. Linki paginacji i zmiany
wracają do sekcji `#comments`. Formularze i potwierdzenie działają bez JavaScript.

## Odpowiedzi i wątki

Przycisk „Odpowiedz” otwiera `/anime/{id}/comments/{commentId}`. Dyskusja ma
jeden poziom odpowiedzi, po 10 na stronę od najstarszych. Odpowiedzi nie
zajmują miejsc na głównej liście komentarzy. Ich autor może je edytować
i usuwać, a inni zalogowani użytkownicy mogą je zgłaszać.

Nie można dopisać odpowiedzi do innej odpowiedzi ani do komentarza usuniętego
lub ukrytego przez moderatora. Istniejące odpowiedzi pozostają dostępne.
Po usunięciu ostatniej odpowiedzi do usuniętego komentarza znika również
pozostawiony przez niego pusty wątek.

Autor głównego komentarza otrzymuje powiadomienie o cudzej odpowiedzi;
odpowiedź na własny wpis nie tworzy powiadomienia. Wymaganą migrację `16`,
odczyt i paginację powiadomień opisuje
[dokumentacja odpowiedzi na komentarze](comment-replies.md).

## Like i dislike

Komentarze i odpowiedzi mają publiczne liczniki reakcji. Zalogowany użytkownik
może oddać jeden głos na cudzy aktywny wpis, zmienić go albo cofnąć. Własne,
usunięte i ukryte wpisy nie przyjmują głosów. Reakcje mają oddzielny limit
60 faktycznych zmian na 60 sekund; ponowienie tego samego głosu nie zwiększa
licznika ani nie zużywa limitu. Migrację `17` i zasady opisuje
[dokumentacja reakcji na komentarze](comment-reactions.md).

Zalogowany użytkownik może zgłosić cudzy komentarz, podając powód. Moderator
może odrzucić zgłoszenie lub ukryć wpis; wówczas zamiast treści pojawia się
informacja o usunięciu przez moderację. Autor może nadal usunąć własny wpis,
ale nie może przywrócić jego treści edycją. Dodawanie komentarzy, odpowiedzi
i edycja obu rodzajów wpisów wspólnie
podlegają limitowi 15 zapisów na 60 sekund oraz blokadzie duplikatów przez
30 sekund. Szczegóły i migrację `15` opisuje
[dokumentacja powiadomień i moderacji](community-moderation.md).

## Ochrona danych

Zapis jest obsługiwany wyłącznie przez POST i wymaga tokenu CSRF.
Autor pochodzi z sesji; przesłane pola `userId` i `user_id` nie zmieniają
właściciela. Edycja i usunięcie sprawdzają jednocześnie konto, anime i ID wpisu.
Cudzy lub nieistniejący komentarz zwraca 404. Odczyt nie udostępnia e-maila,
hasła ani ustawień autora. SQL jest parametryzowany, a treść i nazwy są
zabezpieczone przy wyświetlaniu przez `htmlspecialchars()`.

Komentarze nie zmieniają watchlist, prywatnych ocen, kolekcji ani EXP.
Usunięcie konta lub anime usuwa powiązane komentarze przez klucze obce.

## Sprawdzenie

```cmd
docker compose exec -T php php tests/comment-repository.test.php
node --test tests/comments-http.test.cjs
```

Test repozytorium korzysta z tabel tymczasowych, niezależnych sekwencji
i wycofywanej transakcji. Test HTTP tworzy dwa losowe konta i komentarze;
w sekcji kończącej test usuwa własne wpisy przez endpointy aplikacji.
Konta pozostają w lokalnej bazie, zgodnie z pozostałymi testami HTTP projektu.

Sprawdź także oba motywy przy szerokościach 375 i 1280 px, długie nazwy
i treści oraz obsługę formularzy, potwierdzenia i paginacji klawiszem Tab.
