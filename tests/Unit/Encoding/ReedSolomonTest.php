<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Encoding;

use PhpBarcode\Encoding\GaloisField;
use PhpBarcode\Encoding\ReedSolomon;
use PHPUnit\Framework\TestCase;

final class ReedSolomonTest extends TestCase
{
    public function testQrCodeReferenceVector(): void
    {
        // "HELLO WORLD", version 1-M, from the QR Code specification walkthrough.
        $data = [32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236, 17, 236, 17];

        self::assertSame(
            [196, 35, 39, 119, 235, 215, 231, 226, 93, 23],
            (new ReedSolomon(GaloisField::qrCode()))->encode($data, 10),
        );
    }

    public function testDataMatrixReferenceVector(): void
    {
        // "123456" in a 10x10 symbol, ISO/IEC 16022 Annex O.
        self::assertSame([114, 25, 5, 88, 102], (new ReedSolomon(GaloisField::dataMatrix()))->encode([142, 164, 186], 5));
    }

    public function testFieldTablesAreConsistent(): void
    {
        foreach ([GaloisField::qrCode(), GaloisField::aztec(6), GaloisField::aztec(12)] as $field) {
            for ($value = 1; $value < $field->size; $value++) {
                self::assertSame($value, $field->exp($field->log($value)));
            }
        }
    }
}
