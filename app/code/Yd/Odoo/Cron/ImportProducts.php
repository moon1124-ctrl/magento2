<?php

declare(strict_types=1);

namespace Yd\Odoo\Cron;

use Psr\Log\LoggerInterface;
use Yd\Odoo\Model\ProductImporter;

class ImportProducts
{
    public function __construct(
        private readonly ProductImporter $importer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        try {
            $this->importer->import();
        } catch (\Throwable $exception) {
            $this->logger->error('Odoo import stopped: ' . $exception->getMessage());
        }
    }
}
