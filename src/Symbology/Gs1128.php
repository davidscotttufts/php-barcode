<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Gs1\ElementString;
use PhpBarcode\Gs1\Gs1Exception;
use PhpBarcode\Symbol\LinearSymbol;

/**
 * GS1-128 (formerly UCC/EAN-128): Code 128 carrying GS1 Application Identifiers.
 *
 * Input may be bracketed ("(01)09506000134352(17)251231(10)ABC123") or raw
 * with GS separators. Every AI is validated against the GS1 AI dictionary; the
 * symbol starts with FNC1 and FNC1 separates variable-length fields.
 */
final class Gs1128 extends AbstractSymbology
{
    /** GS1 General Specifications 5.4.1: at most 48 data characters per symbol. */
    public const MAX_DATA_CHARACTERS = 48;

    public function __construct(
        private readonly bool $checkAssociations = true,
        private readonly bool $optimizeOrder = false,
    ) {}

    public function name(): string
    {
        return 'GS1-128';
    }

    protected function validate(string $data): void
    {
        $length = strlen($this->elementString($data)->toEncodable());
        if ($length > self::MAX_DATA_CHARACTERS) {
            throw InvalidDataException::invalidLength(
                $this->name(),
                sprintf('at most %d data characters including separators', self::MAX_DATA_CHARACTERS),
                $length,
            );
        }
    }

    protected function doEncode(string $data): LinearSymbol
    {
        $elements = $this->elementString($data);
        $symbol = (new Code128(fnc1: true))->encode($elements->toEncodable());
        \assert($symbol instanceof LinearSymbol);

        return new LinearSymbol($symbol->widths, $elements->toHri(), $this->name(), $this->quietZone());
    }

    /**
     * @throws InvalidDataException
     */
    private function elementString(string $data): ElementString
    {
        try {
            $elements = ElementString::parse($data, $this->checkAssociations);
        } catch (Gs1Exception $e) {
            throw new InvalidDataException(sprintf('%s: %s', $this->name(), $e->getMessage()), 0, $e);
        }

        return $this->optimizeOrder ? $elements->withOptimalOrder() : $elements;
    }
}
