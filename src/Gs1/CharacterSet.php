<?php

declare(strict_types=1);

namespace PhpBarcode\Gs1;

/**
 * The character sets permitted in GS1 Application Identifier values.
 */
enum CharacterSet: string
{
    /** Digits 0-9. */
    case Numeric = 'N';

    /** GS1 AI encodable character set 82: printable ASCII except space, # $ @ [ \ ] ^ ` { | } ~. */
    case Cset82 = 'X';

    /** GS1 AI encodable character set 39: # - / 0-9 A-Z. */
    case Cset39 = 'Y';

    /** File-safe / URI-safe base64 (RFC 4648 §5) with optional "=" padding. */
    case Cset64 = 'Z';

    public const CSET82 = "!\"%&'()*+,-./0123456789:;<=>?ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz";
    public const CSET39 = '#-/0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    public const CSET64 = '-0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz';

    public function describe(): string
    {
        return match ($this) {
            self::Numeric => 'digits only',
            self::Cset82 => 'GS1 character set 82',
            self::Cset39 => 'GS1 character set 39',
            self::Cset64 => 'base64url characters',
        };
    }

    /**
     * 0-based offset of the first character not in this set, or null if all are valid.
     */
    public function firstInvalid(string $value): ?int
    {
        if ($this === self::Cset64) {
            $padding = strlen($value) - strlen(rtrim($value, '='));
            $value = substr($value, 0, strlen($value) - $padding);
        }

        $valid = strspn($value, match ($this) {
            self::Numeric => '0123456789',
            self::Cset82 => self::CSET82,
            self::Cset39 => self::CSET39,
            self::Cset64 => self::CSET64,
        });

        return $valid === strlen($value) ? null : $valid;
    }
}
