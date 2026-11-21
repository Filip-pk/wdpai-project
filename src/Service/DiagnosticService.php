<?php

declare(strict_types=1);

namespace App\Service;

use Closure;
use PDO;
use RuntimeException;
use Throwable;

final class DiagnosticService
{
    public function __construct(
        private readonly Closure $connect,
        private readonly array $mailConfiguration,
        private readonly ApplicationLogger $logger,
        private readonly string $rootPath,
        private readonly ?string $ollamaUrl = null,
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
                $required = [
                    'users', 'anime', 'genres', 'anime_genres', 'user_watchlist',
                    'anime_comments', 'community_reports', 'user_bans', 'moderation_actions',
                    'private_conversations', 'private_messages', 'user_anime_ratings',
                    'anime_collections', 'anime_collection_items', 'user_achievements',
                    'user_showcase_anime', 'user_showcase_badges', 'admin_actions',
                ];
                $statement = $connection->prepare('SELECT to_regclass(:table_name) IS NOT NULL');
                $missing = [];
                foreach ($required as $table) {
                    $statement->execute(['table_name' => 'public.' . $table]);
                    if (!in_array($statement->fetchColumn(), [true, 1, '1', 't'], true)) {
                        $missing[] = $table;
                    }
                }
                $column = $connection->query("SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'users' AND column_name = 'is_admin')");
                if (!in_array($column->fetchColumn(), [true, 1, '1', 't'], true)) {
                    $missing[] = 'users.is_admin';
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
        $configured = $this->ollamaUrl ?? (getenv('OLLAMA_URL') === false ? 'http://ollama:11434' : getenv('OLLAMA_URL'));
        $url = is_string($configured) ? rtrim($configured, '/') : '';
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || !is_string($parts['host'] ?? null)
            || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9.-]*\z/', $parts['host']) !== 1
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || strlen($url) > 512 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
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
                if (!is_string($name) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:\/-]{0,119}\z/', $name) !== 1) {
                    throw new RuntimeException('Ollama model name invalid.');
                }
                $models[] = $name;
            }

            return $models === []
                ? $this->result('warning', 'admin.diag.ollama_empty')
                : $this->result('ok', 'admin.diag.ollama_ok', ['count' => count($response['models'])], $models);
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
