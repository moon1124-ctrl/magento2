<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Test\Unit;

use PHPUnit\Framework\TestCase;

class BarcodeFeaturesDisabledTest extends TestCase
{
    public function testMagentoDoesNotExposeGenerationOrZebraPrinting(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertFileDoesNotExist($root . '/etc/config.xml');
        self::assertFileDoesNotExist($root . '/etc/adminhtml/system.xml');
        self::assertFileDoesNotExist($root . '/etc/adminhtml/routes.xml');
        self::assertFileDoesNotExist($root . '/Controller/Adminhtml/Product/PrintLabel.php');
        self::assertFileDoesNotExist($root . '/Model/ZebraLabel.php');
        self::assertFileDoesNotExist($root . '/view/adminhtml/ui_component/product_form.xml');
        self::assertFileExists($root . '/Setup/Patch/Data/AddUpcEanProductAttributes.php');
        self::assertFileExists($root . '/etc/db_schema.xml');
    }
}
