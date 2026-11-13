<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

final class UserBlockedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(Translator::current()->translate('message.blocked_messages'));
    }
}
