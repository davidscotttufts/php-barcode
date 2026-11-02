<?php

declare(strict_types=1);

namespace PhpBarcode;

use PhpBarcode\Exception\UnknownSymbologyException;
use PhpBarcode\Symbology\AustraliaPost;
use PhpBarcode\Symbology\AztecCode;
use PhpBarcode\Symbology\AztecRune;
use PhpBarcode\Symbology\Codabar;
use PhpBarcode\Symbology\Code11;
use PhpBarcode\Symbology\Code128;
use PhpBarcode\Symbology\Code128Subset;
use PhpBarcode\Symbology\Code32;
use PhpBarcode\Symbology\Code39;
use PhpBarcode\Symbology\Code39Extended;
use PhpBarcode\Symbology\Code93;
use PhpBarcode\Symbology\DataBarExpanded;
use PhpBarcode\Symbology\DataBarLimited;
use PhpBarcode\Symbology\DataBarOmni;
use PhpBarcode\Symbology\DataBarOmniLayout;
use PhpBarcode\Symbology\DataMatrix;
use PhpBarcode\Symbology\Ean13;
use PhpBarcode\Symbology\Ean2;
use PhpBarcode\Symbology\Ean5;
use PhpBarcode\Symbology\Ean8;
use PhpBarcode\Symbology\Gs1;
use PhpBarcode\Symbology\Gs1128;
use PhpBarcode\Symbology\Iata2of5;
use PhpBarcode\Symbology\Industrial2of5;
use PhpBarcode\Symbology\Interleaved2of5;
use PhpBarcode\Symbology\Isbn;
use PhpBarcode\Symbology\Ismn;
use PhpBarcode\Symbology\Issn;
use PhpBarcode\Symbology\Itf14;
use PhpBarcode\Symbology\JapanPost;
use PhpBarcode\Symbology\Kix;
use PhpBarcode\Symbology\MaxiCode;
use PhpBarcode\Symbology\MicroPdf417;
use PhpBarcode\Symbology\MicroQrCode;
use PhpBarcode\Symbology\MsiPlessey;
use PhpBarcode\Symbology\Pdf417;
use PhpBarcode\Symbology\Pharmacode;
use PhpBarcode\Symbology\Planet;
use PhpBarcode\Symbology\Plessey;
use PhpBarcode\Symbology\Postnet;
use PhpBarcode\Symbology\Pzn;
use PhpBarcode\Symbology\QrCode;
use PhpBarcode\Symbology\RectangularMicroQrCode;
use PhpBarcode\Symbology\RoyalMail4State;
use PhpBarcode\Symbology\RoyalMailMailmark;
use PhpBarcode\Symbology\SymbologyInterface;
use PhpBarcode\Symbology\Telepen;
use PhpBarcode\Symbology\UpcA;
use PhpBarcode\Symbology\UpcE;
use PhpBarcode\Symbology\UspsIntelligentMail;

/**
 * Every built-in barcode type, by the name accepted by Barcode::of(), the CLI
 * and the HTTP endpoint's "codetype" parameter.
 *
 * Each case builds its symbology with default settings. For options (error
 * correction, checksums, sizes, …) construct the symbology class directly
 * and pass it to Barcode::create().
 */
enum BarcodeType: string
{
    // Two-dimensional
    case QrCode = 'qr';
    case Gs1QrCode = 'gs1qr';
    case MicroQrCode = 'microqr';
    case RectangularMicroQrCode = 'rmqr';
    case DataMatrix = 'datamatrix';
    case Gs1DataMatrix = 'gs1datamatrix';
    case Aztec = 'aztec';
    case Gs1Aztec = 'gs1aztec';
    case AztecRune = 'aztecrune';
    case MaxiCode = 'maxicode';
    case Pdf417 = 'pdf417';
    case Pdf417Compact = 'pdf417compact';
    case MicroPdf417 = 'micropdf417';

    // Retail
    case Ean13 = 'ean13';
    case Ean8 = 'ean8';
    case UpcA = 'upca';
    case UpcE = 'upce';
    case Ean2 = 'ean2';
    case Ean5 = 'ean5';
    case Isbn = 'isbn';
    case Issn = 'issn';
    case Ismn = 'ismn';

    // GS1 logistics
    case Gs1128 = 'gs1128';
    case Itf14 = 'itf14';
    case DataBarOmni = 'databar';
    case DataBarTruncated = 'databartruncated';
    case DataBarStacked = 'databarstacked';
    case DataBarStackedOmni = 'databarstackedomni';
    case DataBarLimited = 'databarlimited';
    case DataBarExpanded = 'databarexpanded';
    case DataBarExpandedStacked = 'databarexpandedstacked';

    // General purpose and industrial
    case Code128 = 'code128';
    case Code128A = 'code128a';
    case Code128B = 'code128b';
    case Code128C = 'code128c';
    case Code39 = 'code39';
    case Code39Extended = 'code39ext';
    case Code93 = 'code93';
    case Code93Extended = 'code93ext';
    case Interleaved2of5 = 'itf';
    case Industrial2of5 = 'industrial2of5';
    case Iata2of5 = 'iata2of5';
    case Codabar = 'codabar';
    case Code11 = 'code11';
    case MsiPlessey = 'msi';
    case Plessey = 'plessey';
    case Telepen = 'telepen';
    case TelepenNumeric = 'telepennumeric';

    // Pharmaceutical
    case Pharmacode = 'pharmacode';
    case Code32 = 'code32';
    case Pzn = 'pzn';

    // Postal
    case IntelligentMail = 'imb';
    case Postnet = 'postnet';
    case Planet = 'planet';
    case RoyalMail4State = 'rm4scc';
    case Mailmark = 'mailmark';
    case Kix = 'kix';
    case AustraliaPost = 'auspost';
    case JapanPost = 'japanpost';

