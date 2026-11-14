<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey;

/**
 * The input is longer than the library will key. No key is computed from a
 * truncated input.
 */
final class InputTooLongException extends OutletKeyException
{
    public static function forLength(int $length, int $limit): self
    {
        return new self(sprintf('Input is %d bytes; the limit is %d bytes.', $length, $limit));
    }
}
