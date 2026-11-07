<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;

class ZebraLabel
{
    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    public function zpl(string $name, string $sku, string $barcode): string
    {
        $body = preg_replace('/\D/', '', $barcode) ?? '';
        if (strlen($body) === 13) {
            $body = substr($body, 0, 12);
        }
        $safeName = $this->escape(substr($name, 0, 32));
        $safeSku = $this->escape(substr($sku, 0, 24));

        return "^XA\n^PW406\n^LL203\n^FO20,20^A0N,28,28^FD{$safeName}^FS\n"
            . "^FO20,60^BEN,80,Y,N^FD{$body}^FS\n"
            . "^FO20,170^A0N,22,22^FD{$safeSku}^FS\n^XZ\n";
    }

    public function send(string $zpl): void
    {
        $host = trim((string) $this->scopeConfig->getValue('octocub_upc/zebra/host'));
        if ($host === '') {
            return;
        }
        $port = (int) $this->scopeConfig->getValue('octocub_upc/zebra/port');
        if ($port <= 0) {
            $port = 9100;
        }
        $socket = @fsockopen($host, $port, $errorNumber, $error, 5);
        if ($socket === false) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('The Zebra printer %1:%2 did not accept the label (%3).', $host, $port, $error)
            );
        }
        try {
            fwrite($socket, $zpl);
        } finally {
            fclose($socket);
        }
    }

    private function escape(string $value): string
    {
        return str_replace(['^', '~', '\\'], ' ', $value);
    }
}
