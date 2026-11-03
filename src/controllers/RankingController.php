<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ExperienceRepository;
use App\Repository\UserRepository;
use App\Service\ExperienceService;
use Closure;

final class RankingController extends AppController
{
    public function __construct(
        private UserRepository|Closure $users,
        private ExperienceRepository|Closure $experience,
    ) {
    }

    public function index(): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $ranking = $this->experience()->rankingForUser($user['id']);
        $leaders = array_map(
            static fn (array $entry): array => $entry + ExperienceService::progress($entry['totalExp']),
            $ranking['leaders'],
        );
        $ownRanking = $ranking['ownRanking'];
        if ($ownRanking !== null) {
            $ownRanking += ExperienceService::progress($ownRanking['totalExp']);
        }

        $this->render('ranking', [
            'pageTitle' => 'Ranking TOP 100 — AniVibe',
            'leaders' => $leaders,
            'ownRanking' => $ownRanking,
            'experience' => ExperienceService::progress($ownRanking['totalExp'] ?? 0),
            'isInTop100' => in_array($user['id'], array_column($leaders, 'userId'), true),
        ]);
    }

    private function users(): UserRepository
    {
        if ($this->users instanceof Closure) {
            $this->users = ($this->users)();
        }
        return $this->users;
    }

    private function experience(): ExperienceRepository
    {
        if ($this->experience instanceof Closure) {
            $this->experience = ($this->experience)();
        }
        return $this->experience;
    }
}
