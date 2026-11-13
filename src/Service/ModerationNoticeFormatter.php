<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class ModerationNoticeFormatter
{
    public static function message(array $notice): string
    {
        $type = $notice['actionType'];
        $key = 'moderation.notice.' . $type;
        if ($type === 'warning' && ($notice['animeTitle'] ?? '') === '') {
            $key = 'moderation.notice.warning_account';
        }

        return Translator::current()->translate($key, [
            'anime' => $notice['animeTitle'] ?? '',
            'reason' => $notice['reason'],
            'ends' => isset($notice['endsAt']) ? self::date($notice['endsAt']) : '',
        ]);
    }

    public static function date(string $date): string
    {
        try {
            return (new DateTimeImmutable($date))
                ->setTimezone(new DateTimeZone('Europe/Warsaw'))
                ->format('d.m.Y H:i T');
        } catch (Throwable) {
            return $date;
        }
    }
}
