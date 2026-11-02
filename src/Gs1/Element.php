<?php

declare(strict_types=1);

namespace PhpBarcode\Gs1;

/**
 * One AI and its value, e.g. (17) "251231".
 */
final readonly class Element
{
    public function __construct(
        public ApplicationIdentifier $ai,
        public string $value,
    ) {}

    public function code(): string
    {
        return $this->ai->code;
    }

    /**
     * Human-readable form, e.g. "(17)251231".
     */
    public function toHri(): string
    {
        return '(' . $this->ai->code . ')' . $this->value;
    }
}
