<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Test\Unit\Model;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Octocub\UpcCopy\Model\CatalogCodeLookup;
use PHPUnit\Framework\TestCase;

class CatalogCodeLookupTest extends TestCase
{
    public function testStoredValuePrefersTheGlobalStoreAndReadsBothAttributes(): void
    {
        $captured = [];
        $connection = $this->connection();
        $connection->method('fetchAll')->willReturnCallback(
            function ($sql, $bind = []) use (&$captured): array {
                $captured = ['sql' => $sql, 'bind' => $bind];

                return [
                    ['attribute_id' => 10, 'store_id' => 1, 'value' => 'STORE'],
                    ['attribute_id' => 10, 'store_id' => 0, 'value' => '036000291452'],
                    ['attribute_id' => 11, 'store_id' => 2, 'value' => '4006381333931'],
                ];
            }
        );

        $values = $this->lookup($connection)->storedValues(8);

        self::assertSame('036000291452', $values['upc']);
        self::assertSame('4006381333931', $values['ean']);
        self::assertStringContainsString('catalog_product_entity_varchar', $captured['sql']);
        self::assertSame([8, 10, 11], $captured['bind']);
    }

    public function testSkuMatchIsADuplicateWithoutReadingAttributes(): void
    {
        $connection = $this->connection(true);
        $connection->expects($this->once())->method('fetchOne')->willReturn(1);
        $connection->expects($this->never())->method('fetchAll');

        self::assertTrue($this->lookup($connection)->isUsed('036000291452', 5));
    }

    public function testAttributeMatchChecksUpcAndEanAndSkipsTheCurrentProduct(): void
    {
        $calls = [];
        $connection = $this->connection();
        $connection->method('fetchOne')->willReturnCallback(function ($sql, $bind = []) use (&$calls) {
            $calls[] = ['sql' => $sql, 'bind' => $bind];

            return count($calls) === 1 ? 0 : 1;
        });

        self::assertTrue($this->lookup($connection)->isUsed('036000291452', 9));

        self::assertCount(2, $calls);
        self::assertStringContainsString('sku = ?', $calls[0]['sql']);
        self::assertStringContainsString('attribute_id IN', $calls[1]['sql']);
        self::assertStringContainsString('entity_id <> ?', $calls[1]['sql']);
        self::assertContains(10, $calls[1]['bind']);
        self::assertContains(11, $calls[1]['bind']);
        self::assertSame('036000291452', $calls[1]['bind'][2]);
        self::assertSame(9, $calls[1]['bind'][3]);
    }

    public function testMissingAttributeIsNotACode(): void
    {
        $eav = $this->createStub(EavConfig::class);
        $eav->method('getAttribute')->willReturnCallback(function ($entity, string $code) {
            $attribute = $this->createStub(AbstractAttribute::class);
            $attribute->method('getAttributeId')->willReturn($code === 'upc' ? 10 : 0);
            $attribute->method('getBackendType')->willReturn('varchar');

            return $attribute;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getTableName')->willReturnCallback(static fn ($table): string => (string) $table);

        $codes = (new CatalogCodeLookup($resource, $eav))->existingCodes();

        self::assertSame(['upc'], $codes);
    }

    private function lookup(AdapterInterface $connection): CatalogCodeLookup
    {
        $eav = $this->createStub(EavConfig::class);
        $eav->method('getAttribute')->willReturnCallback(function ($entity, string $code) {
            self::assertSame(Product::ENTITY, $entity);
            $attribute = $this->createStub(AbstractAttribute::class);
            $attribute->method('getAttributeId')->willReturn($code === 'upc' ? 10 : 11);
            $attribute->method('getBackendType')->willReturn('varchar');

            return $attribute;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnCallback(static fn ($table): string => (string) $table);

        return new CatalogCodeLookup($resource, $eav);
    }

    private function connection(bool $mock = false): AdapterInterface
    {
        $connection = $mock ? $this->createMock(AdapterInterface::class) : $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn ($ident): string => (string) $ident);

        return $connection;
    }
}
