<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Test\Unit\Plugin;

use Magento\Catalog\Model\Product;
use Octocub\UpcCopy\Model\Assigner;
use Octocub\UpcCopy\Plugin\CopyBarcode;
use PHPUnit\Framework\TestCase;

class CopyBarcodeTest extends TestCase
{
    public function testBeforeSaveAssignsThroughTheProduct(): void
    {
        $product = $this->createStub(Product::class);
        $assigner = $this->createMock(Assigner::class);
        $assigner->expects($this->once())->method('assign')->with($product);

        (new CopyBarcode($assigner))->beforeSave($product);
    }
}
