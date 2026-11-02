<?php

declare(strict_types=1);

/*
 * Stand-alone autoloader for projects that don't use Composer:
 *
 *     require 'path/to/php-barcode/src/autoload.php';
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'PhpBarcode\\';

    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require_once __DIR__ . '/functions.php';
