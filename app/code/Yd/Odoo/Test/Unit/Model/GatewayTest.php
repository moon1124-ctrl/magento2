<?php

declare(strict_types=1);

namespace Yd\Odoo\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Yd\Odoo\Model\Config;
use Yd\Odoo\Model\Gateway;
use Yd\Odoo\Model\XmlRpcClient;

class GatewayTest extends TestCase
{
    public function testExecuteKwUsesOdoo10PositionalArgumentsAndContext(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('database')->willReturn('shop');
        $config->method('username')->willReturn('user');
        $config->method('password')->willReturn('secret');
        $config->method('endpoint')->willReturnCallback(
            static fn (string $service): string => 'http://odoo.test/xmlrpc/' . $service
        );
        $calls = [];
        $client = $this->createMock(XmlRpcClient::class);
        $client->expects($this->exactly(2))->method('call')->willReturnCallback(
            function (string $endpoint, string $method, array $params) use (&$calls) {
                self::assertStringStartsWith('http://odoo.test/xmlrpc/', $endpoint);
                $calls[] = [$endpoint, $method, $params];

                return $method === 'login' ? 7 : [11];
            }
        );

        $result = (new Gateway($config, $client))->execute(
            'product.product',
            'search',
            [[['default_code', '!=', false]], 0, 50, 'write_date asc, id asc'],
            true
        );

        self::assertSame([11], $result);
        self::assertSame('http://odoo.test/xmlrpc/common', $calls[0][0]);
        self::assertSame('login', $calls[0][1]);
        self::assertSame(['shop', 'user', 'secret'], $calls[0][2]);
        self::assertSame('http://odoo.test/xmlrpc/object', $calls[1][0]);
        self::assertSame('execute_kw', $calls[1][1]);
        self::assertSame([
            'shop',
            7,
            'secret',
            'product.product',
            'search',
            [[['default_code', '!=', false]], 0, 50, 'write_date asc, id asc'],
            ['context' => ['lang' => 'en_US']],
        ], $calls[1][2]);
    }
}
