<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Tests\Unit;

use IndianOpinion\OutletKey\Internal\Punycode;
use IndianOpinion\OutletKey\Internal\Utf8;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The built-in RFC 3492 encoder, against the RFC's own sample strings.
 */
final class PunycodeTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function rfcSamples(): iterable
    {
        // RFC 3492 section 7.1.
        yield 'A Arabic' => [
            "\u{0644}\u{064A}\u{0647}\u{0645}\u{0627}\u{0628}\u{062A}\u{0643}\u{0644}\u{0645}\u{0648}\u{0634}\u{0639}\u{0631}\u{0628}\u{064A}\u{061F}",
            'egbpdaj6bu4bxfgehfvwxn',
        ];
        yield 'B Chinese simplified' => [
            "\u{4ED6}\u{4EEC}\u{4E3A}\u{4EC0}\u{4E48}\u{4E0D}\u{8BF4}\u{4E2D}\u{6587}",
            'ihqwcrb4cv8a8dqg056pqjye',
        ];
        yield 'I Russian' => [
            'почемужеонинеговорятпорусски',
            'b1abfaaepdrnnbgefbadotcwatmq2g4l',
        ];
        yield 'J Spanish' => [
            'PorquénopuedensimplementehablarenEspañol',
            'PorqunopuedensimplementehablarenEspaol-fmd56a',
        ];
        yield 'L Japanese mixed' => [
            "3\u{5E74}B\u{7D44}\u{91D1}\u{516B}\u{5148}\u{751F}",
            '3B-ww4c5e180e575a65lsy2b',
        ];
        yield 'German' => ['bücher', 'bcher-kva'];
        yield 'Devanagari' => ['भारत', 'h2brj9c'];
        yield 'ASCII only' => ['abc', 'abc-'];
    }

    #[DataProvider('rfcSamples')]
    public function testEncodesRfcSamples(string $unicode, string $expected): void
    {
        self::assertSame($expected, Punycode::encode(Utf8::codePoints($unicode)));
    }

    public function testCodePointsDecodesAllSequenceLengths(): void
    {
        self::assertSame([0x41, 0xE9, 0x939, 0x1F600], Utf8::codePoints("A\u{E9}\u{939}\u{1F600}"));
    }
}
