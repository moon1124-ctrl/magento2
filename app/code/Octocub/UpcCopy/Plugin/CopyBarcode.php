<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Plugin;

use Magento\Catalog\Model\Product;
use Octocub\UpcCopy\Model\Assigner;

class CopyBarcode
{
    public function __construct(private readonly Assigner $assigner)
    {
    }

    public function beforeSave(Product $product): void
    {
        $this->assigner->assign($product);
    }
}
