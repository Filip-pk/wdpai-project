<?php

declare(strict_types=1);

namespace App\Service;

use InvalidArgumentException;

final class ExperienceService
{
    public const EPISODE_EXP = 5;
    public const COMPLETION_EXP = 100;
    public const LEVEL_STEP_EXP = 50;

    /** @return array{level: int, totalExp: int, levelExp: int, nextLevelExp: int, remainingExp: int, levelStartExp: int} */
    public static function progress(int $totalExp): array
    {
        if ($totalExp < 0) {
            throw new InvalidArgumentException('Liczba EXP nie może być ujemna.');
        }

        // Poziom L zaczyna się przy 25 * L * (L - 1) EXP.
        $thresholdUnits = intdiv($totalExp, 25);
        $level = max(1, (int) floor((1 + sqrt(1 + 4 * $thresholdUnits)) / 2));

        // Korekta zaokrąglenia sqrt; porównania przez dzielenie nie przepełniają int.
        while ($level > 1 && $level - 1 > intdiv($thresholdUnits, $level)) {
            $level--;
        }
        while ($level <= intdiv($thresholdUnits, $level + 1)) {
            $level++;
        }

        $levelStartExp = 25 * $level * ($level - 1);
        $levelExp = $totalExp - $levelStartExp;
        $nextLevelExp = self::LEVEL_STEP_EXP * $level;

        return [
            'level' => $level,
            'totalExp' => $totalExp,
            'levelExp' => $levelExp,
            'nextLevelExp' => $nextLevelExp,
            'remainingExp' => $nextLevelExp - $levelExp,
            'levelStartExp' => $levelStartExp,
        ];
    }
}
