# Like i dislike komentarzy

Komentarze i odpowiedzi mają osobne liczniki like i dislike, widoczne także
bez logowania. Zalogowany użytkownik widzi własny wybrany głos i może go
zmienić lub cofnąć. Dla jednej pary użytkownik–wpis istnieje najwyżej jeden głos.

## Istniejąca baza

Na bazie z zastosowanymi migracjami `01`–`16` wykonaj raz z katalogu projektu
w Windows CMD:

```cmd
docker compose exec -T php php -r "require 'vendor/autoload.php'; (new App\Database())->connect()->exec(file_get_contents('database/17-comment-reactions.sql'));"
```

Migracja dodaje tabelę `comment_reactions` oraz rodzaj aktywności `reaction`
do mechanizmu antyspamowego. Zachowuje dotychczasowe komentarze, odpowiedzi,
konta i dane użytkowników. Nowa, pusta baza wykonuje skrypt `17` automatycznie.
Nie uruchamiaj go ponownie na tej samej bazie.

## Oddanie, zmiana i cofnięcie głosu

| Wartość żądania | Żądany stan |
| --- | --- |
| `1` | Like |
| `-1` | Dislike |
| `0` | Brak własnego głosu |

Formularz wysyła żądany stan, dzięki czemu ponowienie żądania pozostaje
idempotentne: drugi like tej samej osoby nie dodaje kolejnego like ani nie
cofa poprzedniego. Zmiana z like na dislike usuwa poprzedni głos i ustawia
nowy w jednej operacji. Cofnięcie usuwa wyłącznie własny głos; użytkownik
nie może modyfikować reakcji innych osób.

Reagować można na cudzy aktywny komentarz lub odpowiedź. Własne wpisy oraz
wpisy usunięte albo ukryte przez moderatora nie przyjmują głosów. Aktywna
odpowiedź zachowana pod usuniętym głównym komentarzem nadal przyjmuje reakcje.
Liczniki dotyczą danego wpisu, nie całego wątku.

Zmiany wymagają POST z poprawnym CSRF i zalogowanego konta. Autor głosu
pochodzi z sesji, a serwer sprawdza również anime i ID wpisu. Niepoprawne
wartości głosu oraz sfałszowane formularze nie pozwalają zmienić cudzych
danych. Formularze działają bez JavaScript, z widocznym fokusem klawiatury
w jasnym i ciemnym motywie.

## Ograniczenie spamu

Konto może wykonać 60 faktycznych zmian reakcji na 60 sekund. Limit jest
wspólny dla wszystkich komentowanych anime, głównych komentarzy i odpowiedzi
oraz wszystkich sesji tego konta. Dotyczy oddania, zmiany i cofnięcia głosu.

Ponowienie już ustawionego stanu oraz cofnięcie nieistniejącego głosu nie
zużywają limitu. Nieudany zapis również nie zużywa limitu. Reakcje nie mają
30-sekundowej blokady powtarzania treści: można od razu zmienić decyzję lub
cofnąć głos, dopóki nie przekroczono limitu faktycznych zmian.

Odmowa z powodu limitu zwraca HTTP `429`, nagłówek `Retry-After` i komunikat
z czasem oczekiwania. Zapis reakcji i rozliczenie zmiany są atomowe. Limit
reakcji jest niezależny od wspólnego limitu 15 zapisów komentarzy, odpowiedzi
i ich edycji na 60 sekund.

## Sprawdzenie

Przy kontroli funkcji sprawdź oddanie głosu, ponowienie tego samego żądania,
zmianę like na dislike i cofnięcie. Drugie konto powinno widzieć aktualne
liczniki, zachowując własny niezależny głos. Sprawdź także odmowę reakcji
na własny, usunięty i ukryty wpis, reakcję na zachowaną odpowiedź po usunięciu
głównego komentarza, CSRF, działanie bez JavaScript oraz układ i fokus
klawiatury przy 375 i 1280 px w obu motywach.
