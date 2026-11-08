<?php

declare(strict_types=1);

namespace Yd\Odoo\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Yd\Odoo\Model\Config;

class ConfigEndpointTest extends TestCase
{
    public function testHttpsUsesTheXmlRpc2PathsAndDoesNotInheritPort8069(): void
    {
        $config = $this->config('https://odoo.example.com', '8069');

        self::assertSame('https://odoo.example.com/xmlrpc/2/common', $config->endpoint('common'));
        self::assertSame('https://odoo.example.com/xmlrpc/2/object', $config->endpoint('object'));
    }

    public function testAnExplicitTlsPortAndSubpathArePreserved(): void
    {
        $config = $this->config('https://odoo.example.com:8443/odoo/', '8069');

        self::assertSame('https://odoo.example.com:8443/odoo/xmlrpc/2/object', $config->endpoint('object'));
    }

    public function testAPastedXmlRpcPathIsNotDoubled(): void
    {
        $config = $this->config('https://odoo.example.com/xmlrpc/2/object', '8069');

        self::assertSame('https://odoo.example.com/xmlrpc/2/common', $config->endpoint('common'));
    }

    public function testACustomPortIsUsedWhenTheUrlOmitsOne(): void
    {
        $config = $this->config('https://odoo.example.com', '8443');

        self::assertSame('https://odoo.example.com:8443/xmlrpc/2/object', $config->endpoint('object'));
    }

    public function testHttpAndCredentialsInTheUrlAreRefused(): void
    {
        foreach ([
            'http://odoo.example.com',
            'https://fixture-user:fixture-password@odoo.example.com',
            'odoo.example.com',
            '',
        ] as $url) {
            try {
                $this->config($url, '8069')->endpoint('object');
                self::fail($url . ' was accepted');
            } catch (LocalizedException $exception) {
                self::assertStringNotContainsString('fixture-password', $exception->getMessage());
            }
        }
    }

    private function config(string $url, string $port): Config
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(static function (string $path) use ($url, $port): string {
            return match ($path) {
                'yd_odoo/connection/url' => $url,
                'yd_odoo/connection/port' => $port,
                default => '',
            };
        });

        return new Config($scope, $this->createStub(EncryptorInterface::class));
    }
}
