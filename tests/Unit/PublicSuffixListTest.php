<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Tests\Unit;

use IndianOpinion\OutletKey\InvalidSuffixListException;
use IndianOpinion\OutletKey\OutletMap;
use IndianOpinion\OutletKey\OutletResolver;
use IndianOpinion\OutletKey\PublicSuffixList;
use PHPUnit\Framework\TestCase;

/**
 * Ruling on open question 1: an optional Public Suffix List, loaded only when
 * the caller passes one.
 */
final class PublicSuffixListTest extends TestCase
{
    private const SAMPLE = __DIR__ . '/../fixtures/public_suffix_list.sample.dat';

    public function testParsesRulesWildcardsAndExceptions(): void
    {
        $psl = PublicSuffixList::fromFile(self::SAMPLE);
        self::assertSame('co.uk', $psl->publicSuffix('www.bbc.co.uk'));
        self::assertSame('com', $psl->publicSuffix('www.example.com'));
        self::assertSame('foo.kawasaki.jp', $psl->publicSuffix('a.foo.kawasaki.jp'));
        self::assertSame('kawasaki.jp', $psl->publicSuffix('www.city.kawasaki.jp'));
        self::assertSame('ck', $psl->publicSuffix('www.ck'));
        self::assertSame('a.ck', $psl->publicSuffix('b.a.ck'));
        self::assertSame('xn--h2brj9c', $psl->publicSuffix('example.xn--h2brj9c'));
        self::assertSame('test', $psl->publicSuffix('a.b.test'));
        self::assertSame('example.test', $psl->publicSuffix('a.example.test'));
        // Not in the list: the implicit "*" rule.
        self::assertSame('zz', $psl->publicSuffix('a.b.zz'));
    }

    public function testPrivateSectionIsSkippedByDefault(): void
    {
        $icann = PublicSuffixList::fromFile(self::SAMPLE);
        $all = PublicSuffixList::fromFile(self::SAMPLE, true);
        self::assertSame('com', $icann->publicSuffix('someone.blogspot.com'));
        self::assertSame('blogspot.com', $all->publicSuffix('someone.blogspot.com'));
        self::assertGreaterThan($icann->count(), $all->count());
    }

    public function testResolverUsesTheListWhenGiven(): void
    {
        $psl = PublicSuffixList::fromFile(self::SAMPLE);
        $resolver = new OutletResolver(null, [], $psl);
        self::assertSame('bbc.com', $resolver->outletKey('www.bbc.co.uk'));
        self::assertSame('example.foo.kawasaki.jp', $resolver->outletKey('www.example.foo.kawasaki.jp'));
        self::assertSame('city.kawasaki.jp', $resolver->outletKey('www.city.kawasaki.jp'));
        self::assertSame('public suffix list', $resolver->explain('a.b.example.co.in')->find('registrable-domain')['note'] ?? null);
        // Portals still work on top of the list.
        self::assertSame('economictimes.indiatimes.com', $resolver->outletKey('auto.economictimes.indiatimes.com'));

        // Without the list, the built-in rule decides: "jp" plus a two-letter
        // check finds nothing, so the last two labels win.
        self::assertSame('kawasaki.jp', (new OutletResolver())->outletKey('www.example.foo.kawasaki.jp'));
    }

    public function testMapSuffixesStillApplyWithTheList(): void
    {
        $map = OutletMap::bundled()->withOverrides(['suffixes' => ['example.com']]);
        $resolver = new OutletResolver($map, [], PublicSuffixList::fromFile(self::SAMPLE));
        self::assertSame('city.example.com', $resolver->outletKey('news.city.example.com'));
        self::assertSame('map suffix', $resolver->explain('news.city.example.com')->find('registrable-domain')['note'] ?? null);
    }

    public function testFromString(): void
    {
        $psl = PublicSuffixList::fromString("// c\r\nco.uk\r\nuk\r\n");
        self::assertSame(2, $psl->count());
        self::assertSame('co.uk', $psl->publicSuffix('a.co.uk'));
    }

    public function testEmptyListIsRefused(): void
    {
        $this->expectException(InvalidSuffixListException::class);
        PublicSuffixList::fromString("// only comments\n\n");
    }

    public function testMissingFileIsRefused(): void
    {
        $this->expectException(InvalidSuffixListException::class);
        PublicSuffixList::fromFile('/nonexistent/public_suffix_list.dat');
    }
}
