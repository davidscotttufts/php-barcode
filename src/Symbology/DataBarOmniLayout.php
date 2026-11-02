<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

/**
 * The four presentations of GS1 DataBar Omnidirectional (ISO/IEC 24724 §5).
 */
enum DataBarOmniLayout: string
{
    /** Single row, at least 33X tall, readable in any direction. */
    case Omnidirectional = 'omnidirectional';

    /** Single row cut to 13X, for small items scanned by hand-held readers. */
    case Truncated = 'truncated';

    /** Two short rows (5X and 7X) for narrow items; not omnidirectionally scannable. */
    case Stacked = 'stacked';

    /** Two full-height rows (33X each) that remain omnidirectionally scannable. */
    case StackedOmnidirectional = 'stacked-omnidirectional';
}
