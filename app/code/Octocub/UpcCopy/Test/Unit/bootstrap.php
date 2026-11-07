<?php

declare(strict_types=1);

require dirname(__DIR__, 6) . '/vendor/autoload.php';

if (!class_exists(\Magento\Eav\Setup\EavSetupFactory::class)) {
    require __DIR__ . '/EavSetupFactoryStub.php';
}

\Magento\Framework\Phrase::setRenderer(new \Magento\Framework\Phrase\Renderer\Placeholder());
