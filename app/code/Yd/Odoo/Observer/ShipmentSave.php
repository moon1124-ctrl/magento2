<?php

declare(strict_types=1);

namespace Yd\Odoo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Yd\Odoo\Model\OrderSync;

class ShipmentSave implements ObserverInterface
{
    public function __construct(private readonly OrderSync $orderSync)
    {
    }

    public function execute(Observer $observer): void
    {
        $shipment = $observer->getEvent()->getShipment();
        if (!$shipment instanceof Shipment) {
            return;
        }
        $order = $shipment->getOrder();
        if (!$order instanceof Order) {
            return;
        }
        $tracking = '';
        $carrier = '';
        foreach ($shipment->getTracks() as $track) {
            $tracking = (string) $track->getTrackNumber();
            $carrier = (string) $track->getCarrierCode();
            break;
        }
        $this->orderSync->ship($order, (string) $shipment->getIncrementId(), $tracking, $carrier);
    }
}
