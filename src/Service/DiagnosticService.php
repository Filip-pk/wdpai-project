<?php

declare(strict_types=1);

namespace App\Service;

use Closure;
use PDO;
use RuntimeException;
use Throwable;
use App\Repository\DatabaseValue;

final class DiagnosticService
{
    private const MODEL_NAME = '/\A[a-zA-Z0-9][a-zA-Z0-9_.:\/-]{0,119}\z/';

    /** Every table created by database/*.sql; tests/admin-diagnostics.test.php keeps this list in step. */
    public const REQUIRED_TABLES = [
        'admin_actions', 'anime', 'anime_clue_answers', 'anime_clue_attempts', 'anime_collection_items',
        'anime_collections', 'anime_comments', 'anime_external_ids', 'anime_genres', 'comment_reactions',
        'comment_reply_notifications', 'community_activity', 'community_reports', 'daily_anime_clues',
        'daily_quizzes', 'genres', 'login_attempts', 'moderation_actions', 'moderation_notices',
        'private_conversations', 'private_message_reads', 'private_messages', 'projects', 'quiz_attempts',
        'user_achievements', 'user_anime_experience', 'user_anime_ratings', 'user_bans', 'user_blocks',
        'user_email_changes', 'user_showcase_anime', 'user_showcase_badges', 'user_watchlist', 'users',
    ];

    /** Every column added to an existing table by database/*.sql, as table.column. */
    public const REQUIRED_COLUMNS = [
        'anime.airing_status', 'anime.chat_synopsis', 'anime.search_text', 'anime_clue_attempts.wrong_answers',
        'anime_comments.deleted_at', 'anime_comments.moderated_at', 'anime_comments.parent_id',
        'private_messages.moderated_at', 'users.avatar_path', 'users.avatar_version', 'users.comment_text_size',
        'users.font_size', 'users.high_contrast', 'users.interests', 'users.interface_density', 'users.is_admin',
        'users.is_moderator', 'users.language', 'users.large_controls', 'users.line_height', 'users.message_policy',
        'users.message_text_size', 'users.public_ratings', 'users.public_statistics', 'users.reduced_motion',
        'users.strong_focus', 'users.theme',
    ];

    public function __construct(
        private readonly Closure $connect,
        private readonly array $mailConfiguration,
        private readonly ApplicationLogger $logger,
        private readonly string $rootPath,
        private readonly ?string $ollamaUrl = null,
        private readonly ?string $chatModel = null,
    ) {
    }

