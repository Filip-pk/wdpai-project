# Rekomendacje z watchlisty

Pulpit (`/dashboard`) proponuje do sześciu anime dla zalogowanego użytkownika.
Mechanizm nie korzysta z AI, Ollamy ani zewnętrznych zapytań. Wyciąga preferencje
z całej bieżącej watchlisty i własnych ocen tytułów na tej liście.

## Profil preferencji

Podstawowe wagi statusów to: ukończone `3`, oglądane `2`, planowane `1`,
wstrzymane `0,5`, porzucone `−2`. Prywatna ocena modyfikuje sygnał:

- `1–4`: ujemna waga `−|waga statusu| × (5 − ocena) / 4`;
- `5–6`: połowa wagi statusu;
- `7–10`: waga statusu pomnożona przez `1 + (ocena − 6) / 4`;
- brak oceny: podstawowa waga statusu.

Porzucone anime zawsze daje `−2`, również gdy ma wysoką ocenę. Waga każdego
anime dzieli się równo między jego gatunki, aby duża liczba tagów nie zwiększała
siły pojedynczego wpisu. Osobno sumują się preferencje formatów (TV, film itd.).
Puste i nieznane formaty są pomijane; `TV Special` jest traktowany jak `SPECIAL`.
W obu grupach dzielimy wagi przez sumę ich wartości bezwzględnych,
zachowując sygnały ujemne. Pulpit pokazuje trzy najsilniejsze dodatnie gatunki.

## Dobór propozycji

Wykluczamy wszystkie anime z własnej watchlisty, niezależnie od statusu.
Kandydat musi mieć dodatnią sumę wag dopasowanych gatunków. Kolejność określa:

```text
90 × suma wag gatunków / sqrt(liczba gatunków kandydata)
    + 10 × waga formatu
```

Normalizacja liczby gatunków ogranicza premiowanie szeroko otagowanych anime.
Remisy rozstrzyga ocena źródłowa malejąco (brak na końcu), tytuł i ID.
Oceny społeczności są wyświetlane na kartach osobno i nie budują prywatnego
profilu. Przy propozycji pokazujemy maksymalnie dwa dodatnio dopasowane gatunki.
Punktacja jest heurystyką, nie procentem pewności ani przewidywaną oceną.

Bez dodatnich preferencji pokazujemy jawnie opisane propozycje startowe według
oceny źródłowej. Pomijamy wtedy anime zawierające gatunki z ujemną wagą profilu.
Przy braku pasujących nowych anime wyświetlamy pusty stan i odnośniki do katalogu
oraz watchlisty. Goście widzą wyłącznie propozycje startowe i zachętę do logowania.

## Dane i ograniczenia

Identyfikator użytkownika pochodzi wyłącznie z sesji. Profil nie korzysta z
watchlist ani prywatnych ocen innych kont. Każde wejście na pulpit przelicza
bieżące dane, w tym zmiany i import watchlisty oraz ocen. Nie zapisujemy profilu
ani rekomendacji do nowych tabel; nie ma migracji, zależności ani cache.
Istniejące operacje zapisu zachowują autoryzację i CSRF.

To dopasowanie metadanych: jakość zależy od uzupełnienia gatunków i własnej
watchlisty. Nie analizuje fabuły, nie rozpoznaje franczyz i nie korzysta z
podobieństwa między kontami. Format ma rolę pomocniczą; samo jego dopasowanie
nie wystarcza do personalizacji.

## Sprawdzenie

```cmd
docker compose exec -T php php tests/recommendation-service.test.php
docker compose exec -T php php tests/recommendation-repository.test.php
node --test tests/recommendations-http.test.cjs
```

Test repozytorium używa wyłącznie tabel tymczasowych i wycofuje transakcję.
Test HTTP tworzy losowe konta oraz dwa zestawy własnych anime i gatunków.
Po sprawdzeniu tożsamości fixture usuwa tylko utworzone anime i gatunki;
konta pozostają w lokalnej bazie zgodnie z innymi testami HTTP projektu.
