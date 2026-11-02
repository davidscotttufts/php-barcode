<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Gs1;

use PhpBarcode\Gs1\DigitalLink;
use PhpBarcode\Gs1\ElementString;
use PhpBarcode\Gs1\Gs1Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DigitalLinkTest extends TestCase
{
    #[DataProvider('uris')]
    public function testBuildsUris(string $elements, string $uri): void
    {
        self::assertSame($uri, (new DigitalLink())->toUri($elements));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function uris(): iterable
    {
        yield 'GTIN only' => ['(01)09506000134352', 'https://id.gs1.org/01/09506000134352'];
        yield 'qualifiers reordered, attributes in query' => [
            '(17)251231(21)S1(10)ABC(01)09506000134352(3103)000500',
            'https://id.gs1.org/01/09506000134352/10/ABC/21/S1?17=251231&3103=000500',
        ];
        yield 'CPV qualifier first' => ['(01)09506000134352(21)S1(22)V2', 'https://id.gs1.org/01/09506000134352/22/V2/21/S1'];
        yield 'TPX alternative sequence' => ['(01)09506000134352(235)TPX1', 'https://id.gs1.org/01/09506000134352/235/TPX1'];
        yield 'SSCC' => ['(00)106141412345678908', 'https://id.gs1.org/00/106141412345678908'];
        yield 'GLN with extension' => ['(414)9506000134352(254)A1', 'https://id.gs1.org/414/9506000134352/254/A1'];
        yield 'percent-encoding' => ['(01)09506000134352(10)A/B%C', 'https://id.gs1.org/01/09506000134352/10/A%2FB%25C'];
    }

    public function testCustomDomain(): void
    {
        self::assertSame(
            'https://example.com/products/01/09506000134352',
            (new DigitalLink('https://example.com/products/'))->toUri('(01)09506000134352'),
        );
    }

    public function testRejectsInvalidDomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DigitalLink('ftp://example.com');
    }

    public function testRequiresAPrimaryKey(): void
    {
        $this->expectException(Gs1Exception::class);
        $this->expectExceptionMessage('needs a primary key');

        (new DigitalLink())->toUri(ElementString::parse('(10)ABC', checkAssociations: false));
    }

    public function testRejectsAisThatCannotBeAttributes(): void
    {
        $this->expectException(Gs1Exception::class);
        $this->expectExceptionMessage('AI (8200) PRODUCT URL cannot be expressed in a GS1 Digital Link URI');

        (new DigitalLink())->toUri('(01)09506000134352(8200)HTTP://X');
    }

    public function testParsesUris(): void
    {
        $elements = (new DigitalLink())->toElementString('https://example.com/shop/01/09506000134352/10/A%2FB/21/S1?17=251231&utm_source=x');

        self::assertSame('(01)09506000134352(10)A/B(21)S1(17)251231', $elements->toHri());
    }

    public function testRoundTrip(): void
    {
        $link = new DigitalLink();
        $uri = $link->toUri('(01)09506000134352(10)ABC(3103)000500(17)251231');

        self::assertSame('(01)09506000134352(10)ABC(3103)000500(17)251231', $link->toElementString($uri)->toHri());
    }

    #[DataProvider('invalidUris')]
    public function testRejectsInvalidUris(string $uri, string $message): void
    {
        $this->expectException(Gs1Exception::class);
        $this->expectExceptionMessage($message);

        (new DigitalLink())->toElementString($uri);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidUris(): iterable
    {
        yield 'no key' => ['https://id.gs1.org/foo/bar', 'no GS1 Digital Link primary key'];
        yield 'wrong qualifier order' => ['https://id.gs1.org/01/09506000134352/21/S1/10/ABC', 'are not allowed after AI (01) in that order'];
        yield 'mixed alternatives' => ['https://id.gs1.org/01/09506000134352/22/V/235/T', 'are not allowed after AI (01)'];
        yield 'bad check digit' => ['https://id.gs1.org/01/09506000134353', 'AI (01): invalid check digit'];
        yield 'dangling segment' => ['https://id.gs1.org/01/09506000134352/10', 'AI/value pairs'];
        yield 'not a URI' => ['01/09506000134352', 'is not a valid URI'];
    }
}
