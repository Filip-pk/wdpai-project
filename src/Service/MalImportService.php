<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\MalImportRepository;
use InvalidArgumentException;

final class MalImportService
{
    private const STATUSES = ['planned', 'watching', 'completed', 'on_hold', 'dropped'];

    public function __construct(private readonly MalImportRepository $imports)
    {
    }

    public function previewForUser(int $userId, array $parsed, bool $updateExisting = false): array
    {
        $this->validateParsed($userId, $parsed);
        $states = $this->imports->statesForUser($userId, array_column($parsed['entries'], 'malId'));
        $rows = [];
        $counts = ['add' => 0, 'update' => 0, 'unchanged' => 0, 'skipped' => 0];
        foreach ($parsed['entries'] as $entry) {
            $row = $this->planEntry($entry, $states[$entry['malId']] ?? null, $updateExisting);
            $row['reasonParts'] ??= [$row['reason']];
            $rows[] = $row;
            $counts[$row['action']]++;
        }
        foreach ($parsed['issues'] as $issue) {
            $rows[] = [
                'malId' => $issue['malId'], 'animeId' => null, 'title' => $issue['title'],
                'status' => null, 'watched' => null, 'rating' => null,
                'currentStatus' => null, 'currentWatched' => null, 'currentRating' => null,
                'action' => 'skipped', 'reason' => $issue['reason'], 'reasonParts' => [$issue['reason']], 'ratingAction' => 'none',
            ];
            $counts['skipped']++;
        }

        return [
            'total' => $parsed['total'], 'rows' => $rows, 'counts' => $counts,
            'fingerprint' => hash('sha256', json_encode([
                'userId' => $userId, 'parsed' => $parsed, 'updateExisting' => $updateExisting, 'states' => $states,
            ], JSON_THROW_ON_ERROR)),
            'updateExisting' => $updateExisting,
        ];
    }

