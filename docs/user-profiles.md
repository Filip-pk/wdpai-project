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

Awatar ma obecnie symbol zastępczy; przesyłanie plików pozostaje wyłączone.
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
node --test tests/auth-http.test.cjs tests/projects-http.test.cjs tests/assets.test.cjs tests/theme.test.cjs
docker compose exec php php tests/user-repository.test.php
docker compose exec php php tests/projects-application.test.php
```

Test profili tworzy dwa losowe konta w lokalnej bazie i zachowuje je.
Do kontroli układu sprawdź jasny i ciemny motyw przy szerokościach
375 i 1280 px oraz obsługę formularzy klawiszem Tab.
