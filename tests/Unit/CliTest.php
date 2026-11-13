<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Tests\Unit;

use IndianOpinion\OutletKey\Cli;
use IndianOpinion\OutletKey\OutletMap;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function cli(array $args, string $stdin = ''): array
    {
        $in = fopen('php://memory', 'w+b');
        $out = fopen('php://memory', 'w+b');
        $err = fopen('php://memory', 'w+b');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        fwrite($in, $stdin);
        rewind($in);
        $code = (new Cli($in, $out, $err))->run($args);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testPrintsOutletAndArticlePerUrl(): void
    {
        [$code, $out] = $this->cli(['https://m.economictimes.com/x?utm_source=a', 'https://www.ndtv.com/x-1234567']);
        self::assertSame(0, $code);
        self::assertSame("economictimes.indiatimes.com\teconomictimes.indiatimes.com/x\nndtv.com\tndtv.com/a/1234567\n", $out);
    }

    public function testReadsStdin(): void
    {
        [$code, $out] = $this->cli(['-'], "amp.scmp.com/a\n\nx.com/b/\r\n");
        self::assertSame(0, $code);
        self::assertSame("scmp.com\tscmp.com/a\nx.com\tx.com/b\n", $out);
    }

    public function testExplain(): void
    {
        [$code, $out] = $this->cli(['--explain', 'auto.economictimes.indiatimes.com/a/amp']);
        self::assertSame(0, $code);
        self::assertStringContainsString('portal', $out);
        self::assertStringContainsString('map-version', $out);
    }

    public function testValidateBundledAndBadMaps(): void
    {
        [$code, $out, $err] = $this->cli(['validate']);
        self::assertSame(0, $code);
        self::assertSame('', $out);
        self::assertStringContainsString('valid, version ' . OutletMap::bundled()->version(), $err);

        $path = tempnam(sys_get_temp_dir(), 'outlet-key');
        self::assertIsString($path);
        file_put_contents($path, '{"version": "x"');
        try {
            [$code, $out, $err] = $this->cli(['validate', $path]);
            self::assertSame(1, $code);
            self::assertSame('', $out);
            self::assertStringContainsString('[json]', $err);
        } finally {
            unlink($path);
        }
    }

    public function testMapAndPslOptions(): void
    {
        $dir = sys_get_temp_dir() . '/outlet-key-cli-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $map = OutletMap::bundled()->withOverrides(['aliases' => ['example-mirror.com' => 'example.com']])->toArray();
        file_put_contents($dir . '/map.json', json_encode($map, JSON_THROW_ON_ERROR));
        try {
            [$code, $out] = $this->cli(['--map', $dir . '/map.json', 'www.example-mirror.com/a']);
            self::assertSame(0, $code);
            self::assertSame("example.com\texample.com/a\n", $out);

            [$code, $out] = $this->cli(['--psl', __DIR__ . '/../fixtures/public_suffix_list.sample.dat', 'www.city.kawasaki.jp']);
            self::assertSame(0, $code);
            self::assertSame("city.kawasaki.jp\tcity.kawasaki.jp/\n", $out);

            [$code, , $err] = $this->cli(['--map', $dir . '/missing.json', 'x.com']);
            self::assertSame(1, $code);
            self::assertStringContainsString('missing.json', $err);
        } finally {
            unlink($dir . '/map.json');
            rmdir($dir);
        }
    }

    public function testUsageErrors(): void
    {
        foreach ([[], ['--bogus'], ['--map'], ['--explain', 'a', 'b'], ['validate', 'a', 'b']] as $args) {
            [$code, $out, $err] = $this->cli($args);
            self::assertSame(2, $code, implode(' ', $args));
            self::assertSame('', $out);
            self::assertStringContainsString('Usage:', $err);
        }
    }

    public function testTooLongInputIsAnErrorButOthersStillPrint(): void
    {
        [$code, $out, $err] = $this->cli([str_repeat('a', 9000), 'x.com']);
        self::assertSame(1, $code);
        self::assertSame("x.com\tx.com/\n", $out);
        self::assertStringContainsString('8192', $err);
    }

    public function testHelpAndVersion(): void
    {
        self::assertStringContainsString('Usage:', $this->cli(['--help'])[1]);
        self::assertSame("outlet-key scheme 1\n", $this->cli(['--version'])[1]);
    }

    public function testBinaryRunsWithoutComposer(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is disabled');
        }
        $bin = dirname(__DIR__, 2) . '/bin/outlet-key';
        $process = proc_open([PHP_BINARY, $bin, 'validate'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $err);
        self::assertStringContainsString('valid', $err);
    }
}
