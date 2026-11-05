<?php

declare(strict_types=1);

namespace SecurePay\SecureFrame\Model\Config;

use Magento\Framework\Data\OptionSourceInterface;

class CurrencyType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'A', 'label' => __('AUD')],
            ['value' => 'M', 'label' => __('Order currency')],
        ];
    }
}
