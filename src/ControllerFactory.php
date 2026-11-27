<?php

declare(strict_types=1);

namespace App;

use App\Controller\AchievementController;
use App\Controller\AdminController;
use App\Controller\AnimeClueController;
use App\Controller\AnimeController;
use App\Controller\AnimeRankingController;
use App\Controller\AppController;
use App\Controller\AuthController;
use App\Controller\ChatController;
use App\Controller\CollectionController;
use App\Controller\CommentReactionController;
use App\Controller\CommunityController;
use App\Controller\DashboardController;
use App\Controller\DefaultController;
use App\Controller\LanguageController;
use App\Controller\MalImportController;
use App\Controller\MessageController;
use App\Controller\ModerationController;
use App\Controller\PrivacySettingsController;
use App\Controller\ProfileController;
use App\Controller\ProfileShowcaseController;
use App\Controller\ProjectController;
use App\Controller\QuizController;
use App\Controller\RankingController;
use App\Controller\ReplyNotificationController;
use App\Controller\SecuritySettingsController;
use App\Controller\SettingsController;
use App\Repository\AchievementRepository;
use App\Repository\AdminRepository;
use App\Repository\AnimeClueRepository;
use App\Repository\AnimeRankingRepository;
use App\Repository\AnimeRepository;
use App\Repository\CollectionRepository;
use App\Repository\CommentReactionRepository;
use App\Repository\CommentRepository;
use App\Repository\CommunityRepository;
use App\Repository\ExperienceRepository;
use App\Repository\LoginAttemptRepository;
use App\Repository\MalImportRepository;
use App\Repository\MessageRepository;
use App\Repository\ModerationRepository;
use App\Repository\PreferencesRepository;
use App\Repository\PrivacySettingsRepository;
use App\Repository\ProfileShowcaseRepository;
use App\Repository\ProjectRepository;
use App\Repository\QuizRepository;
use App\Repository\RatingRepository;
use App\Repository\RecommendationRepository;
use App\Repository\ReplyNotificationRepository;
use App\Repository\SecuritySettingsRepository;
use App\Repository\UserRepository;
use App\Repository\WatchlistRepository;
use App\Service\AchievementService;
use App\Service\AnimeClueService;
use App\Service\ApplicationLogger;
use App\Service\DiagnosticService;
use App\Service\LoginThrottle;
use App\Service\MailService;
use App\Service\MalImportService;
use App\Service\QuizService;
use App\Service\RecommendationService;
use Closure;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Builds the controller for each routed request with its dependencies. Repositories are passed
 * as closures, so the database connection opens only when a request first needs it, and every
 * repository of the request shares that one connection (and the request transaction).
 */
final class ControllerFactory
{
    private ?PDO $connection = null;
    private ?array $mailConfiguration = null;

    public function __construct(
        private readonly ApplicationLogger $logger,
        private readonly string $rootPath,
    ) {
    }

    public function connection(): PDO
    {
        return $this->connection ??= (new Database())->connect();
    }

    /** Called by the router for the matched controller class. */
    public function __invoke(string $controllerClass): AppController
    {
        $controller = $this->create($controllerClass);
        $controller->setModerationRepository($this->repository(ModerationRepository::class));
        $controller->setCommunityRepository($this->repository(CommunityRepository::class));
        $controller->setPreferencesRepository($this->repository(PreferencesRepository::class));
        try {
            $controller->initializeLanguage($controllerClass !== AuthController::class);
        } catch (RuntimeException $exception) {
            if (!$controller instanceof DefaultController
                || (!$exception instanceof PDOException && !$exception->getPrevious() instanceof PDOException)) {
                throw $exception;
            }
            // The public home page can use the session language while the database is unavailable.
            $controller = new DefaultController();
            $controller->initializeLanguage(false);
        }

        return $controller;
    }

