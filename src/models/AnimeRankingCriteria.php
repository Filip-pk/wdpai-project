<?php

declare(strict_types=1);

namespace App\Model;

use App\Service\Translator;

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
        $mode = self::stringValue($input, 'mode', Translator::current()->translate('message.field.ranking_mode'), $errors, 'best');
        if (!array_key_exists($mode, self::MODES)) {
            $errors[] = Translator::current()->translate('message.ranking.choose_mode');
            $mode = 'best';
        }
        $genre = self::stringValue($input, 'genre', Translator::current()->translate('message.field.genre'), $errors);
        $year = self::stringValue($input, 'year', Translator::current()->translate('message.field.year'), $errors);
        $minVotes = self::stringValue($input, 'min_votes', Translator::current()->translate('message.field.min_votes'), $errors, '5');
        $page = self::stringValue($input, 'page', Translator::current()->translate('message.field.page'), $errors, '1');

        return new self(
            $mode,
            self::integerValue($genre, 1, PHP_INT_MAX, Translator::current()->translate('message.field.genre'), $errors),
            self::integerValue($year, 1900, 2200, Translator::current()->translate('message.field.year'), $errors),
            self::integerValue($minVotes, 1, 1000000, Translator::current()->translate('message.field.min_votes'), $errors, true) ?? 5,
            self::integerValue($page, 1, 1000000, Translator::current()->translate('message.field.page'), $errors, true) ?? 1,
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
            $errors[] = Translator::current()->translate('message.field.text', ['label' => $label]);
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
            $errors[] = Translator::current()->translate('message.field.integer', [
                'label' => $label, 'minimum' => $minimum, 'maximum' => $maximum,
            ]);
            return null;
        }

        return $number;
    }
}
