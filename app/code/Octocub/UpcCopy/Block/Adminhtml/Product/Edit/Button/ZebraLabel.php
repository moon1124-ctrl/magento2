<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Block\Adminhtml\Product\Edit\Button;

use Magento\Catalog\Block\Adminhtml\Product\Edit\Button\Generic;

class ZebraLabel extends Generic
{
    public function getButtonData()
    {
        $product = $this->getProduct();
        if (!$product || !$product->getId()) {
            return [];
        }

        return [
            'label' => __('Zebra label'),
            'class' => 'action-secondary',
            'on_click' => sprintf("location.href = '%s';", $this->getUrl('octocub_upc/product/printLabel', ['id' => $product->getId()])),
            'sort_order' => 25,
        ];
    }
}
