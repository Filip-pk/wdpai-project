# Wiadomość powitalna po rejestracji

Po zapisaniu nowego konta `AuthController` wywołuje `MailService`.
Usługa wysyła pojedynczą wiadomość tekstową UTF-8 przez SMTP do Mailpit,
bez dodatkowych bibliotek. Hasło ani jego hash nie trafiają do wiadomości.
Duplikat adresu, błąd walidacji i nieprawidłowy CSRF nie wywołują wysyłki.

## Konfiguracja i podgląd

Ustawienia SMTP znajdują się w sekcji `mail` pliku `config.php`:
host `mailpit`, port `1025`, nadawca `noreply@anivibe.local`, nazwa `AniVibe`.
To adres wewnątrz sieci Docker Compose. W przeglądarce otwórz
`http://localhost:8025`, aby sprawdzić odbiorcę, nadawcę, temat, treść
i źródło MIME. Mailpit przechwytuje wiadomości lokalnie, bez doręczania
ich na zewnętrzne skrzynki. Konfiguracja Dockera i zależności nie zmienia się.

Temat to „Witaj w AniVibe — cześć!”, a powitanie zawiera imię podane
przy rejestracji. Źródło zawiera `MIME-Version: 1.0`,
`Content-Type: text/plain; charset=UTF-8` i nagłówek tematu kodowany UTF-8.

## Wynik wysyłki

| Wynik | Przekierowanie po utworzeniu konta |
| --- | --- |
| SMTP przyjął wiadomość | `/login?registered=1&mail=sent` |
| Wysyłka się nie powiodła | `/login?registered=1&mail=failed` |

W obu przypadkach konto pozostaje aktywne i można się zalogować.
Przy awarii użytkownik dostaje krótki komunikat bez danych technicznych.
Diagnostyka trafia do logów PHP:

```cmd
docker compose logs --tail=50 php
```

Wartość `mail` akceptuje wyłącznie `sent` i `failed`, razem z `registered=1`.
Parametry tablicowe i inne wartości są ignorowane. Parametr URL służy
do wyświetlenia komunikatu; nie jest trwałym potwierdzeniem doręczenia.
Wysyłka kończy się sukcesem po odpowiedzi SMTP `250` na treść `DATA`.
Późniejszy błąd zamknięcia połączenia przez `QUIT` nie zmienia tego wyniku.
Klient obsługuje lokalne SMTP bez uwierzytelniania i TLS. Wysyłka jest
synchroniczna, bez kolejki i automatycznych ponowień.

## Weryfikacja w Windows CMD

Przy działających usługach uruchom:

```cmd
docker compose exec -T php php tests/mail-service.test.php
node --test tests/mail-http.test.cjs
node --test tests/auth-http.test.cjs tests/profile-http.test.cjs tests/projects-http.test.cjs tests/assets.test.cjs tests/theme.test.cjs
```

Test usługi sprawdza protokół SMTP na lokalnym serwerze testowym, bez
zewnętrznej wysyłki. Test HTTP używa lokalnego API Mailpit, losowego adresu odbiorcy i nowego
konta. Sprawdza dokładnie jedną wiadomość, MIME, polskie znaki, brak hasła,
brak dodatkowej wysyłki po duplikacie i błędnym CSRF oraz bezpieczne
komunikaty. Nie kasuje istniejących wiadomości. Pozostawia konto i swoją
wiadomość w środowisku deweloperskim. Można nadpisać adresy testowe przez
`ANIVIBE_TEST_URL` i `ANIVIBE_MAILPIT_URL`.

Do osobnego testu awarii zatrzymaj Mailpit, pozostawiając aplikację i bazę:

```cmd
docker compose stop mailpit
set ANIVIBE_EXPECT_MAIL_FAILURE=1
node --test tests/mail-http.test.cjs
set ANIVIBE_EXPECT_MAIL_FAILURE=
docker compose start mailpit
```

Ten tryb sprawdza przekierowanie `mail=failed`, bezpieczny komunikat
i skuteczne logowanie na utworzone konto. Nie korzysta wtedy z API
zatrzymanego Mailpit. Pozostałe testy HTTP wymagają działającej poczty.
Po teście awarii uruchom Mailpit ponownie również wtedy, gdy test nie przejdzie.
