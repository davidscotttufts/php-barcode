<?php

declare(strict_types=1);

namespace PhpBarcode\Gs1;

/**
 * A GS1 Application Identifier (AI) definition, e.g. (01) GTIN or (17) expiry date.
 */
final readonly class ApplicationIdentifier
{
    /**
     * @param list<Component>          $components
     * @param list<list<list<string>>> $requires     Each entry must be satisfied: one of its alternatives
     *                                               (a set of AIs or patterns like "31nn") must be present.
     * @param list<string>             $excludes     AIs or patterns that may not appear alongside this AI.
     * @param list<list<string>>|null  $digitalLinkQualifiers Null unless this AI is a GS1 Digital Link primary
     *                                               key; otherwise the alternative ordered qualifier sequences.
     */
    public function __construct(
        public string $code,
        public string $title,
        public array $components,
        public bool $predefinedLength = false,
        public bool $digitalLinkAttribute = false,
        public array $requires = [],
        public array $excludes = [],
        public ?array $digitalLinkQualifiers = null,
    ) {}

    /**
     * Whether a variable-length field separator (FNC1/GS) must follow this AI's
     * value when another element string follows it.
     */
    public function requiresSeparator(): bool
    {
        return !$this->predefinedLength;
    }

    public function isDigitalLinkPrimaryKey(): bool
    {
        return $this->digitalLinkQualifiers !== null;
    }

    public function minLength(): int
    {
        return array_sum(array_map(static fn(Component $c): int => $c->optional ? 0 : $c->minLength, $this->components));
    }

    public function maxLength(): int
    {
        return array_sum(array_map(static fn(Component $c): int => $c->maxLength, $this->components));
    }

    public function isFixedLength(): bool
    {
        return $this->minLength() === $this->maxLength();
    }

    /**
     * The format in GS1 General Specifications notation, e.g. "N14" or "N3+X..27".
     */
    public function format(): string
    {
        return implode('+', array_map(
            static fn(Component $c): string => $c->optional ? '[' . $c->format() . ']' : $c->format(),
            $this->components,
        ));
    }

    /**
     * Whether this AI matches a dictionary pattern such as "310n" or "35nn".
     */
    public function matches(string $pattern): bool
    {
        if (strlen($pattern) !== strlen($this->code)) {
            return false;
        }
        for ($i = 0, $length = strlen($pattern); $i < $length; $i++) {
            if ($pattern[$i] !== 'n' && $pattern[$i] !== $this->code[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validates a value against this AI's format and content rules.
     *
     * @throws Gs1Exception with the 1-based position of the first problem
     */
    public function validate(string $value): void
    {
        $length = strlen($value);
        if ($length === 0) {
            throw Gs1Exception::forValue($this->code, 'value is empty');
        }

        $position = 0;
        foreach ($this->components as $component) {
            $remaining = $length - $position;
            if ($remaining === 0 && $component->optional) {
                break;
            }

            $take = $component->isFixedLength() ? $component->maxLength : min($remaining, $component->maxLength);
            if ($remaining < $component->minLength) {
                throw Gs1Exception::forValue($this->code, sprintf(
                    'value is too short: %d characters, format %s requires %s%d',
                    $length,
                    $this->format(),
                    $this->isFixedLength() ? '' : 'at least ',
                    $this->minLength(),
                ));
            }

            $part = substr($value, $position, $take);
            $invalid = $component->characterSet->firstInvalid($part);
            if ($invalid !== null) {
                throw Gs1Exception::forValue(
                    $this->code,
                    sprintf('invalid character "%s"', $part[$invalid]),
                    $position + $invalid + 1,
                    $component->characterSet->describe() . ' allowed',
                );
            }

            foreach ($component->linters as $linter) {
                $problem = Linter::check($linter, $part);
                if ($problem !== null) {
                    throw Gs1Exception::forValue($this->code, $problem[1], $position + $problem[0] + 1, $problem[2] ?? null);
                }
            }

            $position += $take;
        }

        if ($position < $length) {
            throw Gs1Exception::forValue($this->code, sprintf(
                'value is too long: %d characters, format %s allows at most %d',
                $length,
                $this->format(),
                $this->maxLength(),
            ));
        }
    }
}
