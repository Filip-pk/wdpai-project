<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\LegacyAvatarRepository;

/** Moves photos from users.avatar_data to avatar files (scripts/migrate-avatars.php). */
final class LegacyAvatarMigration
{
    private const BATCH = 100;
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    public function __construct(
        private readonly LegacyAvatarRepository $avatars,
        private readonly AvatarStorage $storage,
    ) {
    }

    /**
     * Copies every photo to a file and records its path; the database copy stays, so older
     * application versions keep working. Returns how many photos were copied or skipped.
     *
     * @return array{copied: int, skipped: int}
     */
    public function copyToFiles(): array
    {
        $copied = 0;
        $skipped = 0;
        $afterId = 0;
        while (($rows = $this->avatars->withoutFile($afterId, self::BATCH)) !== []) {
            foreach ($rows as $row) {
                $afterId = $row['id'];
                // Every stored photo was re-encoded as PNG on upload; anything else is left untouched.
                if (!str_starts_with($row['contents'], self::PNG_SIGNATURE)) {
                    $skipped++;
                    continue;
                }
                // If the account changed its photo meanwhile, the new file is unused and the cleanup task removes it.
                $this->avatars->assignPath($row['id'], $this->storage->save($row['contents']), $row['version'])
                    ? $copied++ : $skipped++;
            }
        }

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    /** Removes the database copy of each photo whose file holds exactly the same bytes. */
    public function clearDatabaseCopies(): int
    {
        $cleared = 0;
        $afterId = 0;
        while (($rows = $this->avatars->withFile($afterId, self::BATCH)) !== []) {
            foreach ($rows as $row) {
                $afterId = $row['id'];
                if ($this->storage->read($row['path']) === $row['contents']
                    && $this->avatars->clearDatabaseCopy($row['id'], $row['path'])) {
                    $cleared++;
                }
            }
        }

        return $cleared;
    }
}
