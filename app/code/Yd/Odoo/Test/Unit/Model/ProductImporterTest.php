<?php

declare(strict_types=1);

namespace Yd\Odoo\Test\Unit\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute;
use Magento\Eav\Model\Entity\Type;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;
use Yd\Odoo\Model\BarcodeMapper;
use Yd\Odoo\Model\Config;
use Yd\Odoo\Model\Cursor;
use Yd\Odoo\Model\DefaultSourceStock;
use Yd\Odoo\Model\Gateway;
use Yd\Odoo\Model\ImportQuery;
use Yd\Odoo\Model\MapStore;
use Yd\Odoo\Model\ProductImporter;

class ProductImporterTest extends TestCase
{
    public function testScheduledImportStaysOffUntilBothFlagsAreEnabled(): void
    {
        $gateway = $this->createMock(Gateway::class);
        $gateway->expects($this->never())->method('execute');
        $cursor = $this->createMock(Cursor::class);
        $cursor->expects($this->never())->method('read');
        $cursor->expects($this->never())->method('write');

        $result = $this->importer(
            $this->config(false, false, false),
            $gateway,
            $cursor,
            $this->createStub(ProductRepositoryInterface::class),
            $this->createStub(DefaultSourceStock::class),
            $this->createStub(MapStore::class)
        )->import();

        self::assertFalse($result->ran);
        self::assertSame(0, $result->saved);
    }

    public function testUpcAIsSavedOnBothAttributes(): void
    {
        $box = $this->box(['entity_id' => 4, 'sku' => 'ABC', 'upc' => '', 'ean' => '']);
        $saved = $this->importRow('036000291452', 4.0, $box, true, false);

        self::assertSame('036000291452', $box->data['upc']);
        self::assertSame('0036000291452', $box->data['ean']);
        self::assertSame([['ABC', 4.0]], $saved);
    }

    public function testEan13KeepsAnExistingUpc(): void
    {
        $box = $this->box(['entity_id' => 4, 'sku' => 'ABC', 'upc' => 'KEEP', 'ean' => 'OLD']);
        $this->importRow('4006381333931', 1, $box, true, false);

        self::assertSame('KEEP', $box->data['upc']);
        self::assertSame('4006381333931', $box->data['ean']);
        self::assertArrayNotHasKey('upc', $box->assigned);
    }

    public function testEmptyOdooBarcodeDoesNotClearExistingIdentifiers(): void
    {
        $box = $this->box(['entity_id' => 4, 'sku' => 'ABC', 'upc' => 'KEEP', 'ean' => 'KEEP']);
        $this->importRow(false, 1, $box, true, false);

        self::assertSame('KEEP', $box->data['upc']);
        self::assertSame('KEEP', $box->data['ean']);
        self::assertSame([], $box->assigned);
    }

    public function testNonGs1CodeIsStoredOnlyOnEan(): void
    {
        $box = $this->box(['entity_id' => 4, 'sku' => 'ABC', 'upc' => 'KEEP', 'ean' => '']);
        $this->importRow('ABC-1', 1, $box, true, false);

        self::assertSame('KEEP', $box->data['upc']);
        self::assertSame('ABC-1', $box->data['ean']);
        self::assertArrayNotHasKey('upc', $box->assigned);
    }

    public function testNegativeQuantityReachingTheScheduleIsClamped(): void
    {
        $box = $this->box(['entity_id' => 4, 'sku' => 'ABC', 'upc' => '', 'ean' => '']);
        $saved = $this->importRow(false, -3, $box, true, false);

        self::assertSame([['ABC', 0.0]], $saved);
    }

    public function testScheduledStockCanBeTurnedOffWithoutStoppingTheProduct(): void
    {
        $box = $this->box(['entity_id' => 4, 'sku' => 'ABC', 'upc' => '', 'ean' => '']);
        $saved = $this->importRow('036000291452', 5, $box, false, true);

        self::assertSame([], $saved);
        self::assertSame('036000291452', $box->data['upc']);
    }

