<?php

declare(strict_types=1);

namespace Yd\Odoo\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Psr\Log\LoggerInterface;

/**
 * Exports an order only after Magento has a paid invoice.
 */
class OrderSync
{
    public function __construct(
        private readonly Config $config,
        private readonly Gateway $gateway,
        private readonly MapStore $maps,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductSync $productSync,
        private readonly LoggerInterface $logger
    ) {
    }

    public function exportPaidInvoice(Invoice $invoice): void
    {
        if (!$this->config->exportOrders() || (int) $invoice->getState() !== Invoice::STATE_PAID) {
            return;
        }
        $order = $invoice->getOrder();
        if (!$order instanceof Order) {
            return;
        }
        if ($order->getState() === Order::STATE_PENDING_PAYMENT) {
            return;
        }

        try {
            $odooOrderId = $this->ensureOrder($order);
            if ($odooOrderId === null || $this->maps->orderIsInvoiced((int) $order->getEntityId())) {
                return;
            }
            $this->invoiceAndPay($order, $invoice, $odooOrderId);
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Odoo order sync failed for ' . $order->getIncrementId() . ': ' . $exception->getMessage()
            );
        }
    }

    public function ship(Order $order, string $shipmentIncrementId, string $tracking, string $carrier): void
    {
        if (!$this->config->exportOrders()) {
            return;
        }
        $odooOrderId = $this->maps->orderId((int) $order->getEntityId());
        if ($odooOrderId === null) {
            return;
        }
        try {
            $this->gateway->execute(
                'wk.skeleton',
                'set_order_shipped',
                [$odooOrderId],
                true,
                [
                    'mage_ship_number' => $shipmentIncrementId,
                    'carrier_tracking_ref' => $tracking,
                    'carrier_code' => $carrier,
                ]
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Odoo shipment sync failed: ' . $exception->getMessage());
        }
    }

    public function cancel(Order $order): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }
        $odooOrderId = $this->maps->orderId((int) $order->getEntityId());
        if ($odooOrderId === null) {
            return;
        }
        try {
            $this->gateway->execute('wk.skeleton', 'set_order_cancel', [$odooOrderId], true);
        } catch (\Throwable $exception) {
            $this->logger->error('Odoo cancel sync failed: ' . $exception->getMessage());
        }
    }

    private function ensureOrder(Order $order): ?int
    {
        $existing = $this->maps->orderId((int) $order->getEntityId());
        if ($existing) {
            return $existing;
        }
        $pricelistId = $this->config->pricelistId();
        $warehouseId = $this->config->warehouseId();
        if ($pricelistId <= 0 || $warehouseId <= 0) {
            throw new LocalizedException(__('Odoo pricelist id and warehouse id are required before orders can export.'));
        }
        $partnerId = $this->partnerId($order);
        $created = $this->gateway->execute('wk.skeleton', 'create_order', [[
            'partner_id' => $partnerId,
            'partner_invoice_id' => $partnerId,
            'partner_shipping_id' => $partnerId,
            'pricelist_id' => $pricelistId,
            'date_order' => (string) $order->getCreatedAt(),
            'origin' => (string) $order->getIncrementId(),
            'warehouse_id' => $warehouseId,
            'ecommerce_channel' => 'magento',
            'ecommerce_order_id' => (int) $order->getEntityId(),
            'note' => 'Payment Information:- ' . (string) $order->getPayment()?->getMethod(),
        ]], true);
        if (!is_array($created) || empty($created['status']) || empty($created['order_id'])) {
            $message = is_array($created) ? (string) ($created['status_message'] ?? 'create_order failed') : 'create_order failed';
            throw new LocalizedException(__('Odoo order export failed: %1', $message));
        }
        $odooOrderId = (int) $created['order_id'];
        $this->maps->saveOrder((int) $order->getEntityId(), (string) $order->getIncrementId(), $odooOrderId, false);
        $this->exportLines($order, $odooOrderId);

        return $odooOrderId;
    }

    private function exportLines(Order $order, int $odooOrderId): void
    {
        foreach ($order->getAllItems() as $item) {
            if ($item->getProductType() === Configurable::TYPE_CODE) {
                continue;
            }
            $priced = $item->getParentItem() ?: $item;
            $productId = (int) $item->getProductId();
            $odooProductId = $this->maps->productId($productId);
            if ($odooProductId === null) {
                $loaded = $this->productRepository->getById($productId, false, (int) $order->getStoreId());
                if ($loaded instanceof \Magento\Catalog\Model\Product) {
                    $this->productSync->sync($loaded, true);
                }
                $odooProductId = $this->maps->productId($productId);
            }
            if ($odooProductId === null) {
                $this->logger->error('Odoo product missing for order item ' . $item->getSku());
                continue;
            }
            $this->gateway->execute('wk.skeleton', 'create_sale_order_line', [[
                'order_id' => $odooOrderId,
                'product_id' => $odooProductId,
                'price_unit' => (string) $priced->getPrice(),
                'product_uom_qty' => (string) $item->getQtyOrdered(),
                'name' => (string) $item->getName(),
            ]], true);
        }
    }

    private function invoiceAndPay(Order $order, Invoice $invoice, int $odooOrderId): void
    {
        $this->gateway->execute(
            'wk.skeleton',
            'create_order_invoice',
            [$odooOrderId, (string) $invoice->getIncrementId()],
            true
        );
        $method = (string) $order->getPayment()?->getMethod();
        $journalId = $this->paymentJournal($method);
        if ($journalId > 0) {
            $this->gateway->execute('wk.skeleton', 'set_order_paid', [[
                'order_id' => $odooOrderId,
                'journal_id' => $journalId,
                'ecommerce_invoice_id' => (string) $invoice->getIncrementId(),
            ]]);
        }
        $this->maps->saveOrder((int) $order->getEntityId(), (string) $order->getIncrementId(), $odooOrderId, true);
    }

    private function partnerId(Order $order): int
    {
        $email = (string) $order->getCustomerEmail();
        $existing = $this->maps->partnerId($email);
        if ($existing) {
            return $existing;
        }
        $address = $order->getShippingAddress() ?: $order->getBillingAddress();
        $name = trim((string) $order->getCustomerFirstname() . ' ' . (string) $order->getCustomerLastname());
        if ($address) {
            $name = trim($address->getFirstname() . ' ' . $address->getLastname()) ?: $name;
        }
        $street = $address ? implode(', ', (array) $address->getStreet()) : '';
        $odooId = (int) $this->gateway->execute('res.partner', 'create', [[
            'name' => $name !== '' ? $name : $email,
            'email' => $email,
            'phone' => $address ? (string) $address->getTelephone() : '',
            'street' => $street,
            'city' => $address ? (string) $address->getCity() : '',
            'zip' => $address ? (string) $address->getPostcode() : '',
        ]], true);
        if ($odooId <= 0) {
            throw new LocalizedException(__('Odoo did not return a partner id.'));
        }
        $this->maps->savePartner($email, $odooId);

        return $odooId;
    }

    private function paymentJournal(string $methodCode): int
    {
        if ($methodCode === '') {
            return 0;
        }
        $existing = $this->maps->paymentId($methodCode);
        if ($existing) {
            return $existing;
        }
        $odooId = (int) $this->gateway->execute('bridge.backbone', 'create_payment_method', [[
            'name' => $methodCode,
            'type' => 'cash',
        ]], true);
        if ($odooId > 0) {
            $this->maps->savePayment($methodCode, $odooId);
        }

        return $odooId;
    }
}
