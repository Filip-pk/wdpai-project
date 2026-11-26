<?php

declare(strict_types=1);

namespace App\Service;

use JsonException;
use RuntimeException;
use stdClass;

final class OllamaClient
{
    private const MAX_RESPONSE_BYTES = 65536;

    public function __construct(
        private readonly string $url = 'http://ollama:11434',
        private readonly string $model = 'qwen3:4b',
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(
            getenv('OLLAMA_URL') ?: 'http://ollama:11434',
            getenv('OLLAMA_CHAT_MODEL') ?: 'qwen3:4b',
        );
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @return array{output: stdClass, elapsedMs: int, loadMs: int}
     */
    public function chat(array $messages, array $schema, int $timeoutMs, ?callable $cancelled = null): array
    {
        $parts = parse_url($this->url);
        if (!in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Invalid Ollama URL.');
        }

        $payload = json_encode([
            'model' => $this->model,
            'messages' => $messages,
            'format' => $schema,
            'stream' => false,
            'think' => false,
            'keep_alive' => '10m',
            'options' => ['temperature' => 0, 'num_ctx' => 4096, 'num_predict' => 384],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $request = curl_init(rtrim($this->url, '/') . '/api/chat');
        if ($request === false) {
            throw new RuntimeException('Ollama connection could not be initialized.');
        }

        $body = '';
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min(1500, $timeoutMs),
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($cancelled !== null) {
            $options[CURLOPT_NOPROGRESS] = false;
            $options[CURLOPT_XFERINFOFUNCTION] = static fn (): int => $cancelled() ? 1 : 0;
        }

        $started = hrtime(true);
        try {
            curl_setopt_array($request, $options);
            if (curl_exec($request) === false) {
                throw new RuntimeException('Ollama transfer failed (cURL ' . curl_errno($request) . ').');
            }
            if (curl_getinfo($request, CURLINFO_RESPONSE_CODE) !== 200) {
                throw new RuntimeException('Ollama returned HTTP ' . curl_getinfo($request, CURLINFO_RESPONSE_CODE) . '.');
            }
        } finally {
            curl_close($request);
        }

        try {
            $response = json_decode($body, false, 24, JSON_THROW_ON_ERROR);
            if (($response->done ?? false) !== true || ($response->done_reason ?? '') === 'length'
                || !is_string($response->message->content ?? null)) {
                throw new RuntimeException('Ollama response is incomplete.');
            }
            $output = json_decode($response->message->content, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Ollama returned invalid JSON.', 0, $exception);
        }
        if (!$output instanceof stdClass) {
            throw new RuntimeException('Ollama returned an invalid structured response.');
        }

        return [
            'output' => $output,
            'elapsedMs' => (int) round((hrtime(true) - $started) / 1_000_000),
            'loadMs' => is_int($response->load_duration ?? null) ? (int) round($response->load_duration / 1_000_000) : 0,
        ];
    }
}
