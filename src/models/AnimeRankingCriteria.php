<?php

declare(strict_types=1);

namespace App\Model;

final readonly class AnimeRankingCriteria
{
    public const MODES = [
        'best' => 'Najlepiej oceniane',
        'watched' => 'Najczęściej oglądane',
    ];

    private function __construct(
        public string $mode,
        public ?int $genreId,
        public ?int $year,
        public int $minVotes,
        public int $page,
        public array $form,
        public array $errors,
    ) {
    }

    public static function fromQuery(array $input): self
    {
        $errors = [];
        $mode = self::stringValue($input, 'mode', 'Rodzaj rankingu', $errors, 'best');
        if (!array_key_exists($mode, self::MODES)) {
            $errors[] = 'Wybierz prawidłowy rodzaj rankingu.';
            $mode = 'best';
        }
        $genre = self::stringValue($input, 'genre', 'Gatunek', $errors);
        $year = self::stringValue($input, 'year', 'Rok premiery', $errors);
        $minVotes = self::stringValue($input, 'min_votes', 'Minimalna liczba ocen', $errors, '5');
        $page = self::stringValue($input, 'page', 'Numer strony', $errors, '1');

        return new self(
            $mode,
            self::integerValue($genre, 1, PHP_INT_MAX, 'Gatunek', $errors),
            self::integerValue($year, 1900, 2200, 'Rok premiery', $errors),
            self::integerValue($minVotes, 1, 1000000, 'Minimalna liczba ocen', $errors, true) ?? 5,
            self::integerValue($page, 1, 1000000, 'Numer strony', $errors, true) ?? 1,
            ['mode' => $mode, 'genre' => $genre, 'year' => $year, 'min_votes' => $minVotes],
            $errors,
        );
    }

    /** @return array<string, string> */
    public function parameters(): array
    {
        $parameters = ['mode' => $this->mode];
        if ($this->genreId !== null) {
            $parameters['genre'] = (string) $this->genreId;
        }
        if ($this->year !== null) {
            $parameters['year'] = (string) $this->year;
        }
        if ($this->mode === 'best') {
            $parameters['min_votes'] = (string) $this->minVotes;
        }

        return $parameters;
    }

    private static function stringValue(array $input, string $key, string $label, array &$errors, string $default = ''): string
    {
        if (!array_key_exists($key, $input)) {
            return $default;
        }
        $value = $input[$key];
        if (!is_string($value) || strlen($value) > 64 || str_contains($value, "\0") || preg_match('//u', $value) !== 1) {
            $errors[] = $label . ': podaj prawidłową wartość tekstową.';
            return $default;
        }

        return trim($value);
    }

    private static function integerValue(string $value, int $minimum, int $maximum, string $label, array &$errors, bool $required = false): ?int
    {
        if ($value === '' && !$required) {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]);
        if ($number === false) {
            $errors[] = $label . ': wpisz liczbę całkowitą od ' . $minimum . ' do ' . $maximum . '.';
            return null;
        }

        return $number;
    }
}
