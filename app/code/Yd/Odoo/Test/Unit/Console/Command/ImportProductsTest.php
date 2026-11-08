<?php

declare(strict_types=1);

namespace Yd\Odoo\Test\Unit\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
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

        $tester = new CommandTester(new ImportProducts($importer, $this->areaUnset()));
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

        $tester = new CommandTester(new ImportProducts($importer, $this->areaUnset()));
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

        $tester = new CommandTester(new ImportProducts($importer, $this->areaUnset()));

        self::assertSame(0, $tester->execute(['--sku' => 'ABC']));
    }

    public function testAnAreaAlreadySetByCronIsLeftAlone(): void
    {
        $state = $this->createMock(State::class);
        $state->expects($this->once())->method('getAreaCode')->willReturn(Area::AREA_CRONTAB);
        $state->expects($this->never())->method('setAreaCode');

        $importer = $this->createMock(ProductImporter::class);
        $importer->expects($this->once())->method('import')->willReturn($this->idle(false));
        $importer->expects($this->never())->method('preview');
        $importer->expects($this->never())->method('importOne');

        $tester = new CommandTester(new ImportProducts($importer, $state));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('turned off', $tester->getDisplay());
    }

    private function idle(bool $ran): ImportResult
    {
        return new ImportResult($ran, false, 0, 0, 0, null, []);
    }

    private function areaUnset(): State
    {
        $state = $this->createMock(State::class);
        $state->expects($this->once())
            ->method('getAreaCode')
            ->willThrowException(new LocalizedException(__('Area code is not set')));
        $state->expects($this->once())->method('setAreaCode')->with(Area::AREA_ADMINHTML);

        return $state;
    }
}