    public function checkAll(): array
    {
        $results = [];
        foreach (['database', 'mail', 'ollama', 'environment'] as $service) {
            $started = microtime(true);
            try {
                $result = match ($service) {
                    'database' => $this->database(),
                    'mail' => $this->smtp(),
                    'ollama' => $this->ollama(),
                    'environment' => $this->environment(),
                };
            } catch (Throwable $exception) {
                $id = $this->logger->recordException($exception, $service === 'mail' ? 'smtp' : $service);
                $result = $this->result('error', 'admin.diag.check_failed', ['id' => $id]);
            }
            $results[] = ['service' => $service] + $result + [
                'durationMs' => (int) round((microtime(true) - $started) * 1000),
                'checkedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
        }

        return $results;
    }

    private function database(): array
    {
        try {
            $connection = ($this->connect)();
            if (!$connection instanceof PDO || $connection->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
                throw new RuntimeException('Unsupported database connection.');
            }
            $connection->beginTransaction();
            try {
                $connection->exec("SET LOCAL statement_timeout = '2000ms'");
                if ((int) $connection->query('SELECT 1')->fetchColumn() !== 1) {
                    throw new RuntimeException('Database probe failed.');
                }
                // A migration that was not applied to an existing database shows up here by name.
                $statement = $connection->prepare('SELECT to_regclass(:table_name) IS NOT NULL');
                $missing = [];
                foreach (self::REQUIRED_TABLES as $table) {
                    $statement->execute(['table_name' => 'public.' . $table]);
                    if (!DatabaseValue::toBool($statement->fetchColumn())) {
                        $missing[] = $table;
                    }
                }
                $column = $connection->prepare("SELECT EXISTS (SELECT 1 FROM information_schema.columns
                    WHERE table_schema = 'public' AND table_name = :table_name AND column_name = :column_name)");
                foreach (self::REQUIRED_COLUMNS as $qualifiedName) {
                    [$table, $name] = explode('.', $qualifiedName, 2);
                    if (in_array($table, $missing, true)) {
                        continue;
                    }
                    $column->execute(['table_name' => $table, 'column_name' => $name]);
                    if (!DatabaseValue::toBool($column->fetchColumn())) {
                        $missing[] = $qualifiedName;
                    }
                }
            } finally {
                $connection->rollBack();
            }

            return $missing === []
                ? $this->result('ok', 'admin.diag.database_ok')
                : $this->result('warning', 'admin.diag.schema_missing', ['count' => count($missing)], $missing);
        } catch (Throwable $exception) {
            $this->logger->recordException($exception, 'database');

            return $this->result('error', 'admin.diag.database_error');
        }
    }

    private function smtp(): array
    {
        $host = $this->mailConfiguration['host'] ?? null;
        $port = $this->mailConfiguration['port'] ?? null;
        if (!is_string($host) || $host === '' || strlen($host) > 253
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9.-]*\z/', $host) !== 1
            || !is_int($port) || $port < 1 || $port > 65535) {
            return $this->result('not_configured', 'admin.diag.smtp_not_configured');
        }
        $deadline = microtime(true) + 3;
        $socket = null;
        try {
            $socket = @fsockopen($host, $port, $errorCode, $errorMessage, 2);
            if ($socket === false) {
                throw new RuntimeException('SMTP connection failed.');
            }
            $this->expectSmtp($socket, 220, $deadline);
            if (@fwrite($socket, "EHLO anivibe.local\r\n") !== 20) {
                throw new RuntimeException('SMTP write failed.');
            }
            $this->expectSmtp($socket, 250, $deadline);
            @fwrite($socket, "QUIT\r\n");

            return $this->result('ok', 'admin.diag.smtp_ok');
        } catch (Throwable $exception) {
            $this->logger->recordException($exception, 'smtp');

            return $this->result('error', 'admin.diag.smtp_error');
        } finally {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }
    }

    /** @param resource $socket */
    private function expectSmtp($socket, int $expected, float $deadline): void
    {
        for ($count = 0; $count < 30; $count++) {
            $this->setRemainingTimeout($socket, $deadline);
            $line = @fgets($socket, 513);
            if (!is_string($line)
                || preg_match('/\A([2-5][0-9]{2})([ -])[^\r\n]*\r\n\z/', $line, $match) !== 1
                || (int) $match[1] !== $expected) {
                throw new RuntimeException('SMTP response failed.');
            }
            if ($match[2] === ' ') {
                return;
            }
        }

        throw new RuntimeException('SMTP response limit reached.');
    }

