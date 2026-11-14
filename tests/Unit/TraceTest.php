<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Tests\Unit;

use IndianOpinion\OutletKey\ArticleKeyer;
use IndianOpinion\OutletKey\OutletMap;
use IndianOpinion\OutletKey\OutletResolver;
use IndianOpinion\OutletKey\Trace;
use PHPUnit\Framework\TestCase;

/**
 * PRD case 48, acceptance test 48.
 */
final class TraceTest extends TestCase
{
    public function testCase48ExplainListsStepsInOrderAndTheMapVersion(): void
    {
        $keyer = new ArticleKeyer();
        $trace = $keyer->explain('auto.economictimes.indiatimes.com/a/amp');
        $names = $trace->stepNames();

        $mirror = array_search('mirror-strip', $names, true);
        $portal = array_search('portal', $names, true);
        $amp = array_search('path-rewrite', $names, true);
        self::assertIsInt($mirror);
        self::assertIsInt($portal);
        self::assertIsInt($amp);
        self::assertLessThan($portal, $mirror);
        self::assertLessThan($amp, $portal);
        self::assertStringContainsString('amp', $trace->toArray()[$amp]['note']);
        self::assertSame(OutletMap::bundled()->version(), $trace->mapVersion());
        self::assertSame(OutletMap::bundled()->version(), $trace->result());
        self::assertSame('economictimes.indiatimes.com/a', $trace->find('article-key')['value'] ?? null);
    }

    public function testOutletTraceNamesEveryDecision(): void
    {
        $trace = (new OutletResolver())->explain('https://www-x-com.cdn.ampproject.org/c/s/www.economictimes.com/a');
        self::assertSame(
            ['input', 'amp-unwrap', 'host', 'mirror-strip', 'pass-through', 'registrable-domain', 'portal', 'alias', 'outlet-key', 'map-version'],
            $trace->stepNames()
        );
        self::assertSame('unwrapped from www-x-com.cdn.ampproject.org', $trace->find('amp-unwrap')['note'] ?? null);
        self::assertSame('economictimes.indiatimes.com', $trace->find('outlet-key')['value'] ?? null);
    }

    public function testArticleTraceShowsQueryDecisionsAndRules(): void
    {
        $keyer = new ArticleKeyer();
        $trace = $keyer->explain('https://www.ndtv.com/india-news/x-1234567?utm_source=a');
        self::assertSame('1234567', $trace->find('id-rule')['value'] ?? null);

        $trace = $keyer->explain('https://x.com/story/x?utm_source=a&id=5&page=2');
        $queries = array_values(array_filter($trace->toArray(), static fn (array $s): bool => $s['step'] === 'query'));
        self::assertSame(['utm_source', 'id', 'page'], array_column($queries, 'value'));
        self::assertSame(['dropped: tracking', 'kept', 'dropped: not an id parameter on a slug path'], array_column($queries, 'note'));
    }

    public function testTraceIsReadOnlyAndPrintable(): void
    {
        $trace = new Trace([['a', 'x', 'note'], ['bb', '', '']]);
        self::assertCount(2, $trace);
        self::assertSame("a   x  (note)\nbb  ''\n", (string) $trace);
        self::assertSame([['step' => 'a', 'value' => 'x', 'note' => 'note'], ['step' => 'bb', 'value' => '', 'note' => '']], iterator_to_array($trace));
        self::assertNull($trace->find('missing'));
        self::assertSame('', (new Trace([]))->result());
        self::assertSame('', (new Trace([]))->mapVersion());
    }
}
