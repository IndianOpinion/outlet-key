<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Internal;

/**
 * Byte-level UTF-8 helpers that need neither ext-mbstring nor ext-intl.
 *
 * @internal
 */
final class Utf8
{
    /**
     * One well-formed UTF-8 sequence (no overlongs, no surrogates), or any
     * single byte in group 1 when the bytes at this point are not well formed.
     */
    private const SEQUENCE = '/[\x00-\x7F]'
        . '|[\xC2-\xDF][\x80-\xBF]'
        . '|\xE0[\xA0-\xBF][\x80-\xBF]'
        . '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
        . '|\xED[\x80-\x9F][\x80-\xBF]'
        . '|\xF0[\x90-\xBF][\x80-\xBF]{2}'
        . '|[\xF1-\xF3][\x80-\xBF]{3}'
        . '|\xF4[\x80-\x8F][\x80-\xBF]{2}'
        . '|([\x80-\xFF])/';

    public static function isValid(string $s): bool
    {
        return preg_match('//u', $s) === 1;
    }

    public static function isAscii(string $s): bool
    {
        return preg_match('/[\x80-\xFF]/', $s) !== 1;
    }

    /** Lowercase A-Z only, independent of locale. */
    public static function lowerAscii(string $s): string
    {
        return strtr($s, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz');
    }

    /**
     * Decode a valid UTF-8 string to code points. The caller checks validity.
     *
     * @return list<int>
     */
    public static function codePoints(string $s): array
    {
        $out = [];
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $b = ord($s[$i]);
            if ($b < 0x80) {
                $out[] = $b;
                $i += 1;
            } elseif ($b < 0xE0) {
                $out[] = (($b & 0x1F) << 6) | (ord($s[$i + 1]) & 0x3F);
                $i += 2;
            } elseif ($b < 0xF0) {
                $out[] = (($b & 0x0F) << 12) | ((ord($s[$i + 1]) & 0x3F) << 6) | (ord($s[$i + 2]) & 0x3F);
                $i += 3;
            } else {
                $out[] = (($b & 0x07) << 18) | ((ord($s[$i + 1]) & 0x3F) << 12)
                    | ((ord($s[$i + 2]) & 0x3F) << 6) | (ord($s[$i + 3]) & 0x3F);
                $i += 4;
            }
        }

        return $out;
    }

    /**
     * Percent-encode, as uppercase %XX, every byte that is not part of a
     * well-formed UTF-8 sequence, plus every byte listed in $alsoEncode and
     * every ASCII control byte. Everything else is kept as it is.
     */
    public static function encodeUnsafe(string $s, string $alsoEncode): string
    {
        $pattern = '/[\x00-\x20\x7F\x80-\xFF' . preg_quote($alsoEncode, '/') . ']/';
        if (preg_match($pattern, $s) !== 1) {
            return $s;
        }
        $special = $alsoEncode;
        $result = preg_replace_callback(
            self::SEQUENCE,
            static function (array $m) use ($special): string {
                $chunk = $m[0];
                if (isset($m[1])) {
                    return sprintf('%%%02X', ord($chunk));
                }
                if (strlen($chunk) === 1) {
                    $o = ord($chunk);
                    if ($o <= 0x20 || $o === 0x7F || strpbrk($chunk, $special) !== false) {
                        return sprintf('%%%02X', $o);
                    }
                }

                return $chunk;
            },
            $s
        );
        if ($result === null) {
            // The pattern has no u flag and cannot fail on any byte string.
            throw new \LogicException('UTF-8 encoding pass failed: ' . preg_last_error_msg());
        }

        return $result;
    }
}