    public function testDryRunDoesNotSaveOrMoveTheCursor(): void
    {
        $gateway = $this->createMock(Gateway::class);
        $cursor = $this->createMock(Cursor::class);
        $cursor->expects($this->once())->method('read')->willReturn([
            'write_date' => '2019-01-01 00:00:00',
            'id' => 9,
        ]);
        $cursor->expects($this->never())->method('write');
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects($this->never())->method('get');
        $repository->expects($this->never())->method('save');
        $stock = $this->createMock(DefaultSourceStock::class);
        $stock->expects($this->never())->method('setAbsoluteQuantity');
        $maps = $this->createMock(MapStore::class);
        $maps->expects($this->never())->method('saveProduct');
        $calls = $this->stubGateway($gateway, [
            $this->row(2, '2020-01-02 00:00:00', '', false, 1),
            $this->row(1, '2020-01-01 00:00:00', 'ABC', '036000291452', 3),
        ]);

        $result = $this->importer(
            $this->config(false, false, true),
            $gateway,
            $cursor,
            $repository,
            $stock,
            $maps
        )->preview();

        self::assertTrue($result->ran);
        self::assertTrue($result->dryRun);
        self::assertSame(0, $result->saved);
        self::assertSame(1, $result->skipped);
        self::assertNull($result->cursor);
        self::assertSame('ABC', $result->products[0]['sku']);
        self::assertSame('036000291452', $result->products[0]['upc']);
        self::assertSame('0036000291452', $result->products[0]['ean']);
        self::assertSame(3.0, $result->products[0]['quantity']);
        self::assertSame([
            '&',
            ['default_code', '!=', false],
            '|',
            ['write_date', '>', '2019-01-01 00:00:00'],
            '&',
            ['write_date', '=', '2019-01-01 00:00:00'],
            ['id', '>', 9],
        ], $calls->domains[0]);
        self::assertSame(['search', 'read'], $calls->methods);
    }

    public function testSingleProductDoesNotReadOrWriteTheCursor(): void
    {
        $box = $this->box(['entity_id' => 4, 'sku' => 'ABC', 'upc' => '', 'ean' => '']);
        $product = $this->product($box);
        $gateway = $this->createMock(Gateway::class);
        $cursor = $this->createMock(Cursor::class);
        $cursor->expects($this->never())->method('read');
        $cursor->expects($this->never())->method('write');
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturn($product);
        $repository->expects($this->once())->method('save')->willReturn($product);
        $stock = $this->createMock(DefaultSourceStock::class);
        $stock->expects($this->once())->method('setAbsoluteQuantity')->with('ABC', 2.0);
        $calls = $this->stubGateway($gateway, [
            $this->row(8, '2020-05-01 00:00:00', 'ABC', '036000291452', 2),
        ]);

        $result = $this->importer(
            $this->config(false, false, true),
            $gateway,
            $cursor,
            $repository,
            $stock,
            $this->createStub(MapStore::class)
        )->importOne('ABC');

        self::assertSame([['default_code', '=', 'ABC']], $calls->domains[0]);
        self::assertSame(1, $calls->limits[0]);
        self::assertSame('036000291452', $box->data['upc']);
        self::assertSame('0036000291452', $box->data['ean']);
        self::assertNull($result->cursor);
        self::assertSame(1, $result->saved);
    }

    public function testFailureStopsTheCursorBeforeLaterProducts(): void
    {
        $products = [
            'A' => $this->product($this->box(['entity_id' => 11, 'sku' => 'A', 'upc' => '', 'ean' => ''])),
            'B' => $this->product($this->box(['entity_id' => 12, 'sku' => 'B', 'upc' => '', 'ean' => ''])),
            'C' => $this->product($this->box(['entity_id' => 13, 'sku' => 'C', 'upc' => '', 'ean' => ''])),
        ];
        $loaded = [];
        $repository = $this->createStub(ProductRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(function (string $sku) use (&$loaded, $products) {
            $loaded[] = $sku;

            return $products[$sku];
        });
        $repository->method('save')->willReturnCallback(function (Product $product) use ($products) {
            if ($product === $products['B']) {
                throw new \RuntimeException('save failed');
            }

            return $product;
        });
        $stock = $this->createMock(DefaultSourceStock::class);
        $stock->expects($this->once())->method('setAbsoluteQuantity')->with('A', 1.0);
        $maps = $this->createMock(MapStore::class);
        $maps->expects($this->once())->method('saveProduct')->with(11, 'A', 1);
        $cursor = $this->createMock(Cursor::class);
        $cursor->method('read')->willReturn(null);
        $cursor->expects($this->once())->method('write')->with('2020-01-01 00:00:00', 1);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('B'));
        $gateway = $this->createMock(Gateway::class);
        $calls = $this->stubGateway($gateway, [
            $this->row(3, '2020-01-03 00:00:00', 'C', false, 1),
            $this->row(1, '2020-01-01 00:00:00', 'A', false, 1),
            $this->row(2, '2020-01-02 00:00:00', 'B', false, 1),
        ]);

        $result = $this->importer(
            $this->config(true, true, false),
            $gateway,
            $cursor,
            $repository,
            $stock,
            $maps,
            $logger
        )->import();

        self::assertSame(['A', 'B'], $loaded);
        self::assertSame(['A', 'B'], array_column($result->products, 'sku'));
        self::assertSame(1, $result->saved);
        self::assertSame(1, $result->failed);
        self::assertSame('2020-01-01 00:00:00|1', $result->cursor);
        self::assertContains('id', $calls->fields);
        self::assertContains('barcode', $calls->fields);
        self::assertContains('qty_available', $calls->fields);
    }

