<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Pagination;
use Throwable;

/** Stores bounded, deliberately limited diagnostic metadata outside the public directory. */
final class ApplicationLogger
{
    private const LEVELS = ['error', 'warning', 'info'];
    /** Fixed module names; the admin error filter offers exactly these. */
    public const MODULES = ['application', 'database', 'mail', 'smtp', 'ollama', 'environment', 'admin', 'auth', 'diagnostics', 'maintenance'];
    private const MESSAGES = [
        'admin.log.exception', 'admin.log.diagnostic_failed', 'admin.log.event',
        'admin.log.mail_failed', 'admin.log.runtime_error', 'admin.log.fatal_error',
        'admin.log.login_failed', 'admin.log.login_throttled',
        'admin.log.cleanup_finished', 'admin.log.cleanup_skipped', 'admin.log.cleanup_failed',
    ];
    /** Counts of rows and files removed by the cleanup task. */
    public const CLEANUP_COUNTS = ['loginAttempts', 'emailChanges', 'activity', 'avatarFiles'];
    private readonly int $maxBytes;
    private readonly int $retentionDays;
    private readonly int $maxFiles;

    public function __construct(
        private readonly string $directory,
        int $maxBytes = 1048576,
        int $retentionDays = 7,
        int $maxFiles = 8,
    ) {
        $this->maxBytes = max(4096, min($maxBytes, 4194304));
        $this->retentionDays = max(1, min($retentionDays, 30));
        $this->maxFiles = max(2, min($maxFiles, 16));
    }

    public function recordException(Throwable $exception, string $module, string $path = ''): string
    {
        return $this->record('error', $module, 'admin.log.exception', [
            'class' => $exception::class,
            'path' => $path,
        ]);
    }

    public function record(string $level, string $module, string $messageKey, array $context = []): string
    {
        $id = $this->identifier();
        $lock = null;

        try {
            if (!$this->prepareDirectory()) {
                return $id;
            }
            $lock = @fopen($this->directory . '/.application.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                return $id;
            }
            $entry = [
                'id' => $id,
                'createdAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
                'level' => in_array($level, self::LEVELS, true) ? $level : 'error',
                'module' => in_array($module, self::MODULES, true) ? $module : 'application',
                'messageKey' => in_array($messageKey, self::MESSAGES, true) ? $messageKey : 'admin.log.event',
                'context' => $this->safeContext($context),
            ];
            $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
            $active = $this->directory . '/application.jsonl';
            if (is_link($active)) {
                return $id;
            }
            clearstatcache(true, $active);
            if (is_file($active) && filesize($active) + strlen($line) > $this->maxBytes) {
                $stamp = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd-His-u');
                if (!@rename($active, $this->directory . '/application-' . $stamp . '-' . $id . '.jsonl')) {
                    return $id;
                }
            }
            @file_put_contents($active, $line, FILE_APPEND);
            @chmod($active, 0660);
            $this->prune();
        } catch (Throwable) {
            // Failure to log must never replace the original application response.
        } finally {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }

        return $id;
    }

    public function isWritable(): bool
    {
        try {
            if (!$this->prepareDirectory()) {
                return false;
            }
            $path = $this->directory . '/.probe-' . $this->identifier();
            $probe = @fopen($path, 'x');
            if ($probe === false) {
                return false;
            }
            $written = @fwrite($probe, '1');
            @fclose($probe);
            @unlink($path);

            return $written === 1;
        } catch (Throwable) {
            return false;
        }
    }

    /** Reads at most maxFiles * maxBytes of retained records; no arbitrary file can be selected. */
    public function recent(string $level = '', string $module = '', int $page = 1): array
    {
        $items = [];
        $lock = null;

        try {
            if (!is_dir($this->directory) || is_link($this->directory)) {
                return $this->page([], $page);
            }
            $lock = @fopen($this->directory . '/.application.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_SH | LOCK_NB)) {
                return $this->page([], $page);
            }
            foreach (array_slice($this->files(), 0, $this->maxFiles) as $file) {
                if ((int) @filemtime($file) < time() - $this->retentionDays * 86400) {
                    continue;
                }
                $handle = @fopen($file, 'rb');
                if ($handle === false) {
                    continue;
                }
                $data = @stream_get_contents($handle, $this->maxBytes);
                fclose($handle);
                if (!is_string($data)) {
                    continue;
                }
                foreach (explode("\n", $data) as $line) {
                    if (strlen($line) > 2048) {
                        continue;
                    }
                    $entry = json_decode($line, true, 16);
                    if (!$this->validEntry($entry)
                        || ($level !== '' && $entry['level'] !== $level)
                        || ($module !== '' && $entry['module'] !== $module)) {
                        continue;
                    }
                    $entry = array_intersect_key($entry, array_flip(['id', 'createdAt', 'level', 'module', 'messageKey', 'context']));
                    $entry['context'] = $this->safeContext($entry['context'] ?? []);
                    $items[] = $entry;
                }
            }
            usort($items, static fn (array $a, array $b): int => strcmp($b['createdAt'], $a['createdAt']));
        } catch (Throwable) {
            return $this->page([], $page);
        } finally {
            if (is_resource($lock)) {
                @flock($lock, LOCK_UN);
                @fclose($lock);
            }
        }

        return $this->page($items, $page);
    }

