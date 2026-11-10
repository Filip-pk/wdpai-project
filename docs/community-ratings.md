# Oceny społeczności AniVibe

Przy anime aplikacja pokazuje średnią ocen społeczności AniVibe i liczbę
ocen. Informacje są publiczne, także dla gości. Są widoczne w szczegółach
anime oraz na kartach w katalogu, pulpicie, prywatnej i publicznej watchliście
oraz kolekcjach.

## Trzy rodzaje informacji o ocenie

- Ocena źródłowa pochodzi z importowanych danych katalogu.
- Twoja ocena jest wartością od 1 do 10 przypisaną do zalogowanego konta.
  Tę indywidualną wartość aplikacja pokazuje właścicielowi konta.
- Ocena społeczności AniVibe jest średnią wszystkich zapisanych ocen tego
  anime, wraz z liczbą ocen. Aplikacja nie publikuje listy głosujących ani
  ocen przypisanych do poszczególnych kont.

Każde konto ma najwyżej jedną ocenę danego tytułu. Przy braku ocen średnia
nie istnieje, a liczba ocen wynosi zero. Widok pokazuje wtedy brak ocen,
zamiast oceny zero lub zastępowania wyniku oceną źródłową.

## Aktualizacja średniej

Średnia korzysta z istniejącej tabeli `user_anime_ratings`. Obejmuje również
oceny zapisane przed dodaniem tej funkcji oraz oceny zaimportowane z listy
MyAnimeList. Nie trzeba ponownie głosować ani synchronizować dodatkowej tabeli.

Zapis pierwszej oceny dodaje jeden głos. Zmiana własnej oceny zastępuje jej
wcześniejszy wkład, zachowując liczbę głosów. Usunięcie oceny zmniejsza liczbę
głosów i przelicza średnią; po usunięciu ostatniej oceny wynik ponownie jest
pusty. Efekt jest widoczny przy kolejnym pobraniu strony.

Średnia jest obliczana bez zaokrąglania w repozytorium; formatowanie wyniku
należy do widoku. Ocena źródłowa, watchlista, postęp oglądania, kolekcje
i EXP pozostają niezależne od tej agregacji.

## Baza i uprawnienia

Funkcja nie wymaga nowej migracji. Korzysta z tabeli i indeksu dodanych
przez `database/10-user-anime-ratings.sql`. Istniejącą instalację z tą tabelą
wystarczy uruchomić z aktualnym kodem. Instrukcję tworzenia tabeli zawiera
[dokumentacja własnych ocen](user-ratings.md).

Publiczny odczyt udostępnia wyłącznie średnią i liczbę ocen. Zapis, zmiana
i usunięcie własnej oceny nadal wymagają zalogowania i poprawnego tokenu
CSRF. Autor pochodzi z sesji, a wartość musi być liczbą całkowitą od 1 do 10.
Dotychczasowe zasady i ograniczenia zapisu własnych ocen pozostają takie same.

Odczyt kart pobiera podsumowania dla wszystkich wyświetlanych anime jednym
zbiorczym zapytaniem. Nie wykonuje osobnego zapytania dla każdej karty.

## Sprawdzenie

```cmd
docker compose exec -T php php tests/community-rating-repository.test.php
docker compose exec -T php php tests/rating-repository.test.php
node --test tests/ratings-http.test.cjs
node --test tests/community-ratings-http.test.cjs
```

Testy repozytorium używają tabel tymczasowych, niezależnych sekwencji
i wycofywanej transakcji. Sprawdzają brak ocen, średnią wielu kont, zmianę
i usuwanie głosów, istniejące i importowane dane, zakres identyfikatorów
oraz brak danych głosujących w publicznej projekcji.

Test HTTP tworzy losowe konta i własne anime testowe. Po scenariuszu usuwa
wyłącznie zweryfikowane rekordy anime i gatunku utworzone przez ten test.
Konta pozostają w lokalnej bazie, zgodnie z pozostałymi testami HTTP.

W przeglądarce sprawdź średnią i liczbę ocen jako gość i po zalogowaniu,
zmianę oraz usunięcie własnej oceny, a także brak ocen. Sprawdź karty
i szczegóły w jasnym oraz ciemnym motywie przy szerokościach 375 i 1280 px.
