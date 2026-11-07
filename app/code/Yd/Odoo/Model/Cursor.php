<?php

declare(strict_types=1);

namespace Yd\Odoo\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Remembers the last Odoo product imported so the next run continues from there.
 */
class Cursor
{
    private const PATH = 'yd_odoo/sync/cursor';

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * @return array{write_date: string, id: int}|null
     */
    public function read(): ?array
    {
        $value = $this->resource->getConnection()->fetchOne(
            'SELECT value FROM ' . $this->resource->getTableName('core_config_data')
            . " WHERE path = ? AND scope = 'default' AND scope_id = 0",
            [self::PATH]
        );
        if (!is_string($value) || !str_contains($value, '|')) {
            return null;
        }
        [$date, $id] = explode('|', $value, 2);

        return ['write_date' => $date, 'id' => (int) $id];
    }

    public function write(string $writeDate, int $id): void
    {
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName('core_config_data'),
            ['scope' => 'default', 'scope_id' => 0, 'path' => self::PATH, 'value' => $writeDate . '|' . $id],
            ['value']
        );
    }
}
