<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Model;

/**
 * GS1 check digit. The rightmost data digit is weighted 3, then weights alternate.
 * For UPC-A that is 11 data digits; the same rule is the EAN-13 rule for 12 data digits.
 */
class CheckDigit
{
    public static function calculate(string $data): string
    {
        if ($data === '' || !ctype_digit($data)) {
            throw new \InvalidArgumentException('Check digit data must be digits.');
        }
        $sum = 0;
        $weight = 3;
        for ($index = strlen($data) - 1; $index >= 0; $index--) {
            $sum += ((int) $data[$index]) * $weight;
            $weight = $weight === 3 ? 1 : 3;
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }

    public static function append(string $data): string
    {
        return $data . self::calculate($data);
    }

    public static function isValid(string $code): bool
    {
        $digits = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($digits) < 2 || strlen($digits) !== strlen($code)) {
            return false;
        }

        return self::calculate(substr($digits, 0, -1)) === substr($digits, -1);
    }
}
