<?php

declare(strict_types=1);

namespace Yd\Odoo\Model;

use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryCatalogApi\Api\DefaultSourceProviderInterface;

/**
 * Writes Odoo's on-hand quantity to the default source only.
 *
 * Saving the default source also updates the legacy stock item. Other sources
 * are omitted, so their quantities stay as they are. MSI rejects a negative
 * quantity, and a negative Odoo quantity is stored as zero.
 */
class DefaultSourceStock
{
    public function __construct(
        private readonly SourceItemsSaveInterface $sourceItemsSave,
        private readonly SourceItemInterfaceFactory $sourceItemFactory,
        private readonly DefaultSourceProviderInterface $defaultSourceProvider
    ) {
    }

    public function setAbsoluteQuantity(string $sku, float $quantity): void
    {
        if ($quantity < 0) {
            $quantity = 0.0;
        }
        $item = $this->sourceItemFactory->create();
        $item->setSku($sku);
        $item->setSourceCode($this->defaultSourceProvider->getCode());
        $item->setQuantity($quantity);
        $item->setStatus(
            $quantity > 0 ? SourceItemInterface::STATUS_IN_STOCK : SourceItemInterface::STATUS_OUT_OF_STOCK
        );
        $this->sourceItemsSave->execute([$item]);
    }
}
