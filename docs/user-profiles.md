# Profile użytkowników, ustawienia i watchlisty

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

- `/profile`: odczyt danych oraz edycja nazwy, zainteresowań i motywu.
- E-mail zmienisz w [ustawieniach konta](account-security-settings.md): podaj
  aktualne hasło i potwierdź nowy adres kodem przesłanym na ten adres.
  Formularz profilu nie pozwala obejść potwierdzenia. Hasła nie są ponownie wyświetlane.
- Nazwa ma 1–120 znaków, zainteresowania do 1000, e-mail do 255.
- Motyw `system`, `light` lub `dark` jest zapisywany na koncie, także przez
  przyciski w nagłówku. Gość nadal korzysta z ustawienia tej przeglądarki.
- `/watchlist`: własne tytuły, filtrowanie po statusie, zapis postępu i usuwanie.
- Watchlista w ulubionych i profilu wyświetla po 24 tytuły na stronę.
  Paginacja zachowuje wybrany status, a zmiana filtra zaczyna od pierwszej strony.
  Zapis postępu i usunięcie tytułu zachowują stronę oraz filtr.
- Przycisk na stronie anime dodaje tytuł jako planowany. Ponowne dodanie
  zachowuje wcześniejszy postęp. Planowane ma 0 odcinków, ukończone całą serię.
- Liczniki profilu uwzględniają całą własną listę, niezależnie od filtra i strony.

Właściciel zawsze pochodzi z sesji. Pola `userId` i `user_id` przesłane przez
przeglądarkę nie wyznaczają konta. Zapis wymaga CSRF i parametryzowanego SQL.
Gość otwierający `/profile` lub `/watchlist` jest przekierowywany do logowania.

## Publiczne profile użytkowników

Kliknięcie nazwy lub avatara w rankingu albo przy nazwie własnego konta
otwiera `/users/{id}`. Profil jest dostępny również bez logowania i pokazuje
nazwę, avatar, datę dołączenia, zainteresowania, poziom i EXP oraz watchlistę
tylko do odczytu. Nie udostępnia e-maila, ustawień, prywatnych ocen ani kolekcji.
Własny publiczny profil zawiera odnośnik „Edytuj profil” do `/profile`.

Statystyki pokazują liczbę tytułów, ukończonych i oglądanych serii oraz sumę
obejrzanych odcinków z całej aktualnej watchlisty. Filtr statusu i paginacja
po 24 tytuły nie zmieniają tych liczników. EXP nadal uwzględnia zachowane
postępy tytułów usuniętych z listy. Nieistniejący użytkownik zwraca 404.
Odczyt profilu innego konta nie zmienia właściciela prywatnych formularzy.

Przycisk „Napisz wiadomość” otwiera [prywatną rozmowę](private-messages.md)
z wybranym użytkownikiem. Jeśli rozmowa już istnieje, otwiera ten sam wątek.
Pierwsza wiadomość tworzy rozmowę; sam podgląd formularza nie zapisuje danych.
Gość najpierw przechodzi do logowania. Wysyłanie działa bez JavaScript.

Na profilu innej osoby zalogowany użytkownik może ją zablokować lub
odblokować. Dopóki choć jedna strona utrzymuje blokadę, wysyłanie wiadomości
między tą parą jest niedostępne. Wcześniejsza historia pozostaje dostępna
uczestnikom. Własne blokady można przeglądać i usuwać również pod
`/messages/blocked`. Szczegóły opisuje
[dokumentacja moderacji](community-moderation.md).

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

Listę MyAnimeList można przenieść przez [import pliku XML/XML.GZ](mal-watchlist-import.md).
Automatyczna synchronizacja MyAnimeList i AI nadal są demonstracyjne.
[Prywatne oceny anime](user-ratings.md) w skali 1–10 są zapisywane na koncie
i widoczne przy tytułach w profilu oraz na watchliście.
[Katalog anime](anime-catalog.md) korzysta z PostgreSQL; watchlista przechowuje
przypisania jego tytułów do użytkowników.

## Sprawdzenie

Zaloguj dwa konta w zwykłym i prywatnym oknie przeglądarki. Zmień nazwę,
zainteresowania i motyw pierwszego, dodaj anime i zapisz postęp.
Drugie konto powinno zachować swoje dane i pustą listę. Wyloguj się i zaloguj
ponownie, aby sprawdzić trwałość ustawień. W `/settings` sprawdź błędne aktualne
hasło przy zmianie e-maila oraz adres zajęty przez drugie konto. Po poprawnej
próbie odbierz kod w Mailpit i potwierdź go w `/settings/email/confirm`.

```cmd
node --test tests/profile-http.test.cjs
node --test tests/user-profiles-http.test.cjs
node --test tests/avatar-http.test.cjs
node --test tests/auth-http.test.cjs tests/projects-http.test.cjs tests/assets.test.cjs tests/theme.test.cjs
docker compose exec php php tests/user-repository.test.php
docker compose exec php php tests/projects-application.test.php
docker compose exec -T php php tests/pagination.test.php
```

Test paginacji działa na tabelach tymczasowych i nie zostawia kont w bazie.

Test profili tworzy dwa losowe konta w lokalnej bazie i zachowuje je.
Test publicznych profili również tworzy konta testowe; sprawdza publiczny
odczyt, prywatność, statystyki, filtrowanie, paginację i izolację zmian.
Test zdjęć tworzy kolejne dwa konta oraz sprawdza walidację plików,
izolację kont, wyświetlanie, trwałość i zastępowanie zdjęcia.
Do kontroli układu sprawdź jasny i ciemny motyw przy szerokościach
375 i 1280 px oraz obsługę formularzy klawiszem Tab.
