<?php

declare(strict_types=1);

namespace Yd\Odoo\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Yd\Odoo\Model\ProductImporter;

/**
 * Reads Odoo only when an operator runs it. It does not turn the 15-minute sync on.
 */
class ImportProducts extends Command
{
    public function __construct(private readonly ProductImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('yd:odoo:import');
        $this->setDescription('Import products from Odoo. The scheduled sync stays off unless it is already enabled.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the barcode mapping without saving products, stock, or the import cursor.');
        $this->addOption('sku', null, InputOption::VALUE_REQUIRED, 'Read one product by SKU. This does not move the import cursor.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sku = $input->getOption('sku');
        $sku = is_string($sku) ? trim($sku) : '';
        $sku = $sku === '' ? null : $sku;
        try {
            if ((bool) $input->getOption('dry-run')) {
                $result = $this->importer->preview($sku);
                $output->writeln('Dry run. Magento was not changed. Odoo was only authenticated, searched, and read.');
            } elseif ($sku !== null) {
                $result = $this->importer->importOne($sku);
            } else {
                $result = $this->importer->import();
            }
        } catch (\Throwable $exception) {
            $output->writeln($exception->getMessage());

            return Command::FAILURE;
        }
        if (!$result->ran) {
            $output->writeln('Odoo product import is turned off. Nothing was read from Odoo.');

            return Command::SUCCESS;
        }
        foreach ($result->products as $product) {
            $output->writeln(sprintf(
                '%s upc=%s ean=%s qty=%s',
                $product['sku'],
                $product['upc'] ?? '',
                $product['ean'] ?? '',
                $product['quantity']
            ));
        }
        $output->writeln(sprintf(
            'saved=%d failed=%d skipped=%d',
            $result->saved,
            $result->failed,
            $result->skipped
        ));

        return $result->failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
