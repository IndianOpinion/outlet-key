<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Tests\Unit;

use IndianOpinion\OutletKey\ArticleKeyer;
use IndianOpinion\OutletKey\InputTooLongException;
use IndianOpinion\OutletKey\OutletResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PRD cases 1 to 13, acceptance tests 1 to 13.
 */
final class InputPreparationTest extends TestCase
{
    private OutletResolver $resolver;
    private ArticleKeyer $keyer;

    protected function setUp(): void
    {
        $this->resolver = new OutletResolver();
        $this->keyer = new ArticleKeyer($this->resolver);
    }

    public function testCase1TrimsAndDecodesEntitiesOnce(): void
    {
        $url = ' https://amp.scmp.com/x?a=1&amp;utm_source=y ';
        self::assertSame('scmp.com', $this->resolver->outletKey($url));
        self::assertSame('scmp.com/x', $this->keyer->articleKey($url));
        // Decoded once only: a double-encoded entity stays one level encoded.
        self::assertSame(
            'example.com/x.php?a=1&amp%3Bb=2',
            $this->keyer->articleKey('example.com/x.php?a=1&amp;amp;b=2')
        );
    }

    public function testCase2EmptyInputGivesEmptyKeys(): void
    {
        foreach (['', '   ', "\t\n"] as $input) {
            self::assertSame('', $this->resolver->outletKey($input));
            self::assertSame('', $this->keyer->articleKey($input));
        }
    }

    public function testCase3BareHostsAndSchemelessInput(): void
    {
        foreach (['m.economictimes.com', '//m.economictimes.com/x', 'HTTPS://M.ECONOMICTIMES.COM/x', 'm.economictimes.com/x'] as $input) {
            self::assertSame('economictimes.indiatimes.com', $this->resolver->outletKey($input), $input);
        }
    }

    public function testCase4AnySchemeAndSchemeNeverInKey(): void
    {
        self::assertSame($this->keyer->articleKey('http://x.com/a'), $this->keyer->articleKey('https://x.com/a'));
        self::assertSame('x.com/a', $this->keyer->articleKey('feed://x.com/a'));
        self::assertSame('x.com/a', $this->keyer->articleKey('feed:https://x.com/a'));
        self::assertSame('x.com/a', $this->keyer->articleKey('git+ssh://x.com/a'));
        // A scheme with no authority has no host.
        self::assertSame('', $this->resolver->outletKey('mailto:someone@example.com'));
    }

    public function testCase5UserInfoIsDiscarded(): void
    {
        self::assertSame('x.com/a', $this->keyer->articleKey('https://u:p@x.com/a'));
        self::assertSame('x.com', $this->resolver->outletKey('https://user@x.com/'));
    }

    public function testCase6PortIsDiscarded(): void
    {
        self::assertSame($this->resolver->outletKey('x.com/a'), $this->resolver->outletKey('x.com:8080/a'));
        self::assertSame($this->keyer->articleKey('x.com/a'), $this->keyer->articleKey('x.com:8080/a'));
        self::assertSame('example.com', $this->resolver->outletKey('https://example.com:443'));
        self::assertSame('', $this->resolver->outletKey('https://example.com:http/a'));
    }

    public function testCase7LowercaseAndOneTrailingDot(): void
    {
        self::assertSame('example.com', $this->resolver->outletKey('example.com.'));
        self::assertSame('example.com', $this->resolver->outletKey('EXAMPLE.Com.'));
        self::assertSame('', $this->resolver->outletKey('example.com..'));
    }

    public function testCase8InputLengthLimit(): void
    {
        $prefix = 'https://example.com/';
        $ok = $prefix . str_repeat('a', 8192 - strlen($prefix));
        self::assertSame(8192, strlen($ok));
        self::assertSame('example.com', $this->resolver->outletKey($ok));
        self::assertStringStartsWith('example.com/aaa', $this->keyer->articleKey($ok));

        $this->expectException(InputTooLongException::class);
        $this->keyer->articleKey($ok . 'a');
    }

    public function testCase8OutletKeyAlsoRefusesLongInput(): void
    {
        $this->expectException(InputTooLongException::class);
        $this->resolver->outletKey(str_repeat('a', 8193));
    }

