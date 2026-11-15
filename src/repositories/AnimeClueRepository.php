<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class AnimeClueRepository
{
    public function __construct(private readonly PDO $database)
    {
    }

    public function transaction(callable $operation): mixed
    {
        $ownsTransaction = !$this->database->inTransaction();
        $savepoint = 'anime_clues_' . bin2hex(random_bytes(6));
        if ($ownsTransaction) {
            $this->database->beginTransaction();
        } else {
            $this->database->exec('SAVEPOINT ' . $savepoint);
        }
        try {
            $result = $operation();
            if ($ownsTransaction) {
                $this->database->commit();
            } else {
                $this->database->exec('RELEASE SAVEPOINT ' . $savepoint);
            }
            return $result;
        } catch (Throwable $exception) {
            if ($this->database->inTransaction()) {
                if ($ownsTransaction) {
                    $this->database->rollBack();
                } else {
                    $this->database->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $this->database->exec('RELEASE SAVEPOINT ' . $savepoint);
                }
            }
            throw $exception;
        }
    }

    public function findForDate(string $date): ?array
    {
        $statement = $this->database->prepare('SELECT id, game_date, entries FROM daily_anime_clues WHERE game_date = :date');
        $statement->execute(['date' => $date]);
        $row = $statement->fetch();

        return $row === false ? null : $this->mapGame($row);
    }

    public function findById(int $gameId): ?array
    {
        $statement = $this->database->prepare('SELECT id, game_date, entries FROM daily_anime_clues WHERE id = :id');
        $statement->execute(['id' => $gameId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->mapGame($row);
    }

    public function createForDate(string $date, array $entries): array
    {
        $statement = $this->database->prepare(
            'INSERT INTO daily_anime_clues (game_date, entries) VALUES (:date, CAST(:entries AS jsonb))
             ON CONFLICT (game_date) DO NOTHING RETURNING id, game_date, entries'
        );
        $statement->execute(['date' => $date, 'entries' => json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        $row = $statement->fetch();

        return $row === false
            ? ($this->findForDate($date) ?? throw new RuntimeException('Nie znaleziono zestawu wskazówek.'))
            : $this->mapGame($row);
    }

    /** @return array<int, string> */
    public function usageBeforeDate(string $date): array
    {
        $statement = $this->database->prepare(
            'SELECT CAST(entry.value ->> \'id\' AS bigint) AS anime_id, MAX(games.game_date) AS last_date
             FROM daily_anime_clues AS games CROSS JOIN LATERAL jsonb_array_elements(games.entries) AS entry(value)
             WHERE games.game_date < CAST(:date AS date)
             GROUP BY CAST(entry.value ->> \'id\' AS bigint) ORDER BY anime_id'
        );
        $statement->execute(['date' => $date]);
        $usage = [];
        foreach ($statement->fetchAll() as $row) {
            if ($row['anime_id'] !== null) {
                $usage[(int) $row['anime_id']] = (string) $row['last_date'];
            }
        }
        return $usage;
    }

    public function attemptForUser(int $userId, int $gameId): ?array
    {
        $statement = $this->database->prepare(
            'SELECT round_index, hints_used, score, correct_count, completed_at FROM anime_clue_attempts
             WHERE user_id = :user_id AND game_id = :game_id'
        );
        $statement->execute(['user_id' => $userId, 'game_id' => $gameId]);
        $row = $statement->fetch();

        return $row === false ? null : $this->mapAttempt($row);
    }

    /** Must be called inside transaction(); serializes changes across tabs and sessions. */
    public function lockAttempt(int $userId, int $gameId): array
    {
        if (!$this->database->inTransaction()) {
            throw new RuntimeException('Zmiana rozgrywki wymaga transakcji.');
        }
        $create = $this->database->prepare(
            'INSERT INTO anime_clue_attempts (user_id, game_id) VALUES (:user_id, :game_id)
             ON CONFLICT (user_id, game_id) DO NOTHING'
        );
        $parameters = ['user_id' => $userId, 'game_id' => $gameId];
        $create->execute($parameters);
        $lock = $this->database->prepare(
            'SELECT round_index, hints_used, score, correct_count, completed_at FROM anime_clue_attempts
             WHERE user_id = :user_id AND game_id = :game_id FOR UPDATE'
        );
        $lock->execute($parameters);
        $row = $lock->fetch();

        return $row === false ? throw new RuntimeException('Nie znaleziono rozgrywki.') : $this->mapAttempt($row);
    }

    public function revealForUser(int $userId, int $gameId, int $expectedHints): void
    {
        $statement = $this->database->prepare(
            'UPDATE anime_clue_attempts SET hints_used = hints_used + 1
             WHERE user_id = :user_id AND game_id = :game_id AND hints_used = :hints AND hints_used < 3 AND completed_at IS NULL'
        );
        $statement->execute(['user_id' => $userId, 'game_id' => $gameId, 'hints' => $expectedHints]);
    }

    public function finalizeRound(int $userId, int $gameId, int $roundIndex, string $answer, bool $correct, int $points, int $hintsUsed, bool $skipped, DateTimeImmutable $now): void
    {
        if (!$this->database->inTransaction()) {
            throw new RuntimeException('Zapis odpowiedzi wymaga transakcji.');
        }
        $statement = $this->database->prepare(
            'INSERT INTO anime_clue_answers (user_id, game_id, round_index, answer, correct, points, hints_used, skipped, answered_at)
             VALUES (:user_id, :game_id, :round, :answer, :correct, :points, :hints, :skipped, :time)
             ON CONFLICT (user_id, game_id, round_index) DO NOTHING RETURNING round_index'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':game_id', $gameId, PDO::PARAM_INT);
        $statement->bindValue(':round', $roundIndex, PDO::PARAM_INT);
        $statement->bindValue(':answer', $answer);
        $statement->bindValue(':correct', $correct, PDO::PARAM_BOOL);
        $statement->bindValue(':points', $points, PDO::PARAM_INT);
        $statement->bindValue(':hints', $hintsUsed, PDO::PARAM_INT);
        $statement->bindValue(':skipped', $skipped, PDO::PARAM_BOOL);
        $statement->bindValue(':time', $now->format('Y-m-d H:i:s.uP'));
        $statement->execute();
        if ($statement->fetchColumn() === false) {
            return;
        }
        $update = $this->database->prepare(
            'UPDATE anime_clue_attempts
             SET round_index = round_index + 1, hints_used = 1, score = score + :points,
                 correct_count = correct_count + :correct,
                 completed_at = CASE WHEN round_index = 4 THEN CAST(:time AS timestamptz) ELSE NULL END
             WHERE user_id = :user_id AND game_id = :game_id AND round_index = :round'
        );
        $update->execute(['user_id' => $userId, 'game_id' => $gameId, 'round' => $roundIndex,
            'points' => $points, 'correct' => $correct ? 1 : 0, 'time' => $now->format('Y-m-d H:i:s.uP')]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Stan rozgrywki zmienił się w trakcie zapisu.');
        }
    }

    public function answersForUser(int $userId, int $gameId): array
    {
        $statement = $this->database->prepare(
            'SELECT round_index, answer, correct, points, hints_used, skipped FROM anime_clue_answers
             WHERE user_id = :user_id AND game_id = :game_id ORDER BY round_index'
        );
        $statement->execute(['user_id' => $userId, 'game_id' => $gameId]);
        return array_map(static fn (array $row): array => [
            'roundIndex' => (int) $row['round_index'], 'answer' => (string) $row['answer'],
            'correct' => in_array($row['correct'], [true, 1, '1', 't'], true), 'points' => (int) $row['points'],
            'hintsUsed' => (int) $row['hints_used'], 'skipped' => in_array($row['skipped'], [true, 1, '1', 't'], true),
        ], $statement->fetchAll());
    }

    public function totalsForUser(int $userId, string $today): array
    {
        $statement = $this->database->prepare(
            'SELECT COALESCE(SUM(answers.points::BIGINT), 0) AS points,
                    COUNT(*) FILTER (WHERE answers.correct) AS correct, COUNT(*) AS answered
             FROM anime_clue_answers AS answers JOIN daily_anime_clues AS games ON games.id = answers.game_id
             WHERE answers.user_id = :user_id AND games.game_date <= CAST(:today AS date)'
        );
        $statement->execute(['user_id' => $userId, 'today' => $today]);
        $row = $statement->fetch();
        $dates = $this->database->prepare(
            'SELECT games.game_date FROM anime_clue_attempts AS attempts
             JOIN daily_anime_clues AS games ON games.id = attempts.game_id
             WHERE attempts.user_id = :user_id AND attempts.completed_at IS NOT NULL
               AND games.game_date <= CAST(:today AS date) ORDER BY games.game_date'
        );
        $dates->execute(['user_id' => $userId, 'today' => $today]);

        return ['points' => (int) $row['points'], 'correct' => (int) $row['correct'],
            'answered' => (int) $row['answered'], 'completedDates' => $dates->fetchAll(PDO::FETCH_COLUMN)];
    }

    /**
     * Includes resolved rounds from partial days; merely revealing a clue is not participation.
     * Bounds use the Warsaw game date, rather than a timestamp converted in SQL.
     * @return array{leaders: list<array>, ownRanking: ?array}
     */
    public function weeklyRankingForUser(int $userId, string $weekStart, string $weekEnd, ?string $today = null): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('Identyfikator konta musi być dodatni.');
        }
        foreach (array_filter([$weekStart, $weekEnd, $today], static fn (?string $date): bool => $date !== null) as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('Nieprawidłowa data okresu rankingu.');
            }
        }
        if ($weekStart >= $weekEnd) {
            throw new InvalidArgumentException('Koniec okresu musi następować po początku.');
        }
        $parameters = ['user_id' => $userId, 'week_start' => $weekStart, 'week_end' => $weekEnd];
        $cutoff = '';
        if ($today !== null) {
            $cutoff = ' AND games.game_date <= CAST(:today AS date)';
            $parameters['today'] = $today;
        }
        $statement = $this->database->prepare(
            'WITH totals AS (
                SELECT attempts.user_id, SUM(attempts.score::BIGINT) AS points,
                       SUM(attempts.correct_count::BIGINT) AS correct,
                       SUM(attempts.round_index::BIGINT) AS answered,
                       COUNT(*) FILTER (WHERE attempts.completed_at IS NOT NULL) AS completed_days
                FROM anime_clue_attempts AS attempts
                JOIN daily_anime_clues AS games ON games.id = attempts.game_id
                WHERE attempts.round_index > 0
                  AND games.game_date >= CAST(:week_start AS date)
                  AND games.game_date < CAST(:week_end AS date)' . $cutoff . '
                GROUP BY attempts.user_id
             ), ranked AS (
                SELECT users.id AS user_id, users.name, users.avatar_version,
                       totals.points, totals.correct, totals.answered, totals.completed_days,
                       RANK() OVER (ORDER BY totals.points DESC) AS user_rank,
                       ROW_NUMBER() OVER (ORDER BY totals.points DESC, users.id ASC) AS listing_position
                FROM totals JOIN users ON users.id = totals.user_id
             )
             SELECT user_id, name, avatar_version, points, correct, answered, completed_days, user_rank, listing_position
             FROM ranked WHERE listing_position <= 100 OR user_id = :user_id ORDER BY listing_position'
        );
        $statement->execute($parameters);
        $leaders = [];
        $ownRanking = null;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $version = $row['avatar_version'];
            $entry = [
                'userId' => (int) $row['user_id'],
                'name' => (string) $row['name'],
                'avatarUrl' => is_string($version) && preg_match('/\A[a-f0-9]{32}\z/', $version) === 1
                    ? '/avatars/' . (int) $row['user_id'] . '?v=' . $version : null,
                'points' => (int) $row['points'],
                'correct' => (int) $row['correct'],
                'answered' => (int) $row['answered'],
                'completedDays' => (int) $row['completed_days'],
                'rank' => (int) $row['user_rank'],
            ];
            if ((int) $row['listing_position'] <= 100) {
                $leaders[] = $entry;
            }
            if ($entry['userId'] === $userId) {
                $ownRanking = $entry;
            }
        }
        return ['leaders' => $leaders, 'ownRanking' => $ownRanking];
    }

    private function mapGame(array $row): array
    {
        return ['id' => (int) $row['id'], 'date' => (string) $row['game_date'],
            'entries' => json_decode($row['entries'], true, 512, JSON_THROW_ON_ERROR)];
    }

    private function mapAttempt(array $row): array
    {
        return ['roundIndex' => (int) $row['round_index'], 'hintsUsed' => (int) $row['hints_used'],
            'score' => (int) $row['score'], 'correctCount' => (int) $row['correct_count'],
            'completedAt' => $row['completed_at'] === null ? null : (string) $row['completed_at']];
    }
}
