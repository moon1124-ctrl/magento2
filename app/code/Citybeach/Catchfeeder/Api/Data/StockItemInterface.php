<?php

declare(strict_types=1);

namespace Citybeach\Catchfeeder\Api\Data;

interface StockItemInterface
{
    public function getProductId(): int;

    public function getSku(): string;

    public function getQty(): float;

    public function getIsInStock(): bool;
}
