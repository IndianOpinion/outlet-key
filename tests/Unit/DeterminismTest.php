<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Acceptance test 50: identical fixture output with ext-intl and
 * ext-mbstring disabled.
 */
final class DeterminismTest extends TestCase
{
    public function testFixtureOutputIsTheSameWithoutOptionalExtensions(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is disabled');
        }
        $script = dirname(__DIR__) . '/determinism-run.php';

        $bare = $this->exec([PHP_BINARY, '-n', $script]);
        if (!str_ends_with($bare, "extensions:\n")) {
            self::markTestSkipped('intl or mbstring is compiled into this PHP; cannot disable them with -n');
        }
        $full = $this->exec([PHP_BINARY, $script]);

        $strip = static fn (string $out): string => (string) preg_replace('/^extensions:.*\n/m', '', $out);
        self::assertNotSame('', $strip($bare));
        self::assertSame($strip($full), $strip($bare));
    }

    /**
     * @param list<string> $command
     */
    private function exec(array $command): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $err);

        return $out;
    }
}
