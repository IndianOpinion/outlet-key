<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Internal;

use IndianOpinion\OutletKey\InvalidMapException;

/**
 * Checks a decoded map against the schema (data/outlets.schema.json) and
 * the validator rules of PRD section 6.2. Every failure names the source,
 * a JSON pointer and a rule.
 *
 * @internal
 */
final class MapValidator
{
    public const LIST_FIELDS = [
        'mirrorLabels', 'languageLabels', 'secondLevelLabels', 'suffixes', 'portalBases',
        'passThrough', 'aggregators', 'slugOutlets', 'caseInsensitivePaths',
    ];
    public const TABLE_FIELDS = ['aliases', 'articleIdRules'];
    public const SLUG_LISTS = ['stopWords', 'recurringWords'];
    public const QUERY_LISTS = ['idParams', 'trackingParams', 'trackingPrefixes', 'scriptExtensions'];

    private const LABEL = '~^[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?$~D';
    private const WORD = '~^[a-z0-9]+$~D';
    private const PARAM = '~^[a-z0-9_.\[\]-]+$~D';
    private const VERSION = '~^[0-9]{4}\.[0-9]{2}\.[0-9]{2}(?:\.[0-9]+)?$~D';
    private const ALLOWED_MODIFIERS = 'imsxuDU';

    private string $source;

    private function __construct(string $source)
    {
        $this->source = $source;
    }

    /**
     * Validate structure and rules. Returns the map as hash sets.
     *
     * @param mixed $data the decoded JSON (associative arrays)
     */
    public static function validate(mixed $data, string $source): MapData
    {
        $v = new self($source);
        $v->structure($data);
        /** @var array<string, mixed> $data */
        $map = MapData::fromValidatedArray($data);
        $v->rules($data, $map);

        return $map;
    }

