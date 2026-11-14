<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Tests\Unit;

use IndianOpinion\OutletKey\OutletMap;
use IndianOpinion\OutletKey\OutletResolver;
use PHPUnit\Framework\TestCase;

/**
 * PRD cases 14 to 25, acceptance tests 14 to 25.
 */
final class OutletKeyRulesTest extends TestCase
{
    private OutletResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new OutletResolver();
    }

    public function testCase14MirrorLabelsAreStrippedFromTheLeft(): void
    {
        self::assertSame('example.com', $this->resolver->outletKey('www.m.example.com'));
        /** @var list<string> $labels */
        $labels = OutletMap::bundled()->toArray()['mirrorLabels'];
        self::assertContains('www', $labels);
        foreach (['www', 'www1', 'www2', 'www3', 'ww', 'm', 'mobile', 'amp', 'wap', 'mob', 'touch', 'lite'] as $label) {
            self::assertContains($label, $labels);
            self::assertSame('example.com', $this->resolver->outletKey($label . '.example.com'), $label);
        }
    }

    public function testCase15InnerMirrorLabelsAreKeptAndTwoLabelsIsTheFloor(): void
    {
        self::assertSame('example.com', $this->resolver->outletKey('news.m.example.com'));
        $trace = $this->resolver->explain('news.m.example.com');
        $mirror = $trace->find('mirror-strip');
        self::assertNotNull($mirror);
        self::assertSame('none', $mirror['note']);
        self::assertSame('news.m.example.com', $mirror['value']);
        self::assertSame('last two labels', $trace->find('registrable-domain')['note'] ?? null);

        self::assertSame('m.com', $this->resolver->outletKey('m.com'));
        self::assertSame('www.com', $this->resolver->outletKey('www.www.com'));
    }

    public function testCase16PassThroughHostsAreKeptWhole(): void
    {
        self::assertSame('news.google.com', $this->resolver->outletKey('news.google.com/rss/articles/x'));
        self::assertSame('news.google.com', $this->resolver->outletKey('https://www.news.google.com/a'));
        self::assertSame('google.com', $this->resolver->outletKey('https://www.google.com/a'));
    }

    public function testCase17RegistrableDomain(): void
    {
        self::assertSame('pib.gov.in', $this->resolver->outletKey('pib.gov.in'));
        self::assertSame('bbc.com', $this->resolver->outletKey('www.bbc.co.uk'));
        self::assertSame('example.co.in', $this->resolver->outletKey('a.b.example.co.in'));
        self::assertSame('bbc.co.uk', $this->resolver->registrableDomain('news.bbc.co.uk'));
        self::assertSame('example.com', $this->resolver->registrableDomain('a.b.example.com'));
        self::assertSame('legifrance.gouv.fr', $this->resolver->registrableDomain('www.legifrance.gouv.fr'));
        // 17b needs a two-letter top-level label.
        self::assertSame('com.example', $this->resolver->registrableDomain('a.com.example'));
        self::assertSame('', $this->resolver->registrableDomain('not a host'));
        self::assertSame('10.0.0.1', $this->resolver->registrableDomain('10.0.0.1'));

        // 17a: a suffix added through overrides changes the result.
        self::assertSame('example.org', $this->resolver->outletKey('city.example.org'));
        $map = OutletMap::bundled()->withOverrides(['suffixes' => ['example.org']]);
        $resolver = new OutletResolver($map);
        self::assertSame('city.example.org', $resolver->outletKey('www.city.example.org'));
        self::assertSame('city.example.org', $resolver->registrableDomain('news.city.example.org'));
        self::assertSame('map suffix', $resolver->explain('news.city.example.org')->find('registrable-domain')['note'] ?? null);
    }

    public function testCase18UnmappedHostsFallBackToTheRegistrableDomain(): void
    {
        self::assertSame('news18.com', $this->resolver->outletKey('hindi.news18.com'));
        self::assertSame('news18.com', $this->resolver->outletKey('kolkata.news18.com'));
    }

    public function testCase19PortalBasesKeepSiblingTitlesApart(): void
    {
        $toi = $this->resolver->outletKey('timesofindia.indiatimes.com');
        $et = $this->resolver->outletKey('economictimes.indiatimes.com');
        self::assertSame('timesofindia.indiatimes.com', $toi);
        self::assertSame('economictimes.indiatimes.com', $et);
        self::assertNotSame($toi, $et);
        self::assertSame('economictimes.indiatimes.com', $this->resolver->outletKey('auto.economictimes.indiatimes.com'));
        self::assertSame('someone.blogspot.com', $this->resolver->outletKey('www.someone.blogspot.com'));
    }

    public function testCase20LanguageLabelsUnderAPortalAreThePortal(): void
    {
        self::assertSame('indiatimes.com', $this->resolver->outletKey('hindi.indiatimes.com'));
        self::assertSame('india.com', $this->resolver->outletKey('bengali.india.com'));
    }

    public function testCase21BarePortalHosts(): void
    {
        self::assertSame('indiatimes.com', $this->resolver->outletKey('indiatimes.com'));
        self::assertSame('indiatimes.com', $this->resolver->outletKey('www.indiatimes.com'));
    }

    public function testCase22AliasesAreOneHop(): void
    {
        self::assertSame('economictimes.indiatimes.com', $this->resolver->outletKey('economictimes.com'));
        self::assertSame('ndtv.com', $this->resolver->outletKey('ndtv.in'));
        self::assertSame('bbc.com', $this->resolver->outletKey('bbc.co.uk'));
        $trace = $this->resolver->explain('www.ndtv.in');
        self::assertSame('alias of ndtv.in', $trace->find('alias')['note'] ?? null);
    }

    public function testCase23BareHostAndUrlAgree(): void
    {
        self::assertSame(
            $this->resolver->outletKey('m.economictimes.com'),
            $this->resolver->outletKey('https://m.economictimes.com/news/x')
        );
        foreach (Fixtures::read('outlets.tsv') as [$input, $expected]) {
            self::assertSame($expected, $this->resolver->outletKey($input), $input);
            self::assertSame($expected, $this->resolver->outletKey('https://' . $input . '/news/x?y=1#z'), $input);
        }
    }

    public function testCase24Aggregators(): void
    {
        self::assertTrue($this->resolver->isAggregator('msn.com'));
        self::assertSame('msn.com', $this->resolver->outletKey('www.msn.com/a'));
        self::assertTrue($this->resolver->isAggregator($this->resolver->outletKey('news.google.com/rss/articles/x')));
        self::assertFalse($this->resolver->isAggregator('ndtv.com'));
        self::assertFalse($this->resolver->isAggregator(''));
    }

    public function testCase25OwnOutlet(): void
    {
        $resolver = new OutletResolver(null, ['example.org']);
        self::assertTrue($resolver->isOwnOutlet($resolver->outletKey('m.example.org')));
        self::assertTrue($resolver->isOwnOutlet($resolver->outletKey('https://www.example.org/a')));
        self::assertFalse($resolver->isOwnOutlet($resolver->outletKey('example.com')));
        self::assertFalse($resolver->isOwnOutlet(''));

        $byUrl = new OutletResolver(null, ['https://www.example.org/about', 'not a host']);
        self::assertTrue($byUrl->isOwnOutlet('example.org'));
        self::assertFalse($byUrl->isOwnOutlet(''));
    }

    public function testSameOutletNeverMatchesTwoUnknowns(): void
    {
        self::assertTrue($this->resolver->sameOutlet('ndtv.com', 'ndtv.com'));
        self::assertFalse($this->resolver->sameOutlet('', ''));
        self::assertFalse($this->resolver->sameOutlet('ndtv.com', 'bbc.com'));
    }
}
