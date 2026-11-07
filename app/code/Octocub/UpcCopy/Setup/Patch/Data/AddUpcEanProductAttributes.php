<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Setup\Patch\Data;

use Magento\Catalog\Setup\Patch\Data\UpdateProductAttributes;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Octocub\UpcCopy\Model\ProductAttributeInstaller;

/**
 * Adds product attributes upc and ean when eav_attribute does not already have them.
 * An existing attribute, its set placement, and every stored product value are left as they are.
 * There is no revert: deleting the attributes would delete stored barcodes.
 */
class AddUpcEanProductAttributes implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory,
        private readonly ProductAttributeInstaller $installer
    ) {
    }

    public function apply()
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();
        try {
            $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
            $this->installer->install($eavSetup);
        } finally {
            $connection->endSetup();
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [UpdateProductAttributes::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
