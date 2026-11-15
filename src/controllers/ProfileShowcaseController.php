<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\AnimeRepository;
use App\Repository\ProfileShowcaseRepository;
use App\Repository\UserRepository;
use App\Service\AchievementService;
use App\Service\ProfileShowcaseService;
use App\Service\Translator;
use Closure;

final class ProfileShowcaseController extends AppController
{
    public function __construct(
        private UserRepository|Closure $users,
        private AnimeRepository|Closure $animes,
        private ProfileShowcaseRepository|Closure $showcases,
        private AchievementService|Closure $achievements,
    ) {
    }

    public function index(): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $errors = [];

        if ($this->isPost()) {
            if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
                http_response_code(403);
                $errors[] = Translator::current()->translate('message.csrf_expired');
            } else {
                $errors = (new ProfileShowcaseService(
                    $this->showcases(), $this->animes(), $this->achievements(),
                ))->update($user['id'], $_POST['animeIds'] ?? [], $_POST['badgeCodes'] ?? []);

                if ($errors === []) {
                    $this->redirect('/profile?showcaseSaved=1');
                }
                http_response_code(422);
            }
        } else {
            $this->achievements()->synchronize($user['id']);
        }

        $savedSelection = $this->showcases()->findForUser($user['id']);
        $selection = $savedSelection;
        if ($this->isPost()) {
            // Keep editable submitted slots without passing nested or unbounded input to the view.
            $selection = [
                'animeIds' => $this->formValues($_POST['animeIds'] ?? []),
                'badgeCodes' => $this->formValues($_POST['badgeCodes'] ?? []),
            ];
        }
        $query = $this->query();
        $selectedIds = array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) filter_var($id, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]),
            $selection['animeIds'],
        )));
        $selectedAnimes = $this->animes()->findByIds($selectedIds);
        $animeChoices = $selectedAnimes + $this->animes()->search(query: $query, perPage: 24)['animes'];
        $earnedBadges = $this->achievements()->earnedForUser($user['id']);
        $savedAnimes = $this->animes()->findByIds($savedSelection['animeIds']);
        $showcaseAnimes = [];
        foreach ($savedSelection['animeIds'] as $id) {
            if (isset($savedAnimes[$id])) {
                $showcaseAnimes[$id] = $savedAnimes[$id];
            }
        }
        $badgesByCode = array_column($earnedBadges, null, 'code');
        $showcaseBadges = [];
        foreach ($savedSelection['badgeCodes'] as $code) {
            if (isset($badgesByCode[$code])) {
                $showcaseBadges[] = $badgesByCode[$code];
            }
        }

        $this->render('profile-showcase', [
            'pageTitle' => Translator::current()->translate('showcase.title') . ' — AniVibe',
            'user' => $user,
            'selection' => $selection,
            'animeChoices' => $animeChoices,
            'earnedBadges' => $earnedBadges,
            'showcaseQuery' => $query,
            'errors' => $errors,
            'showcaseAnimes' => $showcaseAnimes,
            'showcaseBadges' => $showcaseBadges,
            'canEditShowcase' => false,
        ]);
    }

    public function anime(): void
    {
        $this->requireLogin();
        $this->currentUser($this->users());
        $result = $this->animes()->search(query: $this->query(), perPage: 24);
        $animes = [];
        foreach ($result['animes'] as $id => $anime) {
            $animes[] = [
                'id' => $id,
                'title' => $anime['title'],
                'image' => $anime['coverUrl'],
                'year' => $anime['year'],
                'type' => $anime['type'],
            ];
        }

        header('Content-Type: application/json; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode(['animes' => $animes], JSON_THROW_ON_ERROR);
    }

    private function query(): string
    {
        $query = $_GET['q'] ?? $_POST['q'] ?? '';
        return is_string($query) && preg_match('//u', $query) === 1
            ? mb_substr(trim($query), 0, 160) : '';
    }

    private function formValues(mixed $values): array
    {
        return is_array($values) ? array_map(
            static fn (mixed $value): string => is_string($value) || is_int($value) ? (string) $value : '',
            array_slice(array_values($values), 0, 3),
        ) : [];
    }

    private function users(): UserRepository
    {
        if ($this->users instanceof Closure) {
            $this->users = ($this->users)();
        }
        return $this->users;
    }

    private function animes(): AnimeRepository
    {
        if ($this->animes instanceof Closure) {
            $this->animes = ($this->animes)();
        }
        return $this->animes;
    }

    private function showcases(): ProfileShowcaseRepository
    {
        if ($this->showcases instanceof Closure) {
            $this->showcases = ($this->showcases)();
        }
        return $this->showcases;
    }

    private function achievements(): AchievementService
    {
        if ($this->achievements instanceof Closure) {
            $this->achievements = ($this->achievements)();
        }
        return $this->achievements;
    }
}
