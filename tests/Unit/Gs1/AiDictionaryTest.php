<?php

declare(strict_types=1);

namespace PhpBarcode\Tests\Unit\Gs1;

use PhpBarcode\Gs1\AiDictionary;
use PhpBarcode\Gs1\CharacterSet;
use PhpBarcode\Gs1\Gs1Exception;
use PHPUnit\Framework\TestCase;

final class AiDictionaryTest extends TestCase
{
    public function testContainsTheFullAiTable(): void
    {
        $all = AiDictionary::default()->all();

        self::assertGreaterThan(500, count($all));
        foreach (['00', '01', '10', '17', '21', '3103', '3929', '414', '8003', '8030', '8200', '90', '99'] as $code) {
            self::assertArrayHasKey($code, $all);
        }
    }

    public function testCodesArePrefixFree(): void
    {
        $codes = array_keys(AiDictionary::default()->all());
        foreach ($codes as $a) {
            foreach ($codes as $b) {
                if ($a !== $b) {
                    self::assertFalse(str_starts_with((string) $b, (string) $a), sprintf('%s is a prefix of %s', $a, $b));
                }
            }
        }
    }

    public function testPredefinedLengthAisAreFixedLength(): void
    {
        foreach (AiDictionary::default()->all() as $ai) {
            if ($ai->predefinedLength) {
                self::assertTrue($ai->isFixedLength(), sprintf('AI (%s) is predefined but variable', $ai->code));
            }
        }
    }

    public function testDescribesAnAi(): void
    {
        $gtin = AiDictionary::default()->get('01');

        self::assertSame('GTIN', $gtin->title);
        self::assertSame('N14', $gtin->format());
        self::assertSame(CharacterSet::Numeric, $gtin->components[0]->characterSet);
        self::assertSame(['csum', 'gcppos2'], $gtin->components[0]->linters);
        self::assertFalse($gtin->requiresSeparator());
        self::assertTrue($gtin->isDigitalLinkPrimaryKey());
        self::assertSame([['22', '10', '21'], ['235']], $gtin->digitalLinkQualifiers);

        $batch = AiDictionary::default()->get('10');
        self::assertSame('X..20', $batch->format());
        self::assertTrue($batch->requiresSeparator());
        self::assertSame(1, $batch->minLength());
        self::assertSame(20, $batch->maxLength());

        self::assertSame('N3+N..15', AiDictionary::default()->get('3932')->format());
        self::assertSame('N13+[X..17]', AiDictionary::default()->get('253')->format());
    }

    public function testMatchesPatterns(): void
    {
        $ai = AiDictionary::default()->get('3103');

        self::assertTrue($ai->matches('310n'));
        self::assertTrue($ai->matches('31nn'));
        self::assertFalse($ai->matches('311n'));
        self::assertFalse($ai->matches('31'));
    }

    public function testMatchPrefix(): void
    {
        $dictionary = AiDictionary::default();

        self::assertSame('01', $dictionary->matchPrefix('0109506000134352')?->code);
        self::assertSame('3103', $dictionary->matchPrefix('XX3103000500', 2)?->code);
        self::assertNull($dictionary->matchPrefix('2312'));
    }

    public function testUnknownAi(): void
    {
        $this->expectException(Gs1Exception::class);
        $this->expectExceptionMessage('Unknown GS1 Application Identifier (23).');

        AiDictionary::default()->get('23');
    }
}
