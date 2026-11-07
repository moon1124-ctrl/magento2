<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Model;

use Magento\Framework\Exception\LocalizedException;

class PrefixValidator
{
    public const MIN_LENGTH = 6;

    public const MAX_LENGTH = 10;

    /**
     * @throws LocalizedException
     */
    public static function normalize(string $value): string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if ($digits !== '' && (strlen($digits) < self::MIN_LENGTH || strlen($digits) > self::MAX_LENGTH)) {
            throw new LocalizedException(
                __('The GS1 company prefix must be 6 to 10 digits so a UPC-A number keeps at least one sequence digit.')
            );
        }

        return $digits;
    }
}
