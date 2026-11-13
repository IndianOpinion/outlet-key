<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Tests\Unit;

use RuntimeException;

/**
 * Reads tests/fixtures/*.tsv: input<TAB>expected, "#" comments, blank lines skipped.
 */
final class Fixtures
{
    /**
     * @return list<array{0: string, 1: string, 2: int}> [input, expected, line number]
     */
    public static function read(string $name): array
    {
        $path = dirname(__DIR__) . '/fixtures/' . $name;
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Cannot read fixture ' . $path);
        }
        $out = [];
        foreach ($lines as $i => $line) {
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) !== 2) {
                throw new RuntimeException(sprintf('%s:%d must be input<TAB>expected', $name, $i + 1));
            }
            $out[] = [$parts[0], $parts[1], $i + 1];
        }

        return $out;
    }
}
