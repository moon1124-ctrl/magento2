<?php

declare(strict_types=1);

namespace Yd\Odoo\Test\Unit\Model;

use PHPUnit\Framework\TestCase;

class ConfigDefaultsTest extends TestCase
{
    public function testConnectionAndProductImportStayOff(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/config.xml');

        self::assertNotFalse($xml);
        self::assertSame('0', (string) $xml->default->yd_odoo->connection->enabled);
        self::assertSame('0', (string) $xml->default->yd_odoo->sync->import_products);
    }

    public function testTheCommandDoesNotTurnSynchronizationOn(): void
    {
        $di = simplexml_load_file(dirname(__DIR__, 3) . '/etc/di.xml');

        self::assertNotFalse($di);
        $namespaces = $di->getDocNamespaces();
        $di->registerXPathNamespace('x', $namespaces[''] ?? '');
        $items = $di->xpath('//item[@name="yd_odoo_import"]');
        self::assertIsArray($items);
        self::assertCount(1, $items);
        self::assertSame('Yd\Odoo\Console\Command\ImportProducts', (string) $items[0]);
    }
}
