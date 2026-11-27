<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;
use stdClass;

/**
 * A language model that answers with JSON matching a schema. The catalogue chat only asks it to
 * turn a request into search criteria; the results always come from PostgreSQL. OllamaClient is
 * the implementation used by the application.
 */
interface ChatModel
{
    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $schema JSON schema the answer must follow
     * @param ?callable(): bool $cancelled Polled during the request; returning true aborts it
     * @return array{output: stdClass, elapsedMs: int, loadMs: int}
     * @throws RuntimeException When the model is unavailable, too slow or answers outside the schema
     */
    public function chat(array $messages, array $schema, int $timeoutMs, ?callable $cancelled = null): array;
}
