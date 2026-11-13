<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey;

use IndianOpinion\OutletKey\Internal\HostRules;
use IndianOpinion\OutletKey\Internal\MapData;
use IndianOpinion\OutletKey\Internal\ParsedUrl;
use IndianOpinion\OutletKey\Internal\UrlParser;
use IndianOpinion\OutletKey\Internal\Utf8;

/**
 * URL or bare host to outlet key: the newsroom that owns it.
 *
 * Pure given its map. Bad input never raises (except an input over 8192
 * bytes); it gives the empty key, which means "unknown".
 */
final class OutletResolver
{
    private OutletMap $map;
    private MapData $data;
    private ?PublicSuffixList $suffixList;
    /** @var array<string, true> */
    private array $own = [];

    /**
     * @param OutletMap|null $map null means the bundled map
     * @param list<string> $ownHosts the caller's own site, as hosts or URLs; keyed through the same pipeline
     * @param PublicSuffixList|null $suffixList optional; replaces the map's second-level and last-two-labels rules
     */
    public function __construct(?OutletMap $map = null, array $ownHosts = [], ?PublicSuffixList $suffixList = null)
    {
        $this->map = $map ?? OutletMap::bundled();
        $this->data = $this->map->compiled();
        $this->suffixList = $suffixList;
        foreach ($ownHosts as $host) {
            $key = $this->outletKey($host);
            if ($key !== '') {
                $this->own[$key] = true;
            }
        }
    }

    public function map(): OutletMap
    {
        return $this->map;
    }

    public function outletKey(string $urlOrHost): string
    {
        $trace = null;

        return $this->keyParsed(UrlParser::parse($urlOrHost), $trace);
    }

    /**
     * The prepared host after input preparation and AMP cache unwrapping,
     * before any label is stripped. Empty when the input has no usable host.
     */
    public function host(string $urlOrHost): string
    {
        return UrlParser::parse($urlOrHost)->host ?? '';
    }

    /**
     * The registrable domain of a host (PRD case 17), after the host is
     * prepared. IP literals are returned as they are; an unusable host gives
     * the empty string.
     */
    public function registrableDomain(string $host): string
    {
        $prepared = UrlParser::normaliseHost(Utf8::lowerAscii(trim($host)));
        if ($prepared === null) {
            return '';
        }
        if ($prepared[1] !== ParsedUrl::HOST_NAME) {
            return $prepared[0];
        }

        return HostRules::registrable($prepared[0], $this->data, $this->suffixList)[0];
    }

    public function isAggregator(string $outletKey): bool
    {
        return $outletKey !== '' && isset($this->data->aggregators[$outletKey]);
    }

    public function isOwnOutlet(string $outletKey): bool
    {
        return $outletKey !== '' && isset($this->own[$outletKey]);
    }

    /**
     * True only when both keys are non-empty and equal. Two unknowns are
     * never the same outlet.
     */
    public function sameOutlet(string $a, string $b): bool
    {
        return $a !== '' && $a === $b;
    }

    /** sha256 hex of the outlet key, or "" when the key is empty. */
    public function outletHash(string $urlOrHost): string
    {
        return KeyHash::outlet($this->outletKey($urlOrHost));
    }

    public function explain(string $urlOrHost): Trace
    {
        $trace = [];
        $parsed = UrlParser::parse($urlOrHost);
        $this->keyParsed($parsed, $trace);
        $trace[] = ['map-version', $this->data->version, ''];

        return new Trace($trace);
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}>|null $trace
     *
     * @internal shared with ArticleKeyer
     */
    public function keyParsed(ParsedUrl $parsed, ?array &$trace): string
    {
        if ($trace !== null) {
            $trace[] = ['input', $parsed->prepared, 'trimmed, entities decoded'];
        }
        if ($parsed->host === null) {
            if ($trace !== null) {
                $trace[] = ['host', '', 'unparsed: ' . $parsed->failure];
                $trace[] = ['outlet-key', '', 'empty key: unknown outlet'];
            }

            return '';
        }
        if ($trace !== null) {
            $trace[] = ['amp-unwrap', $parsed->host, $parsed->ampCacheHost === null ? 'no' : 'unwrapped from ' . $parsed->ampCacheHost];
            $trace[] = ['host', $parsed->host, $parsed->hostType];
        }
        if ($parsed->hostType !== ParsedUrl::HOST_NAME) {
            if ($trace !== null) {
                $trace[] = ['outlet-key', $parsed->host, 'IP literal: kept as it is'];
            }

            return $parsed->host;
        }

        $key = HostRules::preAliasKey($parsed->host, $this->data, $this->suffixList, $trace);
        if (!isset($this->data->passThrough[$key]) && isset($this->data->aliases[$key])) {
            $target = $this->data->aliases[$key];
            if ($trace !== null) {
                $trace[] = ['alias', $target, 'alias of ' . $key];
            }
            $key = $target;
        } elseif ($trace !== null) {
            $trace[] = ['alias', $key, 'none'];
        }
        if ($trace !== null) {
            $trace[] = ['outlet-key', $key, ''];
        }

        return $key;
    }

    /**
     * @internal shared with ArticleKeyer
     */
    public function mapData(): MapData
    {
        return $this->data;
    }
}
