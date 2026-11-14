<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey;

/**
 * Static facade over the bundled map, memoised per process.
 *
 * Use OutletResolver and ArticleKeyer directly when you need your own map,
 * overrides, own hosts or a Public Suffix List.
 */
final class OutletKey
{
    /**
     * Changes whenever a code change alters any key for any input. Map
     * changes are tracked by the map version instead.
     */
    public const SCHEME_VERSION = 1;

    private static ?ArticleKeyer $keyer = null;

    private function __construct()
    {
    }

    public static function outlet(string $urlOrHost): string
    {
        return self::keyer()->resolver()->outletKey($urlOrHost);
    }

    public static function article(string $url): string
    {
        return self::keyer()->articleKey($url);
    }

    /** sha256 hex of the outlet key; "" for the empty key. */
    public static function outletHash(string $urlOrHost): string
    {
        return KeyHash::outlet(self::outlet($urlOrHost));
    }

    /** sha256 hex of the article key; "" for the empty key. */
    public static function articleHash(string $url): string
    {
        return KeyHash::article(self::article($url));
    }

    /** The bundled map's data version. */
    public static function mapVersion(): string
    {
        return OutletMap::bundled()->version();
    }

    public static function explain(string $url): Trace
    {
        return self::keyer()->explain($url);
    }

    private static function keyer(): ArticleKeyer
    {
        return self::$keyer ??= new ArticleKeyer(new OutletResolver(OutletMap::bundled()));
    }
}
