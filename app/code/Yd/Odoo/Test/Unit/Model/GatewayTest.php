<?php

declare(strict_types=1);

namespace Yd\Odoo\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Yd\Odoo\Model\Config;
use Yd\Odoo\Model\Gateway;
use Yd\Odoo\Model\XmlRpcClient;

class GatewayTest extends TestCase
{
    public function testAuthenticateAndExecuteKwUseTheXmlRpc2Endpoints(): void
    {
        $calls = [];
        $client = $this->createMock(XmlRpcClient::class);
        $client->expects($this->exactly(2))->method('call')->willReturnCallback(
            function (string $endpoint, string $method, array $params) use (&$calls) {
                self::assertStringStartsWith('https://odoo.test/xmlrpc/2/', $endpoint);
                self::assertStringNotContainsString('fixture-password', $endpoint);
                $calls[] = [$endpoint, $method, $params];

                return $method === 'authenticate' ? 7 : [11];
            }
        );

        $result = $this->gateway($client)->execute(
            'product.product',
            'search',
            [[['default_code', '=', 'ABC']], 0, 1, 'write_date asc, id asc'],
            true
        );

        self::assertSame([11], $result);
        self::assertSame('https://odoo.test/xmlrpc/2/common', $calls[0][0]);
        self::assertSame('authenticate', $calls[0][1]);
        self::assertSame('shop', $calls[0][2][0]);
        self::assertSame('fixture-user', $calls[0][2][1]);
        self::assertSame('fixture-password', $calls[0][2][2]);
        self::assertInstanceOf(\stdClass::class, $calls[0][2][3]);
        self::assertSame([], get_object_vars($calls[0][2][3]));
        self::assertSame('https://odoo.test/xmlrpc/2/object', $calls[1][0]);
        self::assertSame('execute_kw', $calls[1][1]);
        self::assertSame([
            'shop',
            7,
            'fixture-password',
            'product.product',
            'search',
            [[['default_code', '=', 'ABC']], 0, 1, 'write_date asc, id asc'],
            ['context' => ['lang' => 'en_US']],
        ], $calls[1][2]);
    }

    public function testAFalseAuthenticateResponseIsRejected(): void
    {
        $client = $this->createMock(XmlRpcClient::class);
        $client->expects($this->once())->method('call')->willReturn(false);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Odoo login was rejected.');
        $this->gateway($client)->execute('product.product', 'read', [[[1], ['name']]], true);
    }

    public function testWriteCreateAndUnlinkAreRefusedBeforeARequestIsBuilt(): void
    {
        $client = $this->createMock(XmlRpcClient::class);
        $client->expects($this->never())->method('call');
        $gateway = $this->gateway($client);

        foreach (['write', 'create', 'unlink'] as $method) {
            try {
                $gateway->execute('product.product', $method, [[]], true);
                self::fail($method . ' was sent to Odoo');
            } catch (LocalizedException $exception) {
                self::assertStringContainsString($method, $exception->getMessage());
            }
        }
    }

    public function testFaultTextDoesNotKeepThePasswordOrUsername(): void
    {
        $client = $this->createStub(XmlRpcClient::class);
        $client->method('call')->willThrowException(
            new LocalizedException(__('Odoo fault: fixture-user failed fixture-password'))
        );

        try {
            $this->gateway($client)->execute('product.product', 'search', [[]], true);
            self::fail('The fault was not reported.');
        } catch (LocalizedException $exception) {
            self::assertStringNotContainsString('fixture-password', $exception->getMessage());
            self::assertStringNotContainsString('fixture-user', $exception->getMessage());
            self::assertStringContainsString('***', $exception->getMessage());
        }
    }

    private function gateway(XmlRpcClient $client): Gateway
    {
        $config = $this->createStub(Config::class);
        $config->method('database')->willReturn('shop');
        $config->method('username')->willReturn('fixture-user');
        $config->method('password')->willReturn('fixture-password');
        $config->method('endpoint')->willReturnCallback(
            static fn (string $service): string => 'https://odoo.test/xmlrpc/2/' . $service
        );

        return new Gateway($config, $client);
    }
}
