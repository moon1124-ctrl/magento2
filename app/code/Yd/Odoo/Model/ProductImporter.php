<?php

declare(strict_types=1);

namespace Yd\Odoo\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Odoo is the product and stock source. Magento does not send orders back.
 */
class ProductImporter
{
    private const PAGE = 50;

    public function __construct(
        private readonly Config $config,
        private readonly Gateway $gateway,
        private readonly Cursor $cursor,
        private readonly MapStore $maps,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductFactory $productFactory,
        private readonly StockRegistryInterface $stockRegistry,
        private readonly EavConfig $eavConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function import(): void
    {
        if (!$this->config->importProducts()) {
            return;
        }
        $cursor = $this->cursor->read();
        $domain = [['default_code', '!=', false]];
        if ($cursor !== null) {
            $domain = [
                '&',
                ['default_code', '!=', false],
                '|',
                ['write_date', '>', $cursor['write_date']],
                '&',
                ['write_date', '=', $cursor['write_date']],
                ['id', '>', $cursor['id']],
            ];
        }
        $ids = $this->gateway->execute('product.product', 'search', [$domain, 0, self::PAGE, 'write_date asc, id asc'], true);
        if (!is_array($ids) || $ids === []) {
            return;
        }
        $rows = $this->gateway->execute('product.product', 'read', [$ids, [
            'name', 'default_code', 'list_price', 'barcode', 'qty_available',
            'description_sale', 'weight', 'sale_ok', 'write_date',
        ]], true);
        if (!is_array($rows)) {
            return;
        }
        $lastDate = $cursor['write_date'] ?? '';
        $lastId = $cursor['id'] ?? 0;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $this->upsert($row);
            $lastDate = (string) ($row['write_date'] ?? $lastDate);
            $lastId = (int) ($row['id'] ?? $lastId);
        }
        if ($lastDate !== '') {
            $this->cursor->write($lastDate, $lastId);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsert(array $row): void
    {
        $sku = trim((string) ($row['default_code'] ?? ''));
        if ($sku === '') {
            return;
        }
        try {
            try {
                $product = $this->productRepository->get($sku, true, 0);
            } catch (NoSuchEntityException) {
                $product = $this->newProduct($sku);
            }
            if (!$product instanceof Product) {
                return;
            }
            $product->setName((string) ($row['name'] ?? $sku));
            $product->setPrice((float) ($row['list_price'] ?? 0));
            $product->setWeight((float) ($row['weight'] ?? 0));
            $short = trim(strip_tags((string) ($row['description_sale'] ?? '')));
            if ($short !== '') {
                $product->setShortDescription($short);
            }
            $product->setStatus(!empty($row['sale_ok']) ? 1 : 2);
            $barcode = trim((string) ($row['barcode'] ?? ''));
            if ($barcode !== '') {
                $product->setData('ean', $barcode);
                $product->setData('upc', $barcode);
            }
            $this->productRepository->save($product);
            $productId = (int) $product->getId();
            $odooId = (int) ($row['id'] ?? 0);
            if ($productId > 0 && $odooId > 0) {
                $this->maps->saveProduct($productId, $sku, $odooId);
            }
            if ($this->config->importStock()) {
                $qty = (float) ($row['qty_available'] ?? 0);
                $stock = $this->stockRegistry->getStockItemBySku($sku);
                $stock->setQty($qty);
                $stock->setIsInStock($qty > 0);
                $this->stockRegistry->updateStockItemBySku($sku, $stock);
            }
        } catch (\Throwable $exception) {
            $this->logger->error('Odoo product import failed for ' . $sku . ': ' . $exception->getMessage());
        }
    }

    private function newProduct(string $sku): Product
    {
        $websiteId = (int) $this->storeManager->getWebsite(true)->getId();
        $attributeSetId = (int) $this->eavConfig->getEntityType(Product::ENTITY)->getDefaultAttributeSetId();
        $product = $this->productFactory->create();
        $product->setSku($sku);
        $product->setAttributeSetId($attributeSetId);
        $product->setTypeId('simple');
        $product->setVisibility(Visibility::VISIBILITY_BOTH);
        $product->setWebsiteIds([$websiteId]);
        $product->setTaxClassId((int) $this->eavConfig->getAttribute(Product::ENTITY, 'tax_class_id')->getDefaultValue());

        return $product;
    }
}
