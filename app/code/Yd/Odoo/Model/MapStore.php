<?php

declare(strict_types=1);

namespace Yd\Odoo\Model;

use Magento\Framework\App\ResourceConnection;

class MapStore
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function productId(int $productId): ?int
    {
        $value = $this->connection()->fetchOne(
            'SELECT odoo_id FROM ' . $this->table('yd_odoo_product_map') . ' WHERE product_id = ?',
            [$productId]
        );

        return $value === false ? null : (int) $value;
    }

    public function saveProduct(int $productId, string $sku, int $odooId): void
    {
        $this->connection()->insertOnDuplicate(
            $this->table('yd_odoo_product_map'),
            ['product_id' => $productId, 'sku' => $sku, 'odoo_id' => $odooId],
            ['sku', 'odoo_id']
        );
    }

    public function orderId(int $orderId): ?int
    {
        $value = $this->connection()->fetchOne(
            'SELECT odoo_id FROM ' . $this->table('yd_odoo_order_map') . ' WHERE order_id = ?',
            [$orderId]
        );

        return $value === false ? null : (int) $value;
    }

    public function orderIsInvoiced(int $orderId): bool
    {
        return (int) $this->connection()->fetchOne(
            'SELECT invoiced FROM ' . $this->table('yd_odoo_order_map') . ' WHERE order_id = ?',
            [$orderId]
        ) === 1;
    }

    public function saveOrder(int $orderId, string $incrementId, int $odooId, bool $invoiced): void
    {
        $this->connection()->insertOnDuplicate(
            $this->table('yd_odoo_order_map'),
            [
                'order_id' => $orderId,
                'increment_id' => $incrementId,
                'odoo_id' => $odooId,
                'invoiced' => $invoiced ? 1 : 0,
            ],
            ['increment_id', 'odoo_id', 'invoiced']
        );
    }

    public function partnerId(string $email): ?int
    {
        $value = $this->connection()->fetchOne(
            'SELECT odoo_id FROM ' . $this->table('yd_odoo_partner_map') . ' WHERE email = ?',
            [$email]
        );

        return $value === false ? null : (int) $value;
    }

    public function savePartner(string $email, int $odooId): void
    {
        $this->connection()->insertOnDuplicate(
            $this->table('yd_odoo_partner_map'),
            ['email' => $email, 'odoo_id' => $odooId],
            ['odoo_id']
        );
    }

    public function paymentId(string $methodCode): ?int
    {
        $value = $this->connection()->fetchOne(
            'SELECT odoo_id FROM ' . $this->table('yd_odoo_payment_map') . ' WHERE method_code = ?',
            [$methodCode]
        );

        return $value === false ? null : (int) $value;
    }

    public function savePayment(string $methodCode, int $odooId): void
    {
        $this->connection()->insertOnDuplicate(
            $this->table('yd_odoo_payment_map'),
            ['method_code' => $methodCode, 'odoo_id' => $odooId],
            ['odoo_id']
        );
    }

    private function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }

    private function connection(): \Magento\Framework\DB\Adapter\AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
