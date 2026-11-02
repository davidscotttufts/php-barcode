<?php

declare(strict_types=1);

use PhpBarcode\Barcode;
use PhpBarcode\BarcodeType;
use PhpBarcode\Renderer\Orientation;
use PhpBarcode\Renderer\OutputFormat;
use PhpBarcode\Renderer\RenderOptions;

if (!function_exists('barcode')) {
    /**
     * Backward-compatible wrapper with the original script's signature.
     *
     * Renders a PNG to $filepath, or straight to the browser when $filepath is
     * empty. New code should use the PhpBarcode\Barcode class instead.
     *
     * @deprecated since 2.0, use PhpBarcode\Barcode
     */
    function barcode(
        string $filepath = '',
        string $text = '0',
        int|string $size = 20,
        string $orientation = 'horizontal',
        string $code_type = 'code128',
        bool $print = false,
        int|float|string $SizeFactor = 1,
    ): void {
        $type = BarcodeType::fromName($code_type);

        // The original upper-cased Code 128A input, which has no lower case.
        if ($type === BarcodeType::Code128A) {
            $text = strtoupper($text);
        }

        $barcode = Barcode::of($type, $text, new RenderOptions(
            moduleWidth: (float) $SizeFactor,
            barHeight: (float) $size,
            quietZone: 10,
            showText: $print,
            orientation: Orientation::fromName($orientation),
        ));

        if ($filepath === '') {
            $barcode->output(OutputFormat::Png);
        } else {
            $barcode->save($filepath, OutputFormat::Png);
        }
    }
}
