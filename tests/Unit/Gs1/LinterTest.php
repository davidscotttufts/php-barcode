<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Gs1;

use PhpBarcode\Gs1\Linter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinterTest extends TestCase
{
    #[DataProvider('cases')]
    public function testLinter(string $linter, string $value, ?int $errorOffset): void
    {
        $result = Linter::check($linter, $value);

        self::assertSame($errorOffset, $result === null ? null : $result[0], $result[1] ?? 'valid');
    }

    /**
     * @return iterable<string, array{string, string, ?int}>
     */
    public static function cases(): iterable
    {
        yield 'csum ok' => ['csum', '09506000134352', null];
        yield 'csum bad' => ['csum', '09506000134353', 13];
        yield 'csumalpha ok' => ['csumalpha', '1987654Ad4X4bL5ttr2310c2K', null];
        yield 'csumalpha bad' => ['csumalpha', '1987654Ad4X4bL5ttr2310c2X', 23];
        yield 'csumalpha minimal' => ['csumalpha', '22', null];
        yield 'gcppos1 ok' => ['gcppos1', '9506000', null];
        yield 'gcppos1 too short' => ['gcppos1', '950', 0];
        yield 'gcppos2 skips indicator' => ['gcppos2', '19506', null];
        yield 'yymmdd ok' => ['yymmdd', '251231', null];
        yield 'yymmdd zero day' => ['yymmdd', '251200', 4];
        yield 'yymmd0 zero day ok' => ['yymmd0', '251200', null];
        yield 'yymmd0 month 00' => ['yymmd0', '250001', 2];
        yield 'yymmdd 30 Feb' => ['yymmdd', '250230', 4];
        yield 'yyyymmdd leap year 2000' => ['yyyymmdd', '20000229', null];
        yield 'yyyymmdd 1900 is not leap' => ['yyyymmdd', '19000229', 6];
        yield 'hhmi ok' => ['hhmi', '2359', null];
        yield 'hhmi bad minute' => ['hhmi', '2360', 2];
        yield 'hh bad' => ['hh', '24', 0];
        yield 'ss ok' => ['ss', '59', null];
        yield 'iso3166 ok' => ['iso3166', '840', null];
        yield 'iso3166 bad' => ['iso3166', '999', 0];
        yield 'iso3166999' => ['iso3166999', '999', null];
        yield 'iso3166alpha2 ok' => ['iso3166alpha2', 'DE', null];
        yield 'iso3166alpha2 bad' => ['iso3166alpha2', 'XX', 0];
        yield 'iso4217 ok' => ['iso4217', '978', null];
        yield 'iso4217 bad' => ['iso4217', '000', 0];
        yield 'iso5218' => ['iso5218', '9', null];
        yield 'iso5218 bad' => ['iso5218', '3', 0];
        yield 'mediatype ok' => ['mediatype', '10', null];
        yield 'mediatype reserved' => ['mediatype', '11', 0];
        yield 'mediatype local' => ['mediatype', '80', null];
        yield 'packagetype ok' => ['packagetype', 'BX', null];
        yield 'packagetype bad' => ['packagetype', 'QQQ', 0];
        yield 'yesno' => ['yesno', '1', null];
        yield 'winding bad' => ['winding', '2', 0];
        yield 'zero' => ['zero', '0', null];
        yield 'nonzero bad' => ['nonzero', '0000', 0];
        yield 'nozeroprefix bad' => ['nozeroprefix', '0123', 0];
        yield 'hyphen' => ['hyphen', '-', null];
        yield 'hasnondigit bad' => ['hasnondigit', '12345', 0];
        yield 'pieceoftotal ok' => ['pieceoftotal', '0102', null];
        yield 'pieceoftotal zero piece' => ['pieceoftotal', '0002', 0];
        yield 'pieceoftotal zero total' => ['pieceoftotal', '0100', 2];
        yield 'posinseqslash ok' => ['posinseqslash', '2/3', null];
        yield 'posinseqslash exceeds' => ['posinseqslash', '4/3', 0];
        yield 'posinseqslash leading zero' => ['posinseqslash', '01/3', 0];
        yield 'importeridx' => ['importeridx', '_', null];
        yield 'importeridx bad' => ['importeridx', '!', 0];
        yield 'latitude max' => ['latitude', '1800000000', null];
        yield 'latitude over' => ['latitude', '1800000001', 0];
        yield 'longitude over' => ['longitude', '3600000001', 0];
        yield 'pcenc ok' => ['pcenc', 'A%20B', null];
        yield 'pcenc truncated' => ['pcenc', 'AB%2', 2];
        yield 'pcenc not hex' => ['pcenc', '%GG', 0];
        yield 'iban ok' => ['iban', 'DE89370400440532013000', null];
        yield 'iban bad country' => ['iban', 'XX89370400440532013000', 0];
    }

    public function testUnknownLinterIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);
        Linter::check('nope', 'x');
    }
}