    public function testCase9IdnHostsAreKeyedAsALabels(): void
    {
        $unicode = $this->resolver->outletKey('https://भारत.example/a');
        $alabel = $this->resolver->outletKey('https://xn--h2brj9c.example/a');
        self::assertSame($alabel, $unicode);
        self::assertSame('xn--h2brj9c.example', $unicode);
        self::assertStringStartsWith('xn--', $unicode);
        self::assertSame('xn--j2bd4cyah0f.example', $this->resolver->outletKey('हिन्दी.example'));
        // A-labels are kept, lowercased.
        self::assertSame('xn--h2brj9c.example', $this->resolver->outletKey('XN--H2BRJ9C.example'));
        // IDNA full stops separate labels.
        self::assertSame('xn--h2brj9c.example', $this->resolver->outletKey("भारत\u{3002}example"));
    }

    public function testCase10UnparseableHosts(): void
    {
        $long = str_repeat('a', 64) . '.example.com';
        self::assertSame('', $this->resolver->outletKey($long));
        self::assertSame('', $this->resolver->outletKey("https://ex\xFFample.com/a"));
        self::assertSame('', $this->resolver->outletKey(str_repeat('abcdefghi.', 26) . 'com'));
        self::assertSame('', $this->resolver->outletKey('exa mple.com'));
        self::assertSame('', $this->resolver->outletKey('a..b.com'));
        // 63 bytes is fine.
        self::assertSame('example.com', $this->resolver->outletKey(str_repeat('a', 63) . '.example.com'));
    }

    public function testCase11Ipv4LiteralsAreKeptAsTheyAre(): void
    {
        self::assertSame('10.0.0.1', $this->resolver->outletKey('http://10.0.0.1/a'));
        self::assertSame('10.0.0.1/a', $this->keyer->articleKey('http://10.0.0.1:8080/a'));
        // Not an IPv4 literal, so keyed as a name.
        self::assertSame('0.999', $this->resolver->outletKey('http://10.0.0.999/a'));
    }

    public function testCase12Ipv6LiteralsLoweredWithoutBrackets(): void
    {
        self::assertSame('2001:db8::1', $this->resolver->outletKey('http://[2001:DB8::1]/a'));
        self::assertSame('2001:db8::1', $this->resolver->outletKey('http://[2001:DB8::1]:8080/a'));
        self::assertSame('2001:0db8:0:0::1', $this->resolver->outletKey('http://[2001:0DB8:0:0::1]/'));
        self::assertSame('', $this->resolver->outletKey('http://[not-ipv6]/a'));
        self::assertSame('', $this->resolver->outletKey('http://[2001:db8::1/a'));
    }

    public function testCase13GoogleAmpCacheIsUnwrappedOnce(): void
    {
        self::assertSame('scmp.com', $this->resolver->outletKey('https://www-scmp-com.cdn.ampproject.org/c/s/www.scmp.com/a'));
        self::assertSame('scmp.com', $this->resolver->outletKey('https://www-scmp-com.cdn.ampproject.org/v/www.scmp.com/a'));
        self::assertSame('ampproject.org', $this->resolver->outletKey('https://x.cdn.ampproject.org/zz'));
        self::assertSame('www.scmp.com', $this->resolver->host('https://www-scmp-com.cdn.ampproject.org/c/s/www.scmp.com/a'));
        // Nested cache URLs are not unwrapped again.
        $nested = 'https://a.cdn.ampproject.org/c/s/b.cdn.ampproject.org/c/s/www.scmp.com/a';
        self::assertSame('b.cdn.ampproject.org', $this->resolver->host($nested));
        self::assertSame('ampproject.org', $this->resolver->outletKey($nested));
        // The s marker is never read as the host itself.
        self::assertSame('x.cdn.ampproject.org', $this->resolver->host('https://x.cdn.ampproject.org/c/s/'));
        self::assertSame('x.cdn.ampproject.org', $this->resolver->host('https://x.cdn.ampproject.org/c/s'));
        // User info and port in the wrapped host go, as for any host.
        self::assertSame('scmp.com/q', (new ArticleKeyer($this->resolver))->articleKey('https://x.cdn.ampproject.org/c/s/u:p@www.scmp.com:443/q'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function hostProvider(): iterable
    {
        yield 'url' => ['https://WWW.Example.com:443/a?b#c', 'www.example.com'];
        yield 'bare' => ['m.example.com', 'm.example.com'];
        yield 'unparseable' => ['not a url', ''];
        yield 'empty' => ['', ''];
        yield 'ipv6' => ['[::1]', '::1'];
    }

    #[DataProvider('hostProvider')]
    public function testHostReturnsThePreparedHostBeforeStripping(string $input, string $expected): void
    {
        self::assertSame($expected, $this->resolver->host($input));
    }
}
