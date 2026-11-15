<?php

declare(strict_types=1);

namespace App\Data;

final class Achievements
{
    /** @return list<array{code: string, category: string, metric: string, target: int, icon: string}> */
    public static function all(): array
    {
        return [
            ['code' => 'quiz_first', 'category' => 'quiz', 'metric' => 'quizCount', 'target' => 1, 'icon' => 'spark'],
            ['code' => 'quiz_perfect', 'category' => 'quiz', 'metric' => 'perfectQuizzes', 'target' => 1, 'icon' => 'star'],
            ['code' => 'quiz_correct_100', 'category' => 'quiz', 'metric' => 'correctAnswers', 'target' => 100, 'icon' => 'target'],
            ['code' => 'quiz_streak_7', 'category' => 'quiz', 'metric' => 'bestQuizStreak', 'target' => 7, 'icon' => 'flame'],
            ['code' => 'quiz_streak_30', 'category' => 'quiz', 'metric' => 'bestQuizStreak', 'target' => 30, 'icon' => 'crown'],
            ['code' => 'anime_first', 'category' => 'anime', 'metric' => 'completedAnime', 'target' => 1, 'icon' => 'play'],
            ['code' => 'anime_completed_10', 'category' => 'anime', 'metric' => 'completedAnime', 'target' => 10, 'icon' => 'ribbon'],
            ['code' => 'anime_completed_50', 'category' => 'anime', 'metric' => 'completedAnime', 'target' => 50, 'icon' => 'trophy'],
            ['code' => 'anime_genres_5', 'category' => 'anime', 'metric' => 'completedGenres', 'target' => 5, 'icon' => 'compass'],
            ['code' => 'anime_episodes_100', 'category' => 'anime', 'metric' => 'episodes', 'target' => 100, 'icon' => 'screen'],
        ];
    }
}
