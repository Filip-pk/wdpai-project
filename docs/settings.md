# Ustawienia konta

Zakładka `/settings` wymaga logowania. Jest dostępna w panelu bocznym i w menu „Więcej” na telefonie.

## Migracja istniejącej bazy

Uruchom raz w Windows CMD, z katalogu projektu:

```cmd
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\18-appearance-preferences.sql
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\19-accessibility-preferences.sql
```

Nowa baza otrzymuje migrację podczas inicjalizacji. Nie usuwaj wolumenu istniejącej bazy.

## Wygląd

Motyw systemowy, jasny i ciemny korzystają z tego samego pola konta co dotychczasowy przełącznik. Rozmiar czcionki, interlinia i gęstość obowiązują na wszystkich stronach po zalogowaniu, także na innym urządzeniu. Podgląd działa od razu, zapis wymaga przycisku „Zapisz wygląd”. Wyjście bez zapisu odrzuca podgląd. Przycisk przywracania zapisuje domyślne ustawienia wyglądu.

Zmiana motywu w nawigacji nadal zapisuje się od razu. Goście zachowują dotychczasowy lokalny motyw przeglądarki.

## Dostępność

Osobny formularz zapisuje kontrast, ograniczenie animacji, mocniejszy fokus klawiatury, większe przyciski i pola oraz niezależne rozmiary wiadomości i komentarzy. Wszystkie opcje mają podgląd bez zapisu. Ograniczenie animacji w systemie jest respektowane także przy standardowym ustawieniu w aplikacji. Reset wyglądu zachowuje ustawienia dostępności.

Sprawdź klawiszem Tab widoczność fokusu, osobne teksty wiadomości i komentarza w podglądzie oraz większe kontrolki na telefonie. Zapisz i odwiedź inną stronę, aby potwierdzić działanie globalne.

## Konto i bezpieczeństwo

Zastosuj też migrację `20` według [instrukcji bezpieczeństwa konta](account-security-settings.md). Formularze zmieniają hasło po potwierdzeniu obecnego oraz adres e-mail po potwierdzeniu obecnego hasła i kodu wysłanego na nową skrzynkę. Na lokalnym środowisku kod znajdziesz w [Mailpit](http://localhost:8025). Stary adres pozostaje aktywny do zatwierdzenia kodu.

```cmd
node --test tests/settings-http.test.cjs tests/theme.test.cjs tests/assets.test.cjs
```

Test HTTP tworzy losowe konta w bazie deweloperskiej. Sprawdza autoryzację, CSRF, walidację, rozdzielenie kont, zapis między sesjami i reset. Ręcznie sprawdź podgląd oraz menu „Więcej” przy szerokości telefonu.
