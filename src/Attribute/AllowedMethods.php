<?php

declare(strict_types=1);

namespace App\Attribute;

use Attribute;

/** Declares the HTTP methods a controller action accepts; the router answers 405 for any other. */
#[Attribute(Attribute::TARGET_METHOD)]
final class AllowedMethods
{
    /** @var list<string> */
    public readonly array $methods;

    public function __construct(string ...$methods)
    {
        $this->methods = array_values(array_unique(array_map('strtoupper', $methods)));
    }

    public function allows(string $method): bool
    {
        return in_array(strtoupper($method), $this->methods, true);
    }
}
