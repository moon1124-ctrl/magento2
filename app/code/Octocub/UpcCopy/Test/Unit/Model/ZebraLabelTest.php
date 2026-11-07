<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Test\Unit\Model;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Octocub\UpcCopy\Model\CheckDigit;
use Octocub\UpcCopy\Model\ZebraLabel;
use PHPUnit\Framework\TestCase;

class ZebraLabelTest extends TestCase
{
    public function testUpcAUsesBunAndElevenDataDigits(): void
    {
        $zpl = $this->label([])->zpl('Hat^Brim', 'SKU~1', '036000291452');

        self::assertStringContainsString('^BUN,80,Y,N^FD03600029145^FS', $zpl);
        self::assertStringNotContainsString('^BEN', $zpl);
        self::assertStringNotContainsString('036000291452', $zpl);
        self::assertStringContainsString('^FDHat Brim^FS', $zpl);
        self::assertStringContainsString('^FDSKU 1^FS', $zpl);
    }

    public function testElevenDigitDataIsSentWithoutACheckDigit(): void
    {
        self::assertSame(
            ['BUN', '03600029145'],
            $this->label([])->barcodeField('03600029145')
        );
    }

    public function testUpcStoredAsEan13UsesBun(): void
    {
        $ean = CheckDigit::append('003600029145');

        self::assertSame(['BUN', '03600029145'], $this->label([])->barcodeField($ean));
    }

    public function testOtherEan13UsesBenAndTwelveDataDigits(): void
    {
        self::assertSame('1', CheckDigit::calculate('400638133393'));
        $zpl = $this->label([])->zpl('Glue', 'GLUE', '4006381333931');

        self::assertStringContainsString('^BEN,80,Y,N^FD400638133393^FS', $zpl);
        self::assertStringNotContainsString('^BUN', $zpl);
    }

    public function testWrongCheckDigitIsNotPrinted(): void
    {
        $this->expectException(LocalizedException::class);
        $this->label([])->barcodeField('036000291458');
    }

    public function testProductBarcodePrefersUpcOverEanAndSku(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getData')->willReturnCallback(static function ($key = '') {
            $values = ['upc' => '036000291452', 'ean' => '4006381333931', 'sku' => 'NOT-A-CODE'];

            return $values[$key] ?? null;
        });
        $product->method('getSku')->willReturn('NOT-A-CODE');

        self::assertSame('036000291452', $this->label([])->barcodeFromProduct($product));
    }

    public function testProductWithoutABarcodeCannotBePrinted(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getData')->willReturn(null);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('no UPC or EAN');
        $this->label([])->barcodeFromProduct($product);
    }

    public function testEmptyPrinterHostDoesNotOpenASocket(): void
    {
        $this->label(['octocub_upc/zebra/host' => ''])->send("^XA\n^XZ\n");
        $this->addToAssertionCount(1);
    }

    public function testInvalidPrinterHostIsRejectedBeforeASocketIsOpened(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('hostname');
        $this->label([
            'octocub_upc/zebra/host' => 'printer name',
            'octocub_upc/zebra/port' => '9100',
        ])->send("^XA\n^XZ\n");
    }

    public function testPortAboveTheValidRangeIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('port');
        $this->label([
            'octocub_upc/zebra/host' => '127.0.0.1',
            'octocub_upc/zebra/port' => '70000',
        ])->send("^XA\n^XZ\n");
    }

    /**
     * @param array<string, string> $config
     */
    private function label(array $config): ZebraLabel
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(static fn ($path) => $config[$path] ?? null);

        return new ZebraLabel($scope);
    }
}
