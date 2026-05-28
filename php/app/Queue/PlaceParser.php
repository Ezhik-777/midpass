<?php

declare(strict_types=1);

namespace App\Queue;

/**
 * Parses the "place in queue" value, which the API may return as an int,
 * a numeric string, or a localized string like "Место 106".
 */
final class PlaceParser
{
    public static function parse(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/\d+/', $raw, $m) === 1) {
            return (int) $m[0];
        }
        return null;
    }
}
