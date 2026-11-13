<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey;

use IndianOpinion\OutletKey\Internal\MapData;
use IndianOpinion\OutletKey\Internal\MapValidator;
use JsonException;

/**
 * An immutable, validated outlet map (data/outlets.json or a caller's own).
 *
 * Every constructor validates. A map that fails any rule raises
 * InvalidMapException: keys from a half-loaded map would be silently wrong.
 */
final class OutletMap
{
    private const LIST_SECTIONS = ['slug' => MapValidator::SLUG_LISTS, 'query' => MapValidator::QUERY_LISTS];

    private static ?self $bundled = null;
    private static int $bundledReads = 0;

    /** @var array<string, mixed> */
    private array $data;
    private MapData $compiled;

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(array $data, MapData $compiled)
    {
        $this->data = $data;
        $this->compiled = $compiled;
    }

    /**
     * The map shipped with this release, loaded once per process.
     */
    public static function bundled(): self
    {
        if (self::$bundled === null) {
            self::$bundled = self::fromFile(self::bundledPath());
            self::$bundledReads++;
        }

        return self::$bundled;
    }

    /** Absolute path of the bundled data file. */
    public static function bundledPath(): string
    {
        return dirname(__DIR__) . '/data/outlets.json';
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidMapException($path, '', 'file', 'not a readable file');
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new InvalidMapException($path, '', 'file', 'could not be read');
        }

        return self::fromJson($json, $path);
    }

    /**
     * @param string $source a label for error messages, usually the file path
     */
    public static function fromJson(string $json, string $source = 'json'): self
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidMapException($source, '', 'json', $e->getMessage());
        }

        return self::build($data, $source);
    }

    /**
     * @param array<string, mixed> $data the map in the shape of data/outlets.json
     */
    public static function fromArray(array $data): self
    {
        return self::build($data, 'array');
    }

    /**
     * A new map with the caller's changes merged in, then validated again.
     *
     * Lists are unioned and sorted. Tables (aliases, articleIdRules,
     * slug.numberWords, notes) are merged with the caller's entries winning.
     * Scalars (version, slug.minTokens) are replaced. A "remove" section
     * deletes list values or table keys, in the same shape:
     *
     *     ['aliases' => ['a.example' => 'b.example'],
     *      'remove'  => ['portalBases' => ['medium.com']]]
     *
     * @param array<string, mixed> $overrides
     */
    public function withOverrides(array $overrides): self
    {
        $data = $this->data;
        $remove = $overrides['remove'] ?? [];
        unset($overrides['remove']);
        if (!is_array($remove)) {
            throw new InvalidMapException('overrides', '/remove', 'type', 'remove must be an object');
        }

        $data = self::merge($data, $overrides, '');
        $data = self::subtract($data, $remove, '');

        return self::build(self::sortForValidation($data), 'overrides');
    }

    /** The map's data version, for example "2026.11.12". */
    public function version(): string
    {
        return $this->compiled->version;
    }

    /**
     * The validated, normalised content in the shape of data/outlets.json.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @internal
     */
    public function compiled(): MapData
    {
        return $this->compiled;
    }

    /**
     * How many times the bundled file has been read in this process.
     *
     * @internal for tests of the per-process cache
     */
    public static function bundledReadCount(): int
    {
        return self::$bundledReads;
    }

    /**
     * Forget the cached bundled map.
     *
     * @internal for tests of the per-process cache
     */
    public static function resetBundled(): void
    {
        self::$bundled = null;
        self::$bundledReads = 0;
    }

    private static function build(mixed $data, string $source): self
    {
        $compiled = MapValidator::validate($data, $source);
        /** @var array<string, mixed> $data */
        unset($data['$schema']);
        if (!array_key_exists('notes', $data)) {
            $data['notes'] = [];
        }

        return new self($data, $compiled);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<array-key, mixed> $add
     *
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $add, string $path): array
    {
        foreach ($add as $key => $value) {
            $key = (string) $key;
            $current = $base[$key] ?? null;
            if (is_array($value) && is_array($current) && self::isList($current, $path . '/' . $key)) {
                if (!array_is_list($value)) {
                    throw new InvalidMapException('overrides', $path . '/' . MapValidator::escape($key), 'type', 'must be a list');
                }
                foreach ($value as $item) {
                    if (!in_array($item, $current, true)) {
                        $current[] = $item;
                    }
                }
                $base[$key] = $current;
            } elseif (is_array($value) && is_array($current)) {
                /** @var array<string, mixed> $current */
                $base[$key] = self::merge($current, $value, $path . '/' . $key);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<array-key, mixed> $remove
     *
     * @return array<string, mixed>
     */
    private static function subtract(array $base, array $remove, string $path): array
    {
        foreach ($remove as $key => $value) {
            $key = (string) $key;
            $pointer = $path . '/' . MapValidator::escape($key);
            $current = $base[$key] ?? null;
            if (!is_array($current) || !is_array($value)) {
                throw new InvalidMapException(
                    'overrides',
                    '/remove' . $pointer,
                    'type',
                    'remove names a list or table and gives a list of entries'
                );
            }
            if (self::isList($current, $path . '/' . $key)) {
                $base[$key] = array_values(array_filter(
                    $current,
                    static fn (mixed $v): bool => !in_array($v, $value, true)
                ));
            } elseif (array_is_list($value)) {
                foreach ($value as $tableKey) {
                    unset($current[(string) $tableKey]);
                }
                $base[$key] = $current;
            } else {
                /** @var array<string, mixed> $current */
                $base[$key] = self::subtract($current, $value, $path . '/' . $key);
            }
        }

        return $base;
    }

    private static function isList(mixed $value, string $path): bool
    {
        $segments = explode('/', ltrim($path, '/'));
        if (count($segments) === 1) {
            return in_array($segments[0], MapValidator::LIST_FIELDS, true);
        }
        if (count($segments) === 2 && isset(self::LIST_SECTIONS[$segments[0]])) {
            return in_array($segments[1], self::LIST_SECTIONS[$segments[0]], true);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function sortForValidation(array $data): array
    {
        foreach (MapValidator::LIST_FIELDS as $f) {
            if (isset($data[$f]) && is_array($data[$f])) {
                $data[$f] = self::sortedList($data[$f]);
            }
        }
        foreach (array_merge(MapValidator::TABLE_FIELDS, ['notes']) as $f) {
            if (isset($data[$f]) && is_array($data[$f])) {
                ksort($data[$f], SORT_STRING);
            }
        }
        foreach (self::LIST_SECTIONS as $section => $lists) {
            if (!isset($data[$section]) || !is_array($data[$section])) {
                continue;
            }
            foreach ($lists as $f) {
                if (isset($data[$section][$f]) && is_array($data[$section][$f])) {
                    $data[$section][$f] = self::sortedList($data[$section][$f]);
                }
            }
            if ($section === 'slug' && isset($data['slug']['numberWords']) && is_array($data['slug']['numberWords'])) {
                ksort($data['slug']['numberWords'], SORT_STRING);
            }
        }

        return $data;
    }

    /**
     * @param array<array-key, mixed> $list
     *
     * @return list<mixed>
     */
    private static function sortedList(array $list): array
    {
        $list = array_values($list);
        usort($list, static fn (mixed $a, mixed $b): int => strcmp(
            is_scalar($a) ? (string) $a : '',
            is_scalar($b) ? (string) $b : ''
        ));

        return $list;
    }
}
