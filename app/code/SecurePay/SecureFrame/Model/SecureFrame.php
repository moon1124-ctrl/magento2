<?php

declare(strict_types=1);

namespace SecurePay\SecureFrame\Model;

use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Model\Order;

class SecureFrame extends AbstractMethod
{
    public const CODE = 'secureframe';

    protected $_code = self::CODE;

    protected $_isInitializeNeeded = true;

    protected $_canAuthorize = true;

    protected $_canCapture = true;

    protected $_canRefund = false;

    protected $_canUseCheckout = true;

    protected $_canUseInternal = true;

    protected $_isGateway = true;

    public function initialize($paymentAction, $stateObject)
    {
        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus(Order::STATE_PENDING_PAYMENT);
        $stateObject->setIsNotified(false);

        return $this;
    }

    public function canUseForCurrency($currencyCode)
    {
        $accepted = (string) $this->getConfigData('currency_accepted');
        if ($accepted === 'M') {
            return true;
        }

        return $accepted === 'A' && $currencyCode === 'AUD';
    }

    public function isAvailable(?CartInterface $quote = null)
    {
        if (!parent::isAvailable($quote)) {
            return false;
        }

        return trim((string) $this->getConfigData('merchant_id')) !== '';
    }
}
