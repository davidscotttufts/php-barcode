<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Symbology;

use PhpBarcode\Exception\InvalidDataException;
use PhpBarcode\Symbology\Code11;
use PhpBarcode\Symbology\Code32;
use PhpBarcode\Symbology\Code39Extended;
use PhpBarcode\Symbology\Code93;
use PhpBarcode\Symbology\FullAscii;
use PhpBarcode\Symbology\Iata2of5;
use PhpBarcode\Symbology\Industrial2of5;
use PhpBarcode\Symbology\MsiChecksum;
use PhpBarcode\Symbology\MsiPlessey;
use PhpBarcode\Symbology\Pharmacode;
use PhpBarcode\Symbology\Plessey;
use PhpBarcode\Symbology\Pzn;
use PhpBarcode\Symbology\SymbologyInterface;
use PhpBarcode\Symbology\Telepen;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IndustrialSymbologyTest extends TestCase
{
    public function testCode93AppendsBothCheckCharactersAndUpperCases(): void
    {
        $symbol = (new Code93())->encode('test93');

        self::assertSame('TEST93', $symbol->text());
        // Start + 6 data + C + K + stop: 9 modules each, plus the 1-module termination bar.
        self::assertSame(9 * 10 + 1, strlen($symbol->toGrid()[0]));
    }

    public function testCode93FullAsciiUsesDedicatedShifts(): void
    {
        self::assertSame("\x04A", FullAscii::code93(ord('a')));
        self::assertSame('$', FullAscii::code93(ord('$')));
        self::assertSame("\x03A", FullAscii::code93(ord('!')));
        self::assertSame('Hello', (new Code93(fullAscii: true))->encode('Hello')->text());
        self::assertSame('Code 93 Extended', (new Code93(true))->name());
    }

    public function testCode39FullAsciiTable(): void
    {
        self::assertSame('%U', FullAscii::code39(0));
        self::assertSame('$A', FullAscii::code39(1));
        self::assertSame('%E', FullAscii::code39(31));
        self::assertSame('/A', FullAscii::code39(ord('!')));
        self::assertSame('/O', FullAscii::code39(ord('/')));
        self::assertSame('/Z', FullAscii::code39(ord(':')));
        self::assertSame('%V', FullAscii::code39(ord('@')));
        self::assertSame('%W', FullAscii::code39(ord('`')));
        self::assertSame('+Z', FullAscii::code39(ord('z')));
        self::assertSame('%T', FullAscii::code39(127));
        self::assertSame('-', FullAscii::code39(ord('-')));
    }

    public function testCode39ExtendedKeepsOriginalText(): void
    {
        $symbol = (new Code39Extended())->encode("Hi!\t");

        self::assertSame('Hi!', rtrim($symbol->text()));
        self::assertSame('Code 39 Extended', $symbol->symbology());
    }

    public function testCode11CheckDigits(): void
    {
        self::assertSame('123-455', (new Code11())->encode('123-45')->text(), 'one check digit up to 10 characters');
        self::assertSame('1234567890-' . Code11::check('1234567890-', 10), substr((new Code11())->encode('1234567890-')->text(), 0, 12));
        self::assertSame(13, strlen((new Code11())->encode('1234567890-')->text()), 'two check digits above 10 characters');
        self::assertSame('123-45', (new Code11(0))->encode('123-45')->text());
    }

    public function testMsiCheckDigits(): void
    {
        self::assertSame('4', MsiPlessey::mod10('1234567'));
        self::assertSame('12345674', (new MsiPlessey())->encode('1234567')->text());
        self::assertSame('610', (new MsiPlessey(MsiChecksum::Mod11))->encode('6')->text(), 'mod 11 result 10 is written as "10"');
    }

    public function testPharmacode(): void
    {
        // 3 = two narrow bars, 6 = two wide bars (1-module narrow, 3-module wide, 2-module spaces).
        self::assertSame(['1001'], (new Pharmacode())->encode('3')->toGrid());
        self::assertSame(['11100111'], (new Pharmacode())->encode('6')->toGrid());
        self::assertSame('1234', (new Pharmacode())->encode('001234')->text());
    }

    public function testTelepenCharacterEncodingMatchesReferenceTable(): void
    {
        // Entries from Zint's TeleTable.
        self::assertSame('31313131', Telepen::character(0x00));
        self::assertSame('1131313111', Telepen::character(0x01));
        self::assertSame('33313111', Telepen::character(0x02));
        self::assertSame('1311133111', Telepen::character(0x0E));
        self::assertSame('111111111133', Telepen::character(0x5F));
        self::assertSame('331111111111', Telepen::character(0x7A));
        self::assertSame(str_repeat('11', 8), Telepen::character(0x7F));
    }

    public function testTelepenNumericPadsOddLength(): void
    {
        self::assertSame('01234567', (new Telepen(true))->encode('1234567')->text());
        self::assertFalse((new Telepen(true))->supports('X1'), '"X" may only be the second digit of a pair');
    }

    public function testCode32(): void
    {
        $symbol = (new Code32())->encode('12345678');

        self::assertSame('A123456788', $symbol->text());
        self::assertSame('8', Code32::check('12345678'));
        self::assertSame('A000012346', (new Code32())->encode('1234')->text(), 'zero-filled to 8 digits');
        self::assertFalse((new Code32())->supports('123456789'));
    }

    public function testPzn(): void
    {
        self::assertSame('PZN - 12345626', (new Pzn())->encode('1234562')->text());
        self::assertSame('PZN - 12345626', (new Pzn())->encode('PZN 12345626')->text());
        self::assertNull(Pzn::check('0000003'), 'check digit 10 is never issued');
        self::assertFalse((new Pzn())->supports('0000003'));
    }

    public function testDiscrete2of5Variants(): void
    {
        $industrial = (new Industrial2of5())->encode('12');
        $iata = (new Iata2of5())->encode('12');

        self::assertSame('Industrial 2 of 5', $industrial->symbology());
        self::assertSame('IATA 2 of 5', $iata->symbology());
        self::assertNotSame($industrial->toGrid(), $iata->toGrid());
        self::assertSame('123', (new Industrial2of5(withChecksum: true))->encode('12')->text());
    }

    public function testPlesseyUpperCasesHex(): void
    {
        self::assertSame('ABC123', (new Plessey())->encode('abc123')->text());
    }

    /**
     * @return iterable<string, array{SymbologyInterface, string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'code93 lower in standard mode accepted, but not "!"' => [new Code93(), 'A!B'];
        yield 'code93 extended rejects 8-bit' => [new Code93(true), "caf\xC3\xA9"];
        yield 'code39 extended rejects 8-bit' => [new Code39Extended(), "\xFF"];
        yield 'code11 letters' => [new Code11(), '12A'];
        yield 'msi letters' => [new MsiPlessey(), '12A'];
        yield 'industrial letters' => [new Industrial2of5(), '12A'];
        yield 'pharmacode too small' => [new Pharmacode(), '2'];
        yield 'pharmacode too large' => [new Pharmacode(), '131071'];
        yield 'telepen 8-bit' => [new Telepen(), "\x80"];
        yield 'telepen numeric letters' => [new Telepen(true), '12AB'];
        yield 'code32 wrong check' => [new Code32(), '123456780'];
        yield 'pzn wrong check' => [new Pzn(), '12345627'];
        yield 'plessey non-hex' => [new Plessey(), '12G'];
        yield 'empty' => [new Code93(), ''];
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidInput(SymbologyInterface $symbology, string $data): void
    {
        $this->expectException(InvalidDataException::class);
        $symbology->encode($data);
    }

    public function testWideRatioIsValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Code11(wideRatio: 4);
    }
}
