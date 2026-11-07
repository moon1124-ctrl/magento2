<?php

declare(strict_types=1);

namespace Yd\Odoo\Test\Unit\Model;

use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\TestCase;
use Yd\Odoo\Model\XmlRpcClient;

class XmlRpcClientTest extends TestCase
{
    public function testAStringBarcodeKeepsItsLeadingZero(): void
    {
        $decoded = $this->decode(
            '<methodResponse><params><param><value><array><data><value><struct>'
            . '<member><name>id</name><value><int>5</int></value></member>'
            . '<member><name>barcode</name><value><string>036000291452</string></value></member>'
            . '</struct></value></data></array></value></param></params></methodResponse>'
        );

        self::assertIsString($decoded[0]['barcode']);
        self::assertSame('036000291452', $decoded[0]['barcode']);
        self::assertSame(5, $decoded[0]['id']);
    }

    public function testAnEmptyOdooBarcodeDecodesAsBooleanFalse(): void
    {
        $decoded = $this->decode(
            '<methodResponse><params><param><value><struct>'
            . '<member><name>barcode</name><value><boolean>0</boolean></value></member>'
            . '</struct></value></param></params></methodResponse>'
        );

        self::assertFalse($decoded['barcode']);
    }

    private function decode(string $body): mixed
    {
        $curl = $this->createMock(Curl::class);
        $curl->expects($this->once())->method('post')->with('http://odoo.test/xmlrpc/object', $this->isString());
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn($body);

        return (new XmlRpcClient($curl))->call('http://odoo.test/xmlrpc/object', 'execute_kw', []);
    }
}
