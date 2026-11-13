<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey;

/**
 * Stable sha256 hex digests of keys, for storage and indexing.
 *
 * The digest covers the scheme version, the kind of key and the key. It does
 * not cover the map version: a map release that does not touch an outlet
 * leaves its keys, and so its hashes, unchanged. Store the map version beside
 * the hash if you need to know which map produced it.
 *
 * The empty key means "unknown" and hashes to the empty string, so two
 * unknown inputs never share a hash.
 */
final class KeyHash
{
    public const KIND_OUTLET = 'outlet';
    public const KIND_ARTICLE = 'article';

    public static function outlet(string $outletKey): string
    {
        return self::digest(self::KIND_OUTLET, $outletKey);
    }

    public static function article(string $articleKey): string
    {
        return self::digest(self::KIND_ARTICLE, $articleKey);
    }

    private static function digest(string $kind, string $key): string
    {
        if ($key === '') {
            return '';
        }

        return hash('sha256', 'outlet-key/' . OutletKey::SCHEME_VERSION . "\n" . $kind . "\n" . $key);
    }
}
