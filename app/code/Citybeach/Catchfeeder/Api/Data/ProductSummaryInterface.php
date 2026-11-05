<?php

declare(strict_types=1);

namespace Citybeach\Catchfeeder\Api\Data;

interface ProductSummaryInterface
{
    public function getProductId(): int;

    public function getSku(): string;

    public function getName(): string;

    public function getTypeId(): string;

    public function getPrice(): float;

    public function getStatus(): int;

    /**
     * @return int[]
     */
    public function getCategoryIds(): array;

    /**
     * @return int[]
     */
    public function getAssociatedIds(): array;

    public function getQty(): float;

    public function getIsInStock(): bool;

    /**
     * @return string[]
     */
    public function getImageUrls(): array;
}
