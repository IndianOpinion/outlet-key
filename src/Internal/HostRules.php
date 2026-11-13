<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Internal;

use IndianOpinion\OutletKey\PublicSuffixList;

/**
 * Outlet key steps for a prepared host name: PRD cases 14 to 22.
 *
 * @internal
 */
final class HostRules
{
    /**
     * Steps 14 to 21: everything except the alias lookup. The trace
     * receives one entry per step as [step, value, note].
     *
     * @param list<array{0: string, 1: string, 2: string}>|null $trace
     */
    public static function preAliasKey(string $host, MapData $map, ?PublicSuffixList $psl, ?array &$trace = null): string
    {
        // Case 14 and 15: mirror labels, from the left, never below two labels.
        $labels = explode('.', $host);
        $stripped = [];
        while (count($labels) > 2 && isset($map->mirrorLabels[$labels[0]])) {
            $stripped[] = array_shift($labels);
        }
        $host = implode('.', $labels);
        if ($trace !== null) {
            $trace[] = ['mirror-strip', $host, $stripped === [] ? 'none' : 'stripped ' . implode(', ', $stripped)];
        }

        // Case 16: pass-through hosts are returned whole.
        if (isset($map->passThrough[$host])) {
            if ($trace !== null) {
                $trace[] = ['pass-through', $host, 'host is in the pass-through list; kept whole'];
            }

            return $host;
        }
        if ($trace !== null) {
            $trace[] = ['pass-through', $host, 'no'];
        }

        // Case 17 and 18.
        [$registrable, $rule] = self::registrable($host, $map, $psl);
        if ($trace !== null) {
            $trace[] = ['registrable-domain', $registrable, $rule];
        }

        // Case 19 to 21: portal bases.
        $key = $registrable;
        $note = 'not a portal';
        if (isset($map->portalBases[$registrable])) {
            if ($host === $registrable) {
                $note = 'bare portal host';
            } else {
                $left = substr($host, 0, -strlen($registrable) - 1);
                $dot = strrpos($left, '.');
                $label = $dot === false ? $left : substr($left, $dot + 1);
                if (isset($map->languageLabels[$label])) {
                    $note = 'language label "' . $label . '" is an edition of the portal';
                } else {
                    $key = $label . '.' . $registrable;
                    $note = 'publication "' . $label . '" under portal ' . $registrable;
                }
            }
        }
        if ($trace !== null) {
            $trace[] = ['portal', $key, $note];
        }

        return $key;
    }

    /**
     * Case 17: the registrable domain of a prepared host name.
     *
     * @return array{0: string, 1: string} [domain, rule that decided]
     */
    public static function registrable(string $host, MapData $map, ?PublicSuffixList $psl): array
    {
        $labels = explode('.', $host);
        $n = count($labels);
        if ($n <= 2) {
            return [$host, 'two labels or fewer'];
        }

        // 17a: longest explicit suffix from the map.
        $mapSuffixLabels = 0;
        for ($i = 1; $i < $n; $i++) {
            $suffix = implode('.', array_slice($labels, $i));
            if (isset($map->suffixes[$suffix])) {
                $mapSuffixLabels = $n - $i;
                break;
            }
        }

        if ($psl !== null) {
            $pslLabels = $psl->publicSuffixLabelCount($labels);
            if ($mapSuffixLabels >= $pslLabels && $mapSuffixLabels > 0) {
                return [self::lastLabels($labels, $mapSuffixLabels + 1), 'map suffix'];
            }

            return [self::lastLabels($labels, $pslLabels + 1), 'public suffix list'];
        }

        if ($mapSuffixLabels > 0) {
            return [self::lastLabels($labels, $mapSuffixLabels + 1), 'map suffix'];
        }

        // 17b: two-letter country code with a known second-level label.
        $tld = $labels[$n - 1];
        if (strlen($tld) === 2 && preg_match('~^[a-z]{2}$~', $tld) === 1 && isset($map->secondLevelLabels[$labels[$n - 2]])) {
            return [self::lastLabels($labels, 3), 'second-level label'];
        }

        // 17c.
        return [self::lastLabels($labels, 2), 'last two labels'];
    }

    /**
     * @param list<string> $labels
     */
    private static function lastLabels(array $labels, int $count): string
    {
        return implode('.', array_slice($labels, -min($count, count($labels))));
    }
}
