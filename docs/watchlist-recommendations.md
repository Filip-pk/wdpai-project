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

Przy co najmniej dziesięciu ocenach bierzemy również pod uwagę typowy poziom
ocen tego konta: medianę ograniczoną do przedziału `5–9`. Dla ocen `7–10`
mnożymy wagę dodatkowo przez `max(0,2; 1 + 0,35 × (ocena − mediana))`.
Dla `5–6` mnożnik statusu wynosi wtedy `0,5 + 0,2 × (ocena − mediana)`.
Dzięki temu na liście ocenianej zwykle na `9–10` ocena `6` nie wzmacnia
preferencji, a ulubione tytuły mają większy wpływ niż tylko dobre.

Porzucone anime zawsze daje `−2`, również gdy ma wysoką ocenę. Waga każdego
anime dzieli się równo między jego gatunki, aby duża liczba tagów nie zwiększała
siły pojedynczego wpisu. Osobno sumują się preferencje formatów (TV, film itd.).
Puste i nieznane formaty są pomijane; `TV Special` jest traktowany jak `SPECIAL`.
Gatunki ważymy także ich rzadkością w katalogu:
`min(4; 1 + ln((liczba anime + 1) / (liczba anime danego gatunku + 1)))`.
Wyróżniający motyw ma dzięki temu większe znaczenie niż samo występowanie
popularnej Akcji. W obu grupach dzielimy wagi przez sumę ich wartości bezwzględnych,
zachowując sygnały ujemne. Pulpit pokazuje trzy najsilniejsze dodatnie gatunki.

## Dobór propozycji

Wykluczamy wszystkie anime z własnej watchlisty, niezależnie od statusu.
Kandydat musi mieć dodatnią sumę wag dopasowanych gatunków. Pobieramy ograniczoną
pulę do 300 kandydatów: liderów ogólnych oraz liderów każdego dodatniego gatunku.
Mniejsze zainteresowanie, np. romansem, nie ginie dzięki temu już na etapie
przygotowania kandydatów pod dominującą akcją i fantasy.

Ostateczny ranking łączy:

- podobieństwo do konkretnych lubianych anime (42%): ważony rzadkością gatunków
  Jaccard, z pomocniczym dopasowaniem studia i długości;
- jakość według oceny źródłowej (30%): kwadrat oceny podzielonej przez dziesięć;
  brak oceny dostaje niski priorytet;
- dopasowanie do całego własnego profilu (22%): podobieństwo wektorów gatunków;
- preferowany format (6%) oraz karę za podobieństwo do negatywnych przykładów.

Porównujemy do maksymalnie 80 dodatnich i 20 ujemnych przykładów. Wybór uwzględnia
różne zainteresowania i siłę własnych ocen, a profil nadal obejmuje całą listę.
Nazwy studiów normalizujemy bez firmowych końcówek typu „Inc.” czy „Co., Ltd.”;
sama wspólna końcówka nie jest dowodem wspólnego studia.

Wybierając kolejny wynik, obniżamy priorytet anime podobnych do już wybranych
oraz ponownego wykorzystania tego samego przykładu. Ograniczamy też kolejne sezony
tej samej serii, gdy można zaproponować inną. Ta różnorodność nie oznacza losowania:
przy niezmienionych danych wynik jest deterministyczny. Gdy pasujących tytułów
jest mało, nadal można pokazać podobne pozycje, zamiast sztucznie skracać listę.
Nieocenione pozycje bez znanego roku lub liczby odcinków trafiają za pozycje
z uzupełnionymi metadanymi.

Przy karcie pokazujemy konkretny tytuł źródłowy z własnej watchlisty, jego ocenę
lub status i do dwóch rzeczywiście wspólnych motywów; wspólne studio tylko gdy
faktycznie pasuje. Przykład musi należeć do najlepszych dopasowań (co najmniej
85% siły najlepszego), więc różnych zdań nie dobieramy dekoracyjnie.
Oceny społeczności są wyświetlane osobno i nie budują prywatnego profilu.
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
watchlisty. Nie analizuje fabuły ani podobieństwa między kontami. Rozpoznawanie
kolejnych sezonów jest ostrożną regułą nazw, a nie pełnym grafem franczyz.
Format ma rolę pomocniczą; samo jego dopasowanie
nie wystarcza do personalizacji.

## Sprawdzenie

```cmd
docker compose exec -T php php tests/recommendation-service.test.php
docker compose exec -T php php tests/recommendation-ranker.test.php
docker compose exec -T php php tests/recommendation-repository.test.php
node --test tests/recommendations-http.test.cjs
```

Test repozytorium używa wyłącznie tabel tymczasowych i wycofuje transakcję.
Test HTTP tworzy losowe konta oraz dwa zestawy własnych anime i gatunków.
Po sprawdzeniu tożsamości fixture usuwa tylko utworzone anime i gatunki;
konta pozostają w lokalnej bazie zgodnie z innymi testami HTTP projektu.
Scenariusz regresji z 400 wpisami sprawdza jakość, kilka różnych profili
gatunkowych, prawdziwe źródła wyjaśnień i brak powrotu do samych szerokich tagów.
