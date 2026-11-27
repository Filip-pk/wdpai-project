<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\AchievementService;
use App\Service\AnimeClueService;
use App\Service\AnimeClueTitleSearch;
use App\Service\Translator;
use Closure;
use InvalidArgumentException;

final class AnimeClueController extends AppController
{
    public function __construct(
        private UserRepository|Closure $users,
        private AnimeClueService|Closure $game,
        private AchievementService|Closure $achievements,
    ) {
    }

    public function index(): void
    {
        if (!is_int($_SESSION['user_id'] ?? null) || $_SESSION['user_id'] <= 0) {
            $this->show();
            return;
        }
        $user = $this->currentUser($this->users());
        $this->show(
            $this->game()->stateForUser($user['id'], Translator::current()->locale()),
            $this->game()->statistics($user['id']),
        );
    }

    public function reveal(): void
    {
        $this->act('reveal');
    }

    public function ranking(): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $this->render('anime-clue-ranking', [
            'pageTitle' => $this->message('guess.ranking.title'),
            'clueRanking' => $this->game()->weeklyRanking($user['id']),
        ]);
    }

    public function answer(): void
    {
        $this->act('answer');
    }

    public function suggestions(): void
    {
        $this->requireLogin();
        $this->currentUser($this->users());
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['suggestions' => AnimeClueTitleSearch::suggestions($_GET['q'] ?? '')],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function skip(): void
    {
        $this->act('skip');
    }

    private function act(string $action): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        if (!$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            http_response_code(403);
            $this->show(errors: [$this->message('guess.error.csrf')]);
            return;
        }
        $errors = [];
        try {
            $id = filter_var($this->postString('gameId'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $round = filter_var($this->postString('roundIndex'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4]]);
            if ($id === false || $round === false) {
                throw new InvalidArgumentException('guess.error.round');
            }
            if (in_array($action, ['reveal', 'answer'], true)) {
                $hints = filter_var($this->postString('expectedHints'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3]]);
                if ($hints === false) {
                    throw new InvalidArgumentException('guess.error.hints');
                }
            }
            if ($action === 'reveal') {
                $this->game()->reveal($user['id'], $id, $round, $hints);
            } elseif ($action === 'answer') {
                $this->game()->answer($user['id'], $id, $round, $this->postString('answer'), $hints);
            } else {
                $this->game()->skip($user['id'], $id, $round);
            }
            $this->achievements()->synchronize($user['id']);
            $this->redirect('/guess');
        } catch (InvalidArgumentException $exception) {
            $key = $exception->getMessage();
            if (!in_array($key, ['guess.error.expired', 'guess.error.round', 'guess.error.answer', 'guess.error.hints', 'guess.error.user'], true)) {
                throw $exception;
            }
            http_response_code(in_array($key, ['guess.error.expired', 'guess.error.round'], true) ? 409 : 422);
            $errors[] = $this->message($key);
        }
        // An error response rolls back its request transaction. Only read an existing day.
        $this->show(
            $this->game()->stateForUser($user['id'], Translator::current()->locale(), false),
            $this->game()->statistics($user['id']),
            $errors,
        );
    }

    private function show(?array $state = null, ?array $statistics = null, array $errors = []): void
    {
        $this->render('anime-clues', [
            'pageTitle' => $this->message('guess.page_title'),
            'gameState' => $state,
            'gameStats' => $statistics,
            'gameErrors' => $errors,
        ]);
    }

    private function message(string $key): string
    {
        return Translator::current()->translate($key);
    }

    private function users(): UserRepository
    {
        return self::resolveDependency($this->users);
    }

    private function game(): AnimeClueService
    {
        return self::resolveDependency($this->game);
    }

    private function achievements(): AchievementService
    {
        return self::resolveDependency($this->achievements);
    }
}
