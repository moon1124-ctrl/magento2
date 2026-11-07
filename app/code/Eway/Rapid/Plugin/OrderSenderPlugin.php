<?php

declare(strict_types=1);

namespace Eway\Rapid\Plugin;

use Eway\Rapid\Model\Eway;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;

class OrderSenderPlugin
{
    /**
     * @param mixed $forceSyncMode
     */
    public function aroundSend(OrderSender $subject, callable $proceed, Order $order, $forceSyncMode = false)
    {
        $payment = $order->getPayment();
        if ($payment
            && $payment->getMethod() === Eway::CODE
            && $order->getState() === Order::STATE_PENDING_PAYMENT
        ) {
            return false;
        }

        return $proceed($order, $forceSyncMode);
    }
}
