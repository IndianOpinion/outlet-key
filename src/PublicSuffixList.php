<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey;

use IndianOpinion\OutletKey\Internal\UrlParser;
use IndianOpinion\OutletKey\Internal\Utf8;

/**
 * An optional Public Suffix List (https://publicsuffix.org/list/), loaded
 * only when a caller passes one to OutletResolver. Without it the map's
 * small explicit suffix rule decides the registrable domain.
 *
 * The library never downloads the list. Fetch public_suffix_list.dat
 * yourself, pin it, and load it with fromFile().
 *
 * By default only the ICANN section is used. The PRIVATE section lists
 * hosting platforms (blogspot.com, github.io and others) as suffixes, which
 * would turn every blog into its own registrable domain and bypass the
 * map's portal rule. Pass $includePrivate = true if that is what you want.
 */
final class PublicSuffixList
{
    /** @var array<string, true> */
    private array $rules;
    /** @var array<string, true> */
    private array $wildcards;
    /** @var array<string, true> */
    private array $exceptions;
    private int $ruleCount;

    /**
     * @param array<string, true> $rules
     * @param array<string, true> $wildcards
     * @param array<string, true> $exceptions
     */
    private function __construct(array $rules, array $wildcards, array $exceptions)
    {
        $this->rules = $rules;
        $this->wildcards = $wildcards;
        $this->exceptions = $exceptions;
        $this->ruleCount = count($rules) + count($wildcards) + count($exceptions);
    }

    public static function fromFile(string $path, bool $includePrivate = false): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidSuffixListException(sprintf('Public Suffix List file "%s" is not a readable file.', $path));
        }
        $data = file_get_contents($path);
        if ($data === false) {
            throw new InvalidSuffixListException(sprintf('Public Suffix List file "%s" could not be read.', $path));
        }

        return self::fromString($data, $includePrivate);
    }

    /**
     * Parse the list's text format: one rule per line, "//" comments,
     * "*." wildcards and "!" exceptions. Unicode rules are converted to
     * A-labels.
     */
    public static function fromString(string $data, bool $includePrivate = false): self
    {
        $rules = [];
        $wildcards = [];
        $exceptions = [];
        $inPrivate = false;

        foreach (preg_split('~\R~', $data) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (strncmp($line, '//', 2) === 0) {
                if (strpos($line, '===BEGIN PRIVATE DOMAINS===') !== false) {
                    $inPrivate = true;
                } elseif (strpos($line, '===END PRIVATE DOMAINS===') !== false) {
                    $inPrivate = false;
                }
                continue;
            }
            if ($inPrivate && !$includePrivate) {
                continue;
            }
            // A rule ends at the first whitespace.
            $rule = preg_split('~\s~', $line)[0] ?? '';

            $kind = 'rule';
            if (strncmp($rule, '!', 1) === 0) {
                $kind = 'exception';
                $rule = substr($rule, 1);
            } elseif (strncmp($rule, '*.', 2) === 0) {
                $kind = 'wildcard';
                $rule = substr($rule, 2);
            }
            $host = UrlParser::normaliseHost($rule);
            if ($host === null) {
                continue; // A rule we cannot express as a host never matches a key.
            }
            if ($kind === 'exception') {
                $exceptions[$host[0]] = true;
            } elseif ($kind === 'wildcard') {
                $wildcards[$host[0]] = true;
            } else {
                $rules[$host[0]] = true;
            }
        }

        if ($rules === [] && $wildcards === [] && $exceptions === []) {
            throw new InvalidSuffixListException('The Public Suffix List holds no rules.');
        }

        return new self($rules, $wildcards, $exceptions);
    }

    /** Number of rules loaded. */
    public function count(): int
    {
        return $this->ruleCount;
    }

    /**
     * The public suffix of a host, by the list's algorithm (exception rules
     * win, then the longest rule, then the implicit "*" rule).
     */
    public function publicSuffix(string $host): string
    {
        $labels = explode('.', Utf8::lowerAscii($host));

        return implode('.', array_slice($labels, -$this->publicSuffixLabelCount($labels)));
    }

    /**
     * @param list<string> $labels
     *
     * @internal
     */
    public function publicSuffixLabelCount(array $labels): int
    {
        $n = count($labels);
        for ($i = 0; $i < $n; $i++) {
            $candidate = implode('.', array_slice($labels, $i));
            if (isset($this->exceptions[$candidate])) {
                return $n - $i - 1;
            }
            if (isset($this->rules[$candidate])) {
                return $n - $i;
            }
            if ($i + 1 < $n && isset($this->wildcards[implode('.', array_slice($labels, $i + 1))])) {
                return $n - $i;
            }
        }

        return 1;
    }
}
