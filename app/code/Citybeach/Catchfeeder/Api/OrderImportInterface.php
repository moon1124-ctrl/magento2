<?php

declare(strict_types=1);

namespace Citybeach\Catchfeeder\Api;

interface OrderImportInterface
{
    /**
     * Create a paid Magento order from the Catch JSON payload used by Magento 1.
     */
    public function jsonToOrder(string $jsonOrder, int $storeId, bool $guestCheckout): string;
}
