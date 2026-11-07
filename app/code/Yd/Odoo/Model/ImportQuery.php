<?php

declare(strict_types=1);

namespace Yd\Odoo\Model;

/**
 * Odoo 10 product.product search domain and a stable read order.
 *
 * product.product read does not preserve the search order, so callers sort
 * the returned rows before moving the cursor.
 */
class ImportQuery
{
    public const ORDER = 'write_date asc, id asc';

    /**
     * @param array{write_date: string, id: int}|null $cursor
     * @return array<int, mixed>
     */
    public function domain(?array $cursor, ?string $sku): array
    {
        if ($sku !== null && $sku !== '') {
            return [['default_code', '=', $sku]];
        }
        if ($cursor === null) {
            return [['default_code', '!=', false]];
        }

        return [
            '&',
            ['default_code', '!=', false],
            '|',
            ['write_date', '>', $cursor['write_date']],
            '&',
            ['write_date', '=', $cursor['write_date']],
            ['id', '>', $cursor['id']],
        ];
    }

    /**
     * @param array<int, mixed> $rows
     * @return list<array<string, mixed>>
     */
    public function sort(array $rows): array
    {
        $products = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $products[] = $row;
            }
        }
        usort($products, static function (array $left, array $right): int {
            $byDate = strcmp((string) ($left['write_date'] ?? ''), (string) ($right['write_date'] ?? ''));
            if ($byDate !== 0) {
                return $byDate;
            }

            return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
        });

        return $products;
    }
}
