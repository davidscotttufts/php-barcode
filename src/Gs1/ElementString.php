<?php

declare(strict_types=1);

namespace PhpBarcode\Gs1;

/**
 * A validated sequence of GS1 element strings (AI + value pairs).
 *
 * Accepts the bracketed human-readable form, the raw form with GS (0x1D)
 * separators, a scanner transmission with a symbology identifier, or an
 * array of AI => value:
 *
 *     ElementString::parse('(01)09506000134352(17)251231(10)ABC123');
 *     ElementString::parse("01095060001343521725123110ABC123\x1D21XYZ");
 *     ElementString::fromArray(['01' => '09506000134352', '10' => 'ABC123']);
 *
 * Validation covers every AI's format, character set, check digits, dates and
 * code lists; and, unless disabled, the mandatory and invalid AI pairings.
 */
final readonly class ElementString implements \Stringable
{
    /** ASCII Group Separator, which stands for FNC1 between variable-length fields. */
    public const GS = "\x1D";

    /** Symbology identifiers that announce GS1 data in a scanner transmission. */
    private const SYMBOLOGY_IDENTIFIERS = [']C1', ']e0', ']e1', ']e2', ']d2', ']Q3', ']J1', ']z1'];

    /**
     * @param non-empty-list<Element> $elements
     */
    private function __construct(
        public array $elements,
    ) {}

    /**
     * Parses bracketed "(01)…(10)…" text or raw GS-separated data.
     *
     * In bracketed input a literal "(" inside a value may be written as "\(".
     * Pass $checkAssociations = false to skip the mandatory/invalid pairing rules,
     * e.g. when the other AIs are carried by a second barcode on the same item.
     *
     * @throws Gs1Exception
     */
    public static function parse(string $input, bool $checkAssociations = true, ?AiDictionary $dictionary = null): self
    {
        $dictionary ??= AiDictionary::default();
        $input = trim($input, " \r\n\t");

        foreach (self::SYMBOLOGY_IDENTIFIERS as $identifier) {
            if (str_starts_with($input, $identifier)) {
                $input = substr($input, strlen($identifier));
                break;
            }
        }

        if ($input === '') {
            throw new Gs1Exception('GS1 data is empty.');
        }

        $pairs = ($input[0] === '(' || $input[0] === '[')
            ? self::splitBracketed($input, $dictionary)
            : self::splitRaw(ltrim($input, self::GS), $dictionary);

        return self::build($pairs, $checkAssociations, $dictionary);
    }

    /**
     * @param array<string|int, string|int> $values AI => value; integer keys are treated as AI strings.
     *
     * @throws Gs1Exception
     */
    public static function fromArray(array $values, bool $checkAssociations = true, ?AiDictionary $dictionary = null): self
    {
        $pairs = [];
        foreach ($values as $ai => $value) {
            $ai = (string) $ai;
            // PHP turns the key "01" into the integer 1; restore the leading zero.
            $pairs[] = [strlen($ai) === 1 ? '0' . $ai : $ai, (string) $value];
        }

        return self::build($pairs, $checkAssociations, $dictionary ?? AiDictionary::default());
    }

    /**
     * Data for a barcode: values concatenated, with GS after every value whose AI is
     * not of predefined length (except the last). The leading FNC1 is not included.
     */
    public function toEncodable(): string
    {
        $data = '';
        $last = count($this->elements) - 1;
        foreach ($this->elements as $index => $element) {
            $data .= $element->ai->code . $element->value;
            if ($index !== $last && $element->ai->requiresSeparator()) {
                $data .= self::GS;
            }
        }

        return $data;
    }

    /**
     * Human-readable interpretation, e.g. "(01)09506000134352(10)ABC123".
     */
    public function toHri(): string
    {
        return implode('', array_map(static fn(Element $e): string => $e->toHri(), $this->elements));
    }

    public function __toString(): string
    {
        return $this->toHri();
    }

    /**
     * The value of the first element with this AI, or null.
     */
    public function get(string $ai): ?string
    {
        foreach ($this->elements as $element) {
            if ($element->ai->code === $ai) {
                return $element->value;
            }
        }

        return null;
    }

    public function has(string $ai): bool
    {
        return $this->get($ai) !== null;
    }

    /**
     * @return array<string, string> AI => value
     */
    public function toArray(): array
    {
        $values = [];
        foreach ($this->elements as $element) {
            $values[$element->ai->code] ??= $element->value;
        }

        return $values;
    }

    /**
     * A copy with predefined-length AIs first, which avoids separators and
     * gives the shortest barcode (GS1 General Specifications 5.10.3).
     */
    public function withOptimalOrder(): self
    {
        $elements = $this->elements;
        // usort() is stable, so elements keep their relative order within each group.
        usort($elements, static fn(Element $a, Element $b): int => $a->ai->requiresSeparator() <=> $b->ai->requiresSeparator());

        return new self($elements);
    }

    /**
     * @param list<array{string, string}> $pairs
     *
     * @throws Gs1Exception
     */
    private static function build(array $pairs, bool $checkAssociations, AiDictionary $dictionary): self
    {
        if ($pairs === []) {
            throw new Gs1Exception('GS1 data contains no element strings.');
        }

        $elements = [];
        $seen = [];
        foreach ($pairs as [$code, $value]) {
            $ai = $dictionary->get($code);
            $ai->validate($value);

            if (isset($seen[$code]) && $seen[$code] !== $value) {
                throw new Gs1Exception(sprintf('AI (%s) appears more than once with different values.', $code), $code);
            }
            $seen[$code] = $value;
            $elements[] = new Element($ai, $value);
        }

        $elementString = new self($elements);
        if ($checkAssociations) {
            $elementString->checkAssociations();
        }

        return $elementString;
    }

    /**
     * @throws Gs1Exception
     */
    private function checkAssociations(): void
    {
        foreach ($this->elements as $element) {
            foreach ($element->ai->excludes as $pattern) {
                foreach ($this->elements as $other) {
                    if ($other->ai->code !== $element->ai->code && $other->ai->matches($pattern)) {
                        throw new Gs1Exception(sprintf(
                            'AI (%s) must not be used together with AI (%s).',
                            $element->ai->code,
                            $other->ai->code,
                        ), $element->ai->code);
                    }
                }
            }

            foreach ($element->ai->requires as $alternatives) {
                if (!$this->satisfiesAny($alternatives)) {
                    throw new Gs1Exception(sprintf(
                        'AI (%s) requires %s. Pass checkAssociations: false if that data is carried by another barcode.',
                        $element->ai->code,
                        implode(' or ', array_map(
                            static fn(array $set): string => implode(' + ', array_map(static fn(string $ai): string => '(' . $ai . ')', $set)),
                            $alternatives,
                        )),
                    ), $element->ai->code);
                }
            }
        }
    }

    /**
     * @param list<list<string>> $alternatives
     */
    private function satisfiesAny(array $alternatives): bool
    {
        foreach ($alternatives as $required) {
            $all = true;
            foreach ($required as $pattern) {
                $found = false;
                foreach ($this->elements as $element) {
                    if ($element->ai->matches($pattern)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{string, string}>
     *
     * @throws Gs1Exception
     */
    private static function splitBracketed(string $input, AiDictionary $dictionary): array
    {
        $open = $input[0];
        $close = $open === '(' ? ')' : ']';
        $pattern = '/^' . preg_quote($open, '/') . '(\d{2,4})' . preg_quote($close, '/') . '/';

        $pairs = [];
        $code = null;
        $value = '';
        $length = strlen($input);
        for ($i = 0; $i < $length;) {
            if ($input[$i] === '\\' && ($input[$i + 1] ?? '') === $open) {
                $value .= $open;
                $i += 2;
                continue;
            }
            if ($input[$i] === $open && preg_match($pattern, substr($input, $i, 6), $match) === 1 && $dictionary->has($match[1])) {
                if ($code !== null) {
                    $pairs[] = [$code, $value];
                }
                $code = $match[1];
                $value = '';
                $i += strlen($match[0]);
                continue;
            }
            if ($code === null) {
                throw new Gs1Exception(sprintf('GS1 data must start with an Application Identifier such as %s01%s, got "%s".', $open, $close, substr($input, 0, 10)));
            }
            $value .= $input[$i];
            $i++;
        }
        if ($code !== null) {
            $pairs[] = [$code, $value];
        }

        return $pairs;
    }

    /**
     * @return list<array{string, string}>
     *
     * @throws Gs1Exception
     */
    private static function splitRaw(string $input, AiDictionary $dictionary): array
    {
        $pairs = [];
        $length = strlen($input);
        $offset = 0;
        while ($offset < $length) {
            $ai = $dictionary->matchPrefix($input, $offset);
            if ($ai === null) {
                throw new Gs1Exception(sprintf(
                    'No known GS1 Application Identifier at position %d ("%s").',
                    $offset + 1,
                    substr($input, $offset, 4),
                ));
            }
            $offset += strlen($ai->code);

            if ($ai->predefinedLength) {
                $valueLength = $ai->maxLength();
            } else {
                $separator = strpos($input, self::GS, $offset);
                $valueLength = ($separator === false ? $length : $separator) - $offset;
            }

            $pairs[] = [$ai->code, substr($input, $offset, $valueLength)];
            $offset += $valueLength;

            // A separator after a predefined-length field is redundant but harmless.
            if (($input[$offset] ?? '') === self::GS) {
                $offset++;
            }
        }

        return $pairs;
    }
}
