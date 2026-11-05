<?php

declare(strict_types=1);

namespace Yd\Odoo\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;

/**
 * Odoo classic XML-RPC (login + execute), which is what the Magento 1 bridge called.
 */
class XmlRpcClient
{
    public function __construct(private readonly Curl $curl)
    {
    }

    /**
     * @param array<int, mixed> $params
     */
    public function call(string $endpoint, string $method, array $params): mixed
    {
        $this->curl->setTimeout(30);
        $this->curl->setHeaders(['Content-Type' => 'text/xml']);
        $this->curl->post($endpoint, $this->encodeRequest($method, $params));
        $status = $this->curl->getStatus();
        if ($status < 200 || $status >= 300) {
            throw new LocalizedException(__('Odoo returned HTTP %1.', $status));
        }

        return $this->decode((string) $this->curl->getBody());
    }

    /**
     * @param array<int, mixed> $params
     */
    private function encodeRequest(string $method, array $params): string
    {
        $xml = '<?xml version="1.0"?><methodCall><methodName>'
            . htmlspecialchars($method, ENT_XML1)
            . '</methodName><params>';
        foreach ($params as $param) {
            $xml .= '<param><value>' . $this->encodeInner($param) . '</value></param>';
        }

        return $xml . '</params></methodCall>';
    }

    private function encodeInner(mixed $value): string
    {
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
            return '<string>' . htmlspecialchars($value, ENT_XML1) . '</string>';
        }
        if (!is_array($value)) {
            return '<string>' . htmlspecialchars((string) $value, ENT_XML1) . '</string>';
        }
        if (array_is_list($value)) {
            $data = '';
            foreach ($value as $item) {
                $data .= '<value>' . $this->encodeInner($item) . '</value>';
            }

            return '<array><data>' . $data . '</data></array>';
        }
        $members = '';
        foreach ($value as $name => $item) {
            $members .= '<member><name>' . htmlspecialchars((string) $name, ENT_XML1) . '</name><value>'
                . $this->encodeInner($item) . '</value></member>';
        }

        return '<struct>' . $members . '</struct>';
    }

    private function decode(string $body): mixed
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
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
        $type = $child->getName();

        return match ($type) {
            'int', 'i4' => (int) $child,
            'double' => (float) $child,
            'boolean' => (string) $child === '1',
            'string' => (string) $child,
            'nil' => null,
            'array' => $this->decodeArray($child),
            'struct' => $this->decodeStruct($child),
            default => (string) $child,
        };
    }

    /**
     * @return array<int, mixed>
     */
    private function decodeArray(\SimpleXMLElement $array): array
    {
        $items = [];
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
