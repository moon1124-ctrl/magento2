<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Allocates the next UPC-A body from octocub_upc_sequence.
 *
 * Product save does not call this. Odoo assigns GS1 identifiers. The sequence
 * table stays so an existing counter is not dropped.
 */
class Generator
{
    private const DATA_LENGTH = 11;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ResourceConnection $resource
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag('octocub_upc/generate/enabled');
    }

    /**
     * Next UPC-A: 11 data digits plus one check digit.
     *
     * @throws LocalizedException
     */
    public function next(): string
    {
        $prefix = PrefixValidator::normalize((string) $this->scopeConfig->getValue('octocub_upc/generate/prefix'));
        if ($prefix === '') {
            throw new LocalizedException(
                __('Set a GS1 company prefix of 6 to 10 digits before generating UPC-A numbers.')
            );
        }
        $width = self::DATA_LENGTH - strlen($prefix);
        if ($width < 1) {
            throw new LocalizedException(
                __('The GS1 company prefix must be 6 to 10 digits so a UPC-A number keeps at least one sequence digit.')
            );
        }
        $serial = $this->allocate($prefix, $width);
        $body = $prefix . $serial;
        if (strlen($body) !== self::DATA_LENGTH) {
            throw new LocalizedException(__('UPC-A generation produced %1 data digits instead of 11.', strlen($body)));
        }

        return CheckDigit::append($body);
    }

    /**
     * Reserve the next serial under a row lock. The lock is held until the surrounding
     * product-save transaction commits, so a failed save rolls the number back.
     *
     * @throws LocalizedException
     */
    private function allocate(string $prefix, int $width): string
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('octocub_upc_sequence');
        $maximum = (10 ** $width) - 1;
        $connection->beginTransaction();
        try {
            $next = $this->reserve($connection, $table, $prefix);
            if ($next > $maximum) {
                throw new LocalizedException(__('The UPC sequence for this prefix is exhausted.'));
            }
            $updated = $connection->update(
                $table,
                ['next_number' => $next + 1],
                ['prefix = ?' => $prefix]
            );
            if ($updated !== 1) {
                throw new LocalizedException(__('The UPC sequence for this prefix could not be reserved.'));
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            $this->rollBack($connection);
            throw $exception;
        }

        return str_pad((string) $next, $width, '0', STR_PAD_LEFT);
    }

    /**
     * @throws LocalizedException
     */
    private function reserve(AdapterInterface $connection, string $table, string $prefix): int
    {
        $current = $this->fetchLocked($connection, $table, $prefix);
        if ($current === false || $current === null || $current === '') {
            try {
                $connection->insert($table, ['prefix' => $prefix, 'next_number' => 1]);
            } catch (\Throwable $insertError) {
                try {
                    $current = $this->fetchLocked($connection, $table, $prefix);
                } catch (\Throwable) {
                    throw $insertError;
                }
                if ($current === false || $current === null || $current === '') {
                    throw $insertError;
                }

                return $this->positiveInt($current);
            }
            $current = $this->fetchLocked($connection, $table, $prefix);
        }

        return $this->positiveInt($current);
    }

    private function fetchLocked(AdapterInterface $connection, string $table, string $prefix): mixed
    {
        $sql = sprintf(
            'SELECT next_number FROM %s WHERE prefix = ?',
            $connection->quoteIdentifier($table)
        );

        return $connection->fetchOne($connection->forUpdate($sql), [$prefix]);
    }

    /**
     * @throws LocalizedException
     */
    private function positiveInt(mixed $current): int
    {
        $next = (int) $current;
        if ($next < 1) {
            throw new LocalizedException(__('The UPC sequence for this prefix is exhausted.'));
        }

        return $next;
    }

    private function rollBack(AdapterInterface $connection): void
    {
        try {
            $connection->rollBack();
        } catch (\Throwable) {
            // Keep the original failure when the connection is already closed.
        }
    }
}
