<?php

declare(strict_types=1);

namespace PhpBarcode\Symbology;

/**
 * Australia Post barcode formats, identified by their Format Control Code (FCC).
 */
enum AustraliaPostFormat: string
{
    /** 37 bars: DPID only. */
    case StandardCustomer = '11';

    /** 52 bars: DPID plus up to 8 digits or 5 characters of customer information. */
    case Customer2 = '59';

    /** 67 bars: DPID plus up to 15 digits or 10 characters of customer information. */
    case Customer3 = '62';

    /** 37 bars: Reply Paid. */
    case ReplyPaid = '45';

    /** 37 bars: Routing. */
    case Routing = '87';

    /** 37 bars: Redirection. */
    case Redirection = '92';

    /**
     * Bars available for customer information, after start, FCC and DPID.
     */
    public function customerInformationBars(): int
    {
        return match ($this) {
            self::Customer2 => 16,
            self::Customer3 => 31,
            default => 0,
        };
    }

    /**
     * Total data bars before error correction (start + FCC + DPID + customer info + filler):
     * always a whole number of 3-bar Reed–Solomon symbols after the 2 start bars.
     */
    public function dataBars(): int
    {
        return match ($this) {
            self::Customer2 => 38,
            self::Customer3 => 53,
            default => 23,
        };
    }
}
