<?php

declare(strict_types=1);

namespace Yd\Odoo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Yd\Odoo\Model\OrderSync;

class OrderCancel implements ObserverInterface
{
    public function __construct(private readonly OrderSync $orderSync)
    {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        if ($order instanceof Order) {
            $this->orderSync->cancel($order);
        }
    }
}
