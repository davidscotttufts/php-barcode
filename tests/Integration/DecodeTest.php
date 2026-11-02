<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Integration;

use PhpBarcode\Barcode;
use PhpBarcode\Renderer\Style\FinderShape;
use PhpBarcode\Renderer\Style\Gradient;
use PhpBarcode\Renderer\Style\ModuleShape;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Renders barcodes and decodes them with an external reader (zxing-cpp).
 *
 * Set BARCODE_DECODER to a command that takes image paths and prints
 * "path<TAB>format<TAB>json-text" per symbol, e.g.:
 *
 *     BARCODE_DECODER="python3 tests/Integration/decode.py" vendor/bin/phpunit --testsuite Integration
 */
final class DecodeTest extends TestCase
{
    /**
     * @return iterable<string, array{Barcode, string}>
     */
    public static function barcodes(): iterable
    {
        $cases = [
            ['qr', 'https://github.com/davidscotttufts/php-barcode', 'https://github.com/davidscotttufts/php-barcode'],
            ['qr', 'Grüße aus Köln — 東京 🚀', 'Grüße aus Köln — 東京 🚀'],
            ['microqr', 'HELLO 123', 'HELLO 123'],
            ['rmqr', 'https://example.com/r/42', 'https://example.com/r/42'],
            ['datamatrix', 'PhpBarcode Data Matrix', 'PhpBarcode Data Matrix'],
            ['gs1datamatrix', '(01)09506000134352(17)251231(10)ABC123', '(01)09506000134352(17)251231(10)ABC123'],
            ['gs1qr', '(01)09506000134352(10)ABC123', '(01)09506000134352(10)ABC123'],
            ['aztec', 'Boarding pass M1DOE/JANE', 'Boarding pass M1DOE/JANE'],
            ['maxicode', 'Ship to: 1 Infinite Loop, Cupertino', 'Ship to: 1 Infinite Loop, Cupertino'],
            ['aztecrune', '42', '042'],
            ['pdf417', 'PDF417 stacks rows of codewords.', 'PDF417 stacks rows of codewords.'],
            ['micropdf417', 'MicroPDF417', 'MicroPDF417'],
            ['ean13', '400638133393', '4006381333931'],
            ['ean8', '9638507', '96385074'],
            ['upce', '01234565', '0012345000065'],
            ['isbn', '978-0-306-40615-7', '9780306406157'],
            ['gs1128', '(01)09506000134352(17)251231(10)ABC123', '(01)09506000134352(17)251231(10)ABC123'],
            ['itf14', '15400141288763', '15400141288763'],
            ['databar', '(01)09506000134352', '(01)09506000134352'],
            ['databarlimited', '(01)09506000134352', '(01)09506000134352'],
            ['databarexpandedstacked', '(01)09506000134352(10)ABC123(15)261231', '(01)09506000134352(10)ABC123(15)261231'],
            ['code128', 'Hello, World! 1234567890', 'Hello, World! 1234567890'],
            ['code39', 'CODE-39', 'CODE-39'],
            ['code93', 'CODE 93', 'CODE 93'],
            ['itf', '1234567', '01234567'],
            ['codabar', 'A40156B', 'A40156B'],
            ['telepen', 'Telepen', 'Telepen'],
        ];

        foreach ($cases as [$type, $data, $expected]) {
            yield "$type: $data" => [Barcode::of($type, $data), $expected];
        }

        $url = 'https://example.com/styled';
        yield 'qr dots' => [Barcode::qrCode($url, 'H')->withModuleShape(ModuleShape::Dot)->withFinderShape(FinderShape::Circle), $url];
        yield 'qr rounded gradient' => [Barcode::qrCode($url, 'H')->withModuleShape(ModuleShape::Rounded)->withGradient(Gradient::linear('#1e3a8a', '#db2777')), $url];
        yield 'qr vertical' => [Barcode::qrCode($url)->vertical(), $url];
        yield 'code128 vertical' => [Barcode::code128('VERTICAL')->vertical(), 'VERTICAL'];
    }

    #[DataProvider('barcodes')]
    public function testDecodesToTheOriginalData(Barcode $barcode, string $expected): void
    {
        $decoder = getenv('BARCODE_DECODER');
        if ($decoder === false || $decoder === '') {
            self::markTestSkipped('Set BARCODE_DECODER to run decode tests.');
        }
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD is required to rasterize barcodes.');
        }

        $path = sys_get_temp_dir() . '/php-barcode-decode-' . bin2hex(random_bytes(6)) . '.png';
        try {
            $barcode->save($path);
            $output = (string) shell_exec($decoder . ' ' . escapeshellarg($path) . ' 2>&1');
        } finally {
            @unlink($path);
        }

        $decoded = [];
        foreach (explode("\n", trim($output)) as $line) {
            $fields = explode("\t", $line);
            if (count($fields) >= 3) {
                $decoded[] = json_decode($fields[2], true);
            }
        }

        self::assertContains($expected, $decoded, "Decoder output:\n" . $output);
    }
}
