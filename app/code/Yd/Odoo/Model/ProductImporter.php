<?php

declare(strict_types=1);

namespace Yd\Odoo\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Odoo is the product and stock source. Magento does not send orders back.
 *
 * The scheduled import stays off until both connection and product import are
 * enabled. A dry run and a single-SKU import can read Odoo without moving the
 * cursor and without turning that schedule on.
 */
class ProductImporter
{
    private const PAGE = 50;

    private const FIELDS = [
        'id',
        'name',
        'default_code',
        'list_price',
        'barcode',
        'qty_available',
        'description_sale',
        'weight',
        'sale_ok',
        'write_date',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Gateway $gateway,
        private readonly Cursor $cursor,
        private readonly MapStore $maps,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductFactory $productFactory,
        private readonly DefaultSourceStock $stock,
        private readonly BarcodeMapper $barcodes,
        private readonly ImportQuery $query,
        private readonly EavConfig $eavConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function import(): ImportResult
    {
        return $this->execute(new ImportRequest(false, null, false));
    }

    public function preview(?string $sku = null): ImportResult
    {
        $sku = $sku !== null ? trim($sku) : null;

        return $this->execute(new ImportRequest(true, $sku === '' ? null : $sku, true));
    }

    /**
     * @throws LocalizedException
     */
    public function importOne(string $sku): ImportResult
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new LocalizedException(__('Enter a product SKU to import.'));
        }

        return $this->execute(new ImportRequest(false, $sku, true));
    }

    private function execute(ImportRequest $request): ImportResult
    {
        if (!$request->ignoreSchedule && !$this->config->importProducts()) {
            return new ImportResult(false, false, 0, 0, 0, null, []);
        }
        $single = $request->sku !== null;
        $cursor = $single ? null : $this->cursor->read();
        $ids = $this->gateway->execute(
            'product.product',
            'search',
            [$this->query->domain($cursor, $request->sku), 0, $single ? 1 : self::PAGE, ImportQuery::ORDER],
            true
        );
        if (!is_array($ids) || $ids === []) {
            return new ImportResult(true, $request->dryRun, 0, 0, 0, null, []);
        }
        $rows = $this->gateway->execute('product.product', 'read', [$ids, self::FIELDS], true);
        if (!is_array($rows)) {
            return new ImportResult(true, $request->dryRun, 0, 0, 0, null, []);
        }

        $saved = 0;
        $failed = 0;
        $skipped = 0;
        $products = [];
        $advanceDate = null;
        $advanceId = null;
        foreach ($this->query->sort($rows) as $row) {
            $outcome = $this->upsert($row, $request);
            $products[] = $outcome['product'];
            if ($outcome['status'] === 'failed') {
                $failed++;
                break;
            }
            if ($outcome['status'] === 'saved') {
                $saved++;
            } elseif ($outcome['status'] === 'skipped') {
                $skipped++;
            }
            if ($single) {
                continue;
            }
            $date = (string) ($row['write_date'] ?? '');
            $id = (int) ($row['id'] ?? 0);
            if ($date === '' || $id < 1) {
                $failed++;
                $this->logger->error(
                    'Odoo product ' . $outcome['product']['sku'] . ' has no write date or id, so the import stopped before it.'
                );
                break;
            }
            $advanceDate = $date;
            $advanceId = $id;
        }

        $cursorValue = null;
        if (!$single && !$request->dryRun && $advanceDate !== null && $advanceId !== null && $saved + $skipped > 0) {
            $this->cursor->write($advanceDate, $advanceId);
            $cursorValue = $advanceDate . '|' . $advanceId;
        }

        return new ImportResult(
            true,
            $request->dryRun,
            $saved,
            $failed,
            $skipped,
            $cursorValue,
            $products
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array{status: string, product: array{sku: string, odoo_id: int, upc: string|null, ean: string|null, quantity: float}}
     */
    private function upsert(array $row, ImportRequest $request): array
    {
        $sku = trim((string) ($row['default_code'] ?? ''));
        $mapped = $this->barcodes->map($row['barcode'] ?? null);
        $quantity = $this->quantity($row['qty_available'] ?? 0);
        $report = [
            'sku' => $sku,
            'odoo_id' => (int) ($row['id'] ?? 0),
            'upc' => $mapped['upc'],
            'ean' => $mapped['ean'],
            'quantity' => $quantity,
        ];
        if ($sku === '') {
            return ['status' => 'skipped', 'product' => $report];
        }
        if ($request->dryRun) {
            return ['status' => 'preview', 'product' => $report];
        }
        try {
            try {
                $product = $this->productRepository->get($sku, true, 0);
            } catch (NoSuchEntityException) {
                $product = $this->newProduct($sku);
            }
            if (!$product instanceof Product) {
                throw new \RuntimeException('Magento did not return a product for ' . $sku);
            }
            $product->setName((string) ($row['name'] ?? $sku));
            $product->setPrice((float) ($row['list_price'] ?? 0));
            $product->setWeight((float) ($row['weight'] ?? 0));
            $short = trim(strip_tags((string) ($row['description_sale'] ?? '')));
            if ($short !== '') {
                $product->setShortDescription($short);
            }
            $product->setStatus(!empty($row['sale_ok']) ? 1 : 2);
            if ($mapped['ean'] !== null) {
                $product->setData('ean', $mapped['ean']);
            }
            if ($mapped['upc'] !== null) {
                $product->setData('upc', $mapped['upc']);
            }
            $this->productRepository->save($product);
            $productId = (int) $product->getId();
            $odooId = (int) ($row['id'] ?? 0);
            if ($productId > 0 && $odooId > 0) {
                $this->maps->saveProduct($productId, $sku, $odooId);
            }
            if ($this->shouldWriteStock($request)) {
                $this->stock->setAbsoluteQuantity($sku, $quantity);
            }

            return ['status' => 'saved', 'product' => $report];
        } catch (\Throwable $exception) {
            $this->logger->error('Odoo product import failed for ' . $sku . ': ' . $exception->getMessage());

            return ['status' => 'failed', 'product' => $report];
        }
    }

    private function shouldWriteStock(ImportRequest $request): bool
    {
        if ($request->dryRun) {
            return false;
        }
        if ($request->ignoreSchedule) {
            return $this->config->stockSyncRequested();
        }

        return $this->config->importStock();
    }

    private function quantity(mixed $qty): float
    {
        $quantity = is_numeric($qty) ? (float) $qty : 0.0;

        return $quantity < 0 ? 0.0 : $quantity;
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
