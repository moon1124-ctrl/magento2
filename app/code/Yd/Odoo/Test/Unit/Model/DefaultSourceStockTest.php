<?php

declare(strict_types=1);

namespace Yd\Odoo\Test\Unit\Model;

use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryCatalogApi\Api\DefaultSourceProviderInterface;
use PHPUnit\Framework\TestCase;
use Yd\Odoo\Model\DefaultSourceStock;

class DefaultSourceStockTest extends TestCase
{
    public function testQuantityIsWrittenOnlyToTheDefaultSource(): void
    {
        $saved = $this->save(-3.0);

        self::assertSame('ABC', $saved['sku']);
        self::assertSame('default', $saved['source']);
        self::assertSame(0.0, $saved['quantity']);
        self::assertSame(SourceItemInterface::STATUS_OUT_OF_STOCK, $saved['status']);
    }

    public function testPositiveQuantityIsInStockOnTheDefaultSource(): void
    {
        $saved = $this->save(5.0);

        self::assertSame(5.0, $saved['quantity']);
        self::assertSame(SourceItemInterface::STATUS_IN_STOCK, $saved['status']);
    }

    public function testZeroQuantityIsOutOfStock(): void
    {
        $saved = $this->save(0.0);

        self::assertSame(0.0, $saved['quantity']);
        self::assertSame(SourceItemInterface::STATUS_OUT_OF_STOCK, $saved['status']);
    }

    /**
     * @return array{sku: string, source: string, quantity: float, status: int}
     */
    private function save(float $quantity): array
    {
        $record = ['sku' => '', 'source' => '', 'quantity' => -1.0, 'status' => -1];
        $item = $this->createStub(SourceItemInterface::class);
        $item->method('setSku')->willReturnCallback(function (?string $sku) use (&$record): void {
            $record['sku'] = (string) $sku;
        });
        $item->method('setSourceCode')->willReturnCallback(function (?string $source) use (&$record): void {
            $record['source'] = (string) $source;
        });
        $item->method('setQuantity')->willReturnCallback(function (?float $qty) use (&$record): void {
            $record['quantity'] = (float) $qty;
        });
        $item->method('setStatus')->willReturnCallback(function (?int $status) use (&$record): void {
            $record['status'] = (int) $status;
        });
        $factory = $this->createStub(SourceItemInterfaceFactory::class);
        $factory->method('create')->willReturn($item);
        $writer = $this->createMock(SourceItemsSaveInterface::class);
        $writer->expects($this->once())->method('execute')->with($this->callback(
            static function (array $items) use ($item): bool {
                return count($items) === 1 && $items[0] === $item;
            }
        ));
        $provider = $this->createStub(DefaultSourceProviderInterface::class);
        $provider->method('getCode')->willReturn('default');

        (new DefaultSourceStock($writer, $factory, $provider))->setAbsoluteQuantity('ABC', $quantity);

        return $record;
    }
}
