# Konto i bezpieczeństwo

Sekcja **Konto i bezpieczeństwo** na `/settings` wymaga zalogowania. Każdy zapis
sprawdza token CSRF i korzysta z identyfikatora konta z sesji.

Hasło zmienisz, podając obecne hasło i dwukrotnie nowe. Obowiązuje minimum
8 znaków i limit 72 bajtów bcrypt, bez znaku NUL. Hasło jest hashowane przez
`password_hash()`, a zapis sprawdza poprzedni hash, aby uniknąć nadpisania
równoległej zmiany. Po zapisie identyfikator sesji jest regenerowany.

E-mail zmienisz, podając obecne hasło i dwukrotnie nowy adres. Wiadomość wysłana
na nowy adres zawiera losowy kod; wklej go do `/settings/email/confirm`.
Dotychczasowy adres pozostaje aktywny aż do potwierdzenia. Kod jest jednorazowy,
ważny 30 minut, przypisany do konta, przechowywany w bazie jako SHA-256.
Zmiana hasła unieważnia wcześniej wydane kody. Kolejny kod można wysłać po
minucie. Przy niedostępnym SMTP aplikacja usuwa niewysłany kod i podaje błąd.
Unikalność adresu sprawdzana jest również przez bazę podczas potwierdzenia.
Stary formularz `/profile` nie pozwala zmienić adresu bez potwierdzenia.

Na istniejącej bazie zastosuj migrację w Windows CMD:

```cmd
docker compose exec -T db sh -c "PGUSER=$POSTGRES_USER PGDATABASE=$POSTGRES_DB exec psql -X -v ON_ERROR_STOP=1" < database\20-account-security.sql
```

W środowisku deweloperskim odbierz kod w Mailpit: `http://localhost:8025`.
Nie trzeba ustawiać publicznego adresu aplikacji: wiadomość zawiera kod i
instrukcję, a nie link zbudowany z nagłówka Host.

Weryfikacja:

```cmd
docker compose exec -T php php tests/mail-service.test.php
docker compose exec -T php php tests/security-settings-repository.test.php
node --test tests/security-settings-http.test.cjs tests/profile-http.test.cjs tests/mail-http.test.cjs
```

Testy HTTP tworzą losowe konta i wiadomości w deweloperskim Mailpit. Test
repozytorium używa tylko tabel tymczasowych i nie zmienia kont użytkowników.
Zmiana hasła nie wylogowuje innych wcześniej otwartych sesji; globalne
unieważnianie sesji wymaga osobnego rejestru sesji.
