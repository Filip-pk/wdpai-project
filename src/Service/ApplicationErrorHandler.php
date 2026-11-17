<?php

declare(strict_types=1);

namespace App\Service;

final class ApplicationErrorHandler
{
    private static bool $registered = false;

    public static function register(ApplicationLogger $logger): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        $previousHandler = null;
        $previousHandler = set_error_handler(static function (
            int $severity,
            string $message,
            string $file,
            int $line,
        ) use ($logger, &$previousHandler): bool {
            if ((error_reporting() & $severity) !== 0) {
                $logger->record('warning', 'application', 'admin.log.runtime_error', [
                    'code' => $severity,
                    'path' => self::requestPath(),
                ]);
            }

            // Preserve native handling and any handler installed before this one.
            return is_callable($previousHandler)
                ? (bool) $previousHandler($severity, $message, $file, $line)
                : false;
        }, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED | E_STRICT);

        register_shutdown_function(static function () use ($logger): void {
            $error = error_get_last();
            if (!is_array($error)
                || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)
                || (error_reporting() & $error['type']) === 0) {
                return;
            }
            $logger->record('error', 'application', 'admin.log.fatal_error', [
                'code' => $error['type'],
                'path' => self::requestPath(),
            ]);
        });
    }

    private static function requestPath(): string
    {
        return is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '';
    }
}
