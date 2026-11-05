<?php

declare(strict_types=1);

namespace Yd\Wholesales\Model;

/**
 * The Magento 1 controller checked an undefined variable, so the filters never ran.
 */
class SpamGuard
{
    public function isSpam(array $data): bool
    {
        $company = (string) ($data['compName'] ?? '');
        $trading = (string) ($data['tradeName'] ?? '');

        foreach ([$company, $trading] as $value) {
            if (stripos($value, 'http://') !== false || stripos($value, 'https://') !== false) {
                return true;
            }
            if (str_contains($value, 'ВЫИГРАЙТЕ')) {
                return true;
            }
            if (strlen($value) > 40) {
                return true;
            }
        }

        return false;
    }
}
