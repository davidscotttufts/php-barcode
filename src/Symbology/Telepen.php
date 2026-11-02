<?php

/*
 * Portions of this file (lookup tables) are derived from Zint, the open source
 * barcode library, and are used under the following licence:
 *
 * libzint - the open source barcode library
 * Copyright (C) 2008-2024 Robin Stuart <rstuart114@gmail.com>
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 * 1. Redistributions of source code must retain the above copyright
 *    notice, this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 * 3. Neither the name of the project nor the names of its contributors
 *    may be used to endorse or promote products derived from this software
 *    without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

declare(strict_types=1);

namespace PhpBarcode\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\LinearSymbol;

/**
 * Telepen, a full-ASCII symbology popular in UK libraries.
 *
 * Every character is its 7-bit ASCII code plus an even parity bit, sent
 * least significant bit first and drawn with these rules: a 1 bit is a narrow
 * bar and narrow space; "00" is a wide bar and narrow space; "010" is a wide
 * bar and wide space; and in a longer "0 1…1 0" run the opening and closing
 * pairs become a narrow bar and wide space. Wide elements are 3 modules.
 * The check character is 127 − (sum of values mod 127).
 *
 * In numeric mode, digit pairs are packed into single characters (value
 * pair + 27); "X" may be used as the second digit of a pair. An odd number of
 * digits gets a leading zero.
 *
 * Verified against Zint's TeleTable (BSD-3-Clause).
 */
final class Telepen extends AbstractSymbology
{
    private const START = 0x5F; // "_"
    private const STOP = 0x7A;  // "z"

    public function __construct(
        private readonly bool $numeric = false,
    ) {}

    public function name(): string
    {
        return $this->numeric ? 'Telepen Numeric' : 'Telepen';
    }

    protected function normalize(string $data): string
    {
        if (!$this->numeric) {
            return $data;
        }

        $data = strtoupper($data);

        return strlen($data) % 2 === 1 ? '0' . $data : $data;
    }

    protected function validate(string $data): void
    {
        if (!$this->numeric) {
            foreach (str_split($data) as $position => $character) {
                if (ord($character) > 127) {
                    throw InvalidDataException::invalidCharacter($this->name(), $character, $position);
                }
            }

            return;
        }

        $this->assertAlphabet($data, '0123456789X');
        foreach (str_split($data, 2) as $i => $pair) {
            if ($pair[0] === 'X') {
                throw InvalidDataException::invalidCharacter($this->name(), 'X', 2 * $i);
            }
        }
    }

    protected function doEncode(string $data): LinearSymbol
    {
        $values = $this->numeric
            ? array_map(
                static fn(string $pair): int => $pair[1] === 'X' ? (int) $pair[0] + 17 : (int) $pair + 27,
                str_split($data, 2),
            )
            : array_map('ord', str_split($data));

        $check = (127 - array_sum($values) % 127) % 127;

        $pattern = self::character(self::START);
        foreach ([...$values, $check] as $value) {
            $pattern .= self::character($value);
        }
        $pattern .= self::character(self::STOP);

        // Every character ends with a space; drop the stop character's trailing space.
        return $this->fromWidths(substr($pattern, 0, -1), (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $data));
    }

    /**
     * Bar/space widths for one 7-bit character (always ends with a space).
     */
    public static function character(int $value): string
    {
        $ones = substr_count(decbin($value), '1');
        $bits = strrev(str_pad(decbin($value), 7, '0', STR_PAD_LEFT)) . ($ones % 2);

        $widths = '';
        $i = 0;
        while ($i < 8) {
            if ($bits[$i] === '1') {
                $widths .= '11';
                $i++;
            } elseif ($bits[$i + 1] === '0') {
                $widths .= '31';
                $i += 2;
            } else {
                $next = strpos($bits, '0', $i + 1);
                \assert($next !== false); // even parity guarantees a closing 0
                $ones = $next - $i - 1;
                $widths .= $ones === 1 ? '33' : '13' . str_repeat('11', $ones - 2) . '13';
                $i = $next + 1;
            }
        }

        return $widths;
    }
}
