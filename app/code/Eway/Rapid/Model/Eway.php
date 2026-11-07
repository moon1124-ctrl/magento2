<?php

declare(strict_types=1);

namespace Eway\Rapid\Model;

use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Model\Order;

class Eway extends AbstractMethod
{
    public const CODE = 'eway';

    protected $_code = self::CODE;

    protected $_isInitializeNeeded = true;

    protected $_canAuthorize = true;

    protected $_canCapture = true;

    protected $_canUseCheckout = true;

    protected $_canUseInternal = false;

    protected $_isGateway = true;

    public function initialize($paymentAction, $stateObject)
    {
        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus(Order::STATE_PENDING_PAYMENT);
        $stateObject->setIsNotified(false);

        return $this;
    }

    public function isAvailable(?CartInterface $quote = null)
    {
        if (!parent::isAvailable($quote)) {
            return false;
        }
        $storeId = $quote ? (int) $quote->getStoreId() : null;

        return $this->apiKeyConfigured($storeId);
    }

    private function apiKeyConfigured(?int $storeId): bool
    {
        $key = (string) $this->getConfigData('api_key');

        return trim($key) !== '';
    }
}
