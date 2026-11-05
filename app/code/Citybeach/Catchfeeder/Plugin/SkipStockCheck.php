<?php

declare(strict_types=1);

namespace Citybeach\Catchfeeder\Plugin;

use Magento\CatalogInventory\Model\Quote\Item\QuantityValidator;
use Magento\Framework\Event\Observer;

/**
 * Marketplace orders are already paid. Magento stock must not reject the import.
 */
class SkipStockCheck
{
    public function aroundValidate(QuantityValidator $subject, callable $proceed, Observer $observer): void
    {
        $item = $observer->getEvent()->getItem();
        $quote = $item ? $item->getQuote() : null;
        if ($quote && $quote->getData('catchfeeder_import')) {
            return;
        }

        $proceed($observer);
    }
}
