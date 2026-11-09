<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CommentReactionRepository;
use App\Repository\CommunityRepository;

final class CommentReactionService
{
    public function __construct(
        private readonly CommunityRepository $community,
        private readonly CommentReactionRepository $reactions,
    ) {
    }

    public function setForUser(int $userId, int $animeId, int $commentId, int $value): bool
    {
        if (!in_array($value, [-1, 0, 1], true) || $userId <= 0 || $animeId <= 0 || $commentId <= 0) {
            return false;
        }
        $target = $this->reactions->targetForUser($userId, $animeId, $commentId);
        if ($target === null) {
            return false;
        }

        return $this->community->withUsersLocked([$userId, $target['userId']], function () use ($userId, $animeId, $commentId, $value): bool {
            if ($this->reactions->targetForUser($userId, $animeId, $commentId, true) === null) {
                return false;
            }
            if ($this->reactions->currentForUser($userId, $commentId) === $value) {
                return true;
            }

            $hash = hash('sha256', $commentId . ':' . $value);
            $this->community->assertActionAllowed($userId, 'reaction', $hash);
            if (!$this->reactions->setForUser($userId, $animeId, $commentId, $value)) {
                return false;
            }
            $this->community->recordAction($userId, 'reaction', $hash);

            return true;
        });
    }
}
