<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Tests\Data;

use IndianOpinion\OutletKey\InvalidMapException;
use IndianOpinion\OutletKey\OutletMap;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The data file and its JSON Schema, checked by an independent JSON Schema
 * validator (justinrainbow/json-schema, a dev dependency only), and checked
 * against the library's own validator so the two never drift apart.
 */
final class MapSchemaTest extends TestCase
{
    private const DATA = __DIR__ . '/../../data/outlets.json';
    private const SCHEMA = __DIR__ . '/../../data/outlets.schema.json';
    private const META = __DIR__ . '/../../vendor/justinrainbow/json-schema/dist/schema/json-schema-draft-07.json';

    private static function decode(string $path): mixed
    {
        $json = file_get_contents($path);
        self::assertIsString($json, $path);

        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string> error messages, empty when valid
     */
    private static function schemaErrors(mixed $data, mixed $schema): array
    {
        $validator = new Validator();
        $validator->validate($data, $schema, Constraint::CHECK_MODE_NORMAL);
        $errors = [];
        foreach ($validator->getErrors() as $error) {
            $errors[] = sprintf('%s: %s', $error['property'] ?? '', $error['message'] ?? '');
        }

        return $errors;
    }

    public function testSchemaIsAValidDraft07Schema(): void
    {
        $schema = self::decode(self::SCHEMA);
        self::assertIsObject($schema);
        self::assertSame('http://json-schema.org/draft-07/schema#', get_object_vars($schema)['$schema'] ?? null);
        self::assertSame([], self::schemaErrors($schema, self::decode(self::META)));
    }

    public function testBundledMapMatchesTheSchema(): void
    {
        self::assertSame([], self::schemaErrors(self::decode(self::DATA), self::decode(self::SCHEMA)));
    }

    public function testBundledMapPassesTheLibraryValidator(): void
    {
        $map = OutletMap::fromFile(self::DATA);
        self::assertNotSame('', $map->version());
    }

    public function testBundledMapIsInCanonicalFormat(): void
    {
        $json = file_get_contents(self::DATA);
        self::assertIsString($json);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $canonical = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        // PHP pretty-prints with four spaces; the file uses two.
        $canonical = (string) preg_replace_callback('/^( +)/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $canonical);
        self::assertSame($canonical . "\n", $json, 'data/outlets.json must be two-space indented, one entry per line, ending in a newline');
        self::assertStringNotContainsString("\r", $json);
    }

    /**
     * Documents that both validators reject, each for its own reason.
     *
     * @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function invalidDocuments(): iterable
    {
        yield 'missing version' => [static function (array $m): array {
            unset($m['version']);
            return $m;
        }];
        yield 'bad version' => [static function (array $m): array {
            $m['version'] = '12 Nov 2026';
            return $m;
        }];
        yield 'unknown top-level field' => [static function (array $m): array {
            $m['owners'] = [];
            return $m;
        }];
        yield 'uppercase host' => [static function (array $m): array {
            $m['aggregators'] = ['MSN.com'];
            return $m;
        }];
        yield 'host with path' => [static function (array $m): array {
            $m['passThrough'] = ['news.google.com/rss'];
            return $m;
        }];
        yield 'duplicate list entry' => [static function (array $m): array {
            $m['slugOutlets'] = ['nasa.gov', 'nasa.gov'];
            return $m;
        }];
        yield 'single-label suffix' => [static function (array $m): array {
            $m['suffixes'] = ['uk'];
            return $m;
        }];
        yield 'minTokens too large' => [static function (array $m): array {
            $m['slug']['minTokens'] = 21;
            return $m;
        }];
        yield 'number word not digits' => [static function (array $m): array {
            $m['slug']['numberWords'] = ['one' => 'uno'];
            return $m;
        }];
        yield 'unknown query field' => [static function (array $m): array {
            $m['query']['keepAll'] = true;
            return $m;
        }];
        yield 'uppercase tracking param' => [static function (array $m): array {
            $m['query']['trackingParams'] = ['FBCLID'];
            return $m;
        }];
        yield 'note with unknown field' => [static function (array $m): array {
            $m['notes'] = ['ndtv.com' => ['owner' => 'x']];
            return $m;
        }];
        yield 'note example not a path' => [static function (array $m): array {
            $m['notes'] = ['ndtv.com' => ['examples' => ['x-1234567']]];
            return $m;
        }];
        yield 'alias target with scheme' => [static function (array $m): array {
            $m['aliases'] = ['ndtv.in' => 'https://ndtv.com'];
            return $m;
        }];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutate
     */
    #[DataProvider('invalidDocuments')]
    public function testSchemaAndLibraryValidatorBothReject(callable $mutate): void
    {
        $json = file_get_contents(self::DATA);
        self::assertIsString($json);
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $mutated = $mutate($data);
        $encoded = json_encode($mutated, JSON_THROW_ON_ERROR);

        self::assertNotSame([], self::schemaErrors(json_decode($encoded, false, 512, JSON_THROW_ON_ERROR), self::decode(self::SCHEMA)), 'schema accepted it');

        $this->expectException(InvalidMapException::class);
        OutletMap::fromJson($encoded, 'mutated');
    }
}
