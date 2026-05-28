<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Reads and sanitizes environment values. Empty, non-numeric or out-of-range
 * values fall back to the provided (safe) default rather than being clamped to a
 * behaviour-changing extreme, so a bad config value can never put the bot into a
 * broken state (e.g. a negative interval or a zero ceiling).
 */
final class Env
{
    public static function int(string $key, int $default, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        $raw = $_ENV[$key] ?? '';
        if ($raw === '' || !is_numeric($raw)) {
            return $default;
        }
        $value = (int) $raw;
        // Out-of-range values fall back to the safe default rather than being
        // clamped to a behaviour-changing extreme (e.g. a 0 interval).
        return ($value < $min || $value > $max) ? $default : $value;
    }

    public static function intOrNull(string $key, ?int $default, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int
    {
        $raw = $_ENV[$key] ?? '';
        if ($raw === '' || !is_numeric($raw)) {
            return $default;
        }
        $value = (int) $raw;
        return ($value < $min || $value > $max) ? $default : $value;
    }

    public static function str(string $key, string $default = ''): string
    {
        $raw = $_ENV[$key] ?? '';
        return $raw === '' ? $default : (string) $raw;
    }

    /**
     * Boolean flag: the exact strings "0", "false", "no", "off" (case-insensitive)
     * disable; any other non-empty value enables; empty/missing uses $default.
     */
    public static function bool(string $key, bool $default): bool
    {
        $raw = $_ENV[$key] ?? '';
        if ($raw === '') {
            return $default;
        }
        return !in_array(strtolower((string) $raw), ['0', 'false', 'no', 'off'], true);
    }
}
