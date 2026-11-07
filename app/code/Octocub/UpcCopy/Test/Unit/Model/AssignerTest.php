<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Test\Unit\Model;

use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Octocub\UpcCopy\Model\Assigner;
use Octocub\UpcCopy\Model\CatalogCodeLookup;
use Octocub\UpcCopy\Model\CheckDigit;
use Octocub\UpcCopy\Model\Generator;
use PHPUnit\Framework\TestCase;

class AssignerTest extends TestCase
{
    public function testGenerationWritesTheSameTwelveDigitCodeToEmptyAttributes(): void
    {
        $code = CheckDigit::append('03600000001');
        $product = $this->product(['upc' => '', 'ean' => '']);
        $generator = $this->generator(true, [$code]);
        $lookup = $this->lookup(true);
        $lookup->expects($this->once())->method('isUsed')->with($code, null)->willReturn(false);

        (new Assigner($generator, $lookup))->assign($product);

        self::assertSame($code, $product->getData('upc'));
        self::assertSame($code, $product->getData('ean'));
        self::assertSame(12, strlen($code));
    }

    public function testExistingValuesAreNotReplaced(): void
    {
        $product = $this->product([
            'upc' => '036000291452',
            'ean' => '4006381333931',
        ]);
        $generator = $this->generator(true, [], true);
        $generator->expects($this->never())->method('next');

        (new Assigner($generator, $this->lookup()))->assign($product);

        self::assertSame('036000291452', $product->getData('upc'));
        self::assertSame('4006381333931', $product->getData('ean'));
    }

    public function testBlankedStoredValueIsRestoredAndNotRegenerated(): void
    {
        $product = $this->product(['upc' => '', 'ean' => ''], 15);
        $lookup = $this->lookup(true);
        $lookup->expects($this->once())
            ->method('storedValues')
            ->with(15)
            ->willReturn(['upc' => '036000291452', 'ean' => '4006381333931']);
        $generator = $this->generator(true, [], true);
        $generator->expects($this->never())->method('next');

        (new Assigner($generator, $lookup))->assign($product);

        self::assertSame('036000291452', $product->getData('upc'));
        self::assertSame('4006381333931', $product->getData('ean'));
    }

    public function testExplicitReplacementIsKept(): void
    {
        $product = $this->product(['upc' => '012345678905', 'ean' => '4006381333931'], 15);
        $lookup = $this->lookup(true);
        $lookup->expects($this->never())->method('storedValues');
        $generator = $this->generator(true, [], true);
        $generator->expects($this->never())->method('next');

        (new Assigner($generator, $lookup))->assign($product);

        self::assertSame('012345678905', $product->getData('upc'));
        self::assertSame('4006381333931', $product->getData('ean'));
    }

    public function testPartialSaveDoesNotAddMissingAttributes(): void
    {
        $product = $this->product(['name' => 'Hat'], 9);
        $lookup = $this->lookup();
        $lookup->method('storedValues')->willReturn(['upc' => '036000291452', 'ean' => null]);
        $generator = $this->generator(true, [CheckDigit::append('03600000001')], true);
        $generator->expects($this->never())->method('next');

        (new Assigner($generator, $lookup))->assign($product);

        self::assertArrayNotHasKey('upc', $product->getData());
    }

    public function testDuplicateCodeIsSkipped(): void
    {
        $first = CheckDigit::append('03600000001');
        $second = CheckDigit::append('03600000002');
        $product = $this->product(['upc' => '', 'ean' => ''], 42);
        $lookup = $this->lookup(true);
        $lookup->method('storedValues')->willReturn(['upc' => null, 'ean' => null]);
        $lookup->expects($this->exactly(2))
            ->method('isUsed')
            ->willReturnCallback(function (string $code, ?int $except) use ($first, $second): bool {
                self::assertSame(42, $except);
                self::assertContains($code, [$first, $second]);

                return $code === $first;
            });
        $generator = $this->generator(true, [$first, $second]);

        (new Assigner($generator, $lookup))->assign($product);

        self::assertSame($second, $product->getData('upc'));
        self::assertSame($second, $product->getData('ean'));
    }

