<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Model;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;

class ZebraLabel
{
    public function __construct(private readonly ScopeConfigInterface $scopeConfig)
    {
    }

    /**
     * @throws LocalizedException
     */
    public function barcodeFromProduct(Product $product): string
    {
        $barcode = trim((string) ($product->getData('upc') ?: $product->getData('ean') ?: ''));
        if ($barcode === '') {
            throw new LocalizedException(__('This product has no UPC or EAN to print.'));
        }

        return $barcode;
    }

    /**
     * @throws LocalizedException
     */
    public function zpl(string $name, string $sku, string $barcode): string
    {
        [$command, $data] = $this->barcodeField($barcode);
        $safeName = $this->escape(substr($name, 0, 32));
        $safeSku = $this->escape(substr($sku, 0, 24));

        return "^XA\n^PW406\n^LL203\n^FO20,20^A0N,28,28^FD{$safeName}^FS\n"
            . "^FO20,60^{$command},80,Y,N^FD{$data}^FS\n"
            . "^FO20,170^A0N,22,22^FD{$safeSku}^FS\n^XZ\n";
    }

    /**
     * UPC-A uses ^BUN and 11 data digits (the printer adds the check digit).
     * A 13-digit EAN that is a UPC-A with a leading zero uses the same command.
     * Any other valid EAN-13 uses ^BEN and 12 data digits.
     *
     * @return array{0: string, 1: string}
     * @throws LocalizedException
     */
    public function barcodeField(string $barcode): array
    {
        $digits = preg_replace('/\D/', '', $barcode) ?? '';
        if (strlen($digits) === 12 && CheckDigit::isValid($digits)) {
            return ['BUN', substr($digits, 0, 11)];
        }
        if (strlen($digits) === 11 && ctype_digit($digits)) {
            return ['BUN', $digits];
        }
        if (strlen($digits) === 13 && $digits[0] === '0' && CheckDigit::isValid($digits)) {
            return ['BUN', substr($digits, 1, 11)];
        }
        if (strlen($digits) === 13 && CheckDigit::isValid($digits)) {
            return ['BEN', substr($digits, 0, 12)];
        }

        throw new LocalizedException(__('The barcode is not a valid UPC-A or EAN-13 number.'));
    }

    /**
     * @throws LocalizedException
     */
    public function send(string $zpl): void
    {
        $host = trim((string) $this->scopeConfig->getValue('octocub_upc/zebra/host'));
        if ($host === '') {
            return;
        }
        if (!preg_match('/^[A-Za-z0-9.-]+$/', $host)) {
            throw new LocalizedException(__('The Zebra printer host is not a valid hostname or IP address.'));
        }
        $port = (int) $this->scopeConfig->getValue('octocub_upc/zebra/port');
        if ($port <= 0) {
            $port = 9100;
        }
        if ($port > 65535) {
            throw new LocalizedException(__('The Zebra printer port is not valid.'));
        }
        $socket = @fsockopen($host, $port, $errorNumber, $error, 5);
        if ($socket === false) {
            throw new LocalizedException(
                __('The Zebra printer %1:%2 did not accept the label (%3).', $host, $port, $error)
            );
        }
        try {
            $written = 0;
            $length = strlen($zpl);
            while ($written < $length) {
                $chunk = fwrite($socket, substr($zpl, $written));
                if ($chunk === false || $chunk === 0) {
                    throw new LocalizedException(
                        __('The Zebra printer %1:%2 did not accept the full label.', $host, $port)
                    );
                }
                $written += $chunk;
            }
        } finally {
            fclose($socket);
        }
    }

    private function escape(string $value): string
    {
        return str_replace(['^', '~', '\\'], ' ', $value);
    }
}
