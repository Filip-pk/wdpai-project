<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

final class RateLimitException extends RuntimeException
{
    public readonly int $retryAfter;

    public function __construct(int $retryAfter)
    {
        $this->retryAfter = max(1, $retryAfter);
        parent::__construct(Translator::current()->translate('message.rate_limit', ['seconds' => $this->retryAfter]));
    }
}
