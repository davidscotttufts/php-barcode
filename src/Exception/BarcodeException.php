<?php

declare(strict_types=1);

namespace PhpBarcode\Exception;

/**
 * Marker interface implemented by every exception thrown by this library,
 * so callers can catch all barcode errors with a single `catch`.
 */
interface BarcodeException extends \Throwable {}
