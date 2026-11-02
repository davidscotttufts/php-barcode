<?php

declare(strict_types=1);

/*
 * Drop-in HTTP endpoint, compatible with the original script's URLs:
 *
 *     <img src="/barcode.php?text=testing" alt="testing">
 *
 * Query parameters (all optional):
 *   text         Data to encode                            (default "0")
 *   codetype     code128, code128a/b/c, code39, code25/itf,
 *                codabar, ean13, ean8, upca                (default "code128")
 *   size         Bar height in pixels                      (default 20)
 *   sizefactor   Module width in pixels                    (default 1)
 *   orientation  horizontal | vertical                     (default "horizontal")
 *   print        "true" to print the text under the bars   (default "false")
 *   caption      Custom text to print under the barcode instead of the data
 *   format       png | svg | jpeg | gif | webp             (default "png")
 *   fg, bg       Hex colors such as 000 or ff0000          (default black on white)
 *
 * The original "filepath" parameter, which let any visitor write files on the
 * server, is intentionally not supported. Use Barcode::save() from PHP instead.
 */

use PhpBarcode\Barcode;
use PhpBarcode\Exception\BarcodeException;
use PhpBarcode\Renderer\Orientation;
use PhpBarcode\Renderer\OutputFormat;
use PhpBarcode\Renderer\RenderOptions;

require is_file(__DIR__ . '/vendor/autoload.php') ? __DIR__ . '/vendor/autoload.php' : __DIR__ . '/src/autoload.php';

/** Limits that keep a public endpoint from being used to allocate huge images. */
const MAX_TEXT_LENGTH = 256;
const MAX_BAR_HEIGHT = 1000;
const MAX_MODULE_WIDTH = 20;

$param = static fn(string $name, string $default): string => is_string($_GET[$name] ?? null) ? $_GET[$name] : $default;

try {
    $text = $param('text', '0');
    if (strlen($text) > MAX_TEXT_LENGTH) {
        throw new InvalidArgumentException(sprintf('"text" may be at most %d characters.', MAX_TEXT_LENGTH));
    }

    $options = new RenderOptions(
        moduleWidth: min(MAX_MODULE_WIDTH, max(0.1, (float) $param('sizefactor', '1'))),
        barHeight: min(MAX_BAR_HEIGHT, max(1, (float) $param('size', '20'))),
        quietZone: 10,
        showText: $param('print', 'false') === 'true',
        orientation: Orientation::fromName($param('orientation', 'horizontal')),
    );
    $options = $options->withColors($param('fg', '000'), $param('bg', 'fff'));

    $caption = $param('caption', '');
    if (strlen($caption) > MAX_TEXT_LENGTH) {
        throw new InvalidArgumentException(sprintf('"caption" may be at most %d characters.', MAX_TEXT_LENGTH));
    }
    if ($caption !== '') {
        $options = $options->withCaption($caption);
    }

    $format = OutputFormat::fromName($param('format', 'png'));
    if ($format === OutputFormat::Text) {
        throw new InvalidArgumentException('Text output is not available over HTTP.');
    }

    header('Cache-Control: public, max-age=86400');
    Barcode::of($param('codetype', 'code128'), $text, $options)->output($format);
} catch (BarcodeException|InvalidArgumentException $e) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage(), "\n";
}
