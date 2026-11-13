<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Internal;

/**
 * The result of input preparation (PRD cases 1 to 13).
 *
 * @internal
 */
final class ParsedUrl
{
    public const HOST_NAME = 'name';
    public const HOST_IPV4 = 'ipv4';
    public const HOST_IPV6 = 'ipv6';

    /**
     * @param string $prepared the trimmed, entity-decoded input
     * @param string|null $host the prepared host, or null when unparseable
     * @param string $hostType one of the HOST_* constants (meaningless when $host is null)
     * @param string $path raw path, still percent-encoded, may be ""
     * @param string|null $query raw query without "?", null when absent
     * @param string|null $ampCacheHost the cache host when an AMP cache URL was unwrapped
     * @param string $failure why the input is unparseable ("" when it parsed)
     */
    public function __construct(
        public readonly string $prepared,
        public readonly ?string $host,
        public readonly string $hostType,
        public readonly string $path,
        public readonly ?string $query,
        public readonly ?string $ampCacheHost,
        public readonly string $failure
    ) {
    }

    public function isParsed(): bool
    {
        return $this->host !== null;
    }
}
