<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Model;

use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;

class Assigner
{
    public function __construct(
        private readonly Generator $generator,
        private readonly CatalogCodeLookup $lookup
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function assign(Product $product): void
    {
        $present = $this->lookup->existingCodes();
        if ($present === []) {
            return;
        }
        $this->preserveStored($product, $present);
        $this->copyThirteenDigitSku($product, $present);
        if (!$this->generator->isEnabled() || !$this->canGenerate($product, $present)) {
            return;
        }
        $code = $this->unusedCode($product);
        foreach ($present as $attribute) {
            $current = $this->knownValue($product, $attribute);
            if ($current['known'] && !$this->hasValue($current['value'])) {
                $product->setData($attribute, $code);
            }
        }
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
    private function copyThirteenDigitSku(Product $product, array $present): void
    {
        $sku = (string) $product->getSku();
        if (!preg_match('/^\d{13}$/', $sku)) {
            return;
        }
        foreach ($present as $code) {
            $current = $this->knownValue($product, $code);
            if ($current['known'] && !$this->hasValue($current['value'])) {
                $product->setData($code, $sku);
            }
        }
    }

    /**
     * Generate only when every UPC and EAN attribute is in this save and still empty.
     * A missing key means this is a partial save and the stored value must be left alone.
     *
     * @param string[] $present
     */
    private function canGenerate(Product $product, array $present): bool
    {
        foreach ($present as $code) {
            $current = $this->knownValue($product, $code);
            if (!$current['known'] || $this->hasValue($current['value'])) {
                return false;
            }
        }

        return $present !== [];
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

    /**
     * @throws LocalizedException
     */
    private function unusedCode(Product $product): string
    {
        $productId = (int) $product->getId();
        $except = $productId > 0 ? $productId : null;
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = $this->generator->next();
            if (strlen($code) !== 12 || !CheckDigit::isValid($code)) {
                throw new LocalizedException(__('The generated UPC-A number is not valid.'));
            }
            if (!$this->lookup->isUsed($code, $except)) {
                return $code;
            }
        }

        throw new LocalizedException(__('A unique UPC number could not be generated.'));
    }
}
