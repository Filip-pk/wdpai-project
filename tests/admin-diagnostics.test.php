<?php

declare(strict_types=1);

use App\Service\ApplicationLogger;
use App\Service\DiagnosticService;
use App\Service\MailService;

function diagnosticAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// Real local sockets exercise protocol handling without contacting external services.
if (($argv[1] ?? '') === '--server') {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    diagnosticAssert(is_resource($server), 'Could not start the local diagnostic fixture.');
    echo stream_socket_get_name($server, false) . PHP_EOL;
    fflush(STDOUT);
    $socket = stream_socket_accept($server, 8);
    diagnosticAssert(is_resource($socket), 'The client did not reach the local fixture.');
    stream_set_timeout($socket, 5);
    $scenario = $argv[2] ?? '';
    $commands = [];
    if (str_starts_with($scenario, 'smtp')) {
        fwrite($socket, "220 fixture.local ready\r\n");
        $commands[] = trim((string) fgets($socket));
        if ($scenario === 'smtp-timeout') {
            sleep(4);
        } elseif ($scenario === 'smtp-reject') {
            fwrite($socket, "550 unavailable\r\n");
        } else {
            fwrite($socket, "250-fixture.local\r\n250 SIZE 1048576\r\n");
            $commands[] = trim((string) fgets($socket));
            if ($scenario === 'smtp-send') {
                diagnosticAssert($commands[1] === 'MAIL FROM:<noreply@anivibe.local>', 'Unexpected sender command.');
                fwrite($socket, "250 ok\r\n");
                $commands[] = trim((string) fgets($socket));
                diagnosticAssert($commands[2] === 'RCPT TO:<admin@example.test>', 'Unexpected recipient command.');
                fwrite($socket, "250 ok\r\n");
                $commands[] = trim((string) fgets($socket));
                diagnosticAssert($commands[3] === 'DATA', 'Expected DATA command.');
                fwrite($socket, "354 send\r\n");
                $message = '';
                while (($line = fgets($socket)) !== false && $line !== ".\r\n") {
                    $message .= $line;
                }
                $commands[] = $message;
                fwrite($socket, "250 accepted\r\n");
                $commands[] = trim((string) fgets($socket));
                fwrite($socket, "221 bye\r\n");
            }
        }
    } else {
        $commands[] = trim((string) fgets($socket));
        while (($header = fgets($socket)) !== false && $header !== "\r\n") {
            // Only consume request headers; no request contents are persisted.
        }
        $body = match ($scenario) {
            'http-empty' => '{"models":[]}',
            'http-invalid' => 'INVALID JSON',
            'http-oversized' => str_repeat('x', 131073),
            'http-unsafe-model' => '{"models":[{"name":"<script>alert(1)</script>"}]}',
            default => '{"models":[{"name":"llama3.2:latest"}]}',
        };
        $status = $scenario === 'http-redirect' ? '302 Found' : '200 OK';
        $location = $scenario === 'http-redirect' ? "Location: http://127.0.0.1:1/private\r\n" : '';
        fwrite($socket, "HTTP/1.1 {$status}\r\n{$location}Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body);
    }
    fclose($socket);
    fclose($server);
    echo json_encode(['commands' => $commands], JSON_THROW_ON_ERROR);
    exit;
}

require_once dirname(__DIR__) . '/vendor/autoload.php';

final class DiagnosticFakeStatement extends PDOStatement
{
    public function __construct(private readonly mixed $value)
    {
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->value;
    }
}

final class DiagnosticFakePdo extends PDO
{
    public bool $transaction = false;

    public function __construct(private readonly bool $schemaPresent = true, private readonly bool $columnsPresent = true)
    {
    }

    public function getAttribute(int $attribute): mixed
    {
        return 'pgsql';
    }

    public function beginTransaction(): bool
    {
        $this->transaction = true;
        return true;
    }

    public function rollBack(): bool
    {
        $this->transaction = false;
        return true;
    }

    public function exec(string $statement): int|false
    {
        return 0;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return new DiagnosticFakeStatement($query === 'SELECT 1' ? 1 : $this->schemaPresent);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new DiagnosticFakeStatement(str_contains($query, 'information_schema.columns')
            ? $this->columnsPresent : $this->schemaPresent);
    }
}

function withDiagnosticServer(string $scenario, callable $action): array
{
    $process = proc_open([PHP_BINARY, __FILE__, '--server', $scenario],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    diagnosticAssert(is_resource($process), 'Could not launch the local fixture.');
    try {
        stream_set_timeout($pipes[1], 8);
        $address = trim((string) fgets($pipes[1]));
        diagnosticAssert(preg_match('/\A127\.0\.0\.1:([0-9]+)\z/', $address, $match) === 1, 'Invalid fixture address.');
        $action((int) $match[1]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        $status = proc_close($process);
        $process = null;
        diagnosticAssert($status === 0, 'The local fixture failed: ' . $errors);
        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    } finally {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
    }
}

$root = dirname(__DIR__);
$directory = sys_get_temp_dir() . '/anivibe-diagnostics-' . bin2hex(random_bytes(8));
$logger = new ApplicationLogger($directory);
$service = static fn (Closure $connect, array $mail = [], string $ollama = ''): DiagnosticService =>
    new DiagnosticService($connect, $mail, $logger, $root, $ollama);
$cards = static fn (DiagnosticService $diagnostics): array => array_column($diagnostics->checkAll(), null, 'service');

try {
    $pdo = new DiagnosticFakePdo();
    $result = $cards($service(static fn (): PDO => $pdo));
    diagnosticAssert(array_keys($result) === ['database', 'mail', 'ollama', 'environment'], 'All four services must be returned.');
    diagnosticAssert($result['database']['status'] === 'ok' && !$pdo->transaction, 'Successful probes must roll back their transaction.');
    diagnosticAssert($result['mail']['status'] === 'not_configured', 'Incomplete SMTP configuration must be distinguished.');
    diagnosticAssert($result['ollama']['status'] === 'not_configured', 'An empty Ollama address must be distinguished.');
    foreach ($result as $card) {
        diagnosticAssert(is_int($card['durationMs']) && $card['durationMs'] >= 0, 'Every check needs a measured duration.');
        diagnosticAssert(str_ends_with($card['checkedAt'], 'Z'), 'Every check needs a UTC timestamp.');
    }
    $missing = $cards($service(static fn (): PDO => new DiagnosticFakePdo(false)));
    diagnosticAssert($missing['database']['messageKey'] === 'admin.diag.schema_missing' && $missing['database']['parameters']['count'] > 0,
        'Missing schema must be explained separately from connectivity.');
    $failed = $cards($service(static function (): never { throw new RuntimeException('SECRET password=PRIVATE'); }));
    diagnosticAssert($failed['database']['status'] === 'error' && $failed['environment']['status'] === 'ok',
        'A database outage must not prevent independent checks.');
    diagnosticAssert(!str_contains(json_encode($failed), 'SECRET'), 'Probe errors must not expose exception messages.');
    echo "[OK] Independent checks, schema validation and timing metadata\n";

    // The schema check must follow the migrations, so a migration left unapplied is reported by name.
    $declared = ['tables' => [], 'columns' => []];
    foreach (glob($root . '/database/*.sql') ?: [] as $file) {
        $sql = (string) preg_replace(['/\$\$.*?\$\$/s', '/--[^\n]*/'], '', (string) file_get_contents($file));
        foreach (explode(';', $sql) as $statement) {
            if (preg_match('/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:public\.)?([a-z_]+)/i', $statement, $match) === 1) {
                $declared['tables'][] = strtolower($match[1]);
            }
            if (preg_match('/\bALTER\s+TABLE\s+(?:IF\s+EXISTS\s+)?(?:public\.)?([a-z_]+)/i', $statement, $match) === 1
                && preg_match_all('/\bADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?([a-z_]+)/i', $statement, $added) > 0) {
                foreach ($added[1] as $column) {
                    $declared['columns'][] = strtolower($match[1]) . '.' . strtolower($column);
                }
            }
        }
    }
    $sorted = static function (array $values): array {
        $values = array_values(array_unique($values));
        sort($values);
        return $values;
    };
    diagnosticAssert(count($declared['tables']) > 30 && $sorted($declared['tables']) === $sorted(DiagnosticService::REQUIRED_TABLES),
        'Diagnostics must check exactly the tables created by the migrations.');
    diagnosticAssert($declared['columns'] !== [] && $sorted($declared['columns']) === $sorted(DiagnosticService::REQUIRED_COLUMNS),
        'Diagnostics must check exactly the columns added by the migrations.');
    diagnosticAssert($missing['database']['details'] === DiagnosticService::REQUIRED_TABLES,
        'Every missing table is listed once, without repeating its columns.');
    $columns = $cards($service(static fn (): PDO => new DiagnosticFakePdo(true, false)));
    diagnosticAssert($columns['database']['status'] === 'warning'
        && $columns['database']['details'] === DiagnosticService::REQUIRED_COLUMNS,
        'Columns from later migrations are checked when their table exists.');
    echo "[OK] Database diagnostics cover every migrated table and column\n";

    $smtp = withDiagnosticServer('smtp-probe', static function (int $port) use ($cards, $service): void {
        $result = $cards($service(static fn (): PDO => new DiagnosticFakePdo(), ['host' => '127.0.0.1', 'port' => $port]));
        diagnosticAssert($result['mail']['status'] === 'ok', 'A valid SMTP greeting and EHLO must pass.');
    });
    diagnosticAssert($smtp['commands'] === ['EHLO anivibe.local', 'QUIT'], 'SMTP checks must never issue MAIL, RCPT or DATA.');
    withDiagnosticServer('smtp-reject', static function (int $port) use ($cards, $service): void {
        $result = $cards($service(static fn (): PDO => new DiagnosticFakePdo(), ['host' => '127.0.0.1', 'port' => $port]));
        diagnosticAssert($result['mail']['status'] === 'error', 'Rejected SMTP must be an error.');
    });
    withDiagnosticServer('smtp-timeout', static function (int $port) use ($cards, $service): void {
        $started = microtime(true);
        $result = $cards($service(static fn (): PDO => new DiagnosticFakePdo(), ['host' => '127.0.0.1', 'port' => $port]));
        diagnosticAssert($result['mail']['status'] === 'error' && microtime(true) - $started < 3.5, 'SMTP timeouts must be bounded.');
    });
    echo "[OK] SMTP EHLO is read-only, validates responses and has a deadline\n";

    foreach (['http-models' => 'ok', 'http-empty' => 'warning', 'http-invalid' => 'warning',
        'http-oversized' => 'warning', 'http-unsafe-model' => 'warning', 'http-redirect' => 'warning'] as $scenario => $expected) {
        $http = withDiagnosticServer($scenario, static function (int $port) use ($cards, $service, $expected): void {
            $result = $cards($service(static fn (): PDO => new DiagnosticFakePdo(), [], 'http://127.0.0.1:' . $port));
            diagnosticAssert($result['ollama']['status'] === $expected, 'Unexpected Ollama diagnostic status.');
            diagnosticAssert(count($result['ollama']['details']) <= 20, 'Model details must be bounded.');
        });
        diagnosticAssert($http['commands'] === ['GET /api/tags HTTP/1.1'], 'Ollama checks must only request the model list.');
    }
    foreach (['file:///private', 'http://user@localhost', 'http://user:password@localhost', 'http://localhost?token=SECRET'] as $url) {
        diagnosticAssert($cards($service(static fn (): PDO => new DiagnosticFakePdo(), [], $url))['ollama']['status'] === 'not_configured',
            'Unsafe server-side URL configuration must be rejected.');
    }
    echo "[OK] Ollama models, empty list, malformed/oversized responses and redirect rejection\n";

    $sent = withDiagnosticServer('smtp-send', static function (int $port): void {
        (new MailService('127.0.0.1', $port, 'noreply@anivibe.local', 'AniVibe', 2))->sendDiagnosticEmail('admin@example.test');
    });
    diagnosticAssert(count($sent['commands']) === 6 && str_contains($sent['commands'][4], 'Content-Type: text/plain'),
        'The explicit mail test must send a valid diagnostic message.');
    diagnosticAssert(!str_contains(file_get_contents($directory . '/application.jsonl'), 'PRIVATE'), 'Diagnostic logs must exclude sensitive exception content.');
    echo "[OK] Explicit diagnostic email uses the existing SMTP transport\n";
} finally {
    if (is_dir($directory)) {
        foreach (glob($directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        $lock = $directory . '/.application.lock';
        if (is_file($lock)) {
            unlink($lock);
        }
        rmdir($directory);
    }
}
