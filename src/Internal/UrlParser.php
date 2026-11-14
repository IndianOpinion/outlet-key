<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Internal;

use IndianOpinion\OutletKey\InputTooLongException;

/**
 * Input preparation and host forms: PRD cases 1 to 13.
 *
 * @internal
 */
final class UrlParser
{
    public const MAX_INPUT = 8192;

    private const MAX_LABEL = 63;
    private const MAX_HOST = 253;
    private const AMP_CACHE_SUFFIX = '.cdn.ampproject.org';

    /** IDNA label separators other than the ASCII full stop. */
    private const IDNA_DOTS = ["\u{3002}", "\u{FF0E}", "\u{FF61}"];

    private const SCHEME = '~^[A-Za-z][A-Za-z0-9+.\-]*:~';
    private const IPV4 = '~^(?:(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])\.){3}'
        . '(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])$~D';
    private const ASCII_LABEL = '~^[a-z0-9_\-]+$~D';
    private const ASCII_HOST = '~^(?:[a-z0-9_\-]{1,63}\.)*[a-z0-9_\-]{1,63}\.?$~D';

    /**
     * @throws InputTooLongException when the input is over 8192 bytes
     */
    public static function parse(string $input): ParsedUrl
    {
        $length = strlen($input);
        if ($length > self::MAX_INPUT) {
            throw InputTooLongException::forLength($length, self::MAX_INPUT);
        }

        // Case 1: trim, then decode entities once.
        $prepared = trim($input);
        if (str_contains($prepared, '&')) {
            $prepared = trim(html_entity_decode($prepared, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if ($prepared === '') {
            return self::failed($prepared, 'empty input');
        }

        // Case 3 and 4: find the authority.
        $rest = self::stripScheme($prepared);
        if ($rest === null) {
            return self::failed($prepared, 'scheme without an authority');
        }

        $authorityEnd = strcspn($rest, '/?#');
        $authority = substr($rest, 0, $authorityEnd);
        $tail = substr($rest, $authorityEnd);

        // Case 32: the fragment never matters.
        $hash = strpos($tail, '#');
        if ($hash !== false) {
            $tail = substr($tail, 0, $hash);
        }
        $question = strpos($tail, '?');
        if ($question === false) {
            $path = $tail;
            $query = null;
        } else {
            $path = substr($tail, 0, $question);
            $query = substr($tail, $question + 1);
        }

        $host = self::authorityHost($authority);
        if (is_string($host)) {
            return self::failed($prepared, $host);
        }

        // Case 13: unwrap a Google AMP cache URL, once.
        $ampCacheHost = null;
        if (
            $host[1] === ParsedUrl::HOST_NAME
            && str_ends_with($host[0], self::AMP_CACHE_SUFFIX)
            && preg_match('~^/[A-Za-z]/(?:[sS]/|(?![sS](?:/|$)))([^/]+)(.*)$~sD', $path, $m) === 1
        ) {
            $inner = self::authorityHost($m[1]);
            if (is_array($inner)) {
                $ampCacheHost = $host[0];
                $host = $inner;
                $path = $m[2];
            }
        }

        return new ParsedUrl($prepared, $host[0], $host[1], $path, $query, $ampCacheHost, '');
    }

    /**
     * Prepare a bare host (no user info, no port): IDN to A-labels, one
     * trailing dot removed, IP literals recognised. ASCII letters are
     * lowercased.
     *
     * @return array{0: string, 1: string}|null the host and its ParsedUrl::HOST_* type, or null when unusable
     */
    public static function normaliseHost(string $host): ?array
    {
        if ($host === '') {
            return null;
        }
        $host = Utf8::lowerAscii($host);

        // Case 12: IPv6 literal, with or without brackets.
        if ($host[0] === '[' || str_contains($host, ':')) {
            if ($host[0] === '[') {
                if (!str_ends_with($host, ']')) {
                    return null;
                }
                $host = substr($host, 1, -1);
            }
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }

            return [$host, ParsedUrl::HOST_IPV6];
        }

        // The common case, a plain ASCII name: one regex and no label loop.
        if (preg_match(self::ASCII_HOST, $host) === 1) {
            if (str_ends_with($host, '.')) {
                $host = substr($host, 0, -1);
            }
            if (strlen($host) > self::MAX_HOST) {
                return null;
            }
            $last = $host[strlen($host) - 1];
            if ($last >= '0' && $last <= '9') {
                return self::numericTail($host);
            }

            return [$host, ParsedUrl::HOST_NAME];
        }

        if (!Utf8::isValid($host)) {
            return null;
        }
        $host = str_replace(self::IDNA_DOTS, '.', $host);

        // Case 7: one trailing dot.
        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }
        if ($host === '') {
            return null;
        }

        // Case 11.
        $last = $host[strlen($host) - 1];
        if ($last >= '0' && $last <= '9') {
            $numeric = self::numericTail($host);
            if ($numeric === null || $numeric[1] === ParsedUrl::HOST_IPV4) {
                return $numeric;
            }
        }

        // Case 9 and 10.
        $labels = explode('.', $host);
        foreach ($labels as $i => $label) {
            if ($label === '') {
                return null;
            }
            if (!Utf8::isAscii($label)) {
                $points = Utf8::codePoints($label);
                foreach ($points as $cp) {
                    if ($cp < 0x80 && preg_match(self::ASCII_LABEL, chr($cp)) !== 1) {
                        return null;
                    }
                }
                $label = 'xn--' . Punycode::encode($points);
                $labels[$i] = $label;
            } elseif (preg_match(self::ASCII_LABEL, $label) !== 1) {
                return null;
            }
            if (strlen($label) > self::MAX_LABEL) {
                return null;
            }
        }
        $host = implode('.', $labels);
        if (strlen($host) > self::MAX_HOST) {
            return null;
        }

        return [$host, ParsedUrl::HOST_NAME];
    }

    /**
     * A host whose last label ends in a digit: an IPv4 literal, a name whose
     * last label also has letters, or (all digits, not an IPv4 literal, such
     * as "10.0.0.999") unusable. No top-level domain is all digits, so such a
     * host is a broken address, never a name.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function numericTail(string $host): ?array
    {
        if (preg_match(self::IPV4, $host) === 1) {
            return [$host, ParsedUrl::HOST_IPV4];
        }
        $dot = strrpos($host, '.');
        $lastLabel = $dot === false ? $host : substr($host, $dot + 1);
        if (preg_match('~^[0-9]+$~D', $lastLabel) === 1) {
            return null;
        }

        return [$host, ParsedUrl::HOST_NAME];
    }

    /**
     * Drop the scheme (any RFC 3986 scheme, case 4) or, with none, the
     * leading slashes of a bare host (case 3). Null means a scheme with no
     * authority, such as mailto:.
     */
    private static function stripScheme(string $input): ?string
    {
        if (preg_match(self::SCHEME, $input, $m) !== 1) {
            return ltrim($input, '/');
        }
        $after = substr($input, strlen($m[0]));
        if (str_starts_with($after, '//')) {
            return substr($after, 2);
        }
        // "host:8080/path" is a bare host with a port, not a scheme. With an
        // empty port ("example.com:/a") the would-be scheme must hold a dot,
        // so a scheme on its own ("https:", "http:/x") is not read as a host.
        if (
            preg_match('~^[0-9]+(?:[/?#]|$)~D', $after) === 1
            || (str_contains($m[0], '.') && preg_match('~^(?:[/?#]|$)~D', $after) === 1)
        ) {
            return ltrim($input, '/');
        }
        // One nested scheme is allowed: "feed:https://host/".
        if (preg_match(self::SCHEME, $after, $m) === 1) {
            $inner = substr($after, strlen($m[0]));
            if (str_starts_with($inner, '//')) {
                return substr($inner, 2);
            }
        }

        return null;
    }

    /**
     * Cases 5 to 12 on one authority.
     *
     * @return array{0: string, 1: string}|string the prepared host, or why it is unusable
     */
    private static function authorityHost(string $authority): array|string
    {
        // Case 5: user info goes.
        $at = strrpos($authority, '@');
        if ($at !== false) {
            $authority = substr($authority, $at + 1);
        }
        if ($authority === '') {
            return 'no host';
        }

        // Case 6: the port goes, but it must be a port.
        if ($authority[0] === '[') {
            $close = strpos($authority, ']');
            if ($close === false) {
                return 'unclosed IPv6 bracket';
            }
            $hostPart = substr($authority, 0, $close + 1);
            $portPart = substr($authority, $close + 1);
            if ($portPart !== '' && $portPart[0] !== ':') {
                return 'text after the IPv6 literal';
            }
            $port = substr($portPart, 1);
        } else {
            $colon = strrpos($authority, ':');
            if ($colon === false) {
                $hostPart = $authority;
                $port = '';
            } else {
                $hostPart = substr($authority, 0, $colon);
                $port = substr($authority, $colon + 1);
            }
        }
        if ($port !== '' && preg_match('~^[0-9]+$~D', $port) !== 1) {
            return 'port is not a number';
        }
        // A percent-encoded host ("b%C3%BCcher.example") is decoded once, as
        // browsers do, then checked like any other host.
        if (str_contains($hostPart, '%')) {
            $hostPart = rawurldecode($hostPart);
        }

        $host = self::normaliseHost($hostPart);
        if ($host === null) {
            return 'invalid host';
        }

        return $host;
    }

    private static function failed(string $prepared, string $why): ParsedUrl
    {
        return new ParsedUrl($prepared, null, ParsedUrl::HOST_NAME, '', null, null, $why);
    }
}
