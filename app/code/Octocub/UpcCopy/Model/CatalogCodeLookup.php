<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Model;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Reads existing UPC and EAN values so a generated code is not reused and a stored code is not replaced.
 */
class CatalogCodeLookup
{
    private const CODES = ['upc', 'ean'];

    /** @var array<string, array{id: int, backend: string, table: string}>|null */
    private ?array $attributes = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig
    ) {
    }

    /**
     * @return string[]
     */
    public function existingCodes(): array
    {
        return array_keys($this->attributes());
    }

    /**
     * Stored values for the product, preferring the global (store 0) row.
     *
     * @return array<string, string|null>
     */
    public function storedValues(int $productId): array
    {
        $values = [];
        foreach (array_keys($this->attributes()) as $code) {
            $values[$code] = null;
        }
        if ($productId < 1 || $values === []) {
            return $values;
        }
        $connection = $this->resource->getConnection();
        foreach ($this->grouped() as $group) {
            foreach ($this->fetchStored($connection, $group, $productId) as $code => $value) {
                $values[$code] = $value;
            }
        }

        return $values;
    }

    public function isUsed(string $code, ?int $exceptProductId = null): bool
    {
        $code = trim($code);
        if ($code === '') {
            return false;
        }
        $connection = $this->resource->getConnection();
        $entity = $connection->quoteIdentifier($this->resource->getTableName('catalog_product_entity'));
        $sql = 'SELECT COUNT(*) FROM ' . $entity . ' WHERE sku = ?';
        $binds = [$code];
        if ($exceptProductId !== null && $exceptProductId > 0) {
            $sql .= ' AND entity_id <> ?';
            $binds[] = $exceptProductId;
        }
        if ((int) $connection->fetchOne($sql, $binds) > 0) {
            return true;
        }
        foreach ($this->grouped() as $group) {
            if ($this->valueExists($connection, $group, $code, $exceptProductId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array{id: int, backend: string, table: string}>
     */
    private function attributes(): array
    {
        if ($this->attributes !== null) {
            return $this->attributes;
        }
        $found = [];
        foreach (self::CODES as $code) {
            try {
                $attribute = $this->eavConfig->getAttribute(Product::ENTITY, $code);
            } catch (\Throwable) {
                continue;
            }
            if (!$attribute) {
                continue;
            }
            $id = (int) $attribute->getAttributeId();
            $backend = (string) $attribute->getBackendType();
            if ($id < 1 || !in_array($backend, ['varchar', 'text', 'int', 'decimal', 'static'], true)) {
                continue;
            }
            $table = $backend === 'static'
                ? 'catalog_product_entity'
                : 'catalog_product_entity_' . $backend;
            $found[$code] = [
                'id' => $id,
                'backend' => $backend,
                'table' => $this->resource->getTableName($table),
            ];
        }

        return $this->attributes = $found;
    }

    /**
     * @return array<int, array{backend: string, table: string, codes: array<string, int>}>
     */
    private function grouped(): array
    {
        $groups = [];
        foreach ($this->attributes() as $code => $meta) {
            $key = $meta['backend'] . "\0" . $meta['table'];
            $groups[$key]['backend'] = $meta['backend'];
            $groups[$key]['table'] = $meta['table'];
            $groups[$key]['codes'][$code] = $meta['id'];
        }

        return array_values($groups);
    }

    /**
     * @param array{backend: string, table: string, codes: array<string, int>} $group
     * @return array<string, string>
     */
    private function fetchStored(AdapterInterface $connection, array $group, int $productId): array
    {
        $table = $connection->quoteIdentifier($group['table']);
        if ($group['backend'] === 'static') {
            $columns = [];
            foreach (array_keys($group['codes']) as $code) {
                $columns[] = $connection->quoteIdentifier($code);
            }
            $sql = 'SELECT ' . implode(', ', $columns)
                . ' FROM ' . $table . ' WHERE entity_id = ?';
            $row = $connection->fetchRow($sql, [$productId]);
            $values = [];
            if (is_array($row)) {
                foreach (array_keys($group['codes']) as $code) {
                    if (isset($row[$code]) && $row[$code] !== null && $row[$code] !== '') {
                        $values[$code] = (string) $row[$code];
                    }
                }
            }

            return $values;
        }

        $ids = array_values($group['codes']);
        $idToCode = array_flip($group['codes']);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $sql = 'SELECT attribute_id, store_id, value FROM ' . $table
            . ' WHERE entity_id = ? AND attribute_id IN (' . $placeholders . ')';
        $rows = $connection->fetchAll($sql, array_merge([$productId], $ids));
        $chosen = [];
        foreach ($rows ?: [] as $row) {
            $code = $idToCode[(int) ($row['attribute_id'] ?? 0)] ?? null;
            $value = $row['value'] ?? null;
            if ($code === null || $value === null || $value === '') {
                continue;
            }
            $storeId = (int) ($row['store_id'] ?? 0);
            if (!isset($chosen[$code]) || $storeId === 0) {
                $chosen[$code] = (string) $value;
            }
        }

        return $chosen;
    }

    /**
     * @param array{backend: string, table: string, codes: array<string, int>} $group
     */
    private function valueExists(
        AdapterInterface $connection,
        array $group,
        string $value,
        ?int $exceptProductId
    ): bool {
        $table = $connection->quoteIdentifier($group['table']);
        if ($group['backend'] === 'static') {
            $parts = [];
            $binds = [];
            foreach (array_keys($group['codes']) as $code) {
                $parts[] = $connection->quoteIdentifier($code) . ' = ?';
                $binds[] = $value;
            }
            $sql = 'SELECT COUNT(*) FROM ' . $table . ' WHERE (' . implode(' OR ', $parts) . ')';
        } else {
            $ids = array_values($group['codes']);
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $sql = 'SELECT COUNT(*) FROM ' . $table
                . ' WHERE attribute_id IN (' . $placeholders . ') AND value = ?';
            $binds = array_merge($ids, [$value]);
        }
        if ($exceptProductId !== null && $exceptProductId > 0) {
            $sql .= ' AND entity_id <> ?';
            $binds[] = $exceptProductId;
        }

        return (int) $connection->fetchOne($sql, $binds) > 0;
    }
}
