<?php

declare(strict_types=1);

namespace Yd\Odoo\Test\Unit\Model;

use Yd\Odoo\Model\BarcodeMapper;
use PHPUnit\Framework\TestCase;

class BarcodeMapperTest extends TestCase
{
    private BarcodeMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new BarcodeMapper();
    }

    public function testUpcAIsStoredAsUpcAndAsEan13WithALeadingZero(): void
    {
        self::assertSame(
            ['upc' => '036000291452', 'ean' => '0036000291452'],
            $this->mapper->map('036000291452')
        );
        self::assertSame(
            ['upc' => '036000291452', 'ean' => '0036000291452'],
            $this->mapper->map(' 036000291452 ')
        );
    }

    public function testEan13ThatStartsWithZeroAlsoYieldsTheUpc(): void
    {
        self::assertSame(
            ['upc' => '036000291452', 'ean' => '0036000291452'],
            $this->mapper->map('0036000291452')
        );
    }

    public function testEan13ThatDoesNotStartWithZeroDoesNotInventAUpc(): void
    {
        self::assertSame(
            ['upc' => null, 'ean' => '4006381333931'],
            $this->mapper->map('4006381333931')
        );
    }

    public function testEan8AndGtin14StayOnEanOnly(): void
    {
        self::assertSame(['upc' => null, 'ean' => '96385074'], $this->mapper->map('96385074'));
        self::assertSame(['upc' => null, 'ean' => '00012345678905'], $this->mapper->map('00012345678905'));
    }

    public function testEmptyBarcodeDoesNotClearEitherField(): void
    {
        self::assertSame(['upc' => null, 'ean' => null], $this->mapper->map(false));
        self::assertSame(['upc' => null, 'ean' => null], $this->mapper->map(null));
        self::assertSame(['upc' => null, 'ean' => null], $this->mapper->map(''));
        self::assertSame(['upc' => null, 'ean' => null], $this->mapper->map('   '));
    }

    public function testNonGs1AndInvalidCheckDigitsArePreservedExactly(): void
    {
        self::assertSame(['upc' => null, 'ean' => 'ABC-1'], $this->mapper->map('ABC-1'));
        self::assertSame(['upc' => null, 'ean' => '036000291458'], $this->mapper->map('036000291458'));
        self::assertSame(['upc' => null, 'ean' => '36000291452'], $this->mapper->map(36000291452));
    }
}
