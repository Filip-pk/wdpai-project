<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class PreferencesRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function forUser(int $userId): array
    {
        $statement = $this->database->prepare(
            'SELECT theme, font_size, line_height, interface_density FROM users WHERE id = :id'
        );
        $statement->execute(['id' => $userId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function saveAppearance(int $userId, array $values): void
    {
        $statement = $this->database->prepare(
            'UPDATE users SET theme = :theme, font_size = :font_size,
                line_height = :line_height, interface_density = :interface_density WHERE id = :id'
        );
        $statement->execute(['id' => $userId] + array_intersect_key($values, array_flip([
            'theme', 'font_size', 'line_height', 'interface_density',
        ])));
    }
}
