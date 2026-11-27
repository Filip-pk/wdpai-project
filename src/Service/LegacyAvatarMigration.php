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
     * Copies every photo to a file and records its path. The database copy disappears with the
     * avatar_data column in migration 35, which refuses to run while a photo still has no file.
     *
     * @return array{copied: int, skipped: int}
     */
    public function copyToFiles(): array
    {
        if (!$this->avatars->hasLegacyColumn()) {
            return ['copied' => 0, 'skipped' => 0];
        }
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
}
