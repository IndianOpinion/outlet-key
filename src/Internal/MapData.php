<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Internal;

/**
 * The map as hash sets and tables, ready for O(1) lookups.
 *
 * @internal
 */
final class MapData
{
    /**
     * @param array<string, true> $mirrorLabels
     * @param array<string, true> $languageLabels
     * @param array<string, true> $secondLevelLabels
     * @param array<string, true> $suffixes
     * @param array<string, true> $portalBases
     * @param array<string, true> $passThrough
     * @param array<string, string> $aliases
     * @param array<string, true> $aggregators
     * @param array<string, string> $articleIdRules
     * @param array<string, true> $slugOutlets
     * @param array<string, true> $caseInsensitivePaths
     * @param array<string, true> $stopWords
     * @param array<string, string> $numberWords
     * @param array<string, true> $recurringWords
     * @param array<string, true> $idParams
     * @param array<string, true> $trackingParams
     * @param list<string> $trackingPrefixes
     * @param array<string, true> $scriptExtensions
     */
    public function __construct(
        public readonly string $version,
        public readonly array $mirrorLabels,
        public readonly array $languageLabels,
        public readonly array $secondLevelLabels,
        public readonly array $suffixes,
        public readonly array $portalBases,
        public readonly array $passThrough,
        public readonly array $aliases,
        public readonly array $aggregators,
        public readonly array $articleIdRules,
        public readonly array $slugOutlets,
        public readonly array $caseInsensitivePaths,
        public readonly array $stopWords,
        public readonly array $numberWords,
        public readonly array $recurringWords,
        public readonly int $minTokens,
        public readonly array $idParams,
        public readonly array $trackingParams,
        public readonly array $trackingPrefixes,
        public readonly array $scriptExtensions
    ) {
    }

    /**
     * Build from a structurally validated map array.
     *
     * @param array<string, mixed> $d
     */
    public static function fromValidatedArray(array $d): self
    {
        /** @var array{stopWords: list<string>, numberWords: array<string, string>, recurringWords: list<string>, minTokens: int} $slug */
        $slug = $d['slug'];
        /** @var array{idParams: list<string>, trackingParams: list<string>, trackingPrefixes: list<string>, scriptExtensions: list<string>} $query */
        $query = $d['query'];

        return new self(
            (string) $d['version'],
            self::set($d['mirrorLabels']),
            self::set($d['languageLabels']),
            self::set($d['secondLevelLabels']),
            self::set($d['suffixes']),
            self::set($d['portalBases']),
            self::set($d['passThrough']),
            self::table($d['aliases']),
            self::set($d['aggregators']),
            self::table($d['articleIdRules']),
            self::set($d['slugOutlets']),
            self::set($d['caseInsensitivePaths']),
            self::set($slug['stopWords']),
            $slug['numberWords'],
            self::set($slug['recurringWords']),
            $slug['minTokens'],
            self::set($query['idParams']),
            self::set($query['trackingParams']),
            $query['trackingPrefixes'],
            self::set($query['scriptExtensions'])
        );
    }

    /**
     * @return array<string, true>
     */
    private static function set(mixed $list): array
    {
        $out = [];
        foreach ((array) $list as $v) {
            $out[(string) $v] = true;
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function table(mixed $table): array
    {
        $out = [];
        foreach ((array) $table as $k => $v) {
            $out[(string) $k] = (string) $v;
        }

        return $out;
    }
}
