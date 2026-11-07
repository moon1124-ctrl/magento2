<?php

declare(strict_types=1);

namespace Yd\Odoo\Model;

final class ImportResult
{
    /**
     * @param list<array{sku: string, odoo_id: int, upc: string|null, ean: string|null, quantity: float}> $products
     */
    public function __construct(
        public readonly bool $ran,
        public readonly bool $dryRun,
        public readonly int $saved,
        public readonly int $failed,
        public readonly int $skipped,
        public readonly ?string $cursor,
        public readonly array $products
    ) {
    }
}
