<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CommunityRepository;
use Closure;

final class CommunityService
{
    public function __construct(private readonly CommunityRepository $community)
    {
    }

    public function runLimited(int $userId, string $action, string $text, Closure $mutation, ?int $otherUserId = null): mixed
    {
        $users = $otherUserId === null ? [$userId] : [$userId, $otherUserId];

        return $this->community->withUsersLocked($users, function () use ($userId, $action, $text, $mutation, $otherUserId): mixed {
            if ($action === 'message' && $otherUserId !== null
                && $this->community->blockStateForUser($userId, $otherUserId)['blockedEitherWay']) {
                throw new UserBlockedException();
            }

            $hash = hash('sha256', $text);
            $this->community->assertActionAllowed($userId, $action, $hash);
            $result = $mutation();

            if ($result !== null && $result !== false) {
                $this->community->recordAction($userId, $action, $hash);
            }

            return $result;
        });
    }

    public function report(int $userId, string $type, int $targetId, string $reason): ?int
    {
        $reason = self::normalizeReason($reason);

        if (self::validateReason($reason) !== []) {
            return null;
        }

        $target = $this->community->reportTargetForUser($userId, $type, $targetId);
        if ($target === null && !$this->community->reportExistsForUser($userId, $type, $targetId)) {
            return null;
        }
        $users = $target === null ? [$userId] : [$userId, $target['authorId']];

        return $this->community->withUsersLocked($users, function () use ($userId, $type, $targetId, $reason): ?int {
            if ($this->community->reportExistsForUser($userId, $type, $targetId)) {
                return $this->community->createReportForUser($userId, $type, $targetId, $reason);
            }

            $hash = hash('sha256', $type . ':' . $targetId . ':' . $reason);
            $this->community->assertActionAllowed($userId, 'report', $hash);
            $reportId = $this->community->createReportForUser($userId, $type, $targetId, $reason);

            if ($reportId !== null) {
                $this->community->recordAction($userId, 'report', $hash);
            }

            return $reportId;
        });
    }

    public static function normalizeReason(string $reason): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $reason), " \t\r\n");
    }

    /** @return list<string> */
    public static function validateReason(string $reason): array
    {
        if (preg_match('//u', $reason) !== 1) {
            return [Translator::current()->translate('message.report.invalid')];
        }

        if (preg_match('/[^\s\p{Z}]/u', $reason) !== 1) {
            return [Translator::current()->translate('message.report.reason_required')];
        }

        if (preg_match_all('/./us', $reason) > 1000) {
            return [Translator::current()->translate('message.report.reason_length')];
        }

        if (preg_match('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u', $reason) === 1) {
            return [Translator::current()->translate('message.report.controls')];
        }

        return [];
    }
}
