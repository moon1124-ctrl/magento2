<?php

declare(strict_types=1);

namespace Citybeach\Catchfeeder\Model;

use Citybeach\Catchfeeder\Api\Data\ProductSummaryInterface;
use Citybeach\Catchfeeder\Api\Data\StockItemInterface;
use Citybeach\Catchfeeder\Api\ProductFeedInterface;
use Citybeach\Catchfeeder\Model\Data\ProductSummary;
use Citybeach\Catchfeeder\Model\Data\StockItem;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Model\StoreManagerInterface;

class ProductFeed implements ProductFeedInterface
{
    private const MAX_PAGE = 100;

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly Configurable $configurable,
        private readonly Grouped $grouped,
        private readonly StoreManagerInterface $storeManager,
        private readonly StockResolverInterface $stockResolver,
        private readonly GetProductSalableQtyInterface $salableQty,
        private readonly IsProductSalableInterface $isSalable
    ) {
    }

    public function summary(int $limit, int $offset, int $storeId): array
    {
        return $this->page($limit, $offset, $storeId, null);
    }

    public function since(int $limit, int $offset, int $storeId, string $since): array
    {
        return $this->page($limit, $offset, $storeId, $since);
    }

    public function stock(string $productIds): array
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', $productIds))));
        $result = [];
        foreach ($ids as $id) {
            try {
                $product = $this->productRepository->getById($id);
            } catch (\Throwable) {
                continue;
            }
            $stock = $this->stockFor((string) $product->getSku(), $id, (int) $product->getStore()->getWebsiteId());
            $result[] = new StockItem(
                $id,
                (string) $product->getSku(),
                $stock['qty'],
                $stock['in_stock']
            );
        }

        return $result;
    }

    /**
     * @return ProductSummaryInterface[]
     */
    private function page(int $limit, int $offset, int $storeId, ?string $since): array
    {
        $limit = max(1, min(self::MAX_PAGE, $limit));
        $offset = max(0, $offset);
        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addStoreFilter($storeId);
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        if ($since !== null && $since !== '') {
            $collection->addFieldToFilter('updated_at', ['gteq' => $since]);
        }
        $select = $collection->getSelect();
        $select->reset(\Magento\Framework\DB\Select::COLUMNS);
        $select->columns('e.entity_id');
        $select->order('e.entity_id');
        $select->limit($limit, $offset);
        $ids = $collection->getConnection()->fetchCol($select);

        $summaries = [];
        foreach ($ids as $id) {
            $product = $this->productRepository->getById((int) $id, false, $storeId);
            if ($product instanceof Product) {
                $summaries[] = $this->summarize($product);
            }
        }

        return $summaries;
    }

    private function summarize(Product $product): ProductSummaryInterface
    {
        $id = (int) $product->getId();
        $stock = $this->stockFor((string) $product->getSku(), $id, (int) $product->getStore()->getWebsiteId());
        $associated = [];
        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            $children = $this->configurable->getChildrenIds($id);
            foreach ($children as $childIds) {
                foreach ($childIds as $childId) {
                    $associated[] = (int) $childId;
                }
            }
        } elseif ($product->getTypeId() === Grouped::TYPE_CODE) {
            $associated = array_map('intval', $this->grouped->getAssociatedProductIds($product));
        }
        $images = [];
        $gallery = $product->getMediaGalleryImages();
        if ($gallery) {
            foreach ($gallery as $image) {
                $url = (string) $image->getUrl();
                if ($url !== '') {
                    $images[] = $url;
                }
            }
        }

        return new ProductSummary(
            $id,
            (string) $product->getSku(),
            (string) $product->getName(),
            (string) $product->getTypeId(),
            (float) $product->getPrice(),
            (int) $product->getStatus(),
            array_map('intval', $product->getCategoryIds()),
            $associated,
            $stock['qty'],
            $stock['in_stock'],
            $images
        );
    }

    /**
     * Salable quantity is what a marketplace can still sell. Legacy stock qty ignores reservations.
     *
     * @return array{qty: float, in_stock: bool}
     */
    private function stockFor(string $sku, int $productId, int $websiteId): array
    {
        try {
            $websiteCode = (string) $this->storeManager->getWebsite($websiteId)->getCode();
            $stockId = (int) $this->stockResolver->execute(SalesChannelInterface::TYPE_WEBSITE, $websiteCode)->getStockId();
            $qty = $this->salableQty->execute($sku, $stockId);

            return [
                'qty' => max(0, $qty),
                'in_stock' => $this->isSalable->execute($sku, $stockId),
            ];
        } catch (\Throwable) {
            $legacy = $this->stockRegistry->getStockItem($productId);

            return [
                'qty' => max(0, (float) $legacy->getQty()),
                'in_stock' => (bool) $legacy->getIsInStock(),
            ];
        }
    }
}
