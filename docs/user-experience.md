# Poziomy, EXP i ranking TOP 100

Każdy zapisany obejrzany odcinek daje 5 EXP. Ukończenie osobnej pozycji
w katalogu — sezonu, filmu lub innego anime — daje dodatkowo 100 EXP,
niezależnie od liczby odcinków. Film z jednym odcinkiem daje więc 105 EXP,
a sezon 12-odcinkowy 160 EXP. Przy nieznanej liczbie odcinków liczy się
zapisany postęp oraz bonus za status „Ukończone”.

Poziom 1 zaczyna się od 0 EXP. Pierwszy awans wymaga 50 EXP, a koszt każdego
następnego rośnie o 50: 50, 100, 150, 200 itd. Łączne progi poziomów to
0, 50, 150, 300 i 500 EXP. Przy 160 EXP użytkownik ma poziom 3 i pasek
10/150 EXP do poziomu 4.

## Naliczanie

Punkty wynikają z zapisanego postępu, a nie z liczby kliknięć. Powtórne
zapisanie takiej samej liczby odcinków lub ponowne oznaczenie tytułu jako
ukończonego nie powiela EXP. Korekta liczby odcinków lub cofnięcie statusu
ukończenia przelicza wkład tego anime; poziom może wtedy spaść.

Usunięcie anime z watchlisty zachowuje już naliczony EXP. Ponowne dodanie
nie przyznaje go drugi raz i nie nadpisuje zapamiętanego wkładu. Dopiero
zapis nowego postępu lub statusu jawnie koryguje punkty tego tytułu.
Oceny i przypisania do kolekcji nie wpływają na EXP.

Zapis postępu watchlisty oraz odpowiadającego mu wkładu EXP jest atomowy.
Przeglądarka nie może ustawić punktów, poziomu ani właściciela: konto
pochodzi z sesji, a każda zmiana postępu wymaga poprawnego tokenu CSRF.
Postęp jest deklarowany przez użytkownika; aplikacja nie potwierdza
faktycznego obejrzenia odcinków.

## Ranking

`GET /ranking` wymaga zalogowania i pokazuje aktualne maksymalnie 100 kont,
uporządkowanych malejąco według całkowitego EXP. Przy remisie konta mają
wspólne miejsce; kolejność wierszy rozstrzyga ID użytkownika. Limit dotyczy
liczby kont, więc przy remisie na granicy nadal jest najwyżej 100 wierszy.

Własna pozycja jest pokazana osobno, również poza TOP 100. Wiersz własnej
pozycji nie rozszerza listy do 101 kont. Ranking odczytuje stan bazy przy
każdym otwarciu strony; nie jest archiwalnym zestawieniem.

Ranking ujawnia tylko nazwę konta, avatar, poziom, EXP i miejsce. Prywatne
listy, kolekcje, oceny i adresy e-mail nie są publikowane.

## Migracja istniejącej bazy

Po migracjach `01`–`10` wykonaj raz w Windows CMD:

```cmd
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\11-user-experience.sql
```

Migracja zachowuje dotychczasowe dane i uwzględnia aktualny zapisany postęp
istniejących watchlist. Usuniętych wcześniej wpisów nie da się odtworzyć.
Pusta baza wykonuje skrypt automatycznie wraz z pozostałymi migracjami.

## Sprawdzenie

```cmd
docker compose exec -T php php tests/experience-service.test.php
docker compose exec -T php php tests/experience-repository.test.php
node --test tests/experience-http.test.cjs
```

Testy HTTP tworzą dwa losowe konta testowe i zachowują je w lokalnej bazie.
Sprawdzają naliczanie, progi poziomów, korekty, odporność na ponowne dodanie,
izolację kont, CSRF, niezależność kolekcji i ocen oraz aktualny ranking.
Dokładny limit 100 przy większej liczbie kont jest sprawdzany izolowanym
testem repozytorium.
