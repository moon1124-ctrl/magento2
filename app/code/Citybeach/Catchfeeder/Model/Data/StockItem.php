<?php

declare(strict_types=1);

namespace Citybeach\Catchfeeder\Model\Data;

use Citybeach\Catchfeeder\Api\Data\StockItemInterface;

class StockItem implements StockItemInterface
{
    public function __construct(
        private readonly int $productId,
        private readonly string $sku,
        private readonly float $qty,
        private readonly bool $isInStock
    ) {
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getQty(): float
    {
        return $this->qty;
    }

    public function getIsInStock(): bool
    {
        return $this->isInStock;
    }
}
