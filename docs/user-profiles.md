# Profile, ustawienia i prywatne watchlisty

Profil i pulpit używają konta z `users`, odczytanego po ID z sesji.
Nie pobierają już przykładowego użytkownika z `DemoData`.

## Migracja

Na bazie mającej już tabele `users` i `projects`, w Windows CMD wykonaj raz:

```cmd
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\03-user-profiles.sql
```

Migracja zachowuje istniejące konta i dodaje `interests`, `theme` oraz
`user_watchlist` z kluczem `(user_id, anime_id)`. Konta zaczynają z pustymi
zainteresowaniami, motywem systemowym i własną pustą watchlistą.
Przykładów Kacpra nie przenosimy do kont użytkowników.

## Dostępne funkcje

- `/profile`: odczyt i edycja nazwy, e-maila, zainteresowań i motywu.
- Zmiana e-maila wymaga aktualnego hasła. Hasło nie jest ponownie wyświetlane.
- Nazwa ma 1–120 znaków, zainteresowania do 1000, e-mail do 255.
- Motyw `system`, `light` lub `dark` jest zapisywany na koncie, także przez
  przyciski w nagłówku. Gość nadal korzysta z ustawienia tej przeglądarki.
- `/watchlist`: własne tytuły, filtrowanie po statusie, zapis postępu i usuwanie.
- Przycisk na stronie anime dodaje tytuł jako planowany. Ponowne dodanie
  zachowuje wcześniejszy postęp. Planowane ma 0 odcinków, ukończone całą serię.
- Liczniki profilu uwzględniają własną listę, niezależnie od filtra.

Właściciel zawsze pochodzi z sesji. Pola `userId` i `user_id` przesłane przez
przeglądarkę nie wyznaczają konta. Zapis wymaga CSRF i parametryzowanego SQL.
Gość otwierający profil lub watchlistę jest przekierowywany do logowania.

## Zdjęcie profilowe

Na istniejącej bazie zastosuj raz migrację dodającą zdjęcia (Windows CMD):

```cmd
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\04-user-avatars.sql
docker compose up -d --build --no-deps server
```

Nowa baza wykonuje również skrypt `04` automatycznie. Przebudowanie samego
serwera Nginx wczytuje limit przesyłania potrzebny do zdjęć do 4 MB.

W profilu wybierz JPG lub PNG do 4 MB i kliknij „Zapisz zdjęcie”. Maksymalne
wymiary pliku to 4096 × 4096 pikseli. Zdjęcie zostanie przycięte centralnie
do kwadratu 256 × 256 i zapisane jako PNG, bez metadanych oryginału.
Przesłanie kolejnego pliku zastępuje dotychczasowe zdjęcie.

Zdjęcie wyświetla się w podsumowaniu profilu oraz przy nazwie konta we
wspólnej nawigacji. Brak zdjęcia zachowuje symbol zastępczy. Zapis jest
przypisany do zalogowanego konta, wymaga CSRF i pozostaje po ponownym
logowaniu. W innej otwartej sesji wejście na profil lub pulpit odświeża
zdjęcie w nawigacji.

Baza przechowuje wyłącznie przetworzony obraz w `users.avatar_data` (`BYTEA`)
i losową wersję `avatar_version`. Odczyt `/avatars/{id}?v=...` zwraca sam
obraz PNG z `nosniff`; link do zdjęcia jest dostępny również bez logowania.
Oryginalne nazwy plików i ścieżki przesyłania nie są zapisywane.

Synchronizacja MyAnimeList, oceny i AI nadal są demonstracyjne. Katalog anime
pozostaje w `DemoData`; baza przechowuje przypisania tytułów do użytkowników.

## Sprawdzenie

Zaloguj dwa konta w zwykłym i prywatnym oknie przeglądarki. Zmień nazwę,
zainteresowania i motyw pierwszego, dodaj anime i zapisz postęp.
Drugie konto powinno zachować swoje dane i pustą listę. Wyloguj się i zaloguj
ponownie, aby sprawdzić trwałość ustawień. Sprawdź błędne aktualne hasło
przy zmianie e-maila oraz adres zajęty przez drugie konto.

```cmd
node --test tests/profile-http.test.cjs
node --test tests/avatar-http.test.cjs
node --test tests/auth-http.test.cjs tests/projects-http.test.cjs tests/assets.test.cjs tests/theme.test.cjs
docker compose exec php php tests/user-repository.test.php
docker compose exec php php tests/projects-application.test.php
```

Test profili tworzy dwa losowe konta w lokalnej bazie i zachowuje je.
Test zdjęć tworzy kolejne dwa konta oraz sprawdza walidację plików,
izolację kont, wyświetlanie, trwałość i zastępowanie zdjęcia.
Do kontroli układu sprawdź jasny i ciemny motyw przy szerokościach
375 i 1280 px oraz obsługę formularzy klawiszem Tab.
