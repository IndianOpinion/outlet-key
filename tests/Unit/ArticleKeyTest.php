<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Tests\Unit;

use IndianOpinion\OutletKey\ArticleKeyer;
use IndianOpinion\OutletKey\OutletMap;
use IndianOpinion\OutletKey\OutletResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PRD cases 26 to 42, acceptance tests 26 to 42.
 */
final class ArticleKeyTest extends TestCase
{
    private ArticleKeyer $keyer;

    protected function setUp(): void
    {
        $this->keyer = new ArticleKeyer();
    }

    public function testCase26PlainKeyShape(): void
    {
        self::assertSame('example.com/a/b', $this->keyer->articleKey('https://www.example.com/a/b/?utm_source=x#c'));
        self::assertSame('example.com/', $this->keyer->articleKey('https://example.com'));
    }

    public function testCase27AmpCacheAndOriginGiveOneKey(): void
    {
        $origin = $this->keyer->articleKey('https://www.scmp.com/news/asia/article/3000000/a-headline?id=7');
        $cache = $this->keyer->articleKey('https://www-scmp-com.cdn.ampproject.org/c/s/www.scmp.com/news/asia/article/3000000/a-headline?id=7');
        self::assertSame($origin, $cache);
        self::assertSame('scmp.com/news/asia/article/3000000/a-headline?id=7', $cache);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function rewriteProvider(): iterable
    {
        yield 'b amp_articleshow' => ['/amp_articleshow/123.cms', '/articleshow/123.cms'];
        yield 'c trailing amp/1' => ['/story/x/amp/1', '/story/x'];
        yield 'c trailing amp/1/' => ['/story/x/amp/1/', '/story/x'];
        yield 'd leading amp' => ['/amp/story/x', '/story/x'];
        yield 'e amp segment at end' => ['/story/x/amp', '/story/x'];
        yield 'e amp segment inside' => ['/x/amp/y', '/x/y'];
        yield 'f .amp.html' => ['/story/x.amp.html', '/story/x.html'];
        yield 'f -amp.html' => ['/story/x-amp.html', '/story/x.html'];
        yield 'f .amp.htm' => ['/story/x.amp.htm', '/story/x.htm'];
        yield 'f -amp.htm' => ['/story/x-amp.htm', '/story/x.htm'];
        yield 'g trailing .amp' => ['/story/x.amp', '/story/x'];
        yield 'h index.html' => ['/x/index.html', '/x'];
        yield 'h index.htm' => ['/x/index.htm', '/x'];
        yield 'h index.php' => ['/x/index.php', '/x'];
        yield 'i repeated slashes' => ['//story//x//', '/story/x'];
        yield 'j trailing slash' => ['/story/x/', '/story/x'];
        yield 'j empty path' => ['', '/'];
        yield 'amp marker any case' => ['/AMP/story/X', '/story/X'];
    }

    #[DataProvider('rewriteProvider')]
    public function testCase28PathRewrites(string $path, string $expected): void
    {
        self::assertSame('example.com' . $expected, $this->keyer->articleKey('https://example.com' . $path));
    }

    public function testCase28EveryAmpFormKeysAsThePlainForm(): void
    {
        $plain = $this->keyer->articleKey('https://example.com/story/x');
        foreach (['/amp/story/x', '/story/x/amp', '/story/x.amp', '/story/x/', '//story//x//', '/story/x/amp/1', '/story/x/index.html'] as $form) {
            self::assertSame($plain, $this->keyer->articleKey('https://example.com' . $form), $form);
        }
    }

    public function testCase29AmpOnlyAsAWholeSegmentOrSuffix(): void
    {
        self::assertSame('example.com/campaign/x', $this->keyer->articleKey('https://example.com/campaign/x'));
        self::assertSame('example.com/amplify/x', $this->keyer->articleKey('https://example.com/amplify/x'));
        self::assertSame('example.com/x/lamp', $this->keyer->articleKey('https://example.com/x/lamp'));
        self::assertSame('example.com/x/champ.html', $this->keyer->articleKey('https://example.com/x/champ.html'));
    }

    public function testCase30DecodingAndReEncoding(): void
    {
        $encoded = $this->keyer->articleKey('https://example.com/%E0%A4%B9%E0%A4%BF%E0%A4%A8%E0%A5%8D%E0%A4%A6%E0%A5%80-x');
        $raw = $this->keyer->articleKey('https://example.com/हिन्दी-x');
        self::assertSame($raw, $encoded);
        self::assertSame('example.com/हिन्दी-x', $raw);
        // Lowercase hex decodes the same.
        self::assertSame($raw, $this->keyer->articleKey('https://example.com/%e0%a4%b9%e0%a4%bf%e0%a4%a8%e0%a5%8d%e0%a4%a6%e0%a5%80-x'));
        // Invalid bytes, spaces, ? and # come back as uppercase %XX; so does %.
        self::assertSame('example.com/a%FFb%20c%3Fd%23e%25f', $this->keyer->articleKey('https://example.com/a%ffb%20c%3fd%23e%25f'));
        self::assertSame('example.com/a%0Ab', $this->keyer->articleKey('https://example.com/a%0Ab'));
        self::assertDoesNotMatchRegularExpression('/[\x00-\x20\x7F]/', $this->keyer->articleKey("https://example.com/a\x01b c"));
    }

    public function testCase30PathCaseIsKeptByDefault(): void
    {
        self::assertSame('example.com/Story/ABC', $this->keyer->articleKey('https://example.com/Story/ABC'));
        self::assertNotSame(
            $this->keyer->articleKey('https://example.com/watch/AbC'),
            $this->keyer->articleKey('https://example.com/watch/abc')
        );
    }

    public function testCaseInsensitivePathOutletsAreLowercased(): void
    {
        $map = OutletMap::bundled()->withOverrides(['caseInsensitivePaths' => ['example.com']]);
        $keyer = new ArticleKeyer(new OutletResolver($map));
        self::assertSame('example.com/story/abc', $keyer->articleKey('https://www.example.com/Story/ABC'));
        self::assertSame(
            $keyer->articleKey('https://example.com/story/abc'),
            $keyer->articleKey('https://example.com/STORY/Abc/')
        );
        // Query values keep their case even then.
        self::assertSame('example.com/x.php?q=Abc', $keyer->articleKey('https://example.com/X.php?Q=Abc'));
        // Other outlets are untouched.
        self::assertSame('example.org/Story', $keyer->articleKey('https://example.org/Story'));
    }

    public function testCase31UnparseableUrls(): void
    {
        self::assertSame('http://', $this->keyer->articleKey('http://'));
        self::assertSame('not a url', $this->keyer->articleKey('  Not A URL '));
        $trace = $this->keyer->explain('not a url');
        self::assertStringContainsString('unparsed', $trace->find('article-key')['note'] ?? '');
        self::assertStringContainsString('unparsed', $this->keyer->explain('http://')->find('host')['note'] ?? '');
    }

    public function testCase32FragmentsAreDropped(): void
    {
        self::assertSame($this->keyer->articleKey('https://x.com/a'), $this->keyer->articleKey('https://x.com/a#x'));
        self::assertSame('x.com/a', $this->keyer->articleKey('https://x.com/a#x?id=5'));
    }

    public function testCase33QueryKeysLowercasedValuesKeepCase(): void
    {
        self::assertSame('x.com/?p=Abc', $this->keyer->articleKey('https://x.com/?P=Abc'));
    }

    public function testCase34TrackingParametersAreDropped(): void
    {
        /** @var array{trackingParams: list<string>} $query */
        $query = OutletMap::bundled()->toArray()['query'];
        foreach ($query['trackingParams'] as $param) {
            self::assertSame('x.com/x.php?id=1', $this->keyer->articleKey('https://x.com/x.php?id=1&' . $param . '=v'), $param);
        }
        self::assertSame('x.com/x.php?id=1', $this->keyer->articleKey('https://x.com/x.php?id=1&utm_anything=v&UTM_Source=w'));
        self::assertSame('x.com/x.php?id=1', $this->keyer->articleKey('https://x.com/x.php?id=1&q='));
        self::assertSame('x.com/x.php?id=1', $this->keyer->articleKey('https://x.com/x.php?id=1&flag&&=v'));
    }

    public function testCase35IdKeysOnSlugPathsEverythingOnScriptPaths(): void
    {
        self::assertSame('x.com/story/x?id=5', $this->keyer->articleKey('https://x.com/story/x?page=2&id=5'));
        self::assertSame('x.com/?p=123', $this->keyer->articleKey('https://x.com/?p=123'));
        self::assertSame('pib.gov.in/release.aspx?page=2&prid=9', $this->keyer->articleKey('https://pib.gov.in/release.aspx?PRID=9&page=2'));
        foreach (['asp', 'aspx', 'php', 'jsp', 'cfm', 'cgi', 'do'] as $ext) {
            self::assertSame('x.com/a.' . $ext . '?k=1', $this->keyer->articleKey('https://x.com/a.' . $ext . '?k=1'), $ext);
        }
        self::assertSame('x.com/a.PHP?k=1', $this->keyer->articleKey('https://x.com/a.PHP?k=1'));
        // An index script keeps its query even though the path is shortened.
        self::assertSame('x.com/news?cat=3', $this->keyer->articleKey('https://x.com/news/index.php?cat=3'));
    }

    public function testCase36ArticleIdRules(): void
    {
        $cases = [
            'ndtv.com' => ['https://www.ndtv.com/india-news/a-headline-1234567', 'https://www.ndtv.com/world-news/b-other-slug-1234567'],
            'timesofindia.indiatimes.com' => [
                'https://timesofindia.indiatimes.com/india/a/articleshow/123456789.cms',
                'https://timesofindia.indiatimes.com/city/delhi/b/articleshow/123456789',
            ],
            'thehindu.com' => [
                'https://www.thehindu.com/news/national/a/article12345678.ece',
                'https://www.thehindu.com/opinion/b/article12345678.ece',
            ],
            'thehindubusinessline.com' => [
                'https://www.thehindubusinessline.com/economy/a/article12345678.ece',
                'https://www.thehindubusinessline.com/markets/b/article12345678.ece',
            ],
            'deccanherald.com' => ['https://www.deccanherald.com/india/a-b-1234567', 'https://www.deccanherald.com/opinion/c-d-1234567'],
            'timesnownews.com' => [
                'https://www.timesnownews.com/india/a-article-123456789',
                'https://www.timesnownews.com/world/b-article-123456789',
            ],
        ];
        foreach ($cases as $outlet => [$first, $second]) {
            $key = $this->keyer->articleKey($first);
            self::assertMatchesRegularExpression('~^' . preg_quote($outlet, '~') . '/a/[0-9]{6,}$~', $key, $outlet);
            self::assertSame($key, $this->keyer->articleKey($second), $outlet);
            self::assertSame($key, $this->keyer->articleKey($second . '?utm_source=x&id=9'), $outlet);
        }
        // A path without an id gives the plain key.
        self::assertSame('ndtv.com/india-news', $this->keyer->articleKey('https://www.ndtv.com/india-news'));
        self::assertSame('ndtv.com/x-12345', $this->keyer->articleKey('https://www.ndtv.com/x-12345'));
        self::assertSame('thehindu.com/news/national', $this->keyer->articleKey('https://www.thehindu.com/news/national/'));
    }

    public function testCase37SlugOutlets(): void
    {
        $a = $this->keyer->articleKey('https://auto.economictimes.indiatimes.com/news/x/amazon-to-bet-3-billion-on-india-cloud-push/articleshow/123456.cms');
        $b = $this->keyer->articleKey('https://tech.economictimes.indiatimes.com/news/y/amazon-bets-three-billion-on-india-cloud-push/articleshow/987654.cms');
        self::assertSame($a, $b);
        self::assertSame('economictimes.indiatimes.com/s/amazon-bet-3-billion-india-cloud-push', $a);
    }

    public function testCase38SlugKey(): void
    {
        self::assertSame(
            $this->keyer->slugKey('/amazon-to-bet-3-billion-on-india-cloud-push'),
            $this->keyer->slugKey('/amazon-bets-three-billion-on-india-cloud-push')
        );
        self::assertSame('amazon-bet-3-billion-india-cloud-push', $this->keyer->slugKey('/amazon-to-bet-3-billion-on-india-cloud-push'));
        // Five meaningful tokens: too short.
        self::assertSame('', $this->keyer->slugKey('/amazon-bets-billion-india-cloud'));
        // A recurring word.
        self::assertSame('', $this->keyer->slugKey('/india-vs-australia-third-test-live-score-updates'));
        self::assertSame('', $this->keyer->slugKey('/aries-daily-horoscope-for-monday-twelfth-november-2026'));
        // "class" keeps its s; "others" loses it.
        self::assertSame('class-other-student-teacher-meet-parent', $this->keyer->slugKey('/class-others-students-teachers-meet-parents'));
        // Trailing id and marker segments are popped; extensions removed.
        self::assertSame('one-two-three-four-five-six', str_replace(
            ['1', '2', '3', '4', '5', '6'],
            ['one', 'two', 'three', 'four', 'five', 'six'],
            $this->keyer->slugKey('/news/one-two-three-four-five-six.html/articleshow/abc12345.cms/amp')
        ));
        self::assertSame('', $this->keyer->slugKey('/'));
        self::assertSame('', $this->keyer->slugKey('/123456/7654321'));
        // Non-Latin slugs give no slug key.
        self::assertSame('', $this->keyer->slugKey('/समाचार-भारत-सरकार-नई-नीति-घोषणा'));
        // A URL is accepted too.
        self::assertSame('amazon-bet-3-billion-india-cloud-push', $this->keyer->slugKey('/amazon-to-bet-3-billion-on-india-cloud-push/?x=1#y'));
    }

    public function testCase39SlugOutletWithShortSlugFallsThrough(): void
    {
        self::assertSame(
            'economictimes.indiatimes.com/markets/short-slug/articleshow/123456.cms',
            $this->keyer->articleKey('https://economictimes.indiatimes.com/markets/short-slug/articleshow/123456.cms?utm_source=x')
        );
        // A slug outlet with an id rule falls through to the id rule.
        $map = OutletMap::bundled()->withOverrides(['articleIdRules' => ['nasa.gov' => '~/([0-9]{5,})$~']]);
        $keyer = new ArticleKeyer(new OutletResolver($map));
        self::assertSame('nasa.gov/a/12345', $keyer->articleKey('https://www.nasa.gov/short/12345'));
        self::assertSame(
            'nasa.gov/s/nasa-select-new-crew-next-lunar-mission',
            $keyer->articleKey('https://www.nasa.gov/nasa-selects-new-crew-for-the-next-lunar-mission/12345')
        );
    }

    public function testCase40RepeatedKeysKeepEveryValueSorted(): void
    {
        self::assertSame('x.com/story/x?id=1&id=2', $this->keyer->articleKey('https://x.com/story/x?id=2&id=1'));
        self::assertSame('x.com/story/x?id=1&id=2', $this->keyer->articleKey('https://x.com/story/x?id=1&id=2&id=1'));
        self::assertSame('x.com/x.php?a%5B%5D=1', $this->keyer->articleKey('https://x.com/x.php?a[]=1'));
        self::assertSame('x.com/x.php?a%5B%5D=1&a%5B%5D=2', $this->keyer->articleKey('https://x.com/x.php?a%5B%5D=2&a[]=1'));
    }

    public function testCase41QueryKeysAreNotRewrittenAndAreSorted(): void
    {
        self::assertSame('x.com/x.php?a=2&b.c=1', $this->keyer->articleKey('https://x.com/x.php?b.c=1&a=2'));
        self::assertSame('x.com/x.php?a%20b=1&a_b=2', $this->keyer->articleKey('https://x.com/x.php?a_b=2&a+b=1'));
        self::assertSame('x.com/x.php?q=a%20b', $this->keyer->articleKey('https://x.com/x.php?q=a%20b'));
        self::assertSame('x.com/x.php?q=a%20b', $this->keyer->articleKey('https://x.com/x.php?q=a+b'));
        self::assertSame('x.com/x.php?q=%E0%A4%B9', $this->keyer->articleKey('https://x.com/x.php?q=ह'));
    }

    public function testCase42DifferentOutletsNeverCollide(): void
    {
        self::assertNotSame($this->keyer->articleKey('https://a.com/story/x'), $this->keyer->articleKey('https://b.com/story/x'));
        self::assertNotSame($this->keyer->articleKey('https://a.com/b.com/x'), $this->keyer->articleKey('https://a.com.b.com/x'));
    }

    public function testSameArticle(): void
    {
        self::assertTrue($this->keyer->sameArticle('x.com/a', 'x.com/a'));
        self::assertFalse($this->keyer->sameArticle('', ''));
    }
}
