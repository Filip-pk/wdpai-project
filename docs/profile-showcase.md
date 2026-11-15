# Gablotka na profilu

W zakładce „Gablotka” (`/profile/showcase`) właściciel konta wybiera do trzech
ulubionych anime z całego katalogu i do trzech zdobytych odznak. Trzy pola anime
wyznaczają kolejność kart. Puste miejsca są dozwolone; usunięcie wszystkich
wyborów i zapis czyści gablotkę.

Gablotka pojawia się nad ustawieniami własnego profilu i na publicznym profilu
`/users/{id}`. Karty prowadzą do szczegółów anime. Fioletowo-turkusowy neon
przesuwa się po obramowaniu kart i odznak. Animacja wyłącza się przy systemowym
`prefers-reduced-motion: reduce` oraz przy włączonej preferencji ograniczenia
ruchu w ustawieniach AniVibe.

## Wyszukiwanie i zapis

Wyszukiwarka zwraca do 24 tytułów. Z JavaScriptem aktualizuje listy wyboru bez
przeładowania i zachowuje niezapisane wybory. Bez JavaScriptu działa zwykły
formularz GET: wyświetla zapisane wybory i nowe wyniki. W tym trybie należy
zapisać wybór przed wyszukaniem następnego tytułu. Cały formularz wyboru anime
i odznak działa również bez JavaScriptu.

Zapis wymaga sesji właściciela i tokena CSRF. Serwer sprawdza limity,
unikalność, istnienie anime oraz to, czy wybrane odznaki rzeczywiście należą do
konta. Błędne dane nie zastępują żadnej części wcześniejszej gablotki.
Wyszukiwanie i oglądanie cudzego profilu nie przyznają odznak.

Anime w gablotce są publiczne, tak jak watchlista. Wyróżnione odznaki podlegają
ustawieniu prywatności statystyk: po jego wyłączeniu widzi je tylko właściciel.
Pozostała sekcja zdobytych odznak zachowuje dotychczasowe działanie.

## Baza danych

Nowa instalacja uruchamia migrację automatycznie. Dla istniejącej bazy, po
migracjach `01`–`27`, wykonaj jednokrotnie w Windows CMD:

```bat
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\28-profile-showcase.sql
```

Migracja dodaje `user_showcase_anime` i `user_showcase_badges`. Klucze główne
i ograniczenia pilnują trzech miejsc oraz braku duplikatów. Klucz obcy odznak
wskazuje zdobyte osiągnięcia konkretnego użytkownika. Usunięcie anime lub konta
usuwa powiązane wpisy gablotki. Zapis obu list jest atomowy i blokuje rekord
konta, aby równoległe zapisy nie mieszały wyborów.

## Weryfikacja

Przy działających kontenerach aplikacji:

```bat
docker compose exec -T php php tests/profile-showcase-service.test.php
docker compose exec -T php php tests/profile-showcase-repository.test.php
node --test tests/profile-showcase-http.test.cjs
node --test tests/profile-showcase-responsive.test.cjs
```

Test przeglądarkowy wymaga lokalnego Chrome lub Edge, ewentualnie ścieżki
w `ANIVIBE_BROWSER`. Sprawdza profil, profil publiczny i edytor w szerokościach
320, 375, 768 i 1440 px, obu motywach, ograniczenie ruchu, fokus klawiatury,
wyszukiwanie z zachowaniem niezapisanych wyborów oraz zapis bez JavaScriptu.