    private function create(string $controllerClass): AppController
    {
        $users = $this->repository(UserRepository::class);
        $achievements = fn (): AchievementService => new AchievementService(new AchievementRepository($this->connection()));

        return match ($controllerClass) {
            AdminController::class => new AdminController($users, $this->repository(AdminRepository::class),
                // Diagnostics open their own connection to test it independently of the request.
                new DiagnosticService(static fn (): PDO => (new Database())->connect(), $this->mailConfiguration(),
                    $this->logger, $this->rootPath),
                $this->logger, $this->mailer()),
            AchievementController::class => new AchievementController($users, $achievements),
            AnimeClueController::class => new AnimeClueController($users,
                fn (): AnimeClueService => new AnimeClueService(new AnimeClueRepository($this->connection())), $achievements),
            AnimeController::class => new AnimeController($this->repository(AnimeRepository::class),
                $this->repository(CollectionRepository::class), $users, $this->repository(RatingRepository::class),
                $this->repository(CommentRepository::class), $this->repository(CommunityRepository::class),
                $this->repository(ReplyNotificationRepository::class), $this->repository(CommentReactionRepository::class)),
            AnimeRankingController::class => new AnimeRankingController($this->repository(AnimeRepository::class),
                $this->repository(AnimeRankingRepository::class)),
            AuthController::class => new AuthController($users, $this->mailer(),
                fn (): LoginThrottle => new LoginThrottle(new LoginAttemptRepository($this->connection())), $this->logger),
            ChatController::class => new ChatController($this->connection(...)),
            CollectionController::class => new CollectionController($users, $this->repository(CollectionRepository::class),
                $this->repository(AnimeRepository::class), $this->repository(WatchlistRepository::class),
                $this->repository(RatingRepository::class)),
            CommentReactionController::class => new CommentReactionController($users, $this->repository(CommentRepository::class),
                $this->repository(CommunityRepository::class), $this->repository(CommentReactionRepository::class)),
            CommunityController::class => new CommunityController($users, $this->repository(CommunityRepository::class),
                $this->repository(MessageRepository::class)),
            DashboardController::class => new DashboardController($users, $this->repository(AnimeRepository::class),
                $this->repository(RatingRepository::class),
                fn (): RecommendationService => new RecommendationService(new RecommendationRepository($this->connection())),
                fn (): QuizService => new QuizService(new QuizRepository($this->connection()))),
            LanguageController::class => new LanguageController($this->repository(PreferencesRepository::class)),
            MalImportController::class => new MalImportController($users,
                fn (): MalImportService => new MalImportService(new MalImportRepository($this->connection())), $achievements),
            MessageController::class => new MessageController($users, $this->repository(MessageRepository::class),
                $this->repository(CommunityRepository::class)),
            ModerationController::class => new ModerationController($users, $this->repository(ModerationRepository::class)),
            PrivacySettingsController::class => new PrivacySettingsController($users,
                $this->repository(PrivacySettingsRepository::class)),
            ProfileController::class => new ProfileController($users, $this->repository(WatchlistRepository::class),
                $this->repository(AnimeRepository::class), $this->repository(RatingRepository::class),
                $this->repository(ExperienceRepository::class), $this->repository(CommunityRepository::class),
                $this->repository(PrivacySettingsRepository::class), $achievements,
                $this->repository(ProfileShowcaseRepository::class)),
            ProfileShowcaseController::class => new ProfileShowcaseController($users, $this->repository(AnimeRepository::class),
                $this->repository(ProfileShowcaseRepository::class), $achievements),
            ProjectController::class => new ProjectController($this->repository(ProjectRepository::class)),
            QuizController::class => new QuizController($users,
                fn (): QuizService => new QuizService(new QuizRepository($this->connection())), $achievements),
            RankingController::class => new RankingController($users, $this->repository(ExperienceRepository::class)),
            ReplyNotificationController::class => new ReplyNotificationController($users,
                $this->repository(ReplyNotificationRepository::class)),
            SecuritySettingsController::class => new SecuritySettingsController($users,
                $this->repository(SecuritySettingsRepository::class), $this->mailer()),
            SettingsController::class => new SettingsController($users, $this->repository(PreferencesRepository::class),
                $this->repository(PrivacySettingsRepository::class)),
            default => new $controllerClass(),
        };
    }

    /**
     * A closure that creates the repository on first use, with the request connection.
     *
     * @param class-string $class
     */
    private function repository(string $class): Closure
    {
        return fn (): object => new $class($this->connection());
    }

    private function mailer(): MailService
    {
        return new MailService(...$this->mailConfiguration());
    }

    private function mailConfiguration(): array
    {
        return $this->mailConfiguration ??= (require $this->rootPath . '/config.php')['mail'];
    }
}
