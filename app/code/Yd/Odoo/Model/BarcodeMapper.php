<?php

declare(strict_types=1);

namespace Yd\Odoo\Model;

/**
 * Maps an Odoo barcode onto Magento UPC and EAN without inventing a second code.
 *
 * A valid UPC-A is stored as 12 digits and as the EAN-13 formed by prefixing 0.
 * A valid EAN-13 that already starts with 0 also yields that UPC-A. Every other
 * value, including a non-GS1 code assigned in Odoo, is kept exactly on EAN.
 */
class BarcodeMapper
{
    /**
     * @return array{upc: string|null, ean: string|null}
     */
    public function map(mixed $barcode): array
    {
        if ($barcode === null || $barcode === false) {
            return ['upc' => null, 'ean' => null];
        }
        $value = trim((string) $barcode);
        if ($value === '') {
            return ['upc' => null, 'ean' => null];
        }
        if (preg_match('/^\d{12}$/', $value) === 1 && $this->isValid($value)) {
            return ['upc' => $value, 'ean' => '0' . $value];
        }
        if (preg_match('/^\d{13}$/', $value) === 1 && $this->isValid($value)) {
            if ($value[0] === '0') {
                return ['upc' => substr($value, 1), 'ean' => $value];
            }

            return ['upc' => null, 'ean' => $value];
        }
        if (preg_match('/^\d{8}$/', $value) === 1 && $this->isValid($value)) {
            return ['upc' => null, 'ean' => $value];
        }
        if (preg_match('/^\d{14}$/', $value) === 1 && $this->isValid($value)) {
            return ['upc' => null, 'ean' => $value];
        }

        return ['upc' => null, 'ean' => $value];
    }

    private function isValid(string $code): bool
    {
        $data = substr($code, 0, -1);

        return $this->checkDigit($data) === substr($code, -1);
    }

    /**
     * GS1 check digit. The rightmost data digit has weight 3.
     */
    private function checkDigit(string $data): string
    {
        $sum = 0;
        $weight = 3;
        for ($index = strlen($data) - 1; $index >= 0; $index--) {
            $sum += ((int) $data[$index]) * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }
}