    public function testInvalidGeneratedCodeIsRejected(): void
    {
        $product = $this->product(['upc' => '', 'ean' => '']);
        $generator = $this->generator(true, ['036000291458']);
        $lookup = $this->lookup(true);
        $lookup->expects($this->never())->method('isUsed');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('not valid');
        (new Assigner($generator, $lookup))->assign($product);
    }

    public function testGivesUpAfterTwentyCollisions(): void
    {
        $code = CheckDigit::append('03600029145');
        $product = $this->product(['upc' => '', 'ean' => '']);
        $generator = $this->createMock(Generator::class);
        $generator->method('isEnabled')->willReturn(true);
        $generator->expects($this->exactly(20))->method('next')->willReturn($code);
        $lookup = $this->lookup(true);
        $lookup->expects($this->exactly(20))->method('isUsed')->willReturn(true);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('unique UPC');
        (new Assigner($generator, $lookup))->assign($product);
    }

    public function testDisabledGeneratorLeavesEmptyFieldsAlone(): void
    {
        $product = $this->product(['upc' => '', 'ean' => '']);
        $generator = $this->generator(false, [], true);
        $generator->expects($this->never())->method('next');

        (new Assigner($generator, $this->lookup()))->assign($product);

        self::assertSame('', $product->getData('upc'));
        self::assertSame('', $product->getData('ean'));
    }

    public function testThirteenDigitSkuFillsEmptyFieldsAndDoesNotGenerate(): void
    {
        $product = $this->product(['upc' => '', 'ean' => 'KEEP'], null, '4006381333931');
        $generator = $this->generator(true, [], true);
        $generator->expects($this->never())->method('next');

        (new Assigner($generator, $this->lookup()))->assign($product);

        self::assertSame('4006381333931', $product->getData('upc'));
        self::assertSame('KEEP', $product->getData('ean'));
    }

    public function testStoredValueWinsOverTheThirteenDigitSku(): void
    {
        $product = $this->product(['upc' => '', 'ean' => ''], 4, '4006381333931');
        $lookup = $this->lookup();
        $lookup->method('storedValues')->willReturn(['upc' => '036000291452', 'ean' => null]);
        $generator = $this->generator(true, [], true);
        $generator->expects($this->never())->method('next');

        (new Assigner($generator, $lookup))->assign($product);

        self::assertSame('036000291452', $product->getData('upc'));
        self::assertSame('4006381333931', $product->getData('ean'));
    }

    public function testOnlyTheAttributeThatExistsIsFilled(): void
    {
        $code = CheckDigit::append('03600000001');
        $product = $this->product(['upc' => '']);
        $lookup = $this->createStub(CatalogCodeLookup::class);
        $lookup->method('existingCodes')->willReturn(['upc']);
        $lookup->method('isUsed')->willReturn(false);
        $generator = $this->generator(true, [$code]);

        (new Assigner($generator, $lookup))->assign($product);

        self::assertSame($code, $product->getData('upc'));
        self::assertArrayNotHasKey('ean', $product->getData());
    }

    /**
     * @param string[] $codes
     */
    private function generator(bool $enabled, array $codes, bool $mock = false): Generator
    {
        $generator = $mock ? $this->createMock(Generator::class) : $this->createStub(Generator::class);
        $generator->method('isEnabled')->willReturn($enabled);
        if ($codes !== []) {
            $generator->method('next')->willReturnOnConsecutiveCalls(...$codes);
        }

        return $generator;
    }

    private function lookup(bool $mock = false): CatalogCodeLookup
    {
        $lookup = $mock ? $this->createMock(CatalogCodeLookup::class) : $this->createStub(CatalogCodeLookup::class);
        $lookup->method('existingCodes')->willReturn(['upc', 'ean']);

        return $lookup;
    }

    private function product(array $data, ?int $id = null, string $sku = 'SKU-1'): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getSku')->willReturn($sku);
        $product->method('getData')->willReturnCallback(static function ($key = '') use (&$data) {
            if ($key === '' || $key === null) {
                return $data;
            }

            return $data[$key] ?? null;
        });
        $product->method('setData')->willReturnCallback(function ($key, $value = null) use (&$data, $product) {
            $data[$key] = $value;

            return $product;
        });

        return $product;
    }
}
