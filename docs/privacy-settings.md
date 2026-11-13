# Prywatność profilu i wiadomości

`/settings#privacy` zapisuje ustawienia na zalogowanym koncie przez
`POST /settings/privacy`. Zapis wymaga CSRF, akceptuje wyłącznie znane wartości
i ignoruje przesłane identyfikatory właściciela. Błędy i powodzenie są pokazywane
po przekierowaniu do ustawień.

Migracja `database/21-privacy-settings.sql` dodaje do `users` politykę wiadomości
oraz widoczność ocen i statystyk. Nowe i istniejące konta domyślnie przyjmują
wiadomości od wszystkich oraz pokazują statystyki. Indywidualne oceny pozostają
domyślnie ukryte, tak jak przed dodaniem ustawień. Właściciel może je udostępnić.

| Ustawienie wiadomości | Zachowanie |
| --- | --- |
| Wszyscy | Inne konta mogą rozpocząć rozmowę i wysyłać kolejne wiadomości. |
| Tylko istniejące rozmowy | Nadawca i odbiorca muszą mieć już zapisaną wspólną rozmowę; samo otwarcie formularza jej nie tworzy. |
| Nikt | Wszystkie nowe wiadomości przychodzące są odrzucane, także w istniejących rozmowach. Historia i wysyłanie do innych kont pozostają dostępne. |

Jawne blokady kont obowiązują niezależnie od tej polityki. Ograniczenia sprawdzają
zarówno kontrolery, jak i repozytorium przy zapisie, również przy bezpośrednim POST
do rozmowy. Zapis wiadomości oraz zmiana polityki synchronizują się przez blokadę
wiersza konta, by równoległy formularz nie omijał ograniczeń.

Wyłączenie widoczności ocen usuwa indywidualne oceny z publicznych kart watchlisty.
Wyłączenie statystyk ukrywa zbiorcze statystyki oglądania i poziom/EXP. Kontroler nie
pobiera tych prywatnych ocen i agregatów dla odwiedzającego. Właściciel nadal widzi
własne dane. Watchlista oraz poszczególne postępy pozostają publiczne; jej stronicowanie
nadal wymaga liczenia pozycji. Anonimowa średnia społeczności i publiczny ranking
użytkowników nie są ukrywane; ta preferencja dotyczy profilu.

## Weryfikacja

Migrację istniejącej bazy uruchom raz w Windows CMD z katalogu projektu:

```cmd
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\21-privacy-settings.sql
```

Po zastosowaniu migracji, w Windows CMD:

```cmd
docker compose exec -T php php tests/message-repository.test.php
node --test tests/privacy-settings-http.test.cjs tests/messages-http.test.cjs tests/user-profiles-http.test.cjs
```

Test HTTP tworzy trzy losowe konta w lokalnej bazie. Sprawdza CSRF, walidację,
izolację kont, zapis między logowaniami, każdy wariant wiadomości, blokady
oraz ukrycie danych w HTML publicznego profilu dla gościa i innego konta.

Ręcznie użyj dwóch kont w osobnych profilach przeglądarki. Rozpocznij rozmowę,
zmieniaj kolejno każdy wariant wiadomości i próbuj wysłać wiadomość z drugiego konta.
Po wybraniu „Nikt” historia ma pozostać dostępna, a formularz wysyłania zniknąć.
Wyłącz widoczność ocen i statystyk, po czym otwórz publiczny profil w trybie
prywatnym. Sprawdź też skrót do listy zablokowanych użytkowników.
