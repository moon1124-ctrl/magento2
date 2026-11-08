<?php

declare(strict_types=1);

namespace Yd\Odoo\Test\Unit\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ProductFactory;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Yd\Odoo\Model\BarcodeMapper;
use Yd\Odoo\Model\Config;
use Yd\Odoo\Model\Cursor;
use Yd\Odoo\Model\DefaultSourceStock;
use Yd\Odoo\Model\Gateway;
use Yd\Odoo\Model\ImportQuery;
use Yd\Odoo\Model\MapStore;
use Yd\Odoo\Model\ProductImporter;
use Yd\Odoo\Model\XmlRpcClient;

class DryRunTransportTest extends TestCase
{
    public function testDryRunSkuAuthenticatesAndReadsWithoutWritingEitherSystem(): void
    {
        $posts = [];
        $options = [];
        $responses = [
            $this->value('<int>7</int>'),
            $this->value('<array><data><value><int>4</int></value></data></array>'),
            $this->value(
                '<array><data><value><struct>'
                . '<member><name>id</name><value><int>4</int></value></member>'
                . '<member><name>default_code</name><value><string>ABC</string></value></member>'
                . '<member><name>name</name><value><string>Sample</string></value></member>'
                . '<member><name>barcode</name><value><string>036000291452</string></value></member>'
                . '<member><name>qty_available</name><value><double>2.5</double></value></member>'
                . '<member><name>write_date</name><value><string>2020-01-01 00:00:00</string></value></member>'
                . '<member><name>sale_ok</name><value><boolean>1</boolean></value></member>'
                . '</struct></value></data></array>'
            ),
        ];
        $cursor = 0;
        $curl = $this->createMock(Curl::class);
        $curl->expects($this->exactly(3))->method('post')->willReturnCallback(
            function (string $endpoint, string $body) use (&$posts): void {
                $posts[] = [$endpoint, $body];
            }
        );
        $curl->expects($this->exactly(6))->method('setOptions')->willReturnCallback(
            function (array $value) use (&$options): void {
                if ($value !== []) {
                    $options = $value;
                }
            }
        );
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturnCallback(function () use (&$cursor, $responses): string {
            $body = $responses[$cursor];
            $cursor++;

            return $body;
        });
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects($this->never())->method('get');
        $repository->expects($this->never())->method('save');
        $stock = $this->createMock(DefaultSourceStock::class);
        $stock->expects($this->never())->method('setAbsoluteQuantity');
        $maps = $this->createMock(MapStore::class);
        $maps->expects($this->never())->method('saveProduct');
        $cursorStore = $this->createMock(Cursor::class);
        $cursorStore->expects($this->never())->method('read');
        $cursorStore->expects($this->never())->method('write');
        $importer = new ProductImporter(
            $this->config(),
            new Gateway($this->config(), new XmlRpcClient($curl)),
            $cursorStore,
            $maps,
            $repository,
            $this->createStub(ProductFactory::class),
            $stock,
            new BarcodeMapper(),
            new ImportQuery(),
            $this->createStub(EavConfig::class),
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(LoggerInterface::class)
        );

        $result = $importer->preview('ABC');

        self::assertTrue($result->dryRun);
        self::assertSame(0, $result->saved);
        self::assertNull($result->cursor);
        self::assertSame('036000291452', $result->products[0]['upc']);
        self::assertSame('0036000291452', $result->products[0]['ean']);
        self::assertSame(2.5, $result->products[0]['quantity']);
        self::assertSame('https://odoo.test/xmlrpc/2/common', $posts[0][0]);
        self::assertSame('https://odoo.test/xmlrpc/2/object', $posts[1][0]);
        self::assertSame('https://odoo.test/xmlrpc/2/object', $posts[2][0]);
        self::assertStringContainsString('<methodName>authenticate</methodName>', $posts[0][1]);
        self::assertStringContainsString('<struct></struct>', $posts[0][1]);
        self::assertStringContainsString('<string>search</string>', $posts[1][1]);
        self::assertStringContainsString('<string>ABC</string>', $posts[1][1]);
        self::assertStringContainsString('<string>read</string>', $posts[2][1]);
        foreach ($posts as [$endpoint, $body]) {
            self::assertStringNotContainsString('fixture-password', $endpoint);
            self::assertStringNotContainsString('<string>write</string>', $body);
            self::assertStringNotContainsString('<string>create</string>', $body);
            self::assertStringNotContainsString('<string>unlink</string>', $body);
            self::assertStringNotContainsString('<methodName>execute</methodName>', $body);
        }
        self::assertStringContainsString('fixture-password', $posts[0][1]);
        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
    }

    private function config(): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('database')->willReturn('shop');
        $config->method('username')->willReturn('fixture-user');
        $config->method('password')->willReturn('fixture-password');
        $config->method('endpoint')->willReturnCallback(
            static fn (string $service): string => 'https://odoo.test/xmlrpc/2/' . $service
        );
        $config->method('importProducts')->willReturn(false);

        return $config;
    }

    private function value(string $inner): string
    {
        return '<?xml version="1.0"?><methodResponse><params><param><value>' . $inner . '</value></param></params></methodResponse>';
    }
}
