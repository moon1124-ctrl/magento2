<?php

declare(strict_types=1);

namespace Octocub\UpcCopy\Test\Unit\Model;

use Octocub\UpcCopy\Model\CheckDigit;
use PHPUnit\Framework\TestCase;

class CheckDigitTest extends TestCase
{
    public function testUpcACheckDigitWeightsTheRightmostDataDigitByThree(): void
    {
        self::assertSame('2', CheckDigit::calculate('03600029145'));
        self::assertSame('036000291452', CheckDigit::append('03600029145'));
        self::assertSame('5', CheckDigit::calculate('01234567890'));
        self::assertSame('012345678905', CheckDigit::append('01234567890'));
        self::assertSame('2', CheckDigit::calculate('12345678901'));
        self::assertTrue(CheckDigit::isValid('036000291452'));
        self::assertFalse(CheckDigit::isValid('036000291458'));
        self::assertFalse(CheckDigit::isValid('03600029145'));
    }

    public function testLeftToRightEanWeightsProduceTheWrongUpcADigit(): void
    {
        $sum = 0;
        foreach (str_split('03600029145') as $index => $digit) {
            $sum += ((int) $digit) * ($index % 2 === 0 ? 1 : 3);
        }
        $leftWeighted = (10 - ($sum % 10)) % 10;

        self::assertNotSame(2, $leftWeighted);
        self::assertSame('2', CheckDigit::calculate('03600029145'));
    }

    public function testEan13UsesTheSameRightWeightedRule(): void
    {
        self::assertSame('1', CheckDigit::calculate('400638133393'));
        self::assertTrue(CheckDigit::isValid('4006381333931'));
    }

    public function testNonDigitsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CheckDigit::calculate('0360002914A');
    }
}
