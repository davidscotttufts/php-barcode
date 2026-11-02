<?php

declare(strict_types=1);

namespace PhpBarcode\Gs1;

/**
 * One component of an AI value, e.g. the "N3,iso4217" currency code of AI (3910).
 */
final readonly class Component
{
    /**
     * @param list<string> $linters Names of the GS1 linters applied to this component (see Linter).
     */
    public function __construct(
        public CharacterSet $characterSet,
        public int $minLength,
        public int $maxLength,
        public bool $optional = false,
        public array $linters = [],
    ) {}

    public function isFixedLength(): bool
    {
        return $this->minLength === $this->maxLength;
    }

    /**
     * The format notation used by the GS1 General Specifications, e.g. "N14" or "X..20".
     */
    public function format(): string
    {
        return $this->characterSet->value . ($this->isFixedLength() ? '' : '..') . $this->maxLength;
    }
}
