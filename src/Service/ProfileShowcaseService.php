<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\AnimeRepository;
use App\Repository\ProfileShowcaseRepository;
use InvalidArgumentException;

final class ProfileShowcaseService
{
    public function __construct(
        private readonly ProfileShowcaseRepository $showcase,
        private readonly AnimeRepository $animes,
        private readonly AchievementService $achievements,
    ) {
    }

    /** @return list<string> Translated validation errors; an invalid request never replaces the saved selection. */
    public function update(int $userId, mixed $animeIds, mixed $badgeCodes): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Identyfikator konta musi być dodatni.');
        }
        $selection = self::normalize($animeIds, $badgeCodes);
        $errors = $selection['errors'];
        if ($errors === []) {
            if (count($this->animes->findByIds($selection['animeIds'])) !== count($selection['animeIds'])) {
                $errors[] = 'showcase.error.anime_invalid';
            }
            $earnedCodes = array_column($this->achievements->earnedForUser($userId), 'code');
            if (array_diff($selection['badgeCodes'], $earnedCodes) !== []) {
                $errors[] = 'showcase.error.badge_unearned';
            }
        }
        if ($errors === []) {
            $this->showcase->saveForUser($userId, $selection['animeIds'], $selection['badgeCodes']);
        }

        return array_map(Translator::current()->translate(...), $errors);
    }

    /**
     * Normalize the three HTML select values while keeping validation independent of database reads.
     * @return array{animeIds: list<int>, badgeCodes: list<string>, errors: list<string>}
     */
    public static function normalize(mixed $animeIds, mixed $badgeCodes): array
    {
        $selection = ['animeIds' => [], 'badgeCodes' => [], 'errors' => []];
        if (!is_array($animeIds) || !array_is_list($animeIds)) {
            $selection['errors'][] = 'showcase.error.anime_invalid';
        } elseif (count($animeIds) > 3) {
            $selection['errors'][] = 'showcase.error.anime_limit';
        } else {
            foreach ($animeIds as $value) {
                if ($value === '') {
                    continue;
                }
                $id = (is_int($value) || is_string($value))
                    ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
                if ($id === false) {
                    $selection['errors'][] = 'showcase.error.anime_invalid';
                } elseif (in_array($id, $selection['animeIds'], true)) {
                    $selection['errors'][] = 'showcase.error.anime_duplicate';
                } else {
                    $selection['animeIds'][] = $id;
                }
            }
        }
        if (!is_array($badgeCodes) || !array_is_list($badgeCodes)) {
            $selection['errors'][] = 'showcase.error.badge_invalid';
        } elseif (count($badgeCodes) > 3) {
            $selection['errors'][] = 'showcase.error.badge_limit';
        } else {
            foreach ($badgeCodes as $code) {
                if (!is_string($code) || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $code) !== 1) {
                    $selection['errors'][] = 'showcase.error.badge_invalid';
                } elseif (in_array($code, $selection['badgeCodes'], true)) {
                    $selection['errors'][] = 'showcase.error.badge_duplicate';
                } else {
                    $selection['badgeCodes'][] = $code;
                }
            }
        }
        $selection['errors'] = array_values(array_unique($selection['errors']));

        return $selection;
    }
}
