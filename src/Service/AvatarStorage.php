<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/** Profile photos as files under storage/uploads; the database keeps only their relative path. */
final class AvatarStorage
{
    private const DIRECTORY = 'avatars';
    private const PATH = '/\Aavatars\/[a-f0-9]{32}\.png\z/';

    public function __construct(private readonly string $uploadsPath)
    {
    }

    public static function forApplication(): self
    {
        return new self(dirname(__DIR__, 2) . '/storage/uploads');
    }

    public static function isValidPath(string $path): bool
    {
        return preg_match(self::PATH, $path) === 1;
    }

    /** Writes the image under a new random name and returns its path relative to the uploads directory. */
    public function save(string $contents): string
    {
        $directory = $this->uploadsPath . '/' . self::DIRECTORY;
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('The avatar directory cannot be created.');
        }
        $path = self::DIRECTORY . '/' . bin2hex(random_bytes(16)) . '.png';
        $target = $this->uploadsPath . '/' . $path;
        // A complete file appears under its final name only after the write succeeded.
        $temporary = $target . '.tmp';
        if (@file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents) || !@rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('The avatar file cannot be saved.');
        }

        return $path;
    }

    public function read(string $path): ?string
    {
        if (!self::isValidPath($path)) {
            return null;
        }
        $file = $this->uploadsPath . '/' . $path;
        $contents = is_file($file) && !is_link($file) ? @file_get_contents($file) : false;

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    /**
     * Deletes avatar files that no account refers to. Files younger than $minimumAgeSeconds are kept,
     * because the upload that created them may not have committed its database change yet.
     *
     * @param list<string> $referencedPaths
     */
    public function removeUnreferenced(array $referencedPaths, int $minimumAgeSeconds): int
    {
        $referenced = array_fill_keys($referencedPaths, true);
        $cutoff = time() - $minimumAgeSeconds;
        $removed = 0;
        foreach (glob($this->uploadsPath . '/' . self::DIRECTORY . '/*') ?: [] as $file) {
            $name = basename($file);
            $path = self::DIRECTORY . '/' . (str_ends_with($name, '.png.tmp') ? substr($name, 0, -4) : $name);
            if (!self::isValidPath($path) || !is_file($file) || is_link($file)
                || (!str_ends_with($name, '.tmp') && isset($referenced[$path]))
                || (int) @filemtime($file) > $cutoff) {
                continue;
            }
            if (@unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }
}
