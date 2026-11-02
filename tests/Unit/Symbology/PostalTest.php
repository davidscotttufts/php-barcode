<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbol\HeightModulatedSymbol;
use PhpBarcode\Symbology\AustraliaPost;
use PhpBarcode\Symbology\AustraliaPostFormat;
use PhpBarcode\Symbology\Daft;
use PhpBarcode\Symbology\JapanPost;
use PhpBarcode\Symbology\Kix;
use PhpBarcode\Symbology\Planet;
use PhpBarcode\Symbology\Postnet;
use PhpBarcode\Symbology\RoyalMail4State;
use PhpBarcode\Symbology\RoyalMailMailmark;
use PhpBarcode\Symbology\SymbologyInterface;
use PhpBarcode\Symbology\UspsIntelligentMail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden-vector tests for the height-modulated postal symbologies.
 *
 * Vectors come from the published specifications (USPS-B-3200, USPS
 * Publications 25/197, PostNL KIX handbook, Australia Post and Japan Post
 * guides, Royal Mail Mailmark definition documents) as collected in Zint's
 * test suite, and from the official USPS IMb encoder test cases.
 */
final class PostalTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/fixtures';

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function publishedVectors(): iterable
    {
        /** @var list<array{type: string, data: string, states: string, source: string}> $vectors */
        $vectors = json_decode((string) file_get_contents(self::FIXTURES . '/postal-vectors.json'), true, flags: JSON_THROW_ON_ERROR);

        foreach ($vectors as $vector) {
            yield sprintf('%s %s', $vector['type'], $vector['data']) => [$vector['type'], $vector['data'], $vector['states']];
        }
    }

    #[DataProvider('publishedVectors')]
    public function testPublishedVectors(string $type, string $data, string $expected): void
    {
        self::assertSame($expected, self::encode(self::symbology($type), $data)->toStates());
    }

    public function testIntelligentMailSpecificationExample(): void
    {
        // USPS-B-3200 Rev. H, Figure 5.
        self::assertSame(
            'AADTFFDFTDADTAADAATFDTDDAAADDTDTTDAFADADDDTFFFDDTTTADFAAADFTDAADA',
            self::encode(new UspsIntelligentMail(), '01234567094987654321-01234567891')->toStates(),
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function uspsEncoderTestCases(): iterable
    {
        $file = new \SplFileObject(self::FIXTURES . '/usps-imb-encoder-test-cases.csv');
        while (!$file->eof()) {
            $row = $file->fgetcsv(escape: '');
            if (!is_array($row) || count($row) < 5 || str_starts_with((string) $row[0], '#')) {
                continue;
            }
            yield 'USPS case ' . $row[0] => [(string) $row[1], (string) $row[2], (string) $row[3]];
        }
    }

    #[DataProvider('uspsEncoderTestCases')]
    public function testUspsEncoderTestCases(string $tracking, string $routing, string $expected): void
    {
        $imb = new UspsIntelligentMail();

        if ($expected === '') {
            self::assertFalse($imb->supports($tracking . '-' . $routing));

            return;
        }

        self::assertSame($expected, self::encode($imb, $tracking . '-' . $routing)->toStates());
    }

    public function testIntelligentMailAcceptsConcatenatedOrSpacedInput(): void
    {
        $imb = new UspsIntelligentMail();
        $expected = self::encode($imb, '01234567094987654321-01234567891')->toStates();

        self::assertSame($expected, self::encode($imb, '0123456709498765432101234567891')->toStates());
        self::assertSame($expected, self::encode($imb, '01234567094987654321 01234567891')->toStates());
        self::assertSame('01234567094987654321-01234567891', self::encode($imb, '0123456709498765432101234567891')->text());
    }

    public function testPostnetUsesFrameBarsAndCheckDigit(): void
    {
        $symbol = self::encode(new Postnet(), '12345');

        // Frame + 6 digits × 5 + frame.
        self::assertCount(32, $symbol->bars);
        self::assertSame('F', $symbol->toStates()[0]);
        self::assertSame('F', $symbol->toStates()[-1]);
        // Check digit for 1+2+3+4+5 = 15 is 5 → "01010".
        self::assertSame('TFTFT', substr($symbol->toStates(), 26, 5));
    }

    public function testRoyalMailIgnoresCaseAndSpaces(): void
    {
        self::assertSame(
            self::encode(new RoyalMail4State(), 'BX11LT1A')->toStates(),
            self::encode(new RoyalMail4State(), 'bx11 lt1a')->toStates(),
        );
    }

    public function testAustraliaPostPadsShortDpid(): void
    {
        self::assertSame(
            self::encode(new AustraliaPost(), '00001234')->toStates(),
            self::encode(new AustraliaPost(), '1234')->toStates(),
        );
    }

    public function testMailmarkPadsShortInput(): void
    {
        self::assertSame(
            self::encode(new RoyalMailMailmark(), '1100000000000XY11     ')->toStates(),
            self::encode(new RoyalMailMailmark(), '1100000000000XY11')->toStates(),
        );
    }

    public function testDaftPassesStatesThrough(): void
    {
        self::assertSame('FADTTDAF', self::encode(new Daft(), 'fadt tdaf')->toStates());
    }

    /**
     * @return iterable<string, array{SymbologyInterface, string}>
     */
    public static function invalidInput(): iterable
    {
        yield 'POSTNET wrong length' => [new Postnet(), '1234'];
        yield 'POSTNET letters' => [new Postnet(), '1234A'];
        yield 'PLANET wrong length' => [new Planet(), '12345'];
        yield 'IMb short tracking code' => [new UspsIntelligentMail(), '1234567890123456789'];
        yield 'IMb 4-digit routing code' => [new UspsIntelligentMail(), '12345678901234567890-1234'];
        yield 'IMb barcode identifier > 4' => [new UspsIntelligentMail(), '15345678901234567890'];
        yield 'RM4SCC punctuation' => [new RoyalMail4State(), 'AB-12'];
        yield 'KIX too long' => [new Kix(), '1234567890ABCDEFGHI'];
        yield 'Japan Post too long' => [new JapanPost(), '1234567ABCDEFG'];
        yield 'Japan Post punctuation' => [new JapanPost(), '123/456'];
        yield 'Australia Post letters in DPID' => [new AustraliaPost(), '1234ABCD'];
        yield 'Australia Post invalid character' => [new AustraliaPost(), '12345678AB!'];
        yield 'Australia Post too much customer info' => [new AustraliaPost(AustraliaPostFormat::Customer2), '12345678ABCDEF'];
        yield 'Australia Post info on reply paid' => [new AustraliaPost(AustraliaPostFormat::ReplyPaid), '123456781'];
        yield 'Mailmark too short' => [new RoyalMailMailmark(), '1100000000'];
        yield 'Mailmark bad format' => [new RoyalMailMailmark(), '5100000000000XY11     '];
        yield 'Mailmark bad postcode' => [new RoyalMailMailmark(), '11000000000001A1A1A1A1'];
        yield 'DAFT other letters' => [new Daft(), 'FADX'];
    }

    #[DataProvider('invalidInput')]
    public function testRejectsInvalidInput(SymbologyInterface $symbology, string $data): void
    {
        $this->expectException(InvalidDataException::class);
        $symbology->encode($data);
    }

    private static function symbology(string $type): SymbologyInterface
    {
        return match ($type) {
            'POSTNET' => new Postnet(),
            'PLANET' => new Planet(),
            'RM4SCC' => new RoyalMail4State(),
            'KIX' => new Kix(),
            'JAPANPOST' => new JapanPost(),
            'AUSPOST' => new AustraliaPost(),
            'AUSREPLY' => new AustraliaPost(AustraliaPostFormat::ReplyPaid),
            'AUSROUTE' => new AustraliaPost(AustraliaPostFormat::Routing),
            'AUSREDIRECT' => new AustraliaPost(AustraliaPostFormat::Redirection),
            'MAILMARK_4S' => new RoyalMailMailmark(),
            default => throw new \LogicException('Unknown fixture type ' . $type),
        };
    }

    private static function encode(SymbologyInterface $symbology, string $data): HeightModulatedSymbol
    {
        $symbol = $symbology->encode($data);
        self::assertInstanceOf(HeightModulatedSymbol::class, $symbol);

        return $symbol;
    }
}