    private function safeContext(array $context): array
    {
        $safe = [];
        if (is_string($context['class'] ?? null)
            && preg_match('/\A[A-Za-z_][A-Za-z0-9_\\\\]{0,159}\z/', $context['class']) === 1) {
            $safe['class'] = $context['class'];
        }
        if (is_string($context['path'] ?? null) && $context['path'] !== '') {
            $safe['path'] = $this->safePath($context['path']);
        }
        if (is_string($context['service'] ?? null) && in_array($context['service'], self::MODULES, true)) {
            $safe['service'] = $context['service'];
        }
        if (is_string($context['errorId'] ?? null) && preg_match('/\A[a-f0-9]{24}\z/', $context['errorId']) === 1) {
            $safe['errorId'] = $context['errorId'];
        }
        if (is_int($context['code'] ?? null) && $context['code'] >= 0 && $context['code'] <= 65535) {
            $safe['code'] = $context['code'];
        }
        // Sign-in audit: shortened SHA-256 identifiers only, never an e-mail or IP address.
        foreach (['account', 'client'] as $key) {
            if (is_string($context[$key] ?? null) && preg_match('/\A[a-f0-9]{16}\z/', $context[$key]) === 1) {
                $safe[$key] = $context[$key];
            }
        }
        foreach (self::CLEANUP_COUNTS as $key) {
            if (is_int($context[$key] ?? null) && $context[$key] >= 0) {
                $safe[$key] = $context[$key];
            }
        }

        return $safe;
    }

    private function safePath(string $path): string
    {
        $parsed = parse_url($path, PHP_URL_PATH);
        if (!is_string($parsed) || !str_starts_with($parsed, '/') || strlen($parsed) > 512) {
            return '/[other]';
        }
        $allowed = [
            'admin', 'diagnostics', 'errors', 'users', 'roles', 'moderator', 'history', 'catalog', 'mail', 'test-mail',
            'anime', 'ranking', 'comments', 'replies', 'reaction', 'edit', 'delete', 'rating', 'remove', 'collections', 'create',
            'profile', 'showcase', 'avatar', 'theme', 'import', 'preview', 'confirm', 'watchlist',
            'settings', 'security', 'privacy', 'email', 'password', 'language', 'login', 'logout', 'register',
            'messages', 'new', 'read', 'blocked', 'notifications', 'read-all', 'reports', 'comment', 'message',
            'moderation', 'resolve', 'dismiss', 'warn', 'ban', 'unban', 'account', 'banned', 'notices',
            'dashboard', 'quiz', 'guess', 'suggestions', 'reveal', 'answer', 'skip', 'achievements', 'projects', 'chat',
        ];
        $parts = [];
        foreach (explode('/', trim($parsed, '/')) as $part) {
            if ($part === '') {
                continue;
            }
            $parts[] = ctype_digit($part) || $part === '[id]' ? '[id]'
                : (in_array($part, $allowed, true) || $part === '[other]' ? $part : '[other]');
        }

        return '/' . implode('/', array_slice($parts, 0, 8));
    }

    private function prepareDirectory(): bool
    {
        return !is_link($this->directory)
            && (is_dir($this->directory) || @mkdir($this->directory, 0770, true))
            && is_writable($this->directory);
    }

    private function files(): array
    {
        $files = array_values(array_filter(glob($this->directory . '/application*.jsonl') ?: [],
            static fn (string $path): bool => is_file($path) && !is_link($path)
                && preg_match('/\Aapplication(?:-[0-9]{8}-[0-9]{6}(?:-[0-9]{6})?-[a-f0-9]{24})?\.jsonl\z/', basename($path)) === 1));
        usort($files, static function (string $a, string $b): int {
            if (basename($a) === 'application.jsonl') {
                return -1;
            }
            if (basename($b) === 'application.jsonl') {
                return 1;
            }

            return ((int) @filemtime($b)) <=> ((int) @filemtime($a)) ?: strcmp(basename($b), basename($a));
        });

        return $files;
    }

    private function prune(): void
    {
        $kept = 1;
        foreach ($this->files() as $file) {
            if (basename($file) === 'application.jsonl') {
                continue;
            }
            if ((int) @filemtime($file) < time() - $this->retentionDays * 86400 || $kept >= $this->maxFiles) {
                @unlink($file);
            } else {
                $kept++;
            }
        }
    }

    private function validEntry(mixed $entry): bool
    {
        return is_array($entry)
            && is_string($entry['id'] ?? null) && preg_match('/\A[a-f0-9]{24}\z/', $entry['id']) === 1
            && is_string($entry['createdAt'] ?? null) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{6})?Z\z/', $entry['createdAt']) === 1
            && in_array($entry['level'] ?? null, self::LEVELS, true)
            && in_array($entry['module'] ?? null, self::MODULES, true)
            && in_array($entry['messageKey'] ?? null, self::MESSAGES, true)
            && is_array($entry['context'] ?? null);
    }

    private function page(array $items, int $page): array
    {
        $pagination = Pagination::of(count($items), $page, 20);

        return [
            'items' => array_slice($items, ($pagination['page'] - 1) * 20, 20),
            'pagination' => $pagination,
            'bounded' => true,
        ];
    }

    private function identifier(): string
    {
        try {
            return bin2hex(random_bytes(12));
        } catch (Throwable) {
            return substr(hash('sha256', uniqid('', true)), 0, 24);
        }
    }
}
