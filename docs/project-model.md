# Model projektu — etap 4

`App\Model\Project` reprezentuje dane jednego rekordu tabeli `projects`.
Definicja znajduje się w `src/models/Project.php` i odpowiada
[schematowi z etapu 2](projects-schema.md).

Konstruktor przyjmuje kolejno:

| Parametr | Typ | Znaczenie |
| --- | --- | --- |
| `id` | `?int` | Identyfikator nadany przez bazę albo `null` przed zapisem. |
| `title` | `string` | Tytuł projektu. |
| `description` | `?string` | Opis; `null` oznacza brak opisu. |
| `status` | `string` | Status projektu. |
| `createdAt` | `?DateTimeImmutable` | Data utworzenia; domyślnie `null`. |
| `updatedAt` | `?DateTimeImmutable` | Data ostatniej zmiany; domyślnie `null`. |

Nowy projekt można przygotować po załadowaniu `vendor/autoload.php`:

```php
use App\Model\Project;

$project = new Project(
    null,
    'AniVibe',
    null,
    'planned',
);
```

Gettery `getId()`, `getTitle()`, `getDescription()`, `getStatus()`,
`getCreatedAt()` i `getUpdatedAt()` udostępniają wartości z zachowaniem typów.
Repozytorium w kolejnym etapie będzie ustawiało identyfikator metodą
`setId(int $id)` po zapisie oraz przekazywało daty odczytane z bazy do konstruktora.
Dozwolone statusy wymusza ograniczenie `projects_status_check` w PostgreSQL.

## Autoloading i weryfikacja

Composer mapuje `App\Model\` na katalog `src/models/`. Mapowania kontrolerów
oraz ogólne mapowanie `App\` są zachowane. Dokładna ścieżka ma znaczenie
w kontenerze Linux, gdzie wielkość liter jest rozróżniana.

Po pobraniu zmian uruchom w Windows CMD, w katalogu projektu:

```cmd
docker compose exec php composer dump-autoload --optimize --strict-psr --no-interaction
docker compose exec php php -l src/models/Project.php
docker compose exec php php -r "require 'vendor/autoload.php'; var_export(class_exists('App\Model\Project')); echo PHP_EOL;"
```

Poprawny wynik to wygenerowany autoloader, brak błędów składni oraz `true`
z ostatniego polecenia. Model można tworzyć i sprawdzać bez połączenia z bazą.
