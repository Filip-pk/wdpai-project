<?php

declare(strict_types=1);

namespace App\Service;

final class Translator
{
    public const LANGUAGES = [
        'pl' => 'Polski',
        'en' => 'English',
        'de' => 'Deutsch',
        'fr' => 'Français',
        'es' => 'Español',
        'ru' => 'Русский',
    ];

    private static ?self $current = null;
    private static array $catalogues = [];
    private static ?array $sourceKeys = null;
    private readonly string $language;

    public function __construct(string $locale = 'pl')
    {
        $this->language = self::isSupported($locale) ? $locale : 'pl';
    }

    public static function isSupported(mixed $locale): bool
    {
        return is_string($locale) && array_key_exists($locale, self::LANGUAGES);
    }

    public static function current(): self
    {
        return self::$current ??= new self();
    }

    public static function setCurrent(self $translator): void
    {
        self::$current = $translator;
    }

    public function locale(): string
    {
        return $this->language;
    }

    public function translate(string $key, array $parameters = []): string
    {
        $text = $this->catalogue($this->language)[$key]
            ?? $this->catalogue('pl')[$key]
            ?? $key;
        $replacements = [];
        foreach ($parameters as $name => $value) {
            if (is_scalar($value) || $value === null) {
                $replacements['{' . $name . '}'] = (string) $value;
            }
        }

        return strtr($text, $replacements);
    }

    /** Translate application labels from existing constant maps; never pass user content here. */
    public function translateText(string $source, array $parameters = []): string
    {
        if (self::$sourceKeys === null) {
            self::$sourceKeys = [];
            foreach ($this->catalogue('pl') as $key => $text) {
                self::$sourceKeys[$text] ??= $key;
            }
        }

        return $this->translate(self::$sourceKeys[$source] ?? $source, $parameters);
    }

    public static function message(string $source, array $parameters = []): string
    {
        return self::current()->translateText($source, $parameters);
    }

    public function javascriptMessages(): array
    {
        return array_filter(
            $this->catalogue($this->language) + $this->catalogue('pl'),
            static fn (string $key): bool => str_starts_with($key, 'js.'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    private function catalogue(string $locale): array
    {
        return self::$catalogues[$locale] ??= require dirname(__DIR__, 2) . '/translations/' . $locale . '.php';
    }
}
