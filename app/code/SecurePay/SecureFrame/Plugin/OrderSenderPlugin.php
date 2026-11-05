<?php

declare(strict_types=1);

namespace SecurePay\SecureFrame\Plugin;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use SecurePay\SecureFrame\Model\SecureFrame;

class OrderSenderPlugin
{
    /**
     * @param mixed $forceSyncMode
     */
    public function aroundSend(OrderSender $subject, callable $proceed, Order $order, $forceSyncMode = false)
    {
        $payment = $order->getPayment();
        if ($payment
            && $payment->getMethod() === SecureFrame::CODE
            && $order->getState() === Order::STATE_PENDING_PAYMENT
        ) {
            return false;
        }

        return $proceed($order, $forceSyncMode);
    }
}
