<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Translator;

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
            'pageTitle' => Translator::current()->translate('message.page.ranking'),
            'leaders' => $leaders,
            'ownRanking' => $ownRanking,
            'experience' => ExperienceService::progress($ownRanking['totalExp'] ?? 0),
            'isInTop100' => in_array($user['id'], array_column($leaders, 'userId'), true),
        ]);
    }

    private function users(): UserRepository
    {
        return self::resolveDependency($this->users);
    }

    private function experience(): ExperienceRepository
    {
        return self::resolveDependency($this->experience);
    }
}
