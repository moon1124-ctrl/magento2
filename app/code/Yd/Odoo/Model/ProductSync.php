<?php

declare(strict_types=1);

namespace Yd\Odoo\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Psr\Log\LoggerInterface;

class ProductSync
{
    public function __construct(
        private readonly Config $config,
        private readonly Gateway $gateway,
        private readonly MapStore $maps,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly Configurable $configurable,
        private readonly LoggerInterface $logger
    ) {
    }

    public function sync(Product $product, bool $force = false): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }
        if (!$force && !$this->config->autoProduct()) {
            return;
        }
        if ($product->getSku() === null || $product->getSku() === '') {
            return;
        }
        $productId = (int) $product->getId();
        if ($productId <= 0) {
            return;
        }
        if (!$force && (int) $product->getVisibility() === Visibility::VISIBILITY_NOT_VISIBLE) {
            return;
        }
        if (!$force && $this->configurable->getParentIdsByChild($productId)) {
            return;
        }

        try {
            $existing = $this->maps->productId($productId);
            $payload = $this->payload($product);
            if ($existing) {
                $this->gateway->execute('product.product', 'write', [[$existing], $payload], true);
                return;
            }
            $odooId = (int) $this->gateway->execute('product.product', 'create', [$payload], true);
            if ($odooId <= 0) {
                return;
            }
            $this->maps->saveProduct($productId, (string) $product->getSku(), $odooId);
            if ($this->config->autoInventory()) {
                $this->pushQuantity($productId, $odooId);
            }
        } catch (\Throwable $exception) {
            $this->logger->error('Odoo product sync failed for ' . $product->getSku() . ': ' . $exception->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Product $product): array
    {
        $type = in_array($product->getTypeId(), ['simple', 'configurable'], true) ? 'product' : 'service';
        $payload = [
            'type' => $type,
            'default_code' => (string) $product->getSku(),
            'mage_id' => (int) $product->getId(),
            'sale_ok' => (int) $product->getStatus() === 1,
            'name' => (string) $product->getName(),
            'description' => (string) $product->getDescription(),
            'description_sale' => (string) $product->getShortDescription(),
            'list_price' => (float) $product->getPrice(),
            'weight' => (float) $product->getWeight(),
        ];
        $ean = $product->getData('ean');
        if (is_string($ean) && $ean !== '') {
            $payload['barcode'] = $ean;
        }

        return $payload;
    }

    private function pushQuantity(int $productId, int $odooId): void
    {
        $qty = (int) $this->stockRegistry->getStockItem($productId)->getQty();
        $this->gateway->execute('bridge.backbone', 'update_quantity', [[
            'new_quantity' => $qty,
            'product_id' => $odooId,
        ]], true);
    }
}
