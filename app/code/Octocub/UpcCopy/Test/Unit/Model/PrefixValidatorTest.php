<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Octocub\UpcCopy\Model\PrefixValidator;
use PHPUnit\Framework\TestCase;

class PrefixValidatorTest extends TestCase
{
    public function testEmptyPrefixStaysEmpty(): void
    {
        self::assertSame('', PrefixValidator::normalize(''));
        self::assertSame('', PrefixValidator::normalize('---'));
    }

    public function testPrefixIsReducedToDigitsWithinRange(): void
    {
        self::assertSame('036000', PrefixValidator::normalize('036-000'));
        self::assertSame('1234567890', PrefixValidator::normalize('1234567890'));
    }

    public function testPrefixShorterThanSixDigitsIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('6 to 10');
        PrefixValidator::normalize('12345');
    }

    public function testPrefixLongerThanTenDigitsIsRejected(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('6 to 10');
        PrefixValidator::normalize('12345678901');
    }
}
