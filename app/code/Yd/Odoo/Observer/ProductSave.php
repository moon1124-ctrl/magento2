<?php

declare(strict_types=1);

namespace Yd\Odoo\Observer;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Yd\Odoo\Model\ProductSync;

class ProductSave implements ObserverInterface
{
    public function __construct(
        private readonly ProductSync $productSync,
        private readonly State $state
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $area = $this->state->getAreaCode();
        } catch (LocalizedException) {
            $area = '';
        }
        if (in_array($area, ['webapi_rest', 'webapi_soap', 'graphql'], true)) {
            return;
        }
        $product = $observer->getEvent()->getProduct();
        if ($product instanceof Product) {
            $this->productSync->sync($product);
        }
    }
}
