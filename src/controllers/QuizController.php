<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\QuizService;
use App\Service\QuizQuestionLocalizer;
use App\Service\Translator;
use Closure;
use InvalidArgumentException;

final class QuizController extends AppController
{
    public function __construct(
        private UserRepository|Closure $users,
        private QuizService|Closure $quizService,
    ) {
    }

    public function index(): void
    {
        $user = null;
        if ($this->isPost()) {
            $this->requireLogin();
        }
        if (is_int($_SESSION['user_id'] ?? null) && $_SESSION['user_id'] > 0) {
            $user = $this->currentUser($this->users());
        }
        if ($user === null) {
            $this->show();
            return;
        }

        if ($this->isPost() && !$this->isCsrfTokenValid($this->postString('csrfToken'))) {
            http_response_code(403);
            $this->show(errors: [$this->message('quiz.error.csrf')]);
            return;
        }

        $errors = [];
        $answers = [];
        if ($this->isPost()) {
            $quizId = filter_var($this->postString('quizId'), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
            try {
                if ($quizId === false) {
                    throw new InvalidArgumentException('quiz.error.invalid');
                }
                $submittedAnswers = $_POST['answers'] ?? [];
                if (!is_array($submittedAnswers)) {
                    throw new InvalidArgumentException('quiz.error.answers');
                }
                $this->service()->submit($user['id'], $quizId, $submittedAnswers);
                $this->redirect('/quiz');
            } catch (InvalidArgumentException $exception) {
                $key = $exception->getMessage();
                if (!in_array($key, ['quiz.error.invalid', 'quiz.error.answers', 'quiz.error.expired', 'quiz.error.user'], true)) {
                    throw $exception;
                }
                http_response_code($key === 'quiz.error.expired' ? 409 : 422);
                $errors[] = $this->message($key);
                if ($key === 'quiz.error.answers' && is_array($_POST['answers'] ?? null)) {
                    foreach ($_POST['answers'] as $id => $answer) {
                        if ((is_int($answer) || is_string($answer))
                            && in_array((string) $answer, ['0', '1', '2', '3'], true)) {
                            $answers[$id] = (int) $answer;
                        }
                    }
                }
            }
        }

        // Error responses roll back AccountAccessGuard's transaction. Never render
        // a freshly inserted daily snapshot that would disappear after a 409/422.
        $quiz = $errors === [] ? $this->service()->dailyQuiz() : $this->service()->availableQuiz();
        $this->show(
            quiz: $quiz === null ? null : QuizQuestionLocalizer::localize($quiz, Translator::current()->locale()),
            result: $quiz === null ? null : $this->service()->resultForUser($user['id'], $quiz['id']),
            statistics: $this->service()->statistics($user['id']),
            errors: $errors,
            answers: $answers,
        );
    }

    public function ranking(): void
    {
        $this->requireLogin();
        $user = $this->currentUser($this->users());
        $period = $_GET['period'] ?? 'overall';
        $errors = [];
        if (!is_string($period) || !in_array($period, ['overall', 'monthly'], true)) {
            http_response_code(400);
            $errors[] = $this->message('quiz.error.period');
            $period = 'overall';
        }
        $this->render('quiz-ranking', [
            'pageTitle' => $this->message('quiz.ranking.title'),
            'quizRanking' => $this->service()->ranking($user['id'], $period),
            'quizErrors' => $errors,
        ]);
    }

    private function show(
        ?array $quiz = null,
        ?array $result = null,
        ?array $statistics = null,
        array $errors = [],
        array $answers = [],
    ): void {
        $this->render('quiz', [
            'pageTitle' => $this->message('quiz.page_title'),
            'quiz' => $quiz,
            'quizResult' => $result,
            'quizStats' => $statistics,
            'quizErrors' => $errors,
            'quizAnswers' => $answers,
        ]);
    }

    private function message(string $key): string
    {
        return Translator::current()->translate($key);
    }

    private function users(): UserRepository
    {
        if ($this->users instanceof Closure) {
            $this->users = ($this->users)();
        }
        return $this->users;
    }

    private function service(): QuizService
    {
        if ($this->quizService instanceof Closure) {
            $this->quizService = ($this->quizService)();
        }
        return $this->quizService;
    }
}