    private function ollama(): array
    {
        $configured = $this->ollamaUrl ?? (getenv('OLLAMA_URL') === false ? OllamaClient::DEFAULT_URL : getenv('OLLAMA_URL'));
        $url = is_string($configured) ? rtrim($configured, '/') : '';
        $parts = parse_url($url);
        $chatModel = $this->chatModel ?? OllamaClient::configuredModel();
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || !is_string($parts['host'] ?? null)
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9.-]*\z/', $parts['host']) !== 1
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || strlen($url) > 512 || preg_match('/[\x00-\x20\x7F]/', $url) === 1
            || preg_match(self::MODEL_NAME, $chatModel) !== 1) {
            return $this->result('not_configured', 'admin.diag.ollama_not_configured');
        }
        $stream = null;
        $deadline = microtime(true) + 3;
        try {
            $context = stream_context_create(['http' => [
                'method' => 'GET', 'timeout' => 3, 'follow_location' => 0, 'max_redirects' => 0,
                'ignore_errors' => true, 'header' => "Accept: application/json\r\nConnection: close\r\n",
            ]]);
            $stream = @fopen($url . '/api/tags', 'rb', false, $context);
            if ($stream === false || preg_match('~\AHTTP/\S+ 200(?: |$)~', $http_response_header[0] ?? '') !== 1) {
                throw new RuntimeException('Ollama HTTP response failed.');
            }
            $body = '';
            while (!feof($stream) && strlen($body) <= 131072) {
                $this->setRemainingTimeout($stream, $deadline);
                $chunk = @fread($stream, min(8192, 131073 - strlen($body)));
                if ($chunk === false || ($chunk === '' && !feof($stream))) {
                    throw new RuntimeException('Ollama response read failed.');
                }
                $body .= $chunk;
            }
            if (strlen($body) > 131072) {
                throw new RuntimeException('Ollama response limit reached.');
            }
            $response = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($response) || !is_array($response['models'] ?? null) || !array_is_list($response['models'])) {
                throw new RuntimeException('Ollama model list missing.');
            }
            $models = [];
            foreach (array_slice($response['models'], 0, 20) as $model) {
                $name = $model['name'] ?? null;
                if (!is_string($name) || preg_match(self::MODEL_NAME, $name) !== 1) {
                    throw new RuntimeException('Ollama model name invalid.');
                }
                $models[] = $name;
            }
            if ($models === []) {
                return $this->result('warning', 'admin.diag.ollama_empty');
            }
            // Ollama treats a name without a tag as name:latest, so compare both sides the same way.
            $withTag = static fn (string $name): string => str_contains($name, ':') ? $name : $name . ':latest';
            $available = [];
            foreach ($response['models'] as $model) {
                if (is_array($model) && is_string($model['name'] ?? null)) {
                    $available[] = $withTag($model['name']);
                }
            }

            return in_array($withTag($chatModel), $available, true)
                ? $this->result('ok', 'admin.diag.ollama_ok', ['count' => count($response['models']), 'model' => $chatModel], $models)
                : $this->result('warning', 'admin.diag.ollama_model_missing', ['model' => $chatModel], $models);
        } catch (Throwable $exception) {
            $this->logger->recordException($exception, 'ollama');

            return $this->result('warning', 'admin.diag.ollama_unavailable');
        } finally {
            if (is_resource($stream)) {
                @fclose($stream);
            }
        }
    }

    /** @param resource $stream */
    private function setRemainingTimeout($stream, float $deadline): void
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            throw new RuntimeException('Diagnostic deadline exceeded.');
        }
        $seconds = (int) $remaining;
        if (!stream_set_timeout($stream, $seconds, (int) (($remaining - $seconds) * 1000000))) {
            throw new RuntimeException('Diagnostic timeout configuration failed.');
        }
    }

    private function environment(): array
    {
        $checks = [
            'PHP >= 8.3' => PHP_VERSION_ID >= 80300,
            'pdo_pgsql' => extension_loaded('pdo_pgsql'),
            'mbstring' => extension_loaded('mbstring'),
            'fileinfo' => extension_loaded('fileinfo'),
            'zlib' => extension_loaded('zlib'),
            'storage/logs' => $this->logger->isWritable(),
            'storage/uploads/avatars' => is_dir($this->rootPath . '/storage/uploads/avatars')
                && is_writable($this->rootPath . '/storage/uploads/avatars'),
            'vendor/autoload.php' => is_readable($this->rootPath . '/vendor/autoload.php'),
        ];
        $missing = array_keys(array_filter($checks, static fn (bool $value): bool => !$value));

        return $missing === []
            ? $this->result('ok', 'admin.diag.environment_ok', [], ['PHP ' . PHP_VERSION])
            : $this->result('error', 'admin.diag.environment_error', [], $missing);
    }

    private function result(string $status, string $messageKey, array $parameters = [], array $details = []): array
    {
        return ['status' => $status, 'messageKey' => $messageKey, 'parameters' => $parameters, 'details' => $details];
    }
}
