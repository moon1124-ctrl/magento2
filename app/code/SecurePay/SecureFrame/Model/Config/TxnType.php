<?php

declare(strict_types=1);

namespace SecurePay\SecureFrame\Model\Config;

use Magento\Framework\Data\OptionSourceInterface;

class TxnType implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => '0', 'label' => __('Payment')],
            ['value' => '2', 'label' => __('Payment with FraudGuard')],
            ['value' => '4', 'label' => __('Payment with 3D Secure')],
            ['value' => '6', 'label' => __('Payment with FraudGuard and 3D Secure')],
        ];
    }
}
