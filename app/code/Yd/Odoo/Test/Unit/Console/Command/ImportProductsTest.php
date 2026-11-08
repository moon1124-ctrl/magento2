<?php

declare(strict_types=1);

namespace Yd\Odoo\Test\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Yd\Odoo\Console\Command\ImportProducts;
use Yd\Odoo\Model\ImportResult;
use Yd\Odoo\Model\ProductImporter;

class ImportProductsTest extends TestCase
{
    public function testNoOptionsDoesNotReadWhenTheScheduleIsOff(): void
    {
        $importer = $this->createMock(ProductImporter::class);
        $importer->expects($this->once())->method('import')->willReturn($this->idle(false));
        $importer->expects($this->never())->method('preview');
        $importer->expects($this->never())->method('importOne');

        $tester = new CommandTester(new ImportProducts($importer));
        $status = $tester->execute([]);

        self::assertSame(0, $status);
        self::assertStringContainsString('turned off', $tester->getDisplay());
    }

    public function testDryRunDoesNotStartTheScheduledImport(): void
    {
        $importer = $this->createMock(ProductImporter::class);
        $importer->expects($this->never())->method('import');
        $importer->expects($this->never())->method('importOne');
        $importer->expects($this->once())->method('preview')->with('ABC')->willReturn(new ImportResult(
            true,
            true,
            0,
            0,
            0,
            null,
            [[
                'sku' => 'ABC',
                'odoo_id' => 1,
                'upc' => '036000291452',
                'ean' => '0036000291452',
                'quantity' => 2.0,
            ]]
        ));

        $tester = new CommandTester(new ImportProducts($importer));
        $status = $tester->execute(['--dry-run' => true, '--sku' => 'ABC']);

        self::assertSame(0, $status);
        self::assertStringContainsString('Dry run', $tester->getDisplay());
        self::assertStringContainsString('Magento was not changed', $tester->getDisplay());
        self::assertStringContainsString('036000291452', $tester->getDisplay());
        self::assertStringContainsString('0036000291452', $tester->getDisplay());
    }

    public function testSkuImportsThatProductOnly(): void
    {
        $importer = $this->createMock(ProductImporter::class);
        $importer->expects($this->never())->method('import');
        $importer->expects($this->never())->method('preview');
        $importer->expects($this->once())->method('importOne')->with('ABC')->willReturn($this->idle(true));

        $tester = new CommandTester(new ImportProducts($importer));

        self::assertSame(0, $tester->execute(['--sku' => 'ABC']));
    }

    private function idle(bool $ran): ImportResult
    {
        return new ImportResult($ran, false, 0, 0, 0, null, []);
    }
}
