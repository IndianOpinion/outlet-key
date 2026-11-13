<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Tests\Unit;

use IndianOpinion\OutletKey\ArticleKeyer;
use IndianOpinion\OutletKey\OutletResolver;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance test 49: every fixture line passes.
 */
final class FixturesTest extends TestCase
{
    public function testEveryOutletFixtureLine(): void
    {
        $resolver = new OutletResolver();
        $lines = Fixtures::read('outlets.tsv');
        self::assertNotEmpty($lines);
        foreach ($lines as [$input, $expected, $line]) {
            self::assertSame($expected, $resolver->outletKey($input), sprintf('outlets.tsv:%d %s', $line, $input));
        }
    }

    public function testEveryArticleFixtureLine(): void
    {
        $keyer = new ArticleKeyer();
        $lines = Fixtures::read('articles.tsv');
        self::assertNotEmpty($lines);
        foreach ($lines as [$input, $expected, $line]) {
            self::assertSame($expected, $keyer->articleKey($input), sprintf('articles.tsv:%d %s', $line, $input));
        }
    }

    public function testFixtureFilesAreWellFormed(): void
    {
        foreach (['outlets.tsv', 'articles.tsv'] as $name) {
            $seen = [];
            foreach (Fixtures::read($name) as [$input, , $line]) {
                self::assertArrayNotHasKey($input, $seen, sprintf('%s:%d repeats line %d', $name, $line, $seen[$input] ?? 0));
                $seen[$input] = $line;
            }
        }
    }
}
