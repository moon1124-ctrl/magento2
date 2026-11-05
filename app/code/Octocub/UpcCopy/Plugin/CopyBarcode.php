<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Plugin;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;

/**
 * Magento 1 wrote a 13-digit SKU into UPC only. Odoo reads the EAN attribute, so this
 * plugin fills both when the attribute exists and is still empty.
 */
class CopyBarcode
{
    private const ATTRIBUTES = ['upc', 'ean'];

    public function __construct(private readonly EavConfig $eavConfig)
    {
    }

    public function beforeSave(Product $product): void
    {
        $sku = (string) $product->getSku();
        if (!preg_match('/^\d{13}$/', $sku)) {
            return;
        }

        foreach (self::ATTRIBUTES as $code) {
            if (!$this->attributeExists($code)) {
                continue;
            }
            $current = $product->getData($code);
            if ($current === null || $current === '') {
                $product->setData($code, $sku);
            }
        }
    }

    private function attributeExists(string $code): bool
    {
        try {
            $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
        } catch (\Throwable) {
            return false;
        }

        return (bool) $attribute->getId();
    }
}
