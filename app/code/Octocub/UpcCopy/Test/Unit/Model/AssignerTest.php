<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Test\Unit\Model;

use Magento\Catalog\Model\Product;
use Octocub\UpcCopy\Model\Assigner;
use Octocub\UpcCopy\Model\CatalogCodeLookup;
use PHPUnit\Framework\TestCase;

class AssignerTest extends TestCase
{
    public function testExistingValuesAreNotReplaced(): void
    {
        $product = $this->product([
            'upc' => '036000291452',
            'ean' => '4006381333931',
        ]);

        (new Assigner($this->lookup()))->assign($product);

        self::assertSame('036000291452', $product->getData('upc'));
        self::assertSame('4006381333931', $product->getData('ean'));
    }

    public function testBlankedStoredValueIsRestored(): void
    {
        $product = $this->product(['upc' => '', 'ean' => ''], 15);
        $lookup = $this->lookup(true);
        $lookup->expects($this->once())
            ->method('storedValues')
            ->with(15)
            ->willReturn(['upc' => '036000291452', 'ean' => '4006381333931']);

        (new Assigner($lookup))->assign($product);

        self::assertSame('036000291452', $product->getData('upc'));
        self::assertSame('4006381333931', $product->getData('ean'));
    }

    public function testExplicitReplacementIsKept(): void
    {
        $product = $this->product(['upc' => '012345678905', 'ean' => '4006381333931'], 15);
        $lookup = $this->lookup(true);
        $lookup->expects($this->never())->method('storedValues');

        (new Assigner($lookup))->assign($product);

        self::assertSame('012345678905', $product->getData('upc'));
        self::assertSame('4006381333931', $product->getData('ean'));
    }

    public function testPartialSaveDoesNotAddMissingAttributes(): void
    {
        $product = $this->product(['name' => 'Hat'], 9);
        $lookup = $this->lookup();
        $lookup->method('storedValues')->willReturn(['upc' => '036000291452', 'ean' => null]);

        (new Assigner($lookup))->assign($product);

        self::assertArrayNotHasKey('upc', $product->getData());
        self::assertArrayNotHasKey('ean', $product->getData());
    }

    public function testEmptyFieldsAreNotFilledFromTheSkuOrAGeneratedNumber(): void
    {
        $product = $this->product(['upc' => '', 'ean' => ''], null, '4006381333931');

        (new Assigner($this->lookup()))->assign($product);

        self::assertSame('', $product->getData('upc'));
        self::assertSame('', $product->getData('ean'));
    }

    public function testAnOdooEanIsNotCopiedIntoAnEmptyUpc(): void
    {
        $product = $this->product(['upc' => '', 'ean' => '4006381333931'], null, '4006381333931');

        (new Assigner($this->lookup()))->assign($product);

        self::assertSame('', $product->getData('upc'));
        self::assertSame('4006381333931', $product->getData('ean'));
    }

    public function testStoredOdooValueWinsOverABlankedSave(): void
    {
        $product = $this->product(['upc' => '', 'ean' => ''], 4, '4006381333931');
        $lookup = $this->lookup();
        $lookup->method('storedValues')->willReturn(['upc' => '036000291452', 'ean' => null]);

        (new Assigner($lookup))->assign($product);

        self::assertSame('036000291452', $product->getData('upc'));
        self::assertSame('', $product->getData('ean'));
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
