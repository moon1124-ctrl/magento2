<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Eav\Model\Config as EavConfig;

class Assigner
{
    private const ATTRIBUTES = ['upc', 'ean'];

    public function __construct(
        private readonly EavConfig $eavConfig,
        private readonly Generator $generator,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    public function assign(Product $product): void
    {
        $present = array_values(array_filter(self::ATTRIBUTES, fn (string $code): bool => $this->attributeExists($code)));
        if ($present === []) {
            return;
        }
        $sku = (string) $product->getSku();
        if (preg_match('/^\d{13}$/', $sku)) {
            foreach ($present as $code) {
                $current = $product->getData($code);
                if ($current === null || $current === '') {
                    $product->setData($code, $sku);
                }
            }
        }
        if (!$this->generator->isEnabled() || !$this->isBlank($product, $present)) {
            return;
        }
        $code = $this->unusedCode();
        foreach ($present as $attribute) {
            $current = $product->getData($attribute);
            if ($current === null || $current === '') {
                $product->setData($attribute, $code);
            }
        }
    }

    /**
     * @param string[] $attributes
     */
    private function isBlank(Product $product, array $attributes): bool
    {
        foreach ($attributes as $code) {
            $current = $product->getData($code);
            if ($current !== null && $current !== '') {
                return false;
            }
        }

        return true;
    }

    private function unusedCode(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = $this->generator->next();
            $collection = $this->collectionFactory->create();
            $collection->addAttributeToFilter('ean', $code);
            $collection->setPageSize(1);
            if ($collection->getSize() === 0) {
                return $code;
            }
        }

        throw new \Magento\Framework\Exception\LocalizedException(__('A unique UPC number could not be generated.'));
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
