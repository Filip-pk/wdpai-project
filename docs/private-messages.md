# Prywatne wiadomości

Zakładka „Wiadomości” pokazuje wyłącznie rozmowy zalogowanego konta.
Z profilu innej osoby wybierz „Napisz wiadomość”, wpisz tekst i wyślij.
Przycisk na własnym profilu nadal prowadzi do edycji danych.

## Istniejąca baza

W katalogu projektu wykonaj raz w Windows CMD:

```cmd
docker compose exec -T php php -r "require 'vendor/autoload.php'; (new App\Database())->connect()->exec(file_get_contents('database/14-private-messages.sql'));"
```

Migracja dodaje `private_conversations` i `private_messages` wraz z kluczami
obcymi i indeksami. Nie zmienia istniejących komentarzy, kont, list ani ocen.
Nowa pusta baza uruchamia skrypt `14` automatycznie. Zależności i konfiguracja
Dockera pozostają bez zmian.

## Rozmowy i wysyłanie

- `/messages` pokazuje po 20 własnych rozmów na stronę, ostatnio aktywne
  na początku, z nazwą, awatarem, datą i podglądem ostatniej wiadomości.
- `/messages/new/{id}` otwiera formularz dla wskazanego odbiorcy. Sam GET
  nie tworzy rozmowy. Jeżeli para ma już wątek, następuje przekierowanie
  do tego samego wątku.
- Pierwszy POST na `/messages/new/{id}` atomowo zapisuje rozmowę i wiadomość.
  Uporządkowana para ID z ograniczeniem UNIQUE chroni również przed
  duplikatami przy równoległym rozpoczęciu rozmowy z obu stron.
- `/messages/{id}` pokazuje rozmowę i przyjmuje kolejne wiadomości przez POST.
  Historia jest chronologiczna, po 30 wpisów na stronę; domyślnie otwiera się
  ostatnia strona. Wysłanie kieruje do najnowszej wiadomości.
- Wiadomość jest zwykłym tekstem o długości 1–4000 znaków. Puste i składające
  się z samych białych znaków treści są odrzucane. HTML jest wyświetlany
  jako tekst. Nowe linie są zachowywane; skrajne spacje, tabulatory i nowe
  linie są usuwane, a zakończenia CRLF i CR są zamieniane na LF.
- Wpisana treść pozostaje w formularzu po błędzie walidacji. Awatar i nazwa
  rozmówcy prowadzą do jego profilu.

Formularze działają bez JavaScript. Przycisk „Odśwież rozmowę” pobiera nowe
wiadomości; ten etap korzysta z ręcznego odświeżania. Edycja i usuwanie
wysłanych wiadomości nie są dostępne. Liczniki nieprzeczytanych, blokowanie,
zgłoszenia i ograniczenia spamu należą do kolejnego etapu.

## Dostęp i ochrona danych

Wszystkie trasy wymagają logowania. Rozmowę i jej historię mogą odczytać
wyłącznie jej uczestnicy. Dotyczy to także listy, podglądów wiadomości
i bezpośredniego wejścia pod zgadnięty adres. Nieuczestnik otrzymuje 404.
Nie można rozpocząć rozmowy z samym sobą ani nieistniejącym kontem.

Nadawca zawsze pochodzi z sesji. Przesłane `senderId`, `userId`, `recipientId`
i `conversationId` nie zastępują konta ani rozmowy z adresu trasy.
Wszystkie zapisy wymagają POST, tokenu CSRF i poprawnego tekstu UTF-8.
Repozytorium sprawdza udział użytkownika również wewnątrz zapytań SQL.
Dane rozmówcy ograniczają się do nazwy, ID i awatara; e-mail, hasło i motyw
nie są udostępniane. Odpowiedzi korzystają z `Cache-Control: no-store`.

Usunięcie konta usuwa związane z nim rozmowy i wiadomości przez klucze obce.
Prywatne rozmowy są niezależne od demonstracyjnego czatu AI.

## Sprawdzenie

```cmd
docker compose exec -T php php tests/message-repository.test.php
node --test tests/messages-http.test.cjs tests/user-profiles-http.test.cjs
```

Test repozytorium używa tabel tymczasowych, niezależnych sekwencji i rollbacku.
Test HTTP tworzy trzy losowe konta oraz prywatne wiadomości między kontami
testowymi, które pozostają w lokalnej bazie jak inne dane testów HTTP projektu.
Sprawdza dwustronną unikalność rozmowy, dostęp trzeciej osoby, CSRF, walidację,
XSS, historię, listę oraz trwałość po ponownym zalogowaniu.

Przy kontroli widoku sprawdź oba motywy przy 375 i 1280 px, długie nazwy
i treści bez spacji, przejście z profilu do rozmowy oraz obsługę formularza
i paginacji klawiszem Tab.