    public function testAFullPageMovesTheCursorToTheLastOrderedRow(): void
    {
        $loaded = [];
        $repository = $this->createStub(ProductRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(function (string $sku) use (&$loaded) {
            $loaded[] = $sku;

            return $this->product($this->box(['entity_id' => 20, 'sku' => $sku, 'upc' => '', 'ean' => '']));
        });
        $repository->method('save')->willReturnArgument(0);
        $cursor = $this->createMock(Cursor::class);
        $cursor->method('read')->willReturn(null);
        $cursor->expects($this->once())->method('write')->with('2020-01-03 00:00:00', 3);
        $gateway = $this->createMock(Gateway::class);
        $calls = $this->stubGateway($gateway, [
            $this->row(3, '2020-01-03 00:00:00', 'C', false, 1),
            $this->row(1, '2020-01-01 00:00:00', 'A', false, 1),
            $this->row(2, '2020-01-02 00:00:00', 'B', false, 1),
        ]);
        $stock = $this->createMock(DefaultSourceStock::class);
        $stock->expects($this->never())->method('setAbsoluteQuantity');

        $result = $this->importer(
            $this->config(true, false, false),
            $gateway,
            $cursor,
            $repository,
            $stock,
            $this->createStub(MapStore::class)
        )->import();

        self::assertSame(['A', 'B', 'C'], $loaded);
        self::assertSame(3, $result->saved);
        self::assertSame('2020-01-03 00:00:00|3', $result->cursor);
        self::assertSame('write_date asc, id asc', $calls->orders[0]);
    }

    public function testANewProductKeepsAnOdooEanOffTheUpcField(): void
    {
        $box = $this->box(['sku' => '4006381333931']);
        $product = $this->product($box);
        $repository = $this->createStub(ProductRepositoryInterface::class);
        $repository->method('get')->willThrowException(new NoSuchEntityException(__('missing')));
        $repository->method('save')->willReturnCallback(function () use ($box, $product) {
            $box->data['entity_id'] = 15;

            return $product;
        });
        $factory = $this->createMock(ProductFactory::class);
        $factory->expects($this->once())->method('create')->willReturn($product);
        $type = $this->createStub(Type::class);
        $type->method('getDefaultAttributeSetId')->willReturn(4);
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getDefaultValue')->willReturn('2');
        $eav = $this->createStub(EavConfig::class);
        $eav->method('getEntityType')->willReturn($type);
        $eav->method('getAttribute')->willReturn($attribute);
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getId')->willReturn(1);
        $store = $this->createStub(StoreManagerInterface::class);
        $store->method('getWebsite')->willReturn($website);
        $gateway = $this->createMock(Gateway::class);
        $this->stubGateway($gateway, [
            $this->row(8, '2020-05-01 00:00:00', '4006381333931', '4006381333931', 1),
        ]);
        $cursor = $this->createMock(Cursor::class);
        $cursor->expects($this->never())->method('read');
        $cursor->expects($this->never())->method('write');
        $stock = $this->createMock(DefaultSourceStock::class);
        $stock->expects($this->never())->method('setAbsoluteQuantity');

        $this->importer(
            $this->config(false, false, false),
            $gateway,
            $cursor,
            $repository,
            $stock,
            $this->createStub(MapStore::class),
            null,
            $factory,
            $eav,
            $store
        )->importOne('4006381333931');

        self::assertSame('4006381333931', $box->data['ean']);
        self::assertArrayNotHasKey('upc', $box->data);
    }

    public function testAnEmptySingleSkuDoesNotCallOdoo(): void
    {
        $gateway = $this->createMock(Gateway::class);
        $gateway->expects($this->never())->method('execute');
        $importer = $this->importer(
            $this->config(false, false, false),
            $gateway,
            $this->createStub(Cursor::class),
            $this->createStub(ProductRepositoryInterface::class),
            $this->createStub(DefaultSourceStock::class),
            $this->createStub(MapStore::class)
        );

        $this->expectException(LocalizedException::class);
        $importer->importOne('   ');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function box(array $data): stdClass
    {
        $box = new stdClass();
        $box->data = $data;
        $box->assigned = [];

        return $box;
    }

    private function product(stdClass $box): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getData')->willReturnCallback(static function ($key = null) use ($box) {
            if ($key === null || $key === '') {
                return $box->data;
            }

            return $box->data[$key] ?? null;
        });
        $product->method('setData')->willReturnCallback(function ($key, $value = null) use ($box, $product) {
            $box->data[$key] = $value;
            $box->assigned[$key] = $value;

            return $product;
        });
        $product->method('getId')->willReturnCallback(static fn () => $box->data['entity_id'] ?? null);
        foreach ([
            'setName', 'setPrice', 'setWeight', 'setStatus', 'setSku',
            'setAttributeSetId', 'setTypeId', 'setVisibility',
        ] as $method) {
            $product->method($method)->willReturnSelf();
        }

