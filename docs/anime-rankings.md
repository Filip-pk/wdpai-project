# Rankingi anime

Publiczna strona `/anime/ranking` pokazuje najlepiej oceniane i najczęściej
oglądane anime w AniVibe. Można ją przeglądać bez logowania. Dotychczasowy
ranking użytkowników i EXP pozostaje pod adresem `/ranking` i wymaga logowania.

## Najlepiej oceniane

Tryb `best` korzysta ze średniej ocen użytkowników AniVibe oraz liczby ocen.
Domyślnie uwzględnia tytuły mające co najmniej 5 głosów. Minimalną liczbę
można zmienić w formularzu w zakresie od 1 do 1 000 000.

Kolejność wyznaczają:

1. Średnia ocen AniVibe, malejąco, obliczona z pełną precyzją.
2. Liczba ocen, malejąco.
3. Tytuł bez rozróżniania wielkości liter, a następnie ID anime.

Widoczna średnia jest formatowana do dwóch miejsc po przecinku. Tytuły
z takim samym wyświetlanym wynikiem mogą mieć różne dokładne średnie,
które decydują o ich kolejności. Anime bez ocen lub poniżej wybranego
progu nie trafia do tego rankingu.

Ocena źródłowa z importowanego katalogu pozostaje osobną informacją.
Nie zastępuje średniej AniVibe i nie decyduje o pozycji w rankingu.

## Najczęściej oglądane

Tryb `watched` liczy konta, które mają dany tytuł na watchliście ze statusem
„Oglądam” albo „Ukończone”. Jedno konto liczy się raz przy jednym anime.
Statusy „Planowane”, „Wstrzymane” i „Porzucone” nie zwiększają tego wyniku.
Anime bez takich oglądających nie pojawia się na liście.

Kolejność wyznacza liczba oglądających, malejąco. Przy remisie decydują
średnia ocen AniVibe, liczba ocen, tytuł i ID anime. Tytuł może znaleźć się
na tej liście bez żadnej oceny. Minimalna liczba głosów nie filtruje tego
trybu; odpowiadające jej pole formularza jest wyłączone.

## Filtry i strony

| Parametr | Znaczenie |
| --- | --- |
| `mode` | `best` lub `watched`; domyślnie `best` |
| `genre` | ID jednego gatunku; brak wartości oznacza wszystkie gatunki |
| `year` | Dokładny rok premiery od 1900 do 2200; pole jest opcjonalne |
| `min_votes` | Minimalna liczba ocen w trybie `best`; domyślnie 5 |
| `page` | Numer strony; domyślnie 1 |

Przykładowe adresy:

```text
/anime/ranking?mode=best&min_votes=5
/anime/ranking?mode=watched&year=2024
```

Lista pokazuje po 20 anime na stronie. Paginacja zachowuje wybrane kryteria,
a numer przekraczający liczbę stron zostaje ograniczony do ostatniej strony.
Pozycje są kolejnymi numerami na całej przefiltrowanej liście; remisy nie
otrzymują wspólnego numeru. Stała kolejność rozstrzygania remisów pozwala
przeglądać kolejne strony bez przypadkowej zmiany kolejności tych samych danych.

Niepoprawne parametry, w tym tablice zamiast pojedynczych wartości,
niepoprawny UTF-8 i liczby poza zakresem, zwracają HTTP `400` z komunikatem.
Dotyczy to również niepoprawnej wartości `min_votes` przesłanej ręcznie
w trybie `watched`, mimo że poprawna wartość nie wpływa w nim na wyniki.
Poprawne ID nieistniejącego gatunku zwraca pustą listę z HTTP `200`.

## Dane i prywatność

Wyniki są obliczane przy odczycie z istniejących tabel `user_anime_ratings`
i `user_watchlist`. Funkcja nie wymaga nowej migracji ani osobnego cache.
Zapis, zmiana, usunięcie lub import własnej oceny wpływają na kolejne odczyty
rankingu. Zmiana statusu na watchliście aktualizuje liczbę oglądających.
Przy zmieniających się danych pozycje mogą zmienić się między wejściami na strony.

Publiczny ranking pokazuje wyłącznie zbiorcze wyniki. Nie udostępnia listy
głosujących, ich adresów e-mail ani ocen przypisanych do kont. Zapis własnej
oceny i zmiana watchlisty nadal wymagają logowania oraz ochrony CSRF.
Szczegóły agregacji opisuje [dokumentacja ocen społeczności](community-ratings.md).

## Sprawdzenie

```cmd
docker compose exec -T php php tests/anime-ranking-repository.test.php
node --test tests/anime-rankings-http.test.cjs
```

Test repozytorium używa tabel tymczasowych. Test HTTP korzysta z losowych
kont oraz kontrolowanych nowych anime i gatunku; w sekcji kończącej usuwa
wyłącznie dane utworzone jako jego fixture, po sprawdzeniu ich identyfikatorów
i nazw. Nie usuwa istniejących anime ani ocen innych tytułów.
Losowe konta testowe pozostają w lokalnej bazie, zgodnie z pozostałymi
testami HTTP projektu.

Przy kontroli widoków sprawdź oba rodzaje rankingu, filtry, zmianę progu,
paginację, stan pustej listy oraz komunikaty walidacji. Sprawdź też obsługę
klawiatury i układ przy 375 oraz 1280 px w jasnym i ciemnym motywie.
