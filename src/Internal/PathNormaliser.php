<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Internal;

/**
 * Path rules for the article key: PRD cases 28 to 30 and the slug key of
 * case 38.
 *
 * @internal
 */
final class PathNormaliser
{
    /**
     * Rewrites b to i, in order. AMP and index markers are matched ASCII
     * case-insensitively; everything else in the path keeps its case.
     */
    private const REWRITES = [
        ['b', 'amp_articleshow', '~/amp_articleshow/~i', '/articleshow/'],
        ['c', 'trailing /amp/1', '~/amp/1/*$~i', ''],
        ['d', 'leading /amp segment', '~^/+amp(?=/|$)~i', ''],
        ['e', '/amp segment', '~/amp(?=/|$)~i', ''],
        ['f', 'amp html suffix', '~[.-]amp\.(html?)(/*)$~i', '.$1$2'],
        ['g', 'trailing .amp', '~\.amp(/*)$~i', '$1'],
        ['h', 'index file', '~/index\.(?:html?|php)/*$~i', ''],
        ['i', 'repeated slashes', '~/{2,}~', '/'],
    ];

    private const SLUG_TRAILER = '~^(?:articleshow|amp|[a-z]{0,3}[0-9]{5,}(?:\.(?:cms|html?))?)$~D';

    /**
     * Normalise a raw (still percent-encoded) path. The result is decoded;
     * pass it through encode() before it goes into a key.
     *
     * @param list<string>|null $fired receives "<letter> <description>" for each rewrite that changed the path
     */
    public static function normalise(string $rawPath, bool $lowercase, ?array &$fired = null): string
    {
        // a. Decode once. Lowercase only for outlets that opt in.
        $path = rawurldecode($rawPath);
        if ($lowercase) {
            $lowered = Utf8::lowerAscii($path);
            if ($fired !== null && $lowered !== $path) {
                $fired[] = 'a lowercase (case-insensitive outlet)';
            }
            $path = $lowered;
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        foreach (self::REWRITES as [$letter, $what, $pattern, $replacement]) {
            $next = preg_replace($pattern, $replacement, $path);
            if ($next === null) {
                throw new \LogicException('Path rewrite failed: ' . preg_last_error_msg());
            }
            if ($fired !== null && $next !== $path) {
                $fired[] = $letter . ' ' . $what;
            }
            $path = $next;
        }

        // j. Trailing slashes; the empty path is "/".
        $trimmed = rtrim($path, '/');
        if ($fired !== null && $trimmed !== $path && $trimmed !== '') {
            $fired[] = 'j trailing slash';
        }

        return $trimmed === '' ? '/' : $trimmed;
    }

    /**
     * Case 30: make a decoded path printable, single-line and unambiguous.
     */
    public static function encode(string $decoded): string
    {
        return Utf8::encodeUnsafe($decoded, '?#%');
    }

    /**
     * Case 35: "/" or a path ending in a script extension.
     *
     * @param array<string, true> $extensions
     */
    public static function isScriptPath(string $decodedPath, array $extensions): bool
    {
        $path = rtrim($decodedPath, '/');
        if ($path === '') {
            return true;
        }
        $slash = strrpos($path, '/');
        $last = $slash === false ? $path : substr($path, $slash + 1);
        $dot = strrpos($last, '.');
        if ($dot === false) {
            return false;
        }

        return isset($extensions[Utf8::lowerAscii(substr($last, $dot + 1))]);
    }

    /**
     * Case 38: the headline slug key of a normalised path, or "".
     */
    public static function slugKey(string $normalisedPath, MapData $map): string
    {
        // a. Segments, minus trailing id and marker segments.
        $segments = array_values(array_filter(
            explode('/', Utf8::lowerAscii($normalisedPath)),
            static fn (string $s): bool => $s !== ''
        ));
        while ($segments !== [] && preg_match(self::SLUG_TRAILER, $segments[count($segments) - 1]) === 1) {
            array_pop($segments);
        }
        // b.
        if ($segments === []) {
            return '';
        }
        // c.
        $last = (string) preg_replace('~\.(?:cms|html?|php)$~D', '', $segments[count($segments) - 1]);
        // d.
        $tokens = preg_split('~[^a-z0-9]+~', $last, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($tokens as $token) {
            // e.
            if (isset($map->stopWords[$token])) {
                continue;
            }
            // f.
            $token = $map->numberWords[$token] ?? $token;
            // g.
            if (strlen($token) > 3 && $token[-1] === 's' && $token[-2] !== 's') {
                $token = substr($token, 0, -1);
            }
            $out[] = $token;
        }
        // h.
        if (count($out) < $map->minTokens) {
            return '';
        }
        // i.
        foreach ($out as $token) {
            if (isset($map->recurringWords[$token])) {
                return '';
            }
        }

        // j.
        return implode('-', $out);
    }
}
