<?php

declare(strict_types=1);

namespace App\Model;

final readonly class AnimeSearchCriteria
{
    public const DEFAULT_SORT = 'score_desc';

    public const SORT_OPTIONS = [
        'score_desc' => 'Ocena: od najwyższej',
        'score_asc' => 'Ocena: od najniższej',
        'title_asc' => 'Tytuł: A–Z',
        'title_desc' => 'Tytuł: Z–A',
        'episodes_asc' => 'Liczba odcinków: rosnąco',
        'episodes_desc' => 'Liczba odcinków: malejąco',
        'year_desc' => 'Rok premiery: od najnowszych',
        'year_asc' => 'Rok premiery: od najstarszych',
        'relevance' => 'Trafność wyszukiwania',
    ];

    public const TYPE_OPTIONS = [
        '' => 'Wszystkie formaty',
        'TV' => 'Seria telewizyjna',
        'MOVIE' => 'Film',
        'OVA' => 'OVA',
        'ONA' => 'ONA',
        'SPECIAL' => 'Odcinek specjalny',
        'MUSIC' => 'Teledysk',
        'CM' => 'Reklama',
        'PV' => 'Materiał promocyjny',
        'UNKNOWN' => 'Nieznany format',
    ];

    private function __construct(
        public string $query,
        public ?int $genreId,
        public int $page,
        public array $filters,
        public array $form,
        public string $sort,
        public array $errors,
    ) {
    }

    public static function fromQuery(array $input): self
    {
        $errors = [];
        $query = self::stringValue($input, 'q', 'Tytuł anime', $errors);

        if (preg_match('/\A.{0,100}\z/us', $query) !== 1) {
            $errors[] = 'Wpisz tytuł anime o długości do 100 znaków.';
            $query = '';
        }

        $genre = self::stringValue($input, 'genre', 'Gatunek', $errors);
        $genreId = self::integerValue($genre, 1, PHP_INT_MAX, 'Gatunek', $errors);
        $pageValue = self::stringValue($input, 'page', 'Numer strony', $errors, '1');
        $page = self::integerValue($pageValue, 1, 1000000, 'Numer strony', $errors, true) ?? 1;

        $labels = [
            'type' => 'Format anime',
            'episodes_min' => 'Minimalna liczba odcinków',
            'episodes_max' => 'Maksymalna liczba odcinków',
            'score_min' => 'Minimalna ocena',
            'score_max' => 'Maksymalna ocena',
            'year_min' => 'Najwcześniejszy rok premiery',
            'year_max' => 'Najpóźniejszy rok premiery',
        ];
        $values = [];
        $form = [];

        foreach ($labels as $key => $label) {
            $values[$key] = self::stringValue($input, $key, $label, $errors);
            preg_match('/\A.{0,64}/us', $values[$key], $matches);
            $form[$key] = $matches[0] ?? '';
        }

        $type = strtoupper($values['type']);

        if (!array_key_exists($type, self::TYPE_OPTIONS)) {
            $errors[] = 'Wybierz prawidłowy format anime.';
            $type = '';
        } elseif ($type !== '') {
            $form['type'] = $type;
        }

        $filters = [
            'type' => $type,
            'episodesMin' => self::integerValue($values['episodes_min'], 1, 2147483647, $labels['episodes_min'], $errors),
            'episodesMax' => self::integerValue($values['episodes_max'], 1, 2147483647, $labels['episodes_max'], $errors),
            'scoreMin' => self::scoreValue($values['score_min'], $labels['score_min'], $errors),
            'scoreMax' => self::scoreValue($values['score_max'], $labels['score_max'], $errors),
            'yearMin' => self::integerValue($values['year_min'], 1900, 2200, $labels['year_min'], $errors),
            'yearMax' => self::integerValue($values['year_max'], 1900, 2200, $labels['year_max'], $errors),
        ];

        foreach (['score_min' => 'scoreMin', 'score_max' => 'scoreMax'] as $formKey => $filterKey) {
            if ($filters[$filterKey] !== null) {
                $form[$formKey] = str_replace(',', '.', $form[$formKey]);
            }
        }

        self::validateRange($filters, 'episodesMin', 'episodesMax', 'liczby odcinków', $errors);
        self::validateRange($filters, 'scoreMin', 'scoreMax', 'oceny', $errors);
        self::validateRange($filters, 'yearMin', 'yearMax', 'roku premiery', $errors);

        $sort = self::stringValue($input, 'sort', 'Sortowanie', $errors, self::DEFAULT_SORT);

        if (!array_key_exists($sort, self::SORT_OPTIONS)) {
            $errors[] = 'Wybierz prawidłowy sposób sortowania.';
            $sort = self::DEFAULT_SORT;
        }

        return new self($query, $genreId, $page, $filters, $form, $sort, $errors);
    }

    public function parameters(): array
    {
        $parameters = [
            'q' => $this->query,
            'genre' => $this->genreId,
            'type' => $this->filters['type'],
            'episodes_min' => $this->filters['episodesMin'],
            'episodes_max' => $this->filters['episodesMax'],
            'score_min' => $this->filters['scoreMin'],
            'score_max' => $this->filters['scoreMax'],
            'year_min' => $this->filters['yearMin'],
            'year_max' => $this->filters['yearMax'],
            'sort' => $this->sort,
        ];

        return array_filter($parameters, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private static function stringValue(
        array $input,
        string $key,
        string $label,
        array &$errors,
        string $default = '',
    ): string {
        if (!array_key_exists($key, $input)) {
            return $default;
        }

        $value = $input[$key];

        if (!is_string($value) || str_contains($value, "\0") || preg_match('//u', $value) !== 1) {
            $errors[] = $label . ': podaj prawidłową wartość tekstową.';

            return $default;
        }

        return trim($value);
    }

    private static function integerValue(
        string $value,
        int $minimum,
        int $maximum,
        string $label,
        array &$errors,
        bool $required = false,
    ): ?int {
        if ($value === '' && !$required) {
            return null;
        }

        $number = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $minimum, 'max_range' => $maximum],
        ]);

        if ($number === false) {
            $errors[] = $label . ': wpisz liczbę całkowitą od ' . $minimum . ' do ' . $maximum . '.';

            return null;
        }

        return $number;
    }

    private static function scoreValue(string $value, string $label, array &$errors): ?float
    {
        if ($value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', $value);

        if (preg_match('/\A(?:[0-9]+(?:\.[0-9]{1,2})?|\.[0-9]{1,2})\z/', $normalized) !== 1) {
            $errors[] = $label . ': wpisz liczbę od 0 do 10 z najwyżej dwoma miejscami po przecinku.';

            return null;
        }

        $number = (float) $normalized;

        if (!is_finite($number) || $number < 0 || $number > 10) {
            $errors[] = $label . ': wpisz liczbę od 0 do 10.';

            return null;
        }

        return $number;
    }

    private static function validateRange(
        array &$filters,
        string $minimumKey,
        string $maximumKey,
        string $label,
        array &$errors,
    ): void {
        $minimum = $filters[$minimumKey];
        $maximum = $filters[$maximumKey];

        if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
            $errors[] = 'Dolna granica ' . $label . ' nie może być większa od górnej.';
            $filters[$minimumKey] = null;
            $filters[$maximumKey] = null;
        }
    }
}
