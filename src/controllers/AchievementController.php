<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\AchievementService;
use App\Service\Translator;
use Closure;

final class AchievementController extends AppController
{
    public function __construct(
        private UserRepository|Closure $users,
        private AchievementService|Closure $achievements,
    ) {
    }

    public function index(): void
    {
        $this->requireLogin();
        self::resolveDependency($this->users);
        $user = $this->currentUser($this->users);
        self::resolveDependency($this->achievements);
        $this->achievements->synchronize($user['id']);
        $this->render('achievements', [
            'pageTitle' => Translator::current()->translate('achievements.page_title'),
            'achievementOverview' => $this->achievements->overviewForUser($user['id']),
        ]);
    }
}
