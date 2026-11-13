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
            'SELECT language, theme, font_size, line_height, interface_density,
                high_contrast, reduced_motion, strong_focus, large_controls,
                message_text_size, comment_text_size FROM users WHERE id = :id'
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

    public function saveLanguage(int $userId, string $language): void
    {
        if (!\App\Service\Translator::isSupported($language)) {
            throw new \InvalidArgumentException('Unsupported language.');
        }
        $statement = $this->database->prepare('UPDATE users SET language = :language WHERE id = :id');
        $statement->execute(['id' => $userId, 'language' => $language]);
    }

    public function saveAccessibility(int $userId, array $values): void
    {
        $statement = $this->database->prepare(
            'UPDATE users SET high_contrast = :high_contrast, reduced_motion = :reduced_motion,
                strong_focus = :strong_focus, large_controls = :large_controls,
                message_text_size = :message_text_size, comment_text_size = :comment_text_size
             WHERE id = :id'
        );
        $statement->execute(['id' => $userId] + array_intersect_key($values, array_flip([
            'high_contrast', 'reduced_motion', 'strong_focus', 'large_controls',
            'message_text_size', 'comment_text_size',
        ])));
    }
}
