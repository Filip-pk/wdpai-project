<?php

declare(strict_types=1);

namespace App\Repository;

/** Converts values read through PDO, whose type depends on the driver and the query. */
final class DatabaseValue
{
    /** PostgreSQL booleans arrive as true, 1, '1' or 't'; everything else is false. */
    public static function toBool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't'], true);
    }
}
