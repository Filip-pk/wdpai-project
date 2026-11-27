<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\CommunityRepository;
use App\Repository\MaintenanceRepository;
use Throwable;

/** The scheduled cleanup task (docs/maintenance.md). Running it again only finds less to delete. */
final class CleanupService
{
    /** A new avatar file may wait this long for its upload transaction to commit. */
    public const AVATAR_GRACE_SECONDS = 3600;

    public function __construct(
        private readonly MaintenanceRepository $maintenance,
        private readonly AvatarStorage $avatars,
        private readonly ApplicationLogger $logger,
    ) {
    }

    /**
     * @return array{status: 'finished'|'skipped', loginAttempts?: int, emailChanges?: int, activity?: int, avatarFiles?: int}
     */
    public function run(): array
    {
        if (!$this->maintenance->tryLock()) {
            $this->logger->record('info', 'maintenance', 'admin.log.cleanup_skipped');
            return ['status' => 'skipped'];
        }
        try {
            $removed = [
                'loginAttempts' => $this->maintenance->deleteLoginAttemptsOlderThan(LoginThrottle::RETENTION_SECONDS),
                'emailChanges' => $this->maintenance->deleteExpiredEmailChanges(),
                'activity' => $this->maintenance->deleteActivityOlderThan(CommunityRepository::ACTIVITY_RETENTION_SECONDS),
                'avatarFiles' => $this->avatars->removeUnreferenced($this->maintenance->avatarPaths(), self::AVATAR_GRACE_SECONDS),
            ];
            $this->logger->record('info', 'maintenance', 'admin.log.cleanup_finished', $removed);

            return ['status' => 'finished'] + $removed;
        } catch (Throwable $exception) {
            $this->logger->record('error', 'maintenance', 'admin.log.cleanup_failed', ['class' => $exception::class]);
            throw $exception;
        } finally {
            try {
                $this->maintenance->unlock();
            } catch (Throwable) {
                // The session lock also ends with the connection; keep the original outcome.
            }
        }
    }
}
