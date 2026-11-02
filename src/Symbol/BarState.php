<?php

declare(strict_types=1);

namespace PhpBarcode\Symbol;

/**
 * The four bar shapes of height-modulated ("4-state") postal barcodes.
 *
 * The letters are the industry-standard DAFT notation used by USPS,
 * Royal Mail, PostNL, Australia Post and Japan Post specifications.
 */
enum BarState: string
{
    /** Spans the full height: ascender + tracker + descender. */
    case Full = 'F';

    /** Tracker plus the part above it. */
    case Ascender = 'A';

    /** Tracker plus the part below it. */
    case Descender = 'D';

    /** The short central section only. */
    case Tracker = 'T';

    public function hasAscender(): bool
    {
        return $this === self::Full || $this === self::Ascender;
    }

    public function hasDescender(): bool
    {
        return $this === self::Full || $this === self::Descender;
    }
}
