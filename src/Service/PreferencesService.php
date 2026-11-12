<?php

declare(strict_types=1);

namespace App\Service;

final class PreferencesService
{
    public const APPEARANCE = [
        'theme' => ['system' => 'Systemowy', 'light' => 'Jasny', 'dark' => 'Ciemny'],
        'font_size' => ['standard' => 'Standardowy', 'large' => 'Duży', 'largest' => 'Bardzo duży'],
        'line_height' => ['standard' => 'Standardowa', 'relaxed' => 'Luźna', 'spacious' => 'Bardzo luźna'],
        'interface_density' => ['compact' => 'Zwarta', 'comfortable' => 'Wygodna', 'spacious' => 'Przestronna'],
    ];

    public const DEFAULTS = [
        'theme' => 'system',
        'font_size' => 'standard',
        'line_height' => 'standard',
        'interface_density' => 'comfortable',
    ];

    public static function validate(array $values, array $choices): array
    {
        foreach ($choices as $key => $options) {
            if (!is_string($values[$key] ?? null) || !array_key_exists($values[$key], $options)) {
                return ['Wybierz poprawną wartość każdego ustawienia.'];
            }
        }
        return [];
    }
}
