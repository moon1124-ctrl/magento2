<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Test\Unit\Setup\Patch\Data;

use Magento\Catalog\Setup\Patch\Data\UpdateProductAttributes;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Octocub\UpcCopy\Model\ProductAttributeInstaller;
use Octocub\UpcCopy\Setup\Patch\Data\AddUpcEanProductAttributes;
use PHPUnit\Framework\TestCase;

class AddUpcEanProductAttributesTest extends TestCase
{
    public function testApplyInstallsAttributesAndClosesSetup(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('startSetup');
        $connection->expects($this->once())->method('endSetup');
        $setup = $this->createStub(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $eavSetup = $this->createStub(EavSetup::class);
        $factory = $this->createMock(EavSetupFactory::class);
        $factory->expects($this->once())->method('create')->with(['setup' => $setup])->willReturn($eavSetup);
        $installer = $this->createMock(ProductAttributeInstaller::class);
        $installer->expects($this->once())->method('install')->with($eavSetup);

        $patch = new AddUpcEanProductAttributes($setup, $factory, $installer);

        self::assertSame($patch, $patch->apply());
    }

    public function testSetupIsClosedWhenInstallFails(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects($this->once())->method('endSetup');
        $setup = $this->createStub(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $factory = $this->createStub(EavSetupFactory::class);
        $factory->method('create')->willReturn($this->createStub(EavSetup::class));
        $installer = $this->createStub(ProductAttributeInstaller::class);
        $installer->method('install')->willThrowException(new \RuntimeException('stopped'));
        $patch = new AddUpcEanProductAttributes($setup, $factory, $installer);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stopped');
        $patch->apply();
    }

    public function testPatchDependsOnTheCatalogAttributeData(): void
    {
        $patch = new AddUpcEanProductAttributes(
            $this->createStub(ModuleDataSetupInterface::class),
            $this->createStub(EavSetupFactory::class),
            $this->createStub(ProductAttributeInstaller::class)
        );

        self::assertSame([UpdateProductAttributes::class], AddUpcEanProductAttributes::getDependencies());
        self::assertSame([], $patch->getAliases());
    }
}
