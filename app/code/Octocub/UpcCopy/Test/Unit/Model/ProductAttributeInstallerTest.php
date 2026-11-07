<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Test\Unit\Model;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Octocub\UpcCopy\Model\ProductAttributeInstaller;
use PHPUnit\Framework\TestCase;

class ProductAttributeInstallerTest extends TestCase
{
    public function testDefinitionsMatchTheMagentoOneTextAttributes(): void
    {
        $definitions = (new ProductAttributeInstaller())->definitions();

        self::assertSame(['upc', 'ean'], array_keys($definitions));
        foreach ($definitions as $definition) {
            self::assertSame('varchar', $definition['type']);
            self::assertSame('text', $definition['input']);
            self::assertTrue($definition['user_defined']);
            self::assertFalse($definition['required']);
            self::assertFalse($definition['unique']);
            self::assertFalse($definition['visible_on_front']);
            self::assertSame('', $definition['apply_to']);
            self::assertSame(ScopedAttributeInterface::SCOPE_GLOBAL, $definition['global']);
            self::assertArrayNotHasKey('group', $definition);
            self::assertArrayNotHasKey('default', $definition);
        }
    }

    public function testMissingAttributesAreAddedAndLinkedWithoutProductWrites(): void
    {
        $state = $this->catalogState();
        $eav = $this->eavSetup($state, false);
        $created = [];
        $eav->method('addAttribute')->willReturnCallback(
            function ($entity, $code, array $definition) use (&$state, &$created) {
                self::assertSame(Product::ENTITY, $entity);
                self::assertArrayNotHasKey('default', $definition);
                $created[] = $code;
                $state['ids'][$code] = $code === 'upc' ? 70 : 71;
            }
        );

        (new ProductAttributeInstaller())->install($eav);

        self::assertSame(['upc', 'ean'], $created);
        self::assertSame([], $state['updates']);
        self::assertSame([], $state['deletes']);
        self::assertCount(6, $state['inserts']);
        $groups = [];
        foreach ($state['inserts'] as $insert) {
            self::assertSame('eav_entity_attribute', $insert['table']);
            self::assertStringNotContainsString('catalog_product', $insert['table']);
            $groups[$insert['row']['attribute_set_id']] = $insert['row']['attribute_group_id'];
            self::assertSame(6, $insert['row']['sort_order']);
            self::assertContains($insert['row']['attribute_id'], [70, 71]);
        }
        self::assertSame(100, $groups[10]);
        self::assertSame(201, $groups[11]);
        self::assertSame(301, $groups[12]);
        self::assertArrayNotHasKey(13, $groups);
        self::assertArrayNotHasKey(14, $groups);
    }

    public function testExistingAttributesAndPlacementsAreNotChanged(): void
    {
        $state = $this->catalogState();
        $state['ids'] = ['upc' => 70, 'ean' => 71];
        foreach ([10, 11, 12, 13, 14] as $setId) {
            $state['links'][$setId] = [70 => 1, 71 => 1];
        }
        $eav = $this->eavSetup($state, true);

        (new ProductAttributeInstaller())->install($eav);

        self::assertSame([], $state['inserts']);
        self::assertSame([], $state['updates']);
        self::assertSame([], $state['deletes']);
    }

    public function testSecondRunDoesNotInsertAgain(): void
    {
        $state = $this->catalogState();
        $eav = $this->eavSetup($state, false, 2);
        $eav->method('addAttribute')->willReturnCallback(
            function ($entity, $code) use (&$state) {
                $state['ids'][$code] = $code === 'upc' ? 70 : 71;
            }
        );
        $installer = new ProductAttributeInstaller();

        $installer->install($eav);
        $first = count($state['inserts']);
        $installer->install($eav);

        self::assertSame(6, $first);
        self::assertCount($first, $state['inserts']);
        self::assertSame([], $state['updates']);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function eavSetup(array &$state, bool $forbidCreate, int $runs = 1): EavSetup
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn ($ident): string => (string) $ident);
        $connection->method('fetchCol')->willReturnCallback(function ($sql) use (&$state) {
            $this->assertNoProductTable($sql);

            return $state['sets'];
        });
        $connection->method('fetchOne')->willReturnCallback(function ($sql, $bind = []) use (&$state) {
            $this->assertNoProductTable($sql);
            if (str_contains((string) $sql, 'entity_attribute_id')) {
                return $state['links'][(int) $bind[0]][(int) $bind[1]] ?? false;
            }
            if (str_contains((string) $sql, 'attribute_group_code')) {
                return $state['groups'][(int) $bind[0]][(string) $bind[1]] ?? false;
            }
            if (str_contains((string) $sql, 'default_id')) {
                return $state['defaultGroups'][(int) $bind[0]] ?? false;
            }
            if (str_contains((string) $sql, 'MAX(sort_order)')) {
                return 5;
            }
            self::fail('Unexpected query: ' . $sql);
        });
        $connection->method('insert')->willReturnCallback(function ($table, array $row) use (&$state) {
            $this->assertNoProductTable($table);
            $state['inserts'][] = ['table' => $table, 'row' => $row];
            $state['links'][(int) $row['attribute_set_id']][(int) $row['attribute_id']] = 1;

            return 1;
        });
        $connection->method('update')->willReturnCallback(function () use (&$state) {
            $state['updates'][] = func_get_args();

            return 0;
        });
        $connection->method('delete')->willReturnCallback(function () use (&$state) {
            $state['deletes'][] = func_get_args();

            return 0;
        });

        $setup = $this->createStub(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnCallback(static fn ($table): string => (string) $table);

        $eav = $this->createMock(EavSetup::class);
        $eav->method('getSetup')->willReturn($setup);
        $eav->method('getEntityTypeId')->willReturn(4);
        $eav->method('getAttributeId')->willReturnCallback(
            static function ($entity, $code) use (&$state) {
                return $state['ids'][$code] ?? 0;
            }
        );
        $eav->expects($this->exactly($runs))->method('cleanCache');
        if ($forbidCreate) {
            $eav->expects($this->never())->method('addAttribute');
        }

        return $eav;
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogState(): array
    {
        return [
            'ids' => [],
            'sets' => [10, 11, 12, 13, 14],
            'groups' => [
                10 => ['product-details' => 100, 'general' => 101],
                11 => ['general' => 201],
                13 => ['product-details' => 400],
            ],
            'defaultGroups' => [12 => 301],
            'links' => [13 => [70 => 9, 71 => 8]],
            'inserts' => [],
            'updates' => [],
            'deletes' => [],
        ];
    }

    private function assertNoProductTable(mixed $value): void
    {
        self::assertIsString($value);
        self::assertStringNotContainsString('catalog_product', $value);
    }
}
