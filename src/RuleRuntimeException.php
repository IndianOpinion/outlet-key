<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey;

/**
 * A map pattern failed at match time (for example the PCRE backtrack limit
 * was hit). The error is raised, never swallowed.
 */
final class RuleRuntimeException extends OutletKeyException
{
    public static function forPattern(string $outlet, string $pattern, string $pcreError): self
    {
        return new self(sprintf(
            'Article id rule for "%s" (%s) failed while matching: %s.',
            $outlet,
            $pattern,
            $pcreError
        ));
    }
}
