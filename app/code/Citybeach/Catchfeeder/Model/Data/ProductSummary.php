<?php

declare(strict_types=1);

namespace Citybeach\Catchfeeder\Model\Data;

use Citybeach\Catchfeeder\Api\Data\ProductSummaryInterface;

class ProductSummary implements ProductSummaryInterface
{
    /**
     * @param int[] $categoryIds
     * @param int[] $associatedIds
     * @param string[] $imageUrls
     */
    public function __construct(
        private readonly int $productId,
        private readonly string $sku,
        private readonly string $name,
        private readonly string $typeId,
        private readonly float $price,
        private readonly int $status,
        private readonly array $categoryIds,
        private readonly array $associatedIds,
        private readonly float $qty,
        private readonly bool $isInStock,
        private readonly array $imageUrls
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

    public function getName(): string
    {
        return $this->name;
    }

    public function getTypeId(): string
    {
        return $this->typeId;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getCategoryIds(): array
    {
        return $this->categoryIds;
    }

    public function getAssociatedIds(): array
    {
        return $this->associatedIds;
    }

    public function getQty(): float
    {
        return $this->qty;
    }

    public function getIsInStock(): bool
    {
        return $this->isInStock;
    }

    public function getImageUrls(): array
    {
        return $this->imageUrls;
    }
}
