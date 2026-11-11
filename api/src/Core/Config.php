<?php
declare(strict_types=1);

namespace School\Core;

/**
 * Settings and secrets. On the server they live in debuggers-edu-config.<host>.php in the hosting
 * home folder (above the web root), so a password is never
 * downloadable and never in git. Tests and the CLI pass SCHOOL_CONFIG=/path/to/file.php instead.
 */
final class Config
{
    private static ?array $values = null;

    public static function load(): array
    {
        if (self::$values !== null) return self::$values;
        $file = getenv('SCHOOL_CONFIG') ?: self::findForHost($_SERVER['SERVER_NAME'] ?? '');
        if ($file === null || !is_file($file)) throw new HttpError(500, 'Server is not configured yet.');
        return self::$values = require $file;
    }

    public static function set(array $values): void
    {
        self::$values = $values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::load()[$key] ?? $default;
    }

    private static function findForHost(string $serverName): ?string
    {
        $host = preg_replace('/[^a-z0-9.-]/', '', strtolower(preg_replace('/^www\./i', '', $serverName)));
        if ($host === '') return null;
        for ($dir = dirname(__DIR__, 3); $dir !== dirname($dir); $dir = dirname($dir)) {
            // thinkle-config.* is the name used before the move from Thinkle Tots
            foreach (["debuggers-edu-config.$host.php", "thinkle-config.$host.php"] as $name) {
                if (is_file("$dir/$name")) return "$dir/$name";
            }
        }
        return null;
    }
}
