<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey\Internal;

/**
 * RFC 3492 Punycode encoder, built in so results never depend on ext-intl.
 *
 * Only encoding is needed: hosts are keyed in their A-label form.
 *
 * @internal
 */
final class Punycode
{
    private const BASE = 36;
    private const TMIN = 1;
    private const TMAX = 26;
    private const SKEW = 38;
    private const DAMP = 700;
    private const INITIAL_BIAS = 72;
    private const INITIAL_N = 128;

    /**
     * Encode a list of Unicode code points to a Punycode string (no "xn--").
     *
     * @param list<int> $codePoints
     */
    public static function encode(array $codePoints): string
    {
        $output = '';
        foreach ($codePoints as $cp) {
            if ($cp < 0x80) {
                $output .= chr($cp);
            }
        }
        $basic = strlen($output);
        $handled = $basic;
        if ($basic > 0) {
            $output .= '-';
        }

        $n = self::INITIAL_N;
        $delta = 0;
        $bias = self::INITIAL_BIAS;
        $total = count($codePoints);

        while ($handled < $total) {
            $m = PHP_INT_MAX;
            foreach ($codePoints as $cp) {
                if ($cp >= $n && $cp < $m) {
                    $m = $cp;
                }
            }
            $delta += ($m - $n) * ($handled + 1);
            $n = $m;
            foreach ($codePoints as $cp) {
                if ($cp < $n) {
                    $delta++;
                }
                if ($cp === $n) {
                    $q = $delta;
                    for ($k = self::BASE;; $k += self::BASE) {
                        $t = self::threshold($k, $bias);
                        if ($q < $t) {
                            break;
                        }
                        $output .= self::digit($t + ($q - $t) % (self::BASE - $t));
                        $q = intdiv($q - $t, self::BASE - $t);
                    }
                    $output .= self::digit($q);
                    $bias = self::adapt($delta, $handled + 1, $handled === $basic);
                    $delta = 0;
                    $handled++;
                }
            }
            $delta++;
            $n++;
        }

        return $output;
    }

    private static function threshold(int $k, int $bias): int
    {
        if ($k <= $bias) {
            return self::TMIN;
        }
        if ($k >= $bias + self::TMAX) {
            return self::TMAX;
        }

        return $k - $bias;
    }

    private static function digit(int $d): string
    {
        // 0..25 map to a..z, 26..35 map to 0..9.
        return chr($d + ($d < 26 ? 97 : 22));
    }

    private static function adapt(int $delta, int $numPoints, bool $firstTime): int
    {
        $delta = $firstTime ? intdiv($delta, self::DAMP) : intdiv($delta, 2);
        $delta += intdiv($delta, $numPoints);
        $k = 0;
        while ($delta > intdiv((self::BASE - self::TMIN) * self::TMAX, 2)) {
            $delta = intdiv($delta, self::BASE - self::TMIN);
            $k += self::BASE;
        }

        return $k + intdiv((self::BASE - self::TMIN + 1) * $delta, $delta + self::SKEW);
    }
}
