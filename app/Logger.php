<?php

namespace App;

/**
 * Simple logging utility with log levels.
 *
 * Log levels (in order of verbosity):
 * - error: Critical errors that need attention
 * - warn: Warning conditions
 * - info: Informational messages
 * - debug: Debug-level messages
 * - trace: Highly detailed tracing information
 *
 * Set LOG_LEVEL environment variable to control output.
 * Default is 'info'. Set to 'trace' for maximum verbosity.
 */
class Logger
{
    private const LEVELS = [
        'error' => 0,
        'warn'  => 1,
        'info'  => 2,
        'debug' => 3,
        'trace' => 4,
    ];

    private static ?int $currentLevel = null;

    private static function getLevel(): int
    {
        if (self::$currentLevel === null) {
            $envLevel = strtolower(getenv('LOG_LEVEL') ?: 'info');
            self::$currentLevel = self::LEVELS[$envLevel] ?? self::LEVELS['info'];
        }
        return self::$currentLevel;
    }

    private static function log(string $level, string $message): void
    {
        if (self::LEVELS[$level] <= self::getLevel()) {
            error_log("[" . strtoupper($level) . "] " . $message);
        }
    }

    public static function error(string $message): void
    {
        self::log('error', $message);
    }

    public static function warn(string $message): void
    {
        self::log('warn', $message);
    }

    public static function info(string $message): void
    {
        self::log('info', $message);
    }

    public static function debug(string $message): void
    {
        self::log('debug', $message);
    }

    public static function trace(string $message): void
    {
        self::log('trace', $message);
    }
}
