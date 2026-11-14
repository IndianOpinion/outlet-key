<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Tests\Unit;

use IndianOpinion\OutletKey\ArticleKeyer;
use IndianOpinion\OutletKey\KeyHash;
use IndianOpinion\OutletKey\OutletKey;
use IndianOpinion\OutletKey\OutletResolver;
use PHPUnit\Framework\TestCase;

/**
 * Ruling on open question 7: sha256 hex helpers for both keys.
 */
final class KeyHashTest extends TestCase
{
    public function testHashesAreStableSha256Hex(): void
    {
        $outlet = KeyHash::outlet('ndtv.com');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $outlet);
        self::assertSame(hash('sha256', "outlet-key/1\noutlet\nndtv.com"), $outlet);
        self::assertSame(hash('sha256', "outlet-key/1\narticle\nndtv.com/a/1234567"), KeyHash::article('ndtv.com/a/1234567'));
    }

    public function testOutletAndArticleHashesAreSeparated(): void
    {
        self::assertNotSame(KeyHash::outlet('x.com'), KeyHash::article('x.com'));
    }

    public function testEmptyKeyHashesToEmpty(): void
    {
        self::assertSame('', KeyHash::outlet(''));
        self::assertSame('', KeyHash::article(''));
        self::assertSame('', OutletKey::outletHash('not a url'));
    }

    public function testInstanceAndFacadeHelpersAgree(): void
    {
        $resolver = new OutletResolver();
        $keyer = new ArticleKeyer($resolver);
        $url = 'https://m.economictimes.com/news/x?utm_source=feed';
        self::assertSame(KeyHash::outlet('economictimes.indiatimes.com'), $resolver->outletHash($url));
        self::assertSame($resolver->outletHash($url), OutletKey::outletHash($url));
        self::assertSame(KeyHash::article('economictimes.indiatimes.com/news/x'), $keyer->articleHash($url));
        self::assertSame($keyer->articleHash($url), OutletKey::articleHash($url));
        // Variants of one article share a hash.
        self::assertSame(OutletKey::articleHash($url), OutletKey::articleHash('https://economictimes.indiatimes.com/news/x/amp'));
    }
}
