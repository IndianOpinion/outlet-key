<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey;

use IndianOpinion\OutletKey\Internal\MapData;
use IndianOpinion\OutletKey\Internal\PathNormaliser;
use IndianOpinion\OutletKey\Internal\QueryFilter;
use IndianOpinion\OutletKey\Internal\UrlParser;
use IndianOpinion\OutletKey\Internal\Utf8;

/**
 * URL to article key: the same article reached through AMP paths, query
 * strings, fragments, trailing slashes or mirror hosts gives one key.
 *
 * Forms of the key:
 *
 * - outlet + path [+ "?" + kept query]   the plain key
 * - outlet + "/a/" + id                  an article-id rule fired
 * - outlet + "/s/" + slug key            a slug-outlet rule fired
 * - the trimmed input, ASCII-lowercased  the URL could not be parsed
 */
final class ArticleKeyer
{
    private OutletResolver $resolver;
    private MapData $map;

    public function __construct(?OutletResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new OutletResolver();
        $this->map = $this->resolver->mapData();
    }

    public function resolver(): OutletResolver
    {
        return $this->resolver;
    }

    /**
     * @throws InputTooLongException when the input is over 8192 bytes
     * @throws RuleRuntimeException when a map pattern fails at match time
     */
    public function articleKey(string $url): string
    {
        $trace = null;

        return $this->compute($url, $trace);
    }

    /**
     * The headline slug key of a path (PRD case 38), or "" when the slug is
     * too short, recurring, or not Latin. The path is normalised first.
     */
    public function slugKey(string $path): string
    {
        [$path] = self::splitPathOnly($path);

        return PathNormaliser::slugKey(PathNormaliser::normalise($path, false), $this->map);
    }

    /**
     * True only when both keys are non-empty and equal.
     */
    public function sameArticle(string $a, string $b): bool
    {
        return $a !== '' && $a === $b;
    }

    /** sha256 hex of the article key, or "" when the key is empty. */
    public function articleHash(string $url): string
    {
        return KeyHash::article($this->articleKey($url));
    }

    public function explain(string $url): Trace
    {
        $trace = [];
        $this->compute($url, $trace);
        $trace[] = ['map-version', $this->map->version, ''];

        return new Trace($trace);
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}>|null $trace
     */
    private function compute(string $url, ?array &$trace): string
    {
        $parsed = UrlParser::parse($url);
        $outlet = $this->resolver->keyParsed($parsed, $trace);

        // Case 31: unparseable input keys as itself.
        if ($parsed->host === null) {
            $key = Utf8::lowerAscii($parsed->prepared);
            if ($trace !== null) {
                $trace[] = ['article-key', $key, $key === '' ? 'empty input' : 'unparsed: trimmed input, lowercased'];
            }

            return $key;
        }

        // Case 28 to 30.
        $fired = $trace === null ? null : [];
        $lowercase = isset($this->map->caseInsensitivePaths[$outlet]);
        $path = PathNormaliser::normalise($parsed->path, $lowercase, $fired);
        if ($trace !== null) {
            foreach ($fired ?? [] as $rewrite) {
                $trace[] = ['path-rewrite', '', $rewrite];
            }
            $trace[] = ['path', PathNormaliser::encode($path), $lowercase ? 'case-insensitive outlet' : 'case kept'];
        }

        // Case 37 and 39: slug outlets first.
        if (isset($this->map->slugOutlets[$outlet])) {
            $slug = PathNormaliser::slugKey($path, $this->map);
            if ($slug !== '') {
                $key = $outlet . '/s/' . $slug;
                if ($trace !== null) {
                    $trace[] = ['slug-rule', $slug, 'slug outlet; slug key used'];
                    $trace[] = ['article-key', $key, ''];
                }

                return $key;
            }
            if ($trace !== null) {
                $trace[] = ['slug-rule', '', 'slug outlet, but the slug key is empty; falling through'];
            }
        }

        // Case 36: article-id rules.
        if (isset($this->map->articleIdRules[$outlet])) {
            $pattern = $this->map->articleIdRules[$outlet];
            $result = preg_match($pattern, $path, $m);
            if ($result === false) {
                throw RuleRuntimeException::forPattern($outlet, $pattern, preg_last_error_msg());
            }
            if ($result === 1 && isset($m[1]) && $m[1] !== '') {
                $id = PathNormaliser::encode($m[1]);
                $key = $outlet . '/a/' . $id;
                if ($trace !== null) {
                    $trace[] = ['id-rule', $id, 'matched ' . $pattern];
                    $trace[] = ['article-key', $key, ''];
                }

                return $key;
            }
            if ($trace !== null) {
                $trace[] = ['id-rule', '', 'no match for ' . $pattern];
            }
        }

        // Case 33 to 35, 40, 41.
        $script = PathNormaliser::isScriptPath(rawurldecode($parsed->path), $this->map->scriptExtensions)
            || $path === '/';
        if ($trace !== null && $parsed->query !== null && $parsed->query !== '') {
            $trace[] = ['query-mode', $script ? 'script path' : 'slug path', $script ? 'non-tracking keys kept' : 'only id keys kept'];
        }
        $query = QueryFilter::filter($parsed->query, $script, $this->map, $trace);

        $key = $outlet . PathNormaliser::encode($path) . ($query === '' ? '' : '?' . $query);
        if ($trace !== null) {
            $trace[] = ['article-key', $key, 'plain key'];
        }

        return $key;
    }

    /**
     * Accept a full URL or a bare path in slugKey().
     *
     * @return array{0: string}
     */
    private static function splitPathOnly(string $path): array
    {
        $path = trim($path);
        $cut = strcspn($path, '?#');

        return [substr($path, 0, $cut)];
    }
}
