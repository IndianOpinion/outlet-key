<?php

declare(strict_types=1);

namespace IndianOpinion\OutletKey;

/**
 * The outlet map is not valid JSON, does not match the schema, or breaks a
 * validator rule. The library fails closed on a bad map.
 */
final class InvalidMapException extends OutletKeyException
{
    private string $source;
    private string $pointer;
    private string $rule;

    public function __construct(string $source, string $pointer, string $rule, string $detail)
    {
        $this->source = $source;
        $this->pointer = $pointer;
        $this->rule = $rule;
        parent::__construct(sprintf(
            'Invalid outlet map %s at "%s" [%s]: %s',
            $source,
            $pointer === '' ? '/' : $pointer,
            $rule,
            $detail
        ));
    }

    /** The file path, or "array" for a map built from an array. */
    public function source(): string
    {
        return $this->source;
    }

    /** RFC 6901 JSON pointer to the offending value ("" is the whole document). */
    public function pointer(): string
    {
        return $this->pointer;
    }

    /** Short machine-readable rule name, for example "alias-chain". */
    public function rule(): string
    {
        return $this->rule;
    }
}
