<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

class Generator
{
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ResourceConnection $resource
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag('octocub_upc/generate/enabled');
    }

    public function next(): string
    {
        $prefix = preg_replace('/\D/', '', (string) $this->scopeConfig->getValue('octocub_upc/generate/prefix')) ?? '';
        $length = strlen($prefix);
        if ($length < 6 || $length > 11) {
            throw new LocalizedException(__('Set a GS1 company prefix of 6 to 11 digits before generating UPC numbers.'));
        }
        $width = 12 - $length;
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('octocub_upc_sequence');
        $connection->beginTransaction();
        try {
            $connection->insertOnDuplicate($table, ['prefix' => $prefix, 'next_number' => 1], ['prefix']);
            $next = (int) $connection->fetchOne(
                'SELECT next_number FROM ' . $table . ' WHERE prefix = ? FOR UPDATE',
                [$prefix]
            );
            $serial = str_pad((string) $next, $width, '0', STR_PAD_LEFT);
            if (strlen($serial) > $width) {
                throw new LocalizedException(__('The UPC sequence for this prefix is exhausted.'));
            }
            $body = $prefix . $serial;
            $connection->update($table, ['next_number' => $next + 1], ['prefix = ?' => $prefix]);
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }

        return $body . $this->checkDigit($body);
    }

    private function checkDigit(string $body): string
    {
        $sum = 0;
        $digits = str_split($body);
        foreach ($digits as $index => $digit) {
            $sum += ((int) $digit) * ($index % 2 === 0 ? 1 : 3);
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }
}
