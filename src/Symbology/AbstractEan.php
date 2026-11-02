<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Checksum\Mod10;
use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\LinearSymbol;

/**
 * Shared logic for the fixed-length EAN/UPC retail symbologies.
 *
 * Accepts the payload either without the check digit (it is calculated) or
 * with it (it is verified). A 2- or 5-digit add-on may follow, separated by
 * "+" or a space: "9781234567897+51299" or "9781234567897 51299".
 *
 * Symbols are laid out the classic retail way (GS1 General Specifications
 * 5.2.5): guard bars extend into the text area and the digits are grouped
 * under each half of the symbol.
 */
abstract class AbstractEan extends AbstractSymbology
{
    protected const GUARD = EanTables::GUARD;
    protected const CENTER_GUARD = EanTables::CENTER_GUARD;

    /**
     * @param int|null $addOnGap Space between the main symbol and an add-on, in modules
     *                           (GS1: 7–12 for EAN, 9–12 for UPC); null uses the minimum.
     */
    public function __construct(
        private readonly ?int $addOnGap = null,
    ) {
        if ($addOnGap !== null && ($addOnGap < $this->minimumAddOnGap() || $addOnGap > 12)) {
            throw new \InvalidArgumentException(sprintf('The add-on gap must be between %d and 12 modules.', $this->minimumAddOnGap()));
        }
    }

    /**
     * Smallest gap between the symbol and an add-on allowed by GS1, in modules.
     */
    protected function minimumAddOnGap(): int
    {
        return 7;
    }

    /**
     * Total number of digits including the check digit.
     */
    abstract protected function length(): int;

    /**
     * Module bitmap for a complete, check-digit-verified number.
     */
    abstract protected function bitmap(string $digits): string;

    /**
     * Module ranges [start, end) of the bars that extend into the text area.
     *
     * @return list<array{int, int}>
     */
    abstract protected function guards(): array;

    /**
     * Human-readable digit groups, positioned in modules from the first bar.
     *
     * @return list<array{text: string, x: float|int, anchor?: 'start'|'middle'|'end'}>
     */
    abstract protected function textSegments(string $digits): array;

    /**
     * Right quiet zone in modules (GS1 Figure 5.2.2.4.1-1).
     */
    protected function rightQuietZone(): int
    {
        return 7;
    }

    /**
     * Same as encode(), typed as the concrete linear symbol.
     */
    public function encodeLinear(string $data): LinearSymbol
    {
        $symbol = $this->encode($data);
        \assert($symbol instanceof LinearSymbol);

        return $symbol;
    }

    /**
     * Splits "main+addon" / "main addon" into its parts; the add-on is null when absent.
     *
     * @return array{string, string|null}
     */
    public static function splitAddOn(string $data): array
    {
        $data = trim($data);
        if (preg_match('/^(.+?)\s*[+ ]\s*(\d{2}|\d{5})$/', $data, $match) === 1) {
            return [$match[1], $match[2]];
        }

        return [$data, null];
    }

    protected function normalize(string $data): string
    {
        // Allow the common "4006381-33393-1" / "4 006381 333931" print styles.
        [$main, $addOn] = self::splitAddOn($data);
        $strip = static fn(string $value): string => str_replace([' ', '-'], '', $value);

        if ($addOn !== null && in_array(strlen($strip($main)), $this->acceptedLengths(), true)) {
            return $strip($main) . '+' . $addOn;
        }

        return $strip($data);
    }

    /**
     * Accepted lengths of the main part, with and without check digit.
     *
     * @return list<int>
     */
    protected function acceptedLengths(): array
    {
        return [$this->length() - 1, $this->length()];
    }

    final protected function validate(string $data): void
    {
        [$main, $addOn] = array_pad(explode('+', $data, 2), 2, null);

        $this->validateMain((string) $main);

        if ($addOn !== null && (!ctype_digit($addOn) || !in_array(strlen($addOn), [2, 5], true))) {
            throw InvalidDataException::invalidLength($this->name() . ' add-on', '2 or 5 digits', strlen($addOn));
        }
    }

    protected function validateMain(string $data): void
    {
        $this->assertAlphabet($data, '0123456789');

        $length = strlen($data);
        if (!in_array($length, $this->acceptedLengths(), true)) {
            throw InvalidDataException::invalidLength(
                $this->name(),
                sprintf('%d digits (or %d without the check digit)', $this->length(), $this->length() - 1),
                $length,
            );
        }

        if ($length === $this->length()) {
            $expected = (new Mod10())->calculate(substr($data, 0, -1));
            if ($expected !== $data[-1]) {
                throw InvalidDataException::invalidCheckDigit($this->name(), $expected, $data[-1]);
            }
        }
    }

    /**
     * The full digit string to encode, including the check digit.
     */
    protected function complete(string $data): string
    {
        return strlen($data) === $this->length() ? $data : $data . (new Mod10())->calculate($data);
    }

    final protected function doEncode(string $data): LinearSymbol
    {
        [$main, $addOn] = array_pad(explode('+', $data, 2), 2, null);
        $digits = $this->complete((string) $main);

        $symbol = new LinearSymbol(
            LinearSymbol::runLengths($this->bitmap($digits)),
            $digits,
            $this->name(),
            $this->quietZone(),
            rightQuietZone: $this->rightQuietZone(),
            guards: $this->guards(),
            textSegments: $this->textSegments($digits),
        );

        if ($addOn === null) {
            return $symbol;
        }

        $addOnSymbol = strlen($addOn) === 2 ? (new Ean2())->encodeLinear($addOn) : (new Ean5())->encodeLinear($addOn);

        return $symbol->withAddOn($addOnSymbol, $this->addOnGap ?? $this->minimumAddOnGap());
    }

    protected function lCode(string $digit): string
    {
        return EanTables::l($digit);
    }

    protected function gCode(string $digit): string
    {
        return EanTables::g($digit);
    }

    protected function rCode(string $digit): string
    {
        return EanTables::r($digit);
    }
}
