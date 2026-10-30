# Repozytorium projektów — etap 5

`App\Repository\ProjectRepository` wykonuje zapytania dotyczące projektów
i mapuje wyniki PDO na obiekty `App\Model\Project`.
Konstruktor przyjmuje gotowe połączenie PDO z `App\Database`.

Przykład użycia po załadowaniu `vendor/autoload.php`:

```php
use App\Database;
use App\Repository\ProjectRepository;

$repository = new ProjectRepository((new Database())->connect());
$projects = $repository->findAll();
```

## Operacje

| Metoda | Zachowanie |
| --- | --- |
| `findAll(): array` | Lista obiektów `Project`, od najnowszej daty utworzenia; przy równych datach od największego ID. |
| `find(int $id): ?Project` | Jeden projekt albo `null`, jeśli rekord nie istnieje. |
| `save(Project $project): void` | Dodaje nowy rekord i ustawia ID modelu z `INSERT ... RETURNING id`. |
| `update(Project $project): void` | Zmienia tytuł, opis i status oraz ustawia `updated_at = CURRENT_TIMESTAMP`. |
| `delete(int $id): void` | Usuwa wybrany rekord. |

`save()` wymaga modelu z `id = null`, a `update()` modelu z nadanym ID.
Naruszenie tych zasad powoduje `InvalidArgumentException` przed wykonaniem SQL.
Aktualizacja lub usunięcie nieistniejącego rekordu pozostawia tabelę bez zmian.

Ograniczenia tabeli, w tym dozwolone statusy, nadal obowiązują; ich naruszenie
powoduje `PDOException`. Warstwa wywołująca odpowiada za bezpieczny komunikat
dla użytkownika. Zapytania przyjmujące wartości korzystają z parametrów PDO.

Opis `NULL` pozostaje w modelu wartością `null`, a daty są mapowane na
`DateTimeImmutable`. Po zapisie lub aktualizacji aktualne daty można pobrać
przez `find()`: metody zapisujące nie zmieniają dat przechowywanych w modelu.
Transakcją zarządza kod wywołujący repozytorium.

## Weryfikacja w Windows CMD

W katalogu projektu uruchom:

```cmd
docker compose exec php composer dump-autoload --optimize --strict-psr --no-interaction
docker compose exec php php tests/project-repository.test.php
```

Testy wykonują operacje CRUD w PostgreSQL na tabeli tymczasowej utworzonej
z definicji `public.projects`. Tabela tymczasowa ma własną sekwencję identity
i przesłania nazwę `projects` wyłącznie w połączeniu testowym.

Każdy przypadek ma osobny punkt zapisu transakcji. Końcowy rollback usuwa
tymczasowe dane. Skrypt nie zmienia rekordów ani numeracji ID w `public.projects`
i nie wypisuje treści wyjątków sterownika.

Sprawdzane są odczyt i mapowanie modelu, generowanie ID, wartości `null`
i pusty opis, parametryzacja zapytań, kolejność wyników, aktualizacja dat,
usunięcie wybranego rekordu oraz obsługa niedozwolonego statusu i błędnego ID modelu.
