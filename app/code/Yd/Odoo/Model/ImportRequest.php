<?php

declare(strict_types=1);

namespace Yd\Odoo\Model;

final class ImportRequest
{
    public function __construct(
        public readonly bool $dryRun,
        public readonly ?string $sku,
        public readonly bool $ignoreSchedule
    ) {
    }
}
