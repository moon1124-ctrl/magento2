<?php

declare(strict_types=1);

namespace Yd\Odoo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order\Invoice;
use Yd\Odoo\Model\OrderSync;

class InvoiceSave implements ObserverInterface
{
    public function __construct(private readonly OrderSync $orderSync)
    {
    }

    public function execute(Observer $observer): void
    {
        $invoice = $observer->getEvent()->getInvoice();
        if ($invoice instanceof Invoice) {
            $this->orderSync->exportPaidInvoice($invoice);
        }
    }
}
