<?php

declare(strict_types=1);

namespace Citybeach\Catchfeeder\Model;

use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Quote\Api\Data\CartInterface;

/**
 * Offline method for marketplace orders. It is not offered in checkout.
 */
class CatchPayment extends AbstractMethod
{
    public const CODE = 'catchfeederpayment';

    protected $_code = self::CODE;

    protected $_isOffline = true;

    protected $_canUseCheckout = false;

    protected $_canUseInternal = false;

    public function isAvailable(?CartInterface $quote = null)
    {
        return (bool) (int) $this->getConfigData('active');
    }
}
