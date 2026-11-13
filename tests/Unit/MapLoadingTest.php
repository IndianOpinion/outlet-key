<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Tests\Unit;

use IndianOpinion\OutletKey\ArticleKeyer;
use IndianOpinion\OutletKey\InputTooLongException;
use IndianOpinion\OutletKey\InvalidMapException;
use IndianOpinion\OutletKey\OutletMap;
use IndianOpinion\OutletKey\OutletResolver;
use IndianOpinion\OutletKey\RuleRuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PRD cases 43 to 47 and the validator rules of section 6.2.
 */
final class MapLoadingTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function base(): array
    {
        return OutletMap::bundled()->toArray();
    }

    public function testCase43BundledMapLoadsOncePerProcess(): void
    {
        OutletMap::resetBundled();
        $first = OutletMap::bundled();
        $second = OutletMap::bundled();
        new OutletResolver();
        new ArticleKeyer();
        self::assertSame($first, $second);
        self::assertSame(1, OutletMap::bundledReadCount());
        self::assertMatchesRegularExpression('~^[0-9]{4}\.[0-9]{2}\.[0-9]{2}(\.[0-9]+)?$~', $first->version());
    }

    public function testCase44BrokenJson(): void
    {
        $e = $this->catchMap(static fn () => OutletMap::fromJson('{"version": ', 'broken.json'));
        self::assertSame('json', $e->rule());
        self::assertSame('broken.json', $e->source());
        self::assertStringContainsString('broken.json', $e->getMessage());
    }

    public function testCase44MissingFile(): void
    {
        $e = $this->catchMap(static fn () => OutletMap::fromFile('/nonexistent/outlets.json'));
        self::assertSame('file', $e->rule());
    }

    /**
     * @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidMapProvider(): iterable
    {
        yield 'chained alias' => [
            static function (array $m): array {
                $m['aliases'] = ['a-example.com' => 'b-example.com', 'b-example.com' => 'c-example.com'];
                return $m;
            },
            'alias-chain',
            '/aliases/a-example.com',
        ];
        yield 'self alias' => [
            static function (array $m): array {
                $m['aliases'] = ['a-example.com' => 'a-example.com'];
                return $m;
            },
            'alias-self',
            '/aliases/a-example.com',
        ];
        yield 'unsorted list' => [
            static function (array $m): array {
                $m['aggregators'] = ['yahoo.com', 'msn.com'];
                return $m;
            },
            'unsorted',
            '/aggregators/1',
        ];
        yield 'unsorted table' => [
            static function (array $m): array {
                $m['aliases'] = ['ndtv.in' => 'ndtv.com', 'bbc.co.uk' => 'bbc.com'];
                return $m;
            },
            'unsorted',
            '/aliases/bbc.co.uk',
        ];
        yield 'duplicate' => [
            static function (array $m): array {
                $m['passThrough'] = ['news.google.com', 'news.google.com'];
                return $m;
            },
            'duplicate',
            '/passThrough/1',
        ];
        yield 'uppercase host' => [
            static function (array $m): array {
                $m['aggregators'] = ['MSN.com'];
                return $m;
            },
            'host-format',
            '/aggregators/0',
        ];
        yield 'host with scheme' => [
            static function (array $m): array {
                $m['passThrough'] = ['https://news.google.com'];
                return $m;
            },
            'host-format',
            '/passThrough/0',
        ];
        yield 'trailing dot' => [
            static function (array $m): array {
                $m['slugOutlets'] = ['nasa.gov.'];
                return $m;
            },
            'host-format',
            '/slugOutlets/0',
        ];
        yield 'single-label suffix' => [
            static function (array $m): array {
                $m['suffixes'] = ['uk'];
                return $m;
            },
            'host-format',
            '/suffixes/0',
        ];
        yield 'alias target not an outlet key' => [
            static function (array $m): array {
                $m['aliases'] = ['a-example.com' => 'www.b-example.com'];
                return $m;
            },
            'not-outlet-key',
            '/aliases/a-example.com',
        ];
        yield 'alias source never produced' => [
            static function (array $m): array {
                $m['aliases'] = ['news.a-example.com' => 'b-example.com'];
                return $m;
            },
            'alias-unreachable',
            '/aliases/news.a-example.com',
        ];
        yield 'id rule key not an outlet key' => [
            static function (array $m): array {
                $m['articleIdRules'] = ['www.example.com' => '~([0-9]+)~'];
                $m['notes'] = [];
                return $m;
            },
            'not-outlet-key',
            '/articleIdRules/www.example.com',
        ];
        yield 'slug outlet shadowed by alias' => [
            static function (array $m): array {
                $m['slugOutlets'] = ['ndtv.in'];
                return $m;
            },
            'shadowed-by-alias',
            '/slugOutlets/0',
        ];
        yield 'mirror and language overlap' => [
            static function (array $m): array {
                $m['languageLabels'] = ['hindi', 'm'];
                return $m;
            },
            'mirror-language-overlap',
            '/mirrorLabels',
        ];
        yield 'minTokens out of range' => [
            static function (array $m): array {
                $m['slug']['minTokens'] = 1;
                return $m;
            },
            'min-tokens',
            '/slug/minTokens',
        ];
        yield 'bad version' => [
            static function (array $m): array {
                $m['version'] = 'v1';
                return $m;
            },
            'version-format',
            '/version',
        ];
        yield 'missing field' => [
            static function (array $m): array {
                unset($m['aggregators']);
                return $m;
            },
            'required',
            '/aggregators',
        ];
        yield 'unknown field' => [
            static function (array $m): array {
                $m['extra'] = [];
                return $m;
            },
            'unknown-field',
            '/extra',
        ];
        yield 'pattern does not compile' => [
            static function (array $m): array {
                $m['articleIdRules'] = ['example.com' => '~([0-9]+~'];
                return $m;
            },
            'pattern-syntax',
            '/articleIdRules/example.com',
        ];
        yield 'pattern without delimiters' => [
            static function (array $m): array {
                $m['articleIdRules'] = ['example.com' => 'a([0-9]+)a'];
                return $m;
            },
            'pattern-syntax',
            '/articleIdRules/example.com',
        ];
        yield 'pattern with e modifier' => [
            static function (array $m): array {
                $m['articleIdRules'] = ['example.com' => '~([0-9]+)~e'];
                return $m;
            },
            'pattern-modifier',
            '/articleIdRules/example.com',
        ];
        yield 'pattern with no group' => [
            static function (array $m): array {
                $m['articleIdRules'] = ['example.com' => '~[0-9]+~'];
                return $m;
            },
            'pattern-groups',
            '/articleIdRules/example.com',
        ];
        yield 'note example does not match' => [
            static function (array $m): array {
                $m['notes'] = ['ndtv.com' => ['examples' => ['/no-id-here']]];
                return $m;
            },
            'pattern-example',
            '/notes/ndtv.com/examples/0',
        ];
        yield 'list where an object is expected' => [
            static function (array $m): array {
                $m['aliases'] = ['a', 'b'];
                return $m;
            },
            'type',
            '/aliases',
        ];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutate
     */
    #[DataProvider('invalidMapProvider')]
    public function testCase44InvalidMapsNameTheRuleAndPointer(callable $mutate, string $rule, string $pointer): void
    {
        $e = $this->catchMap(static fn () => OutletMap::fromArray($mutate(self::base())));
        self::assertSame($rule, $e->rule(), $e->getMessage());
        self::assertSame($pointer, $e->pointer(), $e->getMessage());
        self::assertSame('array', $e->source());
        self::assertStringContainsString('[' . $rule . ']', $e->getMessage());
    }

    public function testCase44FromFileNamesTheFile(): void
    {
        $dir = sys_get_temp_dir() . '/outlet-key-test-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $path = $dir . '/bad.json';
        $map = self::base();
        $map['aggregators'] = ['yahoo.com', 'msn.com'];
        file_put_contents($path, json_encode($map, JSON_THROW_ON_ERROR));
        try {
            $e = $this->catchMap(static fn () => OutletMap::fromFile($path));
            self::assertSame($path, $e->source());
            self::assertStringContainsString($path, $e->getMessage());
            self::assertStringContainsString('/aggregators/1', $e->getMessage());
        } finally {
            unlink($path);
            rmdir($dir);
        }
    }

    public function testCase45PatternWithTwoGroupsIsRejectedAtLoad(): void
    {
        $e = $this->catchMap(static fn () => OutletMap::bundled()->withOverrides([
            'articleIdRules' => ['example.com' => '~/(a)([0-9]+)$~'],
        ]));
        self::assertSame('pattern-groups', $e->rule());
        // Named groups count once; non-capturing groups not at all.
        $map = OutletMap::bundled()->withOverrides([
            'articleIdRules' => ['example.com' => '~/(?:x|y)-(?<id>[0-9]+)$~'],
        ]);
        self::assertSame('example.com/a/12', (new ArticleKeyer(new OutletResolver($map)))->articleKey('example.com/x-12'));
    }

    public function testCase45CatastrophicPatternRaisesAtKeyingTime(): void
    {
        $map = OutletMap::bundled()->withOverrides([
            'articleIdRules' => ['example.com' => '~/(a+)+$~'],
        ]);
        $keyer = new ArticleKeyer(new OutletResolver($map));
        $jit = ini_get('pcre.jit');
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1000');
        try {
            $this->expectException(RuleRuntimeException::class);
            $keyer->articleKey('https://example.com/' . str_repeat('a', 40) . '!');
        } finally {
            ini_set('pcre.jit', (string) $jit);
            ini_set('pcre.backtrack_limit', (string) $limit);
        }
    }

    public function testCase46RandomBytesNeverRaise(): void
    {
        mt_srand(20261112);
        $resolver = new OutletResolver();
        $keyer = new ArticleKeyer($resolver);
        $alphabet = "abcAMP./:?#&=%-_@[]0123456789 \t\xE0\xA4\xB9\xFF\x00";
        for ($i = 0; $i < 1000; $i++) {
            $len = mt_rand(0, 200);
            $s = '';
            for ($j = 0; $j < $len; $j++) {
                $s .= mt_rand(0, 1) === 0 ? chr(mt_rand(0, 255)) : $alphabet[mt_rand(0, strlen($alphabet) - 1)];
            }
            if ($i % 3 === 0) {
                $s = 'https://' . $s;
            }
            $resolver->outletKey($s);
            $resolver->host($s);
            $keyer->articleKey($s);
            $keyer->slugKey($s);
            $keyer->explain($s);
        }
        $this->addToAssertionCount(1);
    }

    public function testCase46OnlyLengthRaisesOnInput(): void
    {
        $this->expectException(InputTooLongException::class);
        (new ArticleKeyer())->articleKey(str_repeat("\xFF", 9000));
    }

    public function testCase47OverridesAddRemoveAndRevalidate(): void
    {
        $base = OutletMap::bundled();
        $map = $base->withOverrides([
            'aliases' => ['example-mirror.com' => 'example.com'],
            'remove' => ['portalBases' => ['medium.com']],
        ]);
        self::assertSame($base->version(), $map->version());
        $resolver = new OutletResolver($map);
        self::assertSame('example.com', $resolver->outletKey('www.example-mirror.com'));
        self::assertSame('medium.com', $resolver->outletKey('someone.medium.com'));
        self::assertSame('someone.medium.com', (new OutletResolver($base))->outletKey('someone.medium.com'));
        /** @var list<string> $portals */
        $portals = $map->toArray()['portalBases'];
        self::assertNotContains('medium.com', $portals);

        // Lists are unioned and sorted, so the order of an override does not matter.
        $map = $base->withOverrides(['aggregators' => ['zzz.example', 'aaa.example', 'msn.com']]);
        /** @var list<string> $aggregators */
        $aggregators = $map->toArray()['aggregators'];
        self::assertSame('aaa.example', $aggregators[0]);
        self::assertSame(array_values(array_unique($aggregators)), $aggregators);

        // Removing a table entry and nested list entries.
        $map = $base->withOverrides(['remove' => ['aliases' => ['ndtv.in'], 'query' => ['idParams' => ['v']]]]);
        $resolver = new OutletResolver($map);
        self::assertSame('ndtv.in', $resolver->outletKey('ndtv.in'));
        self::assertSame('x.com/watch', (new ArticleKeyer($resolver))->articleKey('https://x.com/watch?v=abc'));

        // Scalars are replaced.
        $map = $base->withOverrides(['version' => '2030.01.01.2', 'slug' => ['minTokens' => 3]]);
        self::assertSame('2030.01.01.2', $map->version());
        self::assertSame('alpha-beta-gamma', (new ArticleKeyer(new OutletResolver($map)))->slugKey('/alpha-beta-gamma'));
        self::assertSame('', (new ArticleKeyer())->slugKey('/alpha-beta-gamma'));
    }

    public function testCase47OverrideThatCreatesAChainRaises(): void
    {
        $e = $this->catchMap(static fn () => OutletMap::bundled()->withOverrides([
            'aliases' => ['ndtv.com' => 'ndtvnews.com'],
        ]));
        self::assertSame('alias-chain', $e->rule());
        self::assertSame('overrides', $e->source());
    }

    public function testCase47BadRemoveSection(): void
    {
        $e = $this->catchMap(static fn () => OutletMap::bundled()->withOverrides(['remove' => ['nothing' => ['x']]]));
        self::assertSame('type', $e->rule());
    }

    public function testToArrayRoundTrips(): void
    {
        $map = OutletMap::bundled();
        self::assertSame($map->toArray(), OutletMap::fromArray($map->toArray())->toArray());
    }

    private function catchMap(callable $fn): InvalidMapException
    {
        try {
            $fn();
        } catch (InvalidMapException $e) {
            return $e;
        }
        self::fail('Expected InvalidMapException');
    }
}
