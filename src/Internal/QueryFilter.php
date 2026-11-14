<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Internal;

/**
 * Query rules for the article key: PRD cases 33, 34, 35, 40 and 41.
 *
 * Parsed by hand. Generic parsers rewrite key names (dots and spaces become
 * underscores in parse_str), which would merge distinct keys.
 *
 * @internal
 */
final class QueryFilter
{
    /**
     * @param list<array{0: string, 1: string, 2: string}>|null $trace
     */
    public static function filter(?string $query, bool $scriptPath, MapData $map, ?array &$trace = null): string
    {
        if ($query === null || $query === '') {
            return '';
        }

        $kept = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            $rawKey = $eq === false ? $pair : substr($pair, 0, $eq);
            $key = Utf8::lowerAscii(rawurldecode(str_replace('+', ' ', $rawKey)));
            $value = $eq === false ? '' : rawurldecode(str_replace('+', ' ', substr($pair, $eq + 1)));

            $reason = null;
            if ($key === '') {
                $reason = 'empty key';
            } elseif ($value === '') {
                $reason = $eq === false ? 'no value' : 'empty value';
            } elseif (self::isTracking($key, $map)) {
                $reason = 'tracking';
            } elseif (!$scriptPath && !isset($map->idParams[$key])) {
                $reason = 'not an id parameter on a slug path';
            }

            if ($trace !== null) {
                $trace[] = ['query', $key, $reason === null ? 'kept' : 'dropped: ' . $reason];
            }
            if ($reason === null) {
                $kept[$key . "\0" . $value] = [$key, $value];
            }
        }

        // Ruling on open question 4: every value of a repeated key is kept,
        // pairs sorted by key then value, byte order. Exact repeats collapse.
        uasort($kept, static function (array $a, array $b): int {
            return strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]);
        });
        $parts = [];
        foreach ($kept as [$key, $value]) {
            $parts[] = rawurlencode($key) . '=' . rawurlencode($value);
        }

        return implode('&', $parts);
    }

    private static function isTracking(string $key, MapData $map): bool
    {
        if (isset($map->trackingParams[$key])) {
            return true;
        }
        foreach ($map->trackingPrefixes as $prefix) {
            if (strncmp($key, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }

        return false;
    }
}
