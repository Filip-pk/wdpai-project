# Prywatne kolekcje anime

Zakładka „Moje kolekcje” w profilu pozwala porządkować anime w prywatnych
listach tematycznych. Każde konto otrzymuje jedną pustą kolekcję o nazwie
„moja kolekcja”. Można tworzyć kolejne listy, zmieniać ich nazwy i opisy,
a także usuwać z nich pojedyncze anime.

Nazwa ma od 1 do 120 znaków, a opcjonalny opis do 500 znaków. Na stronie anime
można wybrać kilka własnych kolekcji. Ponowne dodanie tego samego tytułu do
tej samej kolekcji nie tworzy duplikatu. Kolekcje mają podgląd do czterech
okładek. Lista „Moje kolekcje” wyświetla po 12 kolekcji na stronę,
a zawartość pojedynczej kolekcji po 24 tytuły. Zmiana nazwy i usuwanie anime
zachowują bieżącą stronę kolekcji.

Status i postęp oglądania należą do watchlisty. Dodawanie i usuwanie anime
w kolekcjach nie zmienia watchlisty. Usunięcie tytułu z watchlisty również
nie usuwa go z kolekcji.

## Migracja istniejącej bazy

Po wcześniejszych migracjach `01`–`08` wykonaj raz w Windows CMD:

```cmd
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\09-user-collections.sql
```

Migracja tworzy `anime_collections` i `anime_collection_items` oraz puste
domyślne kolekcje dla istniejących kont. Nowe konta otrzymują kolekcję przy
pierwszym otwarciu funkcji. Częściowy indeks unikalny dopuszcza tylko jedną
domyślną kolekcję na konto, także przy równoczesnych żądaniach.

## Prywatność

Właściciel pochodzi wyłącznie z sesji. Wszystkie odczyty i zmiany repozytorium
sprawdzają `user_id`, także przy odwołaniu bezpośrednio do ID kolekcji.
Przesłanie listy zawierającej choć jedno cudze lub nieistniejące ID odrzuca
cały zapis. Parametryzowany SQL traktuje nazwy i opisy jako dane. Operacje
formularzy wymagają logowania i tokenu CSRF.

## Sprawdzenie

```cmd
docker compose exec -T php php tests/collection-repository.test.php
docker compose exec -T php php tests/pagination.test.php
node --test tests/collections-http.test.cjs
```

Test repozytorium działa w transakcji na tabelach i sekwencjach tymczasowych.
Sprawdza domyślną kolekcję, izolację dwóch kont, atomowy wybór kilku list,
duplikaty, miniatury, paginację i niezależność watchlisty.

Ręcznie sprawdź w profilu oraz na stronie anime jasny i ciemny motyw,
szerokości 375 i 1280 px, etykiety formularzy i obsługę klawiaturą.
