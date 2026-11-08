<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Model;

use Magento\Catalog\Model\Product;

/**
 * Keeps UPC and EAN values already stored on the product.
 *
 * Odoo assigns GS1 identifiers and prints Zebra labels. This save hook does not
 * generate a number, copy the SKU into a barcode, or replace a value Odoo sent.
 */
class Assigner
{
    public function __construct(private readonly CatalogCodeLookup $lookup)
    {
    }

    public function assign(Product $product): void
    {
        $present = $this->lookup->existingCodes();
        if ($present === []) {
            return;
        }
        $this->preserveStored($product, $present);
    }

    /**
     * Put a stored UPC or EAN back onto a partial or blanked save so the EAV writer does not delete it.
     *
     * @param string[] $present
     */
    private function preserveStored(Product $product, array $present): void
    {
        $productId = (int) $product->getId();
        if ($productId < 1 || !$this->hasBlank($product, $present)) {
            return;
        }
        $stored = $this->lookup->storedValues($productId);
        foreach ($present as $code) {
            $storedValue = $stored[$code] ?? null;
            if (!$this->hasValue($storedValue)) {
                continue;
            }
            $current = $this->knownValue($product, $code);
            // Only fill a blank field that this save already includes. Adding a missing
            // attribute would make the EAV writer insert a second row for a partial save.
            if ($current['known'] && !$this->hasValue($current['value'])) {
                $product->setData($code, $storedValue);
            }
        }
    }

    /**
     * @param string[] $present
     */
    private function hasBlank(Product $product, array $present): bool
    {
        foreach ($present as $code) {
            $current = $this->knownValue($product, $code);
            if (!$current['known'] || !$this->hasValue($current['value'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{known: bool, value: mixed}
     */
    private function knownValue(Product $product, string $code): array
    {
        $data = $product->getData();
        if (!is_array($data) || !array_key_exists($code, $data)) {
            return ['known' => false, 'value' => null];
        }

        return ['known' => true, 'value' => $data[$code]];
    }

    private function hasValue(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }
}
