<?php

declare(strict_types=1);

namespace Yd\Odoo\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;

/**
 * Odoo 10 XML-RPC 2 client. Calls are limited to authenticate and execute_kw.
 */
class XmlRpcClient
{
    private const METHODS = ['authenticate', 'execute_kw'];

    private const MAX_BODY = 2097152;

    public function __construct(private readonly Curl $curl)
    {
    }

    /**
     * @param array<int, mixed> $params
     */
    public function call(string $endpoint, string $method, array $params): mixed
    {
        $this->assertTransport($endpoint, $method);
        $this->curl->setTimeout(30);
        $this->curl->setOptions([
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ]);
        $this->curl->setHeaders(['Content-Type' => 'text/xml; charset=utf-8']);
        try {
            $this->curl->post($endpoint, $this->encodeRequest($method, $params));
            $status = $this->curl->getStatus();
            $body = (string) $this->curl->getBody();
        } catch (\Throwable $exception) {
            throw new LocalizedException(__('Odoo could not be reached: %1', $exception->getMessage()));
        } finally {
            $this->curl->setOptions([]);
        }
        if ($status < 200 || $status >= 300) {
            throw new LocalizedException(__('Odoo returned HTTP %1.', $status));
        }

        return $this->decode($body);
    }

    /**
     * @param array<int, mixed> $params
     */
    private function encodeRequest(string $method, array $params): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><methodCall><methodName>'
            . $this->escape($method)
            . '</methodName><params>';
        foreach ($params as $param) {
            $xml .= '<param><value>' . $this->encodeValue($param) . '</value></param>';
        }

        return $xml . '</params></methodCall>';
    }

    private function encodeValue(mixed $value): string
    {
        if ($value instanceof \stdClass) {
            return $this->encodeStruct(get_object_vars($value));
        }
        if ($value === null) {
            return '<nil/>';
        }
        if (is_bool($value)) {
            return '<boolean>' . ($value ? '1' : '0') . '</boolean>';
        }
        if (is_int($value)) {
            return '<int>' . $value . '</int>';
        }
        if (is_float($value)) {
            return '<double>' . $value . '</double>';
        }
        if (is_string($value)) {
            return '<string>' . $this->escape($value) . '</string>';
        }
        if (!is_array($value)) {
            return '<string>' . $this->escape((string) $value) . '</string>';
        }
        if (array_is_list($value)) {
            $data = '';
            foreach ($value as $item) {
                $data .= '<value>' . $this->encodeValue($item) . '</value>';
            }

            return '<array><data>' . $data . '</data></array>';
        }

        return $this->encodeStruct($value);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function encodeStruct(array $values): string
    {
        $members = '';
        foreach ($values as $name => $item) {
            $members .= '<member><name>' . $this->escape((string) $name) . '</name><value>'
                . $this->encodeValue($item) . '</value></member>';
        }

        return '<struct>' . $members . '</struct>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function assertTransport(string $endpoint, string $method): void
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new LocalizedException(__('Odoo XML-RPC method "%1" is not used.', $method));
        }
        $parts = parse_url($endpoint);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        $allowedPath = str_ends_with($path, '/xmlrpc/2/common') || str_ends_with($path, '/xmlrpc/2/object');
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || !$allowedPath
        ) {
            throw new LocalizedException(__('Odoo XML-RPC requires https://host/xmlrpc/2/common or /xmlrpc/2/object.'));
        }
    }

    private function decode(string $body): mixed
    {
        if ($body === '' || strlen($body) > self::MAX_BODY || preg_match('/<!DOCTYPE|<!ENTITY/i', $body) === 1) {
            throw new LocalizedException(__('Odoo returned a response that is not XML-RPC.'));
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new LocalizedException(__('Odoo returned a response that is not XML-RPC.'));
        }
        if (isset($xml->fault)) {
            $fault = $this->decodeValue($xml->fault->value);
            $message = is_array($fault) ? (string) ($fault['faultString'] ?? 'Odoo fault') : 'Odoo fault';
            throw new LocalizedException(__('Odoo fault: %1', $message));
        }
        if (!isset($xml->params->param->value)) {
            return null;
        }

        return $this->decodeValue($xml->params->param->value);
    }

    private function decodeValue(\SimpleXMLElement $value): mixed
    {
        $child = null;
        foreach ($value->children() as $candidate) {
            $child = $candidate;
            break;
        }
        if ($child === null) {
            return (string) $value;
        }

        return match ($child->getName()) {
            'int', 'i4', 'i8' => (int) $child,
            'double' => (float) $child,
            'boolean' => (string) $child === '1',
            'string', 'dateTime.iso8601' => (string) $child,
            'base64' => $this->decodeBase64((string) $child),
            'nil' => null,
            'array' => $this->decodeArray($child),
            'struct' => $this->decodeStruct($child),
            default => (string) $child,
        };
    }

    private function decodeBase64(string $encoded): string
    {
        $decoded = base64_decode($encoded, true);

        return $decoded === false ? $encoded : $decoded;
    }

    /**
     * @return array<int, mixed>
     */
    private function decodeArray(\SimpleXMLElement $array): array
    {
        $items = [];
        if (!isset($array->data->value)) {
            return $items;
        }
        foreach ($array->data->value as $value) {
            $items[] = $this->decodeValue($value);
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeStruct(\SimpleXMLElement $struct): array
    {
        $items = [];
        foreach ($struct->member as $member) {
            $items[(string) $member->name] = $this->decodeValue($member->value);
        }

        return $items;
    }
}