    /**
     * @param mixed $data
     */
    private function structure(mixed $data): void
    {
        if (!self::isObject($data)) {
            $this->fail('', 'type', 'the map must be a JSON object');
        }
        /** @var array<string, mixed> $data */
        $allowed = array_merge(
            ['$schema', 'version', 'slug', 'query', 'notes'],
            self::LIST_FIELDS,
            self::TABLE_FIELDS
        );
        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowed, true)) {
                $this->fail('/' . self::escape((string) $key), 'unknown-field', 'field is not part of the schema');
            }
        }
        foreach (array_merge(['version', 'slug', 'query'], self::LIST_FIELDS, self::TABLE_FIELDS) as $key) {
            if (!array_key_exists($key, $data)) {
                $this->fail('/' . $key, 'required', 'required field is missing');
            }
        }
        if (isset($data['$schema']) && !is_string($data['$schema'])) {
            $this->fail('/$schema', 'type', 'must be a string');
        }

        if (!is_string($data['version']) || preg_match(self::VERSION, $data['version']) !== 1) {
            $this->fail('/version', 'version-format', 'must be a string of the form YYYY.MM.DD or YYYY.MM.DD.n');
        }

        $this->sortedList($data, 'mirrorLabels', '', self::LABEL, 'label-format');
        $this->sortedList($data, 'languageLabels', '', self::LABEL, 'label-format');
        $this->sortedList($data, 'secondLevelLabels', '', self::LABEL, 'label-format');
        $this->sortedHostList($data, 'suffixes', '', 2);
        $this->sortedHostList($data, 'portalBases', '', 2);
        $this->sortedHostList($data, 'passThrough', '', 1);
        $this->sortedHostList($data, 'aggregators', '', 1);
        $this->sortedHostList($data, 'slugOutlets', '', 1);
        $this->sortedHostList($data, 'caseInsensitivePaths', '', 1);

        // aliases: host to host
        $aliases = $this->sortedTable($data, 'aliases', '');
        foreach ($aliases as $from => $to) {
            $p = '/aliases/' . self::escape($from);
            $this->host($from, $p, 1);
            if (!is_string($to)) {
                $this->fail($p, 'type', 'alias target must be a string');
            }
            $this->host($to, $p, 1);
        }

        // articleIdRules: host to pattern
        $rules = $this->sortedTable($data, 'articleIdRules', '');
        foreach ($rules as $outlet => $pattern) {
            $p = '/articleIdRules/' . self::escape($outlet);
            $this->host($outlet, $p, 1);
            if (!is_string($pattern) || $pattern === '') {
                $this->fail($p, 'type', 'pattern must be a non-empty string');
            }
        }

        // slug
        if (!self::isObject($data['slug'])) {
            $this->fail('/slug', 'type', 'must be an object');
        }
        /** @var array<string, mixed> $slug */
        $slug = $data['slug'];
        $this->exactKeys($slug, '/slug', ['minTokens', 'numberWords', 'recurringWords', 'stopWords']);
        $this->sortedList($slug, 'stopWords', '/slug', self::WORD, 'word-format');
        $this->sortedList($slug, 'recurringWords', '/slug', self::WORD, 'word-format');
        $numbers = $this->sortedTable($slug, 'numberWords', '/slug');
        foreach ($numbers as $word => $digits) {
            $p = '/slug/numberWords/' . self::escape($word);
            if (preg_match(self::WORD, $word) !== 1) {
                $this->fail($p, 'word-format', 'must be lowercase ASCII letters and digits');
            }
            if (!is_string($digits) || preg_match('~^[0-9]+$~D', $digits) !== 1) {
                $this->fail($p, 'type', 'value must be a string of digits');
            }
        }
        if (!is_int($slug['minTokens']) || $slug['minTokens'] < 2 || $slug['minTokens'] > 20) {
            $this->fail('/slug/minTokens', 'min-tokens', 'must be an integer from 2 to 20');
        }

        // query
        if (!self::isObject($data['query'])) {
            $this->fail('/query', 'type', 'must be an object');
        }
        /** @var array<string, mixed> $query */
        $query = $data['query'];
        $this->exactKeys($query, '/query', self::QUERY_LISTS);
        $this->sortedList($query, 'idParams', '/query', self::PARAM, 'param-format');
        $this->sortedList($query, 'trackingParams', '/query', self::PARAM, 'param-format');
        $this->sortedList($query, 'trackingPrefixes', '/query', self::PARAM, 'param-format');
        $this->sortedList($query, 'scriptExtensions', '/query', self::WORD, 'word-format');

        // notes
        if (array_key_exists('notes', $data)) {
            $notes = $this->sortedTable($data, 'notes', '');
            foreach ($notes as $key => $note) {
                $p = '/notes/' . self::escape($key);
                if (is_string($note)) {
                    continue;
                }
                if (!self::isObject($note)) {
                    $this->fail($p, 'type', 'a note is a string or an object');
                }
                /** @var array<string, mixed> $note */
                foreach ($note as $field => $value) {
                    $fp = $p . '/' . self::escape((string) $field);
                    if ($field === 'examples') {
                        if (!is_array($value) || !array_is_list($value) || $value === []) {
                            $this->fail($fp, 'type', 'examples must be a non-empty list of paths');
                        }
                        foreach ($value as $i => $example) {
                            if (!is_string($example) || strncmp($example, '/', 1) !== 0) {
                                $this->fail($fp . '/' . $i, 'type', 'an example is a path starting with "/"');
                            }
                        }
                    } elseif (in_array($field, ['text', 'evidence'], true)) {
                        if (!is_string($value) || $value === '') {
                            $this->fail($fp, 'type', 'must be a non-empty string');
                        }
                    } elseif ($field === 'added') {
                        if (!is_string($value) || preg_match('~^[0-9]{4}-[0-9]{2}-[0-9]{2}$~D', $value) !== 1) {
                            $this->fail($fp, 'type', 'must be a date YYYY-MM-DD');
                        }
                    } else {
                        $this->fail($fp, 'unknown-field', 'a note object has text, evidence, added and examples');
                    }
                }
            }
        }
    }

    /**
     * Semantic rules of PRD 6.2, run on structurally valid data.
     *
     * @param array<string, mixed> $data
     */
    private function rules(array $data, MapData $map): void
    {
        foreach (array_keys($map->mirrorLabels) as $label) {
            if (isset($map->languageLabels[$label])) {
                $this->fail(
                    '/mirrorLabels',
                    'mirror-language-overlap',
                    sprintf('"%s" is both a mirror label and a language label', $label)
                );
            }
        }

        foreach ($map->aliases as $from => $to) {
            $p = '/aliases/' . self::escape($from);
            if ($from === $to) {
                $this->fail($p, 'alias-self', 'an alias must not point at itself');
            }
            if (isset($map->aliases[$to])) {
                $this->fail($p, 'alias-chain', sprintf('target "%s" is itself an alias; aliases are one hop', $to));
            }
            if (isset($map->passThrough[$from])) {
                $this->fail($p, 'alias-unreachable', sprintf('"%s" is a pass-through host and is never aliased', $from));
            }
            if (HostRules::preAliasKey($from, $map, null) !== $from) {
                $this->fail($p, 'alias-unreachable', sprintf(
                    'no host produces "%s" before the alias lookup (it keys as "%s")',
                    $from,
                    HostRules::preAliasKey($from, $map, null)
                ));
            }
            $this->outletKey($to, $p, $map);
        }

        foreach (['articleIdRules', 'slugOutlets', 'aggregators', 'caseInsensitivePaths'] as $field) {
            $keys = $field === 'articleIdRules' ? array_keys($map->articleIdRules) : $data[$field];
            foreach ((array) $keys as $i => $key) {
                $key = (string) $key;
                $p = '/' . $field . '/' . ($field === 'articleIdRules' ? self::escape($key) : (string) $i);
                $this->outletKey($key, $p, $map);
                if (isset($map->aliases[$key])) {
                    $this->fail($p, 'shadowed-by-alias', sprintf('"%s" is an alias source, so no outlet key ever equals it', $key));
                }
            }
        }

        /** @var array<string, mixed> $notes */
        $notes = is_array($data['notes'] ?? null) ? $data['notes'] : [];
        foreach ($map->articleIdRules as $outlet => $pattern) {
            $p = '/articleIdRules/' . self::escape($outlet);
            $this->pattern($pattern, $p);
            $note = $notes[$outlet] ?? null;
            if (is_array($note) && isset($note['examples']) && is_array($note['examples'])) {
                foreach ($note['examples'] as $i => $example) {
                    $result = preg_match($pattern, (string) $example);
                    if ($result === false) {
                        $this->fail('/notes/' . self::escape($outlet) . '/examples/' . $i, 'pattern-runtime', preg_last_error_msg());
                    }
                    if ($result !== 1) {
                        $this->fail('/notes/' . self::escape($outlet) . '/examples/' . $i, 'pattern-example', sprintf(
                            'example path "%s" does not match the id rule',
                            (string) $example
                        ));
                    }
                }
            }
        }
    }

    private function outletKey(string $key, string $pointer, MapData $map): void
    {
        $pre = HostRules::preAliasKey($key, $map, null);
        if ($pre !== $key) {
            $this->fail($pointer, 'not-outlet-key', sprintf('"%s" is not an outlet key; it keys as "%s"', $key, $pre));
        }
    }

    private function pattern(string $pattern, string $pointer): void
    {
        $parts = self::splitPattern($pattern);
        if ($parts === null) {
            $this->fail($pointer, 'pattern-syntax', 'pattern needs a non-alphanumeric delimiter at both ends');
        }
        [$open, $body, $close, $modifiers] = $parts;
        if (strspn($modifiers, self::ALLOWED_MODIFIERS) !== strlen($modifiers)) {
            $this->fail(
                $pointer,
                'pattern-modifier',
                sprintf('modifiers "%s" are not allowed; use only %s', $modifiers, self::ALLOWED_MODIFIERS)
            );
        }

        $error = null;
        set_error_handler(static function (int $no, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });
        try {
            $compiled = preg_match($pattern, '');
            // The empty alternative always matches, so every group is reported.
            $probe = $open . '(?:' . $body . "\n)|" . $close . $modifiers;
            $matched = preg_match($probe, '', $m, PREG_UNMATCHED_AS_NULL);
        } finally {
            restore_error_handler();
        }
        if ($compiled === false || $error !== null) {
            $this->fail($pointer, 'pattern-syntax', 'pattern does not compile: ' . ($error ?? preg_last_error_msg()));
        }
        if ($matched !== 1) {
            $this->fail($pointer, 'pattern-syntax', 'pattern could not be inspected');
        }
        $groups = count(array_filter(array_keys($m), 'is_int')) - 1;
        if ($groups !== 1) {
            $this->fail($pointer, 'pattern-groups', sprintf('pattern must have exactly one capturing group; it has %d', $groups));
        }
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}|null
     */
    private static function splitPattern(string $pattern): ?array
    {
        $open = $pattern[0];
        if (preg_match('~^[A-Za-z0-9\\\\\s]$~', $open) === 1) {
            return null;
        }
        $close = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'][$open] ?? $open;
        $end = strrpos($pattern, $close);
        if ($end === false || $end === 0) {
            return null;
        }

        return [$open, substr($pattern, 1, $end - 1), $close, substr($pattern, $end + 1)];
    }

    /**
     * @param array<string, mixed> $parent
     */
    private function sortedList(array $parent, string $field, string $base, string $format, string $rule): void
    {
        $p = $base . '/' . $field;
        $list = $parent[$field] ?? null;
        if (!is_array($list) || !array_is_list($list)) {
            $this->fail($p, 'type', 'must be a list');
        }
        $previous = null;
        foreach ($list as $i => $value) {
            if (!is_string($value)) {
                $this->fail($p . '/' . $i, 'type', 'must be a string');
            }
            if (preg_match($format, $value) !== 1) {
                $this->fail($p . '/' . $i, $rule, sprintf('"%s" must be lowercase ASCII', $value));
            }
            $this->order($previous, $value, $p . '/' . $i);
            $previous = $value;
        }
    }

    /**
     * @param array<string, mixed> $parent
     */
    private function sortedHostList(array $parent, string $field, string $base, int $minLabels): void
    {
        $p = $base . '/' . $field;
        $list = $parent[$field] ?? null;
        if (!is_array($list) || !array_is_list($list)) {
            $this->fail($p, 'type', 'must be a list');
        }
        $previous = null;
        foreach ($list as $i => $value) {
            if (!is_string($value)) {
                $this->fail($p . '/' . $i, 'type', 'must be a string');
            }
            $this->host($value, $p . '/' . $i, $minLabels);
            $this->order($previous, $value, $p . '/' . $i);
            $previous = $value;
        }
    }

    /**
     * @param array<string, mixed> $parent
     *
     * @return array<string, mixed>
     */
    private function sortedTable(array $parent, string $field, string $base): array
    {
        $p = $base . '/' . $field;
        $table = $parent[$field] ?? null;
        if (!self::isObject($table)) {
            $this->fail($p, 'type', 'must be an object');
        }
        /** @var array<string, mixed> $table */
        $previous = null;
        $out = [];
        foreach ($table as $key => $value) {
            $key = (string) $key;
            $this->order($previous, $key, $p . '/' . self::escape($key));
            $previous = $key;
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $object
     * @param list<string> $keys
     */
    private function exactKeys(array $object, string $base, array $keys): void
    {
        foreach (array_keys($object) as $key) {
            if (!in_array($key, $keys, true)) {
                $this->fail($base . '/' . self::escape((string) $key), 'unknown-field', 'field is not part of the schema');
            }
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $object)) {
                $this->fail($base . '/' . $key, 'required', 'required field is missing');
            }
        }
    }

    private function order(?string $previous, string $value, string $pointer): void
    {
        if ($previous === null) {
            return;
        }
        $cmp = strcmp($previous, $value);
        if ($cmp === 0) {
            $this->fail($pointer, 'duplicate', sprintf('"%s" appears twice', $value));
        }
        if ($cmp > 0) {
            $this->fail($pointer, 'unsorted', sprintf('"%s" must come before "%s" (byte order)', $value, $previous));
        }
    }

    private function host(mixed $host, string $pointer, int $minLabels): void
    {
        if (!is_string($host)) {
            $this->fail($pointer, 'type', 'must be a string');
        }
        $labels = explode('.', $host);
        foreach ($labels as $label) {
            if (preg_match(self::LABEL, $label) !== 1) {
                $this->fail($pointer, 'host-format', sprintf(
                    '"%s" must be a lowercase ASCII host (A-labels for IDN) with no scheme, path, port or trailing dot',
                    $host
                ));
            }
        }
        if (strlen($host) > 253) {
            $this->fail($pointer, 'host-format', 'host is longer than 253 bytes');
        }
        if (count($labels) < $minLabels) {
            $this->fail($pointer, 'host-format', sprintf('"%s" needs at least %d labels', $host, $minLabels));
        }
    }

    private static function isObject(mixed $value): bool
    {
        // json_decode(..., true) turns {} into [], so an empty array is an empty object.
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /** RFC 6901 escaping of one reference token. */
    public static function escape(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }

    private function fail(string $pointer, string $rule, string $detail): never
    {
        throw new InvalidMapException($this->source, $pointer, $rule, $detail);
    }
}
