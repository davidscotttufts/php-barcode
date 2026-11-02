<?php

declare(strict_types=1);

/*
 * Renders one sample of every built-in barcode type into docs/images/.
 *
 *     php examples/gallery.php [output-dir] [format]
 */

use PhpBarcode\Barcode;
use PhpBarcode\BarcodeType;
use PhpBarcode\Renderer\Style\FinderShape;
use PhpBarcode\Renderer\Style\Gradient;
use PhpBarcode\Renderer\Style\Logo;
use PhpBarcode\Renderer\Style\ModuleShape;

require __DIR__ . '/../vendor/autoload.php';

$outputDir = $argv[1] ?? __DIR__ . '/../docs/images';
$format = $argv[2] ?? 'svg';

/** Sample data for every type: realistic values, so the pictures look like the real thing. */
const SAMPLES = [
    'qr' => 'https://github.com/davidscotttufts/php-barcode',
    'gs1qr' => '(01)09506000134352(10)ABC123',
    'microqr' => 'HELLO 123',
    'rmqr' => 'https://example.com/r/42',
    'datamatrix' => 'PhpBarcode Data Matrix',
    'gs1datamatrix' => '(01)09506000134352(17)251231(10)ABC123',
    'aztec' => 'Boarding pass M1DOE/JANE',
    'gs1aztec' => '(01)09506000134352(10)ABC123',
    'aztecrune' => '42',
    'maxicode' => 'Ship to: 1 Infinite Loop, Cupertino',
    'pdf417' => 'PDF417 stacks rows of codewords to hold a lot of data.',
    'pdf417compact' => 'Compact PDF417',
    'micropdf417' => 'MicroPDF417',
    'ean13' => '4006381333931',
    'ean8' => '96385074',
    'upca' => '036000291452',
    'upce' => '01234565',
    'ean2' => '53',
    'ean5' => '52495',
    'isbn' => '978-0-306-40615-7',
    'issn' => '0317-8471',
    'ismn' => '979-0-2600-0043-8',
    'gs1128' => '(01)09506000134352(17)251231(10)ABC123',
    'itf14' => '15400141288763',
    'databar' => '(01)09506000134352',
    'databartruncated' => '(01)09506000134352',
    'databarstacked' => '(01)09506000134352',
    'databarstackedomni' => '(01)09506000134352',
    'databarlimited' => '(01)09506000134352',
    'databarexpanded' => '(01)09506000134352(3103)001750',
    'databarexpandedstacked' => '(01)09506000134352(10)ABC123(15)261231',
    'code128' => 'PhpBarcode-128',
    'code128a' => 'CODE 128A',
    'code128b' => 'Code 128B',
    'code128c' => '12345678',
    'code39' => 'CODE-39',
    'code39ext' => 'Code 39 ext',
    'code93' => 'CODE 93',
    'code93ext' => 'Code 93 ext',
    'itf' => '12345678',
    'industrial2of5' => '123456',
    'iata2of5' => '123456',
    'codabar' => 'A40156B',
    'code11' => '123-45',
    'msi' => '1234567',
    'plessey' => '01234ABCD',
    'telepen' => 'Telepen',
    'telepennumeric' => '123456',
    'pharmacode' => '1234',
    'code32' => '12345678',
    'pzn' => '1234562',
    'imb' => '01234567094987654321-01234567891',
    'postnet' => '55555-1237',
    'planet' => '40123456789',
    'rm4scc' => 'SN34RD1A',
    'mailmark' => '11210012341234567AB19XY1A',
    'kix' => '2500GG30250',
    'auspost' => '5956439111ABA 9',
    'japanpost' => '15400233-16-4-205',
];

$failures = 0;
foreach (BarcodeType::cases() as $type) {
    $data = SAMPLES[$type->value] ?? null;
    if ($data === null) {
        fwrite(STDERR, "No sample for {$type->value}\n");
        $failures++;
        continue;
    }

    try {
        Barcode::of($type, $data)->save(sprintf('%s/%s.%s', $outputDir, $type->value, $format));
        echo $type->value, "\t", json_encode($data), "\n";
    } catch (Throwable $e) {
        fwrite(STDERR, sprintf("%s: %s\n", $type->value, $e->getMessage()));
        $failures++;
    }
}

// A few styled QR codes for the README.
$url = 'https://github.com/davidscotttufts/php-barcode';
$logo = new Logo(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4.6" fill="#dc2626"/><circle cx="5" cy="5" r="2.2" fill="#fff"/></svg>',
    'image/svg+xml',
    size: 0.2,
);
Barcode::qrCode($url, 'H')->withModuleShape(ModuleShape::Dot)->withFinderShape(FinderShape::Circle)
    ->save("$outputDir/styled-dots.$format");
Barcode::qrCode($url, 'H')->withModuleShape(ModuleShape::Rounded)->withFinderShape(FinderShape::Rounded)
    ->withGradient(Gradient::linear('#1e3a8a', '#db2777'))->save("$outputDir/styled-gradient.$format");
if ($format === 'svg') {
    Barcode::qrCode($url, 'H')->withModuleShape(ModuleShape::Dot)->withFinderShape(FinderShape::Rounded)
        ->withGradient(Gradient::radial('#111827', '#2563eb'))->withLogo($logo)->save("$outputDir/styled-logo.svg");
}

exit($failures === 0 ? 0 : 1);
