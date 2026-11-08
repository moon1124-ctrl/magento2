<?php

declare(strict_types=1);

namespace Yd\Odoo\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\TestCase;
use Yd\Odoo\Model\XmlRpcClient;

class XmlRpcClientTest extends TestCase
{
    private const ENDPOINT = 'https://odoo.test/xmlrpc/2/object';

    public function testAStringBarcodeKeepsItsLeadingZero(): void
    {
        $decoded = $this->decode(
            '<methodResponse><params><param><value><array><data><value><struct>'
            . '<member><name>id</name><value><int>5</int></value></member>'
            . '<member><name>barcode</name><value><string>036000291452</string></value></member>'
            . '<member><name>write_date</name><value><dateTime.iso8601>20200101T00:00:00</dateTime.iso8601></value></member>'
            . '<member><name>qty_available</name><value><double>2.5</double></value></member>'
            . '<member><name>empty</name><value><nil/></value></member>'
            . '</struct></value></data></array></value></param></params></methodResponse>'
        );

        self::assertIsString($decoded[0]['barcode']);
        self::assertSame('036000291452', $decoded[0]['barcode']);
        self::assertSame(5, $decoded[0]['id']);
        self::assertSame('20200101T00:00:00', $decoded[0]['write_date']);
        self::assertSame(2.5, $decoded[0]['qty_available']);
        self::assertNull($decoded[0]['empty']);
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

    public function testExecuteKwEncodingEscapesThePasswordAndSendsAnEmptyStruct(): void
    {
        $body = '';
        $options = [];
        $curl = $this->curl(200, '<methodResponse><params><param><value><int>7</int></value></param></params></methodResponse>', $body, $options);

        (new XmlRpcClient($curl))->call('https://odoo.test/xmlrpc/2/common', 'authenticate', [
            'shop',
            'fixture-user',
            'a&b<c>',
            new \stdClass(),
        ]);

        self::assertStringContainsString('<methodName>authenticate</methodName>', $body);
        self::assertStringContainsString('<string>a&amp;b&lt;c&gt;</string>', $body);
        self::assertStringContainsString('<struct></struct>', $body);
        self::assertStringNotContainsString('a&b<c>', $body);
        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_REDIR_PROTOCOLS]);
    }

    public function testAFaultDoesNotReturnTheResponseBody(): void
    {
        $body = '';
        $curl = $this->curl(
            200,
            '<methodResponse><fault><value><struct>'
            . '<member><name>faultCode</name><value><int>1</int></value></member>'
            . '<member><name>faultString</name><value><string>AccessDenied</string></value></member>'
            . '</struct></value></fault></methodResponse>',
            $body
        );

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Odoo fault: AccessDenied');
        (new XmlRpcClient($curl))->call(self::ENDPOINT, 'execute_kw', []);
    }

    public function testAnHttpErrorDoesNotIncludeTheResponseBody(): void
    {
        $body = '';
        $curl = $this->curl(500, 'fixture-password leaked in the body', $body);

        try {
            (new XmlRpcClient($curl))->call(self::ENDPOINT, 'execute_kw', []);
            self::fail('The HTTP error was ignored.');
        } catch (LocalizedException $exception) {
            self::assertStringContainsString('HTTP 500', $exception->getMessage());
            self::assertStringNotContainsString('fixture-password', $exception->getMessage());
        }
    }

    public function testATransportFailureIsReportedWithoutCallingOdooAgain(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->expects($this->once())->method('post')->willThrowException(new \RuntimeException('SSL certificate problem'));
        $curl->expects($this->exactly(2))->method('setOptions');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('SSL certificate problem');
        (new XmlRpcClient($curl))->call(self::ENDPOINT, 'execute_kw', []);
    }

    public function testLegacyPathsAndWriteMethodsAreRejectedBeforePost(): void
    {
        $curl = $this->createMock(Curl::class);
        $curl->expects($this->never())->method('post');
        $client = new XmlRpcClient($curl);

        foreach ([
            ['http://odoo.test/xmlrpc/2/object', 'execute_kw'],
            ['https://user:secret@odoo.test/xmlrpc/2/object', 'execute_kw'],
            ['https://odoo.test/xmlrpc/object', 'execute_kw'],
            ['https://odoo.test/xmlrpc/2/object', 'login'],
            ['https://odoo.test/xmlrpc/2/object', 'execute'],
        ] as [$endpoint, $method]) {
            try {
                $client->call($endpoint, $method, []);
                self::fail($endpoint . ' ' . $method . ' was posted');
            } catch (LocalizedException) {
                self::assertTrue(true);
            }
        }
    }

    public function testADoctypeIsRejected(): void
    {
        $body = '';
        $curl = $this->curl(200, '<!DOCTYPE foo [<!ENTITY x "y">]><methodResponse></methodResponse>', $body);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('not XML-RPC');
        (new XmlRpcClient($curl))->call(self::ENDPOINT, 'execute_kw', []);
    }

    /**
     * @param array<int, mixed> $options
     */
    private function curl(int $status, string $response, string &$body, array &$options = []): Curl
    {
        $curl = $this->createMock(Curl::class);
        $curl->expects($this->once())->method('post')->with(self::anything(), self::isString())->willReturnCallback(
            function (string $endpoint, string $payload) use (&$body): void {
                self::assertStringStartsWith('https://', $endpoint);
                $body = $payload;
            }
        );
        $curl->expects($this->exactly(2))->method('setOptions')->willReturnCallback(
            function (array $value) use (&$options): void {
                if ($value !== []) {
                    $options = $value;
                }
            }
        );
        $curl->method('getStatus')->willReturn($status);
        $curl->method('getBody')->willReturn($response);

        return $curl;
    }

    private function decode(string $response): mixed
    {
        $body = '';

        return (new XmlRpcClient($this->curl(200, $response, $body)))->call(self::ENDPOINT, 'execute_kw', []);
    }
}
