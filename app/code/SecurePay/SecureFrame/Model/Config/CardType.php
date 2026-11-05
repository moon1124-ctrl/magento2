<?php

declare(strict_types=1);

namespace SecurePay\SecureFrame\Model\Config;

use Magento\Framework\Data\OptionSourceInterface;

class CardType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'VISA', 'label' => __('Visa')],
            ['value' => 'MASTERCARD', 'label' => __('Mastercard')],
            ['value' => 'AMEX', 'label' => __('American Express')],
            ['value' => 'DINERS', 'label' => __('Diners')],
            ['value' => 'JCB', 'label' => __('JCB')],
        ];
    }
}
