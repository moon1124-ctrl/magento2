<?php

declare(strict_types=1);

namespace Citybeach\Catchfeeder\Api;

interface ProductFeedInterface
{
    /**
     * @return \Citybeach\Catchfeeder\Api\Data\ProductSummaryInterface[]
     */
    public function summary(int $limit, int $offset, int $storeId): array;

    /**
     * @return \Citybeach\Catchfeeder\Api\Data\ProductSummaryInterface[]
     */
    public function since(int $limit, int $offset, int $storeId, string $since): array;

    /**
     * Comma-separated Magento product ids.
     *
     * @return \Citybeach\Catchfeeder\Api\Data\StockItemInterface[]
     */
    public function stock(string $productIds): array;
}
