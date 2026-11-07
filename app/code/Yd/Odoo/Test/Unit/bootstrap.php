<?php

declare(strict_types=1);

require dirname(__DIR__, 6) . '/vendor/autoload.php';

if (!class_exists(\Magento\Catalog\Model\ProductFactory::class)) {
    require __DIR__ . '/ProductFactoryStub.php';
}
if (!class_exists(\Magento\InventoryApi\Api\Data\SourceItemInterfaceFactory::class)) {
    require __DIR__ . '/SourceItemInterfaceFactoryStub.php';
}

\Magento\Framework\Phrase::setRenderer(new \Magento\Framework\Phrase\Renderer\Placeholder());
