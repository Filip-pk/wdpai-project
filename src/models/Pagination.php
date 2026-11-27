<?php

declare(strict_types=1);

namespace App\Model;

/** One rule for every paginated list: a bounded page size and a page number within range. */
final class Pagination
{
    public const MAX_PER_PAGE = 100;

    /** @return array{page: int, perPage: int, total: int, totalPages: int} */
    public static function of(int $total, int $page, int $perPage): array
    {
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $totalPages = max(1, (int) ceil($total / $perPage));

        return ['page' => max(1, min($page, $totalPages)), 'perPage' => $perPage, 'total' => $total, 'totalPages' => $totalPages];
    }
}