        return $product;
    }

    /**
     * @return list<array{0: string, 1: float}>
     */
    private function importRow(
        mixed $barcode,
        float|int $qty,
        stdClass $box,
        bool $importStock,
        bool $stockRequested
    ): array {
        $product = $this->product($box);
        $gateway = $this->createMock(Gateway::class);
        $this->stubGateway($gateway, [$this->row(5, '2020-01-01 00:00:00', 'ABC', $barcode, $qty)]);
        $repository = $this->createStub(ProductRepositoryInterface::class);
        $repository->method('get')->willReturn($product);
        $repository->method('save')->willReturn($product);
        $saved = [];
        $stock = $this->createStub(DefaultSourceStock::class);
        $stock->method('setAbsoluteQuantity')->willReturnCallback(
            function (string $sku, float $quantity) use (&$saved): void {
                $saved[] = [$sku, $quantity];
            }
        );
        $cursor = $this->createMock(Cursor::class);
        $cursor->method('read')->willReturn(null);
        $cursor->expects($this->once())->method('write')->with('2020-01-01 00:00:00', 5);

        $this->importer(
            $this->config(true, $importStock, $stockRequested),
            $gateway,
            $cursor,
            $repository,
            $stock,
            $this->createStub(MapStore::class)
        )->import();

        return $saved;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function stubGateway(MockObject $gateway, array $rows): stdClass
    {
        $calls = new stdClass();
        $calls->domains = [];
        $calls->limits = [];
        $calls->orders = [];
        $calls->fields = [];
        $calls->methods = [];
        $gateway->expects($this->exactly(2))->method('execute')->willReturnCallback(
            function (string $model, string $method, array $args, bool $withContext = false) use ($calls, $rows) {
                $calls->methods[] = $method;
                self::assertSame('product.product', $model);
                self::assertTrue($withContext);
                self::assertContains($method, ['search', 'read']);
                if ($method === 'search') {
                    $calls->domains[] = $args[0];
                    $calls->limits[] = $args[2];
                    $calls->orders[] = $args[3];

                    return array_column($rows, 'id');
                }
                $calls->fields = $args[1];

                return $rows;
            }
        );

        return $calls;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id, string $date, string $sku, mixed $barcode, float|int $qty): array
    {
        return [
            'id' => $id,
            'write_date' => $date,
            'default_code' => $sku,
            'name' => $sku === '' ? 'No SKU' : $sku,
            'barcode' => $barcode,
            'qty_available' => $qty,
            'sale_ok' => true,
            'list_price' => 10,
            'weight' => 0,
            'description_sale' => '',
        ];
    }

    private function config(bool $importProducts, bool $importStock, bool $stockRequested): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('importProducts')->willReturn($importProducts);
        $config->method('importStock')->willReturn($importStock);
        $config->method('stockSyncRequested')->willReturn($stockRequested);

        return $config;
    }

    private function importer(
        Config $config,
        Gateway $gateway,
        Cursor $cursor,
        ProductRepositoryInterface $repository,
        DefaultSourceStock $stock,
        MapStore $maps,
        ?LoggerInterface $logger = null,
        ?ProductFactory $factory = null,
        ?EavConfig $eav = null,
        ?StoreManagerInterface $store = null
    ): ProductImporter {
        return new ProductImporter(
            $config,
            $gateway,
            $cursor,
            $maps,
            $repository,
            $factory ?? $this->createStub(ProductFactory::class),
            $stock,
            new BarcodeMapper(),
            new ImportQuery(),
            $eav ?? $this->createStub(EavConfig::class),
            $store ?? $this->createStub(StoreManagerInterface::class),
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }
}