    private const ALIASES = [
        'qrcode' => self::QrCode,
        'mqr' => self::MicroQrCode,
        'rectangularmicroqr' => self::RectangularMicroQrCode,
        'dm' => self::DataMatrix,
        'ecc200' => self::DataMatrix,
        'gs1dm' => self::Gs1DataMatrix,
        'azteccode' => self::Aztec,
        'rune' => self::AztecRune,
        'maxi' => self::MaxiCode,
        'ups' => self::MaxiCode,
        'pdf' => self::Pdf417,
        'truncatedpdf417' => self::Pdf417Compact,
        'micropdf' => self::MicroPdf417,
        'upc' => self::UpcA,
        'ean128' => self::Gs1128,
        'ucc128' => self::Gs1128,
        'gtin14' => self::Itf14,
        'rss14' => self::DataBarOmni,
        'databaromni' => self::DataBarOmni,
        'rsslimited' => self::DataBarLimited,
        'rssexpanded' => self::DataBarExpanded,
        'code25' => self::Interleaved2of5, // the original script's name for ITF
        'i25' => self::Interleaved2of5,
        'interleaved2of5' => self::Interleaved2of5,
        'code3of9' => self::Code39,
        'code39fullascii' => self::Code39Extended,
        'code93fullascii' => self::Code93Extended,
        'standard2of5' => self::Industrial2of5,
        'industrial25' => self::Industrial2of5,
        'nw7' => self::Codabar,
        'msiplessey' => self::MsiPlessey,
        'pharma' => self::Pharmacode,
        'italianpharmacode' => self::Code32,
        'uspsimb' => self::IntelligentMail,
        'intelligentmail' => self::IntelligentMail,
        'onecode' => self::IntelligentMail,
        'royalmail' => self::RoyalMail4State,
        'australiapost' => self::AustraliaPost,
    ];

    /**
     * Resolves a type name case-insensitively, ignoring "-", "_" and spaces,
     * so "EAN-13", "ean_13" and "ean13" are equivalent.
     *
     * @throws UnknownSymbologyException
     */
    public static function fromName(string $name): self
    {
        $key = strtolower(str_replace(['-', '_', ' '], '', $name));

        return self::tryFrom($key)
            ?? self::ALIASES[$key]
            ?? throw UnknownSymbologyException::forName($name, array_column(self::cases(), 'value'));
    }

    public function symbology(): SymbologyInterface
    {
        return match ($this) {
            self::QrCode => new QrCode(),
            self::Gs1QrCode => new Gs1(new QrCode(gs1: true)),
            self::MicroQrCode => new MicroQrCode(),
            self::RectangularMicroQrCode => new RectangularMicroQrCode(),
            self::DataMatrix => new DataMatrix(),
            self::Gs1DataMatrix => new Gs1(new DataMatrix(gs1: true)),
            self::Aztec => new AztecCode(),
            self::Gs1Aztec => new Gs1(new AztecCode(gs1: true)),
            self::AztecRune => new AztecRune(),
            self::MaxiCode => new MaxiCode(),
            self::Pdf417 => new Pdf417(),
            self::Pdf417Compact => new Pdf417(compact: true),
            self::MicroPdf417 => new MicroPdf417(),
            self::Ean13 => new Ean13(),
            self::Ean8 => new Ean8(),
            self::UpcA => new UpcA(),
            self::UpcE => new UpcE(),
            self::Ean2 => new Ean2(),
            self::Ean5 => new Ean5(),
            self::Isbn => new Isbn(),
            self::Issn => new Issn(),
            self::Ismn => new Ismn(),
            self::Gs1128 => new Gs1128(),
            self::Itf14 => new Itf14(),
            self::DataBarOmni => new DataBarOmni(),
            self::DataBarTruncated => new DataBarOmni(DataBarOmniLayout::Truncated),
            self::DataBarStacked => new DataBarOmni(DataBarOmniLayout::Stacked),
            self::DataBarStackedOmni => new DataBarOmni(DataBarOmniLayout::StackedOmnidirectional),
            self::DataBarLimited => new DataBarLimited(),
            self::DataBarExpanded => new DataBarExpanded(),
            self::DataBarExpandedStacked => new DataBarExpanded(segmentsPerRow: 4),
            self::Code128 => new Code128(),
            self::Code128A => new Code128(Code128Subset::A),
            self::Code128B => new Code128(Code128Subset::B),
            self::Code128C => new Code128(Code128Subset::C),
            self::Code39 => new Code39(),
            self::Code39Extended => new Code39Extended(),
            self::Code93 => new Code93(),
            self::Code93Extended => new Code93(fullAscii: true),
            self::Interleaved2of5 => new Interleaved2of5(),
            self::Industrial2of5 => new Industrial2of5(),
            self::Iata2of5 => new Iata2of5(),
            self::Codabar => new Codabar(),
            self::Code11 => new Code11(),
            self::MsiPlessey => new MsiPlessey(),
            self::Plessey => new Plessey(),
            self::Telepen => new Telepen(),
            self::TelepenNumeric => new Telepen(numeric: true),
            self::Pharmacode => new Pharmacode(),
            self::Code32 => new Code32(),
            self::Pzn => new Pzn(),
            self::IntelligentMail => new UspsIntelligentMail(),
            self::Postnet => new Postnet(),
            self::Planet => new Planet(),
            self::RoyalMail4State => new RoyalMail4State(),
            self::Mailmark => new RoyalMailMailmark(),
            self::Kix => new Kix(),
            self::AustraliaPost => new AustraliaPost(),
            self::JapanPost => new JapanPost(),
        };
    }
}