    public function importForUser(int $userId, array $parsed, bool $updateExisting, string $expectedFingerprint): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $expectedFingerprint) !== 1) {
            throw new MalImportChangedException(Translator::current()->translate('message.mal.import_expired'));
        }
        return $this->imports->transaction(function () use ($userId, $parsed, $updateExisting, $expectedFingerprint): array {
            $preview = $this->previewForUser($userId, $parsed, $updateExisting);
            if (!hash_equals($preview['fingerprint'], $expectedFingerprint)) {
                throw new MalImportChangedException(Translator::current()->translate('message.mal.changed'));
            }

            $before = $this->imports->totalExpForUser($userId);
            $changes = array_values(array_filter($preview['rows'], static fn (array $row): bool => in_array($row['action'], ['add', 'update'], true)));
            $this->imports->saveRowsForUser($userId, $changes);

            return [
                'added' => $preview['counts']['add'], 'updated' => $preview['counts']['update'],
                'unchanged' => $preview['counts']['unchanged'], 'skipped' => $preview['counts']['skipped'],
                'ratingsSaved' => count(array_filter($changes, static fn (array $row): bool => $row['ratingAction'] === 'set')),
                'ratingsRemoved' => count(array_filter($changes, static fn (array $row): bool => $row['ratingAction'] === 'remove')),
                'expBefore' => $before, 'expAfter' => $this->imports->totalExpForUser($userId),
                'rows' => $preview['rows'],
            ];
        });
    }

    private function planEntry(array $entry, ?array $state, bool $updateExisting): array
    {
        $row = [
            'malId' => $entry['malId'], 'animeId' => $state['animeId'] ?? null,
            'title' => $state['title'] ?? $entry['title'], 'status' => $entry['status'],
            'watched' => $entry['watched'], 'rating' => $entry['rating'],
            'currentStatus' => $state['status'] ?? null, 'currentWatched' => $state['watched'] ?? null,
            'currentRating' => $state['rating'] ?? null, 'action' => 'skipped',
            'reason' => '', 'ratingAction' => 'none',
        ];
        if ($state === null) {
            $row['reason'] = 'Nie znaleziono tego identyfikatora MAL w katalogu AniVibe.';
            return $row;
        }
        $episodes = $state['episodes'] !== null && $state['episodes'] > 0 ? $state['episodes'] : null;
        $row['watched'] = $row['status'] === 'planned' ? 0 : $row['watched'];
        if ($episodes !== null && $row['watched'] > $episodes) {
            $row['reason'] = 'Postęp z pliku przekracza liczbę odcinków zapisaną w katalogu AniVibe.';
            return $row;
        }
        if ($row['status'] === 'completed' && $episodes !== null) {
            $row['watched'] = $episodes;
        }
        if (!$updateExisting && $state['status'] !== null) {
            $row['status'] = $state['status'];
            $row['watched'] = $state['watched'];
            $row['rating'] = $state['rating'];
            $row['action'] = 'unchanged';
            $row['reason'] = 'Istniejący wpis i własna ocena pozostają bez zmian.';
            return $row;
        }

        $notes = [];
        if (!$updateExisting) {
            if ($state['rating'] !== null) {
                $row['rating'] = $state['rating'];
                $notes[] = 'Zachowano wcześniejszą własną ocenę.';
            }
            if ($state['creditedWatched'] !== null) {
                $row['watched'] = max($row['watched'], $state['creditedWatched']);
                if ($state['creditedCompleted']) {
                    $row['status'] = 'completed';
                } elseif ($row['status'] === 'planned' && $row['watched'] > 0) {
                    $row['status'] = 'watching';
                }
                if ($episodes !== null && $row['watched'] > $episodes) {
                    $row['reason'] = 'Zachowany wcześniejszy postęp przekracza obecną liczbę odcinków; pominięto wpis bez zmiany EXP.';
                    return $row;
                }
                $notes[] = 'Zachowano wcześniej zdobyty EXP.';
            }
        }
        $row['ratingAction'] = $row['rating'] === $state['rating'] ? 'none'
            : ($row['rating'] === null ? 'remove' : 'set');
        $same = $state['status'] === $row['status'] && $state['watched'] === $row['watched']
            && $state['rating'] === $row['rating'] && $state['creditedWatched'] === $row['watched']
            && $state['creditedCompleted'] === ($row['status'] === 'completed');
        $row['action'] = $state['status'] === null ? 'add' : ($same ? 'unchanged' : 'update');
        $row['reason'] = match ($row['action']) {
            'add' => 'Tytuł zostanie dodany do Twojej watchlisty.',
            'update' => 'Status, postęp i własna ocena zostaną zastąpione wartościami z podglądu.',
            default => 'Dane są już zgodne z importem; zapis nie jest potrzebny.',
        };
        $row['reasonParts'] = [$row['reason'], ...$notes];
        if ($notes !== []) {
            $row['reason'] .= ' ' . implode(' ', $notes);
        }
        return $row;
    }

    private function validateParsed(int $userId, array $parsed): void
    {
        if ($userId <= 0 || !is_array($parsed['entries'] ?? null) || !array_is_list($parsed['entries'])
            || !is_array($parsed['issues'] ?? null) || !array_is_list($parsed['issues'])
            || !is_int($parsed['total'] ?? null) || $parsed['total'] < 0
            || $parsed['total'] !== count($parsed['entries']) + count($parsed['issues'])
            || $parsed['total'] > MalXmlParser::MAX_ENTRIES) {
            throw new InvalidArgumentException(Translator::current()->translate('message.mal.invalid_preview'));
        }
        $seen = [];
        foreach ($parsed['entries'] as $entry) {
            if (!is_array($entry) || !is_int($entry['malId'] ?? null) || $entry['malId'] <= 0
                || $entry['malId'] > 2147483647 || isset($seen[$entry['malId']])
                || !is_string($entry['title'] ?? null) || !in_array($entry['status'] ?? null, self::STATUSES, true)
                || !is_int($entry['watched'] ?? null) || $entry['watched'] < 0 || $entry['watched'] > 2147483647
                || !array_key_exists('rating', $entry)
                || ($entry['rating'] !== null && (!is_int($entry['rating']) || $entry['rating'] < 1 || $entry['rating'] > 10))) {
                throw new InvalidArgumentException(Translator::current()->translate('message.mal.invalid_entry'));
            }
            $seen[$entry['malId']] = true;
        }
        foreach ($parsed['issues'] as $issue) {
            if (!is_array($issue) || !is_string($issue['title'] ?? null) || !is_string($issue['reason'] ?? null)
                || !array_key_exists('malId', $issue)
                || ($issue['malId'] !== null && (!is_int($issue['malId']) || $issue['malId'] <= 0))) {
                throw new InvalidArgumentException(Translator::current()->translate('message.mal.invalid_report'));
            }
        }
    }
}
